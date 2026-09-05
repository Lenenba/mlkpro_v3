<?php

namespace App\Queries\Demo;

use App\Models\AvailabilityException;
use App\Models\Customer;
use App\Models\DemoWorkspace;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Reservation;
use App\Models\SaleItem;
use App\Models\Task;
use App\Models\TeamMember;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WeeklyAvailability;
use App\Models\Work;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class DemoScenarioDashboardQuery
{
    /**
     * Build a dashboard snapshot from persisted scenario records.
     *
     * @return array<string, mixed>|null
     */
    public function execute(User $owner): ?array
    {
        $workspace = DemoWorkspace::query()
            ->where('owner_user_id', $owner->id)
            ->whereNotNull('scenario_key')
            ->latest('id')
            ->first(['id', 'scenario_key', 'reference_date', 'timezone']);

        if (! $workspace) {
            return null;
        }

        $timezone = filled($workspace->timezone)
            ? (string) $workspace->timezone
            : (filled($owner->company_timezone) ? (string) $owner->company_timezone : 'UTC');
        $reference = CarbonImmutable::parse(
            $workspace->reference_date?->toDateString() ?? now($timezone)->toDateString(),
            $timezone,
        )->endOfDay();
        $ownerId = (int) $owner->id;
        $monthStart = $reference->startOfMonth()->utc();
        $monthEnd = $reference->endOfMonth()->utc();
        $previousMonthStart = $reference->subMonthNoOverflow()->startOfMonth()->utc();
        $previousMonthEnd = $reference->subMonthNoOverflow()->endOfMonth()->utc();
        $dayStart = $reference->startOfDay()->utc();
        $dayEnd = $reference->endOfDay()->utc();
        $windowStart = $reference->subDays(29)->startOfDay()->utc();
        $historyStart = $reference->subMonthsNoOverflow(11)->startOfMonth()->utc();
        $usesReservations = $owner->hasCompanyFeature('reservations');
        $operatingModel = $usesReservations ? 'appointments' : 'field_operations';

        $revenueCurrent = $this->settledRevenue($ownerId, $monthStart, $monthEnd);
        $revenuePrevious = $this->settledRevenue($ownerId, $previousMonthStart, $previousMonthEnd);
        $availableMinutes = $this->availableMinutes($ownerId, $windowStart, $dayEnd, $timezone);
        $activity = $usesReservations
            ? $this->appointmentActivity($ownerId, $windowStart, $dayStart, $dayEnd, $monthStart, $monthEnd, $historyStart)
            : $this->fieldOperationActivity($ownerId, $reference, $windowStart, $monthStart, $monthEnd, $historyStart);
        $serviceOutcomeTotal = (int) $activity['outcomes'];
        $outstanding = Invoice::query()
            ->byUser($ownerId)
            ->whereNotIn('status', ['paid', 'void'])
            ->withSum([
                'payments as payments_sum_amount' => fn ($query) => $query->whereIn('status', Payment::settledStatuses()),
            ], 'amount')
            ->get(['id', 'total']);
        $outstandingBalance = round((float) $outstanding->sum(
            fn (Invoice $invoice): float => max(
                0,
                (float) $invoice->total - (float) ($invoice->payments_sum_amount ?? 0),
            ),
        ), 2);
        $recurringCustomers = (int) $activity['recurring_customers'];
        $futureCustomerIds = $activity['future_customer_ids'];
        $acceptedFutureQuotes = Quote::query()
            ->byUser($ownerId)
            ->where('status', 'accepted')
            ->whereIn('customer_id', $futureCustomerIds)
            ->get(['id', 'total']);
        $acceptedFutureQuoteIds = $acceptedFutureQuotes->pluck('id');
        $futureDeposits = $acceptedFutureQuoteIds->isEmpty()
            ? 0.0
            : (float) Transaction::query()
                ->where('user_id', $ownerId)
                ->whereIn('quote_id', $acceptedFutureQuoteIds)
                ->where('type', 'deposit')
                ->where('status', 'completed')
                ->sum('amount');
        $committedFutureRevenue = max(
            0,
            round((float) $acceptedFutureQuotes->sum('total') - $futureDeposits, 2),
        );

        $monthly = $this->monthlySeries($ownerId, $reference, $usesReservations);
        $rankingHistoryStart = $usesReservations
            ? $historyStart
            : $reference->subMonthsNoOverflow(11)->startOfMonth();
        $rankingWindowStart = $usesReservations
            ? $windowStart
            : $reference->subDays(29)->startOfDay();
        $rankingEnd = $usesReservations ? $dayEnd : $reference;

        return [
            'scenario_key' => (string) $workspace->scenario_key,
            'operating_model' => $operatingModel,
            'reference_date' => $reference->toDateString(),
            'timezone' => $timezone,
            'range_months' => 12,
            'metrics' => [
                'revenue_current_month' => round($revenueCurrent, 2),
                'revenue_previous_month' => round($revenuePrevious, 2),
                'revenue_change_percent' => $revenuePrevious > 0
                    ? round((($revenueCurrent - $revenuePrevious) / $revenuePrevious) * 100, 1)
                    : null,
                'reservations_today' => (int) $activity['today'],
                'reservations_upcoming' => (int) $activity['upcoming'],
                'occupancy_rate' => $availableMinutes > 0
                    ? round(min(100, (((int) $activity['booked_minutes']) / $availableMinutes) * 100), 1)
                    : 0.0,
                'customers_new' => Customer::query()
                    ->byUser($ownerId)
                    ->whereBetween('created_at', [$monthStart, $monthEnd])
                    ->count(),
                'customers_recurring' => $recurringCustomers,
                'average_service_value' => round((float) $activity['average_service_value'], 2),
                'outstanding_invoices' => $outstanding->count(),
                'outstanding_balance' => $outstandingBalance,
                'committed_future_revenue' => $committedFutureRevenue,
                'cancellation_rate' => $serviceOutcomeTotal > 0
                    ? round((((int) $activity['cancelled']) / $serviceOutcomeTotal) * 100, 1)
                    : 0.0,
                'no_show_rate' => $serviceOutcomeTotal > 0
                    ? round((((int) $activity['exceptions']) / $serviceOutcomeTotal) * 100, 1)
                    : 0.0,
                'pending_quotes' => Quote::query()
                    ->byUser($ownerId)
                    ->whereIn('status', ['draft', 'sent'])
                    ->count(),
                'open_tasks' => Task::query()
                    ->forAccount($ownerId)
                    ->open()
                    ->count(),
                'inventory_alerts' => Product::query()
                    ->byUser($ownerId)
                    ->products()
                    ->whereColumn('stock', '<=', 'minimum_stock')
                    ->count(),
                'unread_notifications' => $owner->unreadNotifications()->count(),
            ],
            'monthly' => $monthly,
            'top_services' => $this->topServices($ownerId, $rankingHistoryStart, $rankingEnd, $usesReservations),
            'top_employees' => $this->topEmployees($ownerId, $rankingWindowStart, $rankingEnd, $usesReservations),
            'top_products' => $this->topProducts($ownerId, $rankingHistoryStart, $rankingEnd, $usesReservations),
            'recent_payments' => Payment::query()
                ->where('user_id', $ownerId)
                ->whereIn('status', Payment::settledStatuses())
                ->whereNotNull('paid_at')
                ->where('paid_at', '<=', $dayEnd)
                ->latest('paid_at')
                ->limit(5)
                ->get(['id', 'amount', 'currency_code', 'method', 'paid_at'])
                ->map(fn (Payment $payment): array => [
                    'id' => $payment->id,
                    'amount' => (float) $payment->amount,
                    'currency_code' => $payment->currency_code,
                    'method' => $payment->method,
                    'paid_at' => $payment->paid_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
        ];
    }

    private function settledRevenue(int $ownerId, CarbonImmutable $start, CarbonImmutable $end): float
    {
        return (float) Payment::query()
            ->where('user_id', $ownerId)
            ->whereIn('status', Payment::settledStatuses())
            ->whereBetween('paid_at', [$start, $end])
            ->sum('amount');
    }

    /**
     * @return array<string, mixed>
     */
    private function appointmentActivity(
        int $ownerId,
        CarbonImmutable $windowStart,
        CarbonImmutable $dayStart,
        CarbonImmutable $dayEnd,
        CarbonImmutable $monthStart,
        CarbonImmutable $monthEnd,
        CarbonImmutable $historyStart,
    ): array {
        $reservationsWindow = Reservation::query()
            ->forAccount($ownerId)
            ->whereBetween('starts_at', [$windowStart, $dayEnd]);
        $bookedMinutes = (int) (clone $reservationsWindow)
            ->whereIn('status', [
                Reservation::STATUS_CONFIRMED,
                Reservation::STATUS_COMPLETED,
                Reservation::STATUS_NO_SHOW,
            ])
            ->sum('duration_minutes');
        $outcomes = (clone $reservationsWindow)
            ->whereIn('status', [
                Reservation::STATUS_COMPLETED,
                Reservation::STATUS_NO_SHOW,
                Reservation::STATUS_CANCELLED,
            ])
            ->count();
        $averageServiceValue = (float) Reservation::query()
            ->forAccount($ownerId)
            ->where('status', Reservation::STATUS_COMPLETED)
            ->whereBetween('starts_at', [$monthStart, $monthEnd])
            ->leftJoin('products', 'reservations.service_id', '=', 'products.id')
            ->avg('products.price');
        $recurringCustomers = DB::query()
            ->fromSub(
                Reservation::query()
                    ->forAccount($ownerId)
                    ->where('status', Reservation::STATUS_COMPLETED)
                    ->whereBetween('starts_at', [$historyStart, $dayEnd])
                    ->whereNotNull('client_id')
                    ->select('client_id')
                    ->groupBy('client_id')
                    ->havingRaw('COUNT(*) >= 2'),
                'recurring_customers',
            )
            ->count();
        $futureCustomerIds = Reservation::query()
            ->forAccount($ownerId)
            ->whereIn('status', Reservation::ACTIVE_STATUSES)
            ->where('starts_at', '>', $dayEnd)
            ->whereNotNull('client_id')
            ->distinct()
            ->pluck('client_id');

        return [
            'today' => Reservation::query()
                ->forAccount($ownerId)
                ->whereBetween('starts_at', [$dayStart, $dayEnd])
                ->count(),
            'upcoming' => Reservation::query()
                ->forAccount($ownerId)
                ->whereIn('status', Reservation::ACTIVE_STATUSES)
                ->where('starts_at', '>', $dayEnd)
                ->where('starts_at', '<=', $dayEnd->addWeeks(3))
                ->count(),
            'booked_minutes' => $bookedMinutes,
            'outcomes' => $outcomes,
            'cancelled' => (clone $reservationsWindow)->where('status', Reservation::STATUS_CANCELLED)->count(),
            'exceptions' => (clone $reservationsWindow)->where('status', Reservation::STATUS_NO_SHOW)->count(),
            'average_service_value' => $averageServiceValue,
            'recurring_customers' => $recurringCustomers,
            'future_customer_ids' => $futureCustomerIds,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fieldOperationActivity(
        int $ownerId,
        CarbonImmutable $reference,
        CarbonImmutable $windowStart,
        CarbonImmutable $monthStart,
        CarbonImmutable $monthEnd,
        CarbonImmutable $historyStart,
    ): array {
        $timezone = $reference->getTimezone()->getName();
        $referenceDate = $reference->toDateString();
        $windowStartDate = $windowStart->setTimezone($timezone)->toDateString();
        $historyStartDate = $historyStart->setTimezone($timezone)->toDateString();
        $monthStartDate = $monthStart->setTimezone($timezone)->toDateString();
        $monthEndDate = $monthEnd->setTimezone($timezone)->toDateString();
        $activeStatuses = [
            Work::STATUS_TO_SCHEDULE,
            Work::STATUS_SCHEDULED,
            Work::STATUS_EN_ROUTE,
            Work::STATUS_IN_PROGRESS,
        ];
        $windowWorks = Work::query()
            ->byUser($ownerId)
            ->whereBetween('start_date', [$windowStartDate, $referenceDate])
            ->where('status', '!=', Work::STATUS_CANCELLED)
            ->withCount('teamMembers')
            ->get(['id', 'start_time', 'end_time', 'status']);
        $outcomeStatuses = array_values(array_unique([
            ...Work::COMPLETED_STATUSES,
            Work::STATUS_DISPUTE,
            Work::STATUS_CANCELLED,
        ]));
        $outcomes = Work::query()
            ->byUser($ownerId)
            ->whereBetween('start_date', [$historyStartDate, $referenceDate])
            ->whereIn('status', $outcomeStatuses)
            ->count();
        $recurringCustomers = DB::query()
            ->fromSub(
                Work::query()
                    ->byUser($ownerId)
                    ->whereIn('status', Work::COMPLETED_STATUSES)
                    ->whereBetween('start_date', [$historyStartDate, $referenceDate])
                    ->whereNotNull('customer_id')
                    ->select('customer_id')
                    ->groupBy('customer_id')
                    ->havingRaw('COUNT(*) >= 2'),
                'recurring_customers',
            )
            ->count();
        $futureCustomerIds = Work::query()
            ->byUser($ownerId)
            ->whereIn('status', $activeStatuses)
            ->whereDate('start_date', '>', $referenceDate)
            ->whereNotNull('customer_id')
            ->distinct()
            ->pluck('customer_id');

        return [
            'today' => Work::query()
                ->byUser($ownerId)
                ->whereDate('start_date', $referenceDate)
                ->where('status', '!=', Work::STATUS_CANCELLED)
                ->count(),
            'upcoming' => Work::query()
                ->byUser($ownerId)
                ->whereIn('status', $activeStatuses)
                ->whereDate('start_date', '>', $referenceDate)
                ->whereDate('start_date', '<=', $reference->addWeeks(3)->toDateString())
                ->count(),
            'booked_minutes' => (int) $windowWorks->sum(
                fn (Work $work): int => $this->timeRangeMinutes($work->start_time, $work->end_time)
                    * max(1, (int) $work->team_members_count),
            ),
            'outcomes' => $outcomes,
            'cancelled' => Work::query()
                ->byUser($ownerId)
                ->whereBetween('start_date', [$historyStartDate, $referenceDate])
                ->where('status', Work::STATUS_CANCELLED)
                ->count(),
            'exceptions' => Work::query()
                ->byUser($ownerId)
                ->whereBetween('start_date', [$historyStartDate, $referenceDate])
                ->where('status', Work::STATUS_DISPUTE)
                ->count(),
            'average_service_value' => (float) Work::query()
                ->byUser($ownerId)
                ->whereIn('status', Work::COMPLETED_STATUSES)
                ->whereBetween('start_date', [$monthStartDate, $monthEndDate])
                ->avg('total'),
            'recurring_customers' => $recurringCustomers,
            'future_customer_ids' => $futureCustomerIds,
        ];
    }

    /**
     * @return array{labels: array<int, string>, revenue: array<int, float>, expenses: array<int, float>, reservations: array<int, int>}
     */
    private function monthlySeries(int $ownerId, CarbonImmutable $reference, bool $usesReservations): array
    {
        $labels = [];
        $revenue = [];
        $expenses = [];
        $reservations = [];

        for ($offset = 11; $offset >= 0; $offset--) {
            $month = $reference->subMonthsNoOverflow($offset);
            $monthStart = $month->startOfMonth();
            $monthEnd = $month->endOfMonth();
            $start = $monthStart->utc();
            $end = $monthEnd->utc();
            $labels[] = $month->format('Y-m');
            $revenue[] = round($this->settledRevenue($ownerId, $start, $end), 2);
            $expenses[] = round((float) Expense::query()
                ->byAccount($ownerId)
                ->whereIn('status', [Expense::STATUS_PAID, Expense::STATUS_REIMBURSED])
                ->whereBetween('paid_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                ->sum('total'), 2);
            $reservations[] = $usesReservations
                ? Reservation::query()
                    ->forAccount($ownerId)
                    ->whereBetween('starts_at', [$start, $end])
                    ->count()
                : Work::query()
                    ->byUser($ownerId)
                    ->whereBetween('start_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                    ->count();
        }

        return compact('labels', 'revenue', 'expenses', 'reservations');
    }

    /**
     * @return array<int, array{name: string, count: int, revenue: float}>
     */
    private function topServices(
        int $ownerId,
        CarbonImmutable $start,
        CarbonImmutable $end,
        bool $usesReservations,
    ): array {
        if (! $usesReservations) {
            return DB::table('product_works')
                ->join('works', 'works.id', '=', 'product_works.work_id')
                ->join('products', 'products.id', '=', 'product_works.product_id')
                ->where('works.user_id', $ownerId)
                ->where('products.item_type', Product::ITEM_TYPE_SERVICE)
                ->where('works.status', '!=', Work::STATUS_CANCELLED)
                ->whereBetween('works.start_date', [$start->toDateString(), $end->toDateString()])
                ->groupBy('products.id', 'products.name')
                ->orderByDesc('work_count')
                ->limit(5)
                ->get([
                    'products.name',
                    DB::raw('COUNT(DISTINCT works.id) as work_count'),
                    DB::raw('COALESCE(SUM(product_works.total), 0) as service_revenue'),
                ])
                ->map(fn ($row): array => [
                    'name' => (string) $row->name,
                    'count' => (int) $row->work_count,
                    'revenue' => round((float) $row->service_revenue, 2),
                ])
                ->values()
                ->all();
        }

        return Invoice::query()
            ->byUser($ownerId)
            ->where('source', 'reservation')
            ->whereNotIn('status', ['void'])
            ->whereBetween('created_at', [$start, $end])
            ->get(['billing_snapshot', 'total'])
            ->filter(fn (Invoice $invoice): bool => filled(data_get($invoice->billing_snapshot, 'service_name')))
            ->groupBy(fn (Invoice $invoice): string => (string) data_get($invoice->billing_snapshot, 'service_name'))
            ->map(fn ($invoices, string $name): array => [
                'name' => $name,
                'count' => $invoices->count(),
                'revenue' => round((float) $invoices->sum('total'), 2),
            ])
            ->sortByDesc('count')
            ->take(5)
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{name: string, reservations: int, booked_minutes: int}>
     */
    private function topEmployees(
        int $ownerId,
        CarbonImmutable $start,
        CarbonImmutable $end,
        bool $usesReservations,
    ): array {
        if (! $usesReservations) {
            return DB::table('work_team_members')
                ->join('works', 'works.id', '=', 'work_team_members.work_id')
                ->join('team_members', 'team_members.id', '=', 'work_team_members.team_member_id')
                ->join('users', 'users.id', '=', 'team_members.user_id')
                ->where('works.user_id', $ownerId)
                ->where('works.status', '!=', Work::STATUS_CANCELLED)
                ->whereBetween('works.start_date', [$start->toDateString(), $end->toDateString()])
                ->get(['users.name', 'works.id as work_id', 'works.start_time', 'works.end_time'])
                ->groupBy('name')
                ->map(function ($rows, string $name): array {
                    $works = $rows->unique('work_id');

                    return [
                        'name' => $name,
                        'reservations' => $works->count(),
                        'activity_count' => $works->count(),
                        'booked_minutes' => (int) $works->sum(
                            fn ($row): int => $this->timeRangeMinutes($row->start_time, $row->end_time),
                        ),
                    ];
                })
                ->sortByDesc('activity_count')
                ->take(5)
                ->values()
                ->all();
        }

        return TeamMember::query()
            ->where('team_members.account_id', $ownerId)
            ->join('users', 'team_members.user_id', '=', 'users.id')
            ->leftJoin('reservations', function ($join) use ($start, $end): void {
                $join->on('reservations.team_member_id', '=', 'team_members.id')
                    ->whereBetween('reservations.starts_at', [$start, $end])
                    ->whereIn('reservations.status', [
                        Reservation::STATUS_CONFIRMED,
                        Reservation::STATUS_RESCHEDULED,
                        Reservation::STATUS_COMPLETED,
                        Reservation::STATUS_NO_SHOW,
                    ]);
            })
            ->groupBy('team_members.id', 'users.name')
            ->orderByDesc('reservation_count')
            ->limit(5)
            ->get([
                'users.name',
                DB::raw('COUNT(reservations.id) as reservation_count'),
                DB::raw('COALESCE(SUM(reservations.duration_minutes), 0) as booked_minutes'),
            ])
            ->map(fn ($row): array => [
                'name' => (string) $row->name,
                'reservations' => (int) $row->reservation_count,
                'activity_count' => (int) $row->reservation_count,
                'booked_minutes' => (int) $row->booked_minutes,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{name: string, quantity: int, revenue: float}>
     */
    private function topProducts(
        int $ownerId,
        CarbonImmutable $start,
        CarbonImmutable $end,
        bool $usesReservations,
    ): array {
        if (! $usesReservations) {
            return DB::table('product_stock_movements')
                ->join('products', 'products.id', '=', 'product_stock_movements.product_id')
                ->where('products.user_id', $ownerId)
                ->whereIn('product_stock_movements.type', ['out', 'damage', 'spoilage', 'transfer_out'])
                ->whereBetween('product_stock_movements.created_at', [$start->utc(), $end->utc()])
                ->groupBy('products.id', 'products.name')
                ->orderByDesc('used_quantity')
                ->limit(5)
                ->get([
                    'products.name',
                    DB::raw('ABS(SUM(product_stock_movements.quantity)) as used_quantity'),
                    DB::raw('COALESCE(SUM(ABS(product_stock_movements.quantity) * product_stock_movements.unit_cost), 0) as usage_cost'),
                ])
                ->map(fn ($row): array => [
                    'name' => (string) $row->name,
                    'quantity' => (int) $row->used_quantity,
                    'revenue' => round((float) $row->usage_cost, 2),
                ])
                ->values()
                ->all();
        }

        return SaleItem::query()
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->join('products', 'sale_items.product_id', '=', 'products.id')
            ->where('sales.user_id', $ownerId)
            ->where('sales.status', 'paid')
            ->whereBetween('sales.created_at', [$start, $end])
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('sold_quantity')
            ->limit(5)
            ->get([
                'products.name',
                DB::raw('SUM(sale_items.quantity) as sold_quantity'),
                DB::raw('SUM(sale_items.total) as product_revenue'),
            ])
            ->map(fn ($row): array => [
                'name' => (string) $row->name,
                'quantity' => (int) $row->sold_quantity,
                'revenue' => round((float) $row->product_revenue, 2),
            ])
            ->values()
            ->all();
    }

    private function timeRangeMinutes(mixed $startTime, mixed $endTime): int
    {
        if (! $startTime || ! $endTime) {
            return 0;
        }

        $start = CarbonImmutable::parse((string) $startTime);
        $end = CarbonImmutable::parse((string) $endTime);

        if ($end->lte($start)) {
            $end = $end->addDay();
        }

        return max(0, $start->diffInMinutes($end, false));
    }

    private function availableMinutes(
        int $ownerId,
        CarbonImmutable $start,
        CarbonImmutable $end,
        string $timezone,
    ): int {
        $rows = WeeklyAvailability::query()
            ->forAccount($ownerId)
            ->active()
            ->get(['team_member_id', 'day_of_week', 'start_time', 'end_time'])
            ->groupBy('day_of_week');
        $closed = AvailabilityException::query()
            ->forAccount($ownerId)
            ->where('type', AvailabilityException::TYPE_CLOSED)
            ->whereBetween('date', [$start->setTimezone($timezone)->toDateString(), $end->setTimezone($timezone)->toDateString()])
            ->get(['team_member_id', 'date'])
            ->mapWithKeys(fn (AvailabilityException $exception): array => [
                $exception->team_member_id.'|'.$exception->date->toDateString() => true,
            ]);
        $cursor = $start->setTimezone($timezone)->startOfDay();
        $lastDay = $end->setTimezone($timezone)->endOfDay();
        $minutes = 0;

        while ($cursor->lte($lastDay)) {
            foreach ($rows->get($cursor->dayOfWeekIso, collect()) as $row) {
                if ($closed->has($row->team_member_id.'|'.$cursor->toDateString())) {
                    continue;
                }

                $shiftStart = CarbonImmutable::parse($cursor->toDateString().' '.$row->start_time, $timezone);
                $shiftEnd = CarbonImmutable::parse($cursor->toDateString().' '.$row->end_time, $timezone);
                $minutes += max(0, $shiftStart->diffInMinutes($shiftEnd, false));
            }

            $cursor = $cursor->addDay();
        }

        return $minutes;
    }
}
