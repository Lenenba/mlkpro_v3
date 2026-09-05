<?php

namespace App\Queries\Performance;

use App\Models\Customer;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\ReservationQueueInvoiceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ReservationPerformanceQuery
{
    /**
     * @return array{employeePerformance: array<string, mixed>, clientPerformance: array<string, mixed>}
     */
    public function summary(
        User $owner,
        Carbon $reference,
        int $memberLimit = 12,
        int $serviceLimit = 6,
        int $customerLimit = 10,
    ): array {
        $employeePeriods = [];
        $clientPeriods = [];

        foreach ($this->periods($reference) as $key => [$start, $end]) {
            $period = $this->period(
                (int) $owner->id,
                $start,
                $end,
                $memberLimit,
                $serviceLimit,
                $customerLimit,
            );
            $employeePeriods[$key] = $period['employee'];
            $clientPeriods[$key] = $period['client'];
        }

        $membersOfPeriods = [];
        $customersOfPeriods = [];

        foreach ($employeePeriods as $key => $period) {
            $membersOfPeriods[$key] = $period['top_sellers'][0] ?? null;
        }
        foreach ($clientPeriods as $key => $period) {
            $customersOfPeriods[$key] = $period['top_customers'][0] ?? null;
        }

        return [
            'employeePerformance' => [
                'periods' => $employeePeriods,
                'seller_of_periods' => $membersOfPeriods,
                'seller_of_year' => $membersOfPeriods['year'] ?? null,
            ],
            'clientPerformance' => [
                'periods' => $clientPeriods,
                'customer_of_periods' => $customersOfPeriods,
                'customer_of_year' => $customersOfPeriods['year'] ?? null,
            ],
        ];
    }

    /**
     * @return array{periods: array<string, array<string, mixed>>}
     */
    public function member(
        User $owner,
        int $teamMemberId,
        Carbon $reference,
        int $serviceLimit = 6,
        int $customerLimit = 6,
    ): array {
        $periods = [];

        foreach ($this->periods($reference) as $key => [$start, $end]) {
            $periods[$key] = $this->memberPeriod(
                (int) $owner->id,
                $teamMemberId,
                $start,
                $end,
                $serviceLimit,
                $customerLimit,
            );
        }

        return ['periods' => $periods];
    }

    /**
     * @return array<string, array{0: Carbon, 1: Carbon}>
     */
    private function periods(Carbon $reference): array
    {
        return [
            'day' => [$reference->copy()->startOfDay(), $reference->copy()->endOfDay()],
            'week' => [$reference->copy()->startOfWeek(), $reference->copy()->endOfWeek()],
            'month' => [$reference->copy()->startOfMonth(), $reference->copy()->endOfMonth()],
            'year' => [$reference->copy()->startOfYear(), $reference->copy()->endOfYear()],
        ];
    }

    /**
     * @return array{employee: array<string, mixed>, client: array<string, mixed>}
     */
    private function period(
        int $accountId,
        Carbon $start,
        Carbon $end,
        int $memberLimit,
        int $serviceLimit,
        int $customerLimit,
    ): array {
        $reservations = $this->completedReservationsStartingDuring($accountId, $start, $end);
        $reservationCount = (clone $reservations)->count();
        $serviceCount = (clone $reservations)->whereNotNull('service_id')->count();
        $attribution = $this->settledCashAttributionDuring($accountId, $start, $end);
        $revenue = $attribution['total'];

        $customerRows = (clone $reservations)
            ->whereNotNull('client_id')
            ->selectRaw(
                'client_id, COUNT(*) as reservations_count, '
                .'SUM(CASE WHEN service_id IS NOT NULL THEN 1 ELSE 0 END) as services_count'
            )
            ->groupBy('client_id')
            ->get()
            ->keyBy(fn (object $row): int => (int) $row->client_id);
        $activeCustomerCount = $customerRows->count();
        foreach (array_keys($attribution['customers']) as $customerId) {
            $customerId = (int) $customerId;
            if (! $customerRows->has($customerId)) {
                $customerRows->put($customerId, (object) [
                    'client_id' => $customerId,
                    'reservations_count' => 0,
                    'services_count' => 0,
                ]);
            }
        }
        $customerIds = $customerRows->keys()->map(fn (mixed $id): int => (int) $id);
        $customerMap = $customerIds->isNotEmpty()
            ? Customer::query()
                ->byUser($accountId)
                ->whereIn('id', $customerIds)
                ->get(['id', 'first_name', 'last_name', 'company_name', 'logo'])
                ->keyBy('id')
            : collect();
        $topCustomers = $customerRows
            ->map(function (object $row) use ($customerMap, $attribution): array {
                $customerId = (int) $row->client_id;
                $customer = $customerMap->get($customerId);

                return [
                    'id' => $customerId,
                    'name' => $this->customerName($customer),
                    'logo_url' => $customer?->logo_url,
                    'orders' => (int) $row->reservations_count,
                    'revenue' => round((float) ($attribution['customers'][$customerId] ?? 0), 2),
                    'items' => (int) $row->services_count,
                ];
            })
            ->sort($this->rankingComparator())
            ->take($customerLimit)
            ->values();

        $memberRows = (clone $reservations)
            ->selectRaw(
                'team_member_id, COUNT(*) as reservations_count, '
                .'SUM(CASE WHEN service_id IS NOT NULL THEN 1 ELSE 0 END) as services_count'
            )
            ->groupBy('team_member_id')
            ->get()
            ->keyBy(fn (object $row): int => (int) $row->team_member_id);
        $activeMemberCount = $memberRows->count();
        foreach (array_keys($attribution['members']) as $memberId) {
            $memberId = (int) $memberId;
            if (! $memberRows->has($memberId)) {
                $memberRows->put($memberId, (object) [
                    'team_member_id' => $memberId,
                    'reservations_count' => 0,
                    'services_count' => 0,
                ]);
            }
        }
        $memberIds = $memberRows->keys()->map(fn (mixed $id): int => (int) $id);
        $memberMap = $memberIds->isNotEmpty()
            ? TeamMember::query()
                ->forAccount($accountId)
                ->whereIn('id', $memberIds)
                ->with('user:id,name,profile_picture')
                ->get()
                ->keyBy('id')
            : collect();
        $topMembers = $memberRows
            ->map(function (object $row) use ($memberMap, $attribution): array {
                $memberId = (int) $row->team_member_id;
                $member = $memberMap->get($memberId);
                $userId = $member?->user_id ? (int) $member->user_id : null;

                return [
                    'id' => $userId ?? $memberId,
                    'team_member_id' => $memberId,
                    'type' => $userId ? 'user' : 'member',
                    'name' => $member?->user?->name ?? 'Member',
                    'profile_picture_url' => $member?->user?->profile_picture_url,
                    'orders' => (int) $row->reservations_count,
                    'revenue' => round((float) ($attribution['members'][$memberId] ?? 0), 2),
                    'items' => (int) $row->services_count,
                ];
            })
            ->sort($this->rankingComparator())
            ->take($memberLimit)
            ->values();

        $serviceRows = (clone $reservations)
            ->whereNotNull('service_id')
            ->selectRaw('service_id, COUNT(*) as quantity')
            ->groupBy('service_id')
            ->get()
            ->keyBy(fn (object $row): int => (int) $row->service_id);
        foreach (array_keys($attribution['services']) as $serviceId) {
            $serviceId = (int) $serviceId;
            if (! $serviceRows->has($serviceId)) {
                $serviceRows->put($serviceId, (object) [
                    'service_id' => $serviceId,
                    'quantity' => 0,
                ]);
            }
        }
        $serviceIds = $serviceRows->keys()->map(fn (mixed $id): int => (int) $id);
        $serviceMap = $serviceIds->isNotEmpty()
            ? Product::query()
                ->byUser($accountId)
                ->whereIn('id', $serviceIds)
                ->get(['id', 'name', 'image'])
                ->keyBy('id')
            : collect();
        $topServices = $serviceRows
            ->map(function (object $row) use ($serviceMap, $attribution): array {
                $serviceId = (int) $row->service_id;
                $service = $serviceMap->get($serviceId);

                return [
                    'id' => $serviceId,
                    'name' => $service?->name ?? 'Service',
                    'image_url' => $service?->image_url,
                    'quantity' => (int) $row->quantity,
                    'revenue' => round((float) ($attribution['services'][$serviceId] ?? 0), 2),
                ];
            })
            ->sort($this->serviceRankingComparator())
            ->take($serviceLimit)
            ->values();

        $average = $reservationCount > 0 ? round($revenue / $reservationCount, 2) : 0.0;
        $customerCount = $activeCustomerCount;
        $averageCustomer = $customerCount > 0 ? round($revenue / $customerCount, 2) : 0.0;
        $memberCount = $activeMemberCount;

        $range = [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
        ];

        return [
            'employee' => [
                'range' => $range,
                'orders' => $reservationCount,
                'revenue' => $revenue,
                'avg_order' => $average,
                'revenue_per_seller' => $memberCount > 0 ? round($revenue / $memberCount, 2) : 0.0,
                'items_sold' => $serviceCount,
                'customers' => $customerCount,
                'active_sellers' => $memberCount,
                'top_sellers' => $topMembers,
                'top_products' => $topServices,
            ],
            'client' => [
                'range' => $range,
                'orders' => $reservationCount,
                'revenue' => $revenue,
                'avg_order' => $average,
                'avg_customer_value' => $averageCustomer,
                'items_sold' => $serviceCount,
                'customers' => $customerCount,
                'top_customers' => $topCustomers,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function memberPeriod(
        int $accountId,
        int $teamMemberId,
        Carbon $start,
        Carbon $end,
        int $serviceLimit,
        int $customerLimit,
    ): array {
        $reservations = $this->completedReservationsStartingDuring($accountId, $start, $end)
            ->where('team_member_id', $teamMemberId);
        $reservationCount = (clone $reservations)->count();
        $serviceCount = (clone $reservations)->whereNotNull('service_id')->count();
        $attribution = $this->settledCashAttributionDuring($accountId, $start, $end);
        $revenue = round((float) ($attribution['members'][$teamMemberId] ?? 0), 2);

        $serviceRows = (clone $reservations)
            ->whereNotNull('service_id')
            ->selectRaw('service_id, COUNT(*) as quantity')
            ->groupBy('service_id')
            ->get()
            ->keyBy(fn (object $row): int => (int) $row->service_id);
        foreach (array_keys($attribution['member_services'][$teamMemberId] ?? []) as $serviceId) {
            $serviceId = (int) $serviceId;
            if (! $serviceRows->has($serviceId)) {
                $serviceRows->put($serviceId, (object) [
                    'service_id' => $serviceId,
                    'quantity' => 0,
                ]);
            }
        }
        $serviceIds = $serviceRows->keys()->map(fn (mixed $id): int => (int) $id);
        $serviceMap = $serviceIds->isNotEmpty()
            ? Product::query()
                ->byUser($accountId)
                ->whereIn('id', $serviceIds)
                ->get(['id', 'name', 'image'])
                ->keyBy('id')
            : collect();
        $topServices = $serviceRows
            ->map(function (object $row) use ($serviceMap, $attribution, $teamMemberId): array {
                $serviceId = (int) $row->service_id;
                $service = $serviceMap->get($serviceId);

                return [
                    'id' => $serviceId,
                    'name' => $service?->name ?? 'Service',
                    'image_url' => $service?->image_url,
                    'quantity' => (int) $row->quantity,
                    'revenue' => round((float) ($attribution['member_services'][$teamMemberId][$serviceId] ?? 0), 2),
                ];
            })
            ->sort($this->serviceRankingComparator())
            ->take($serviceLimit)
            ->values();

        $customerRows = (clone $reservations)
            ->whereNotNull('client_id')
            ->selectRaw(
                'client_id, COUNT(*) as reservations_count, '
                .'SUM(CASE WHEN service_id IS NOT NULL THEN 1 ELSE 0 END) as services_count'
            )
            ->groupBy('client_id')
            ->get()
            ->keyBy(fn (object $row): int => (int) $row->client_id);
        $activeCustomerCount = $customerRows->count();
        foreach (array_keys($attribution['member_customers'][$teamMemberId] ?? []) as $customerId) {
            $customerId = (int) $customerId;
            if (! $customerRows->has($customerId)) {
                $customerRows->put($customerId, (object) [
                    'client_id' => $customerId,
                    'reservations_count' => 0,
                    'services_count' => 0,
                ]);
            }
        }
        $customerIds = $customerRows->keys()->map(fn (mixed $id): int => (int) $id);
        $customerMap = $customerIds->isNotEmpty()
            ? Customer::query()
                ->byUser($accountId)
                ->whereIn('id', $customerIds)
                ->get(['id', 'first_name', 'last_name', 'company_name', 'logo'])
                ->keyBy('id')
            : collect();
        $topCustomers = $customerRows
            ->map(function (object $row) use ($customerMap, $attribution, $teamMemberId): array {
                $customerId = (int) $row->client_id;
                $customer = $customerMap->get($customerId);

                return [
                    'id' => $customerId,
                    'name' => $this->customerName($customer),
                    'logo_url' => $customer?->logo_url,
                    'orders' => (int) $row->reservations_count,
                    'revenue' => round((float) ($attribution['member_customers'][$teamMemberId][$customerId] ?? 0), 2),
                    'items' => (int) $row->services_count,
                ];
            })
            ->sort($this->rankingComparator())
            ->take($customerLimit)
            ->values();

        return [
            'range' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ],
            'orders' => $reservationCount,
            'revenue' => $revenue,
            'avg_order' => $reservationCount > 0 ? round($revenue / $reservationCount, 2) : 0.0,
            'items_sold' => $serviceCount,
            'customers' => $activeCustomerCount,
            'top_products' => $topServices,
            'top_customers' => $topCustomers,
        ];
    }

    /**
     * Operational activity belongs to the period in which the completed reservation starts.
     */
    private function completedReservationsStartingDuring(int $accountId, Carbon $start, Carbon $end)
    {
        return Reservation::query()
            ->forAccount($accountId)
            ->where('status', Reservation::STATUS_COMPLETED)
            ->whereBetween('starts_at', [$start->copy()->utc(), $end->copy()->utc()]);
    }

    /**
     * @return array{
     *     total: float,
     *     customers: array<int, float>,
     *     members: array<int, float>,
     *     services: array<int, float>,
     *     member_customers: array<int, array<int, float>>,
     *     member_services: array<int, array<int, float>>
     * }
     */
    private function settledCashAttributionDuring(int $accountId, Carbon $start, Carbon $end): array
    {
        // Revenue is a cash metric: it belongs to the period in which a successful
        // payment settles, independently from the reservation's starts_at period.
        $paymentRows = DB::table('payments')
            ->join('invoices', 'payments.invoice_id', '=', 'invoices.id')
            ->where('payments.user_id', $accountId)
            ->where('invoices.user_id', $accountId)
            ->whereIn('invoices.source', [
                'reservation',
                ReservationQueueInvoiceService::SOURCE_RESERVATION_QUEUE,
            ])
            ->where('invoices.status', '!=', 'void')
            ->whereIn('payments.status', Payment::settledStatuses())
            ->whereNotNull('payments.paid_at')
            ->whereBetween('payments.paid_at', [$start->copy()->utc(), $end->copy()->utc()])
            ->select(
                'payments.invoice_id',
                'invoices.customer_id',
                DB::raw('SUM(payments.amount) as revenue'),
            )
            ->groupBy('payments.invoice_id', 'invoices.customer_id')
            ->get();

        $result = [
            'total' => round((float) $paymentRows->sum('revenue'), 2),
            'customers' => [],
            'members' => [],
            'services' => [],
            'member_customers' => [],
            'member_services' => [],
        ];

        if ($paymentRows->isEmpty()) {
            return $result;
        }

        $itemsByInvoice = InvoiceItem::query()
            ->whereIn('invoice_id', $paymentRows->pluck('invoice_id'))
            ->get(['invoice_id', 'assigned_team_member_id', 'total', 'meta'])
            ->groupBy('invoice_id');

        foreach ($paymentRows as $paymentRow) {
            $invoiceId = (int) $paymentRow->invoice_id;
            $customerId = $paymentRow->customer_id ? (int) $paymentRow->customer_id : null;
            $revenue = round((float) $paymentRow->revenue, 2);
            $items = $itemsByInvoice->get($invoiceId, collect());

            if ($customerId) {
                $this->addAmount($result['customers'], $customerId, $revenue);
            }

            $memberWeights = [];
            $serviceWeights = [];
            $memberServiceWeights = [];

            foreach ($items as $item) {
                $memberId = $item->assigned_team_member_id ? (int) $item->assigned_team_member_id : null;
                $serviceId = (int) (
                    data_get($item->meta, 'service_id')
                    ?? data_get($item->meta, 'service.id')
                    ?? 0
                );
                $weight = max(0.0, (float) $item->total);

                if ($memberId) {
                    $memberWeights[$memberId] = ($memberWeights[$memberId] ?? 0.0) + $weight;
                }
                if ($serviceId > 0) {
                    $serviceWeights[$serviceId] = ($serviceWeights[$serviceId] ?? 0.0) + $weight;
                }
                if ($memberId && $serviceId > 0) {
                    $key = $memberId.':'.$serviceId;
                    $memberServiceWeights[$key] = ($memberServiceWeights[$key] ?? 0.0) + $weight;
                }
            }

            foreach ($this->allocate($revenue, $memberWeights) as $memberId => $amount) {
                $memberId = (int) $memberId;
                $this->addAmount($result['members'], $memberId, $amount);
                if ($customerId) {
                    $result['member_customers'][$memberId] ??= [];
                    $this->addAmount($result['member_customers'][$memberId], $customerId, $amount);
                }
            }
            foreach ($this->allocate($revenue, $serviceWeights) as $serviceId => $amount) {
                $this->addAmount($result['services'], (int) $serviceId, $amount);
            }
            foreach ($this->allocate($revenue, $memberServiceWeights) as $pair => $amount) {
                [$memberId, $serviceId] = array_map('intval', explode(':', (string) $pair, 2));
                $result['member_services'][$memberId] ??= [];
                $this->addAmount($result['member_services'][$memberId], $serviceId, $amount);
            }
        }

        return $result;
    }

    /**
     * @param  array<int|string, float>  $weights
     * @return array<int|string, float>
     */
    private function allocate(float $amount, array $weights): array
    {
        if ($weights === []) {
            return [];
        }

        if (array_sum($weights) <= 0) {
            $weights = array_fill_keys(array_keys($weights), 1.0);
        }

        ksort($weights);
        $totalWeight = (float) array_sum($weights);
        $amountInCents = (int) round($amount * 100);
        $remaining = $amountInCents;
        $keys = array_keys($weights);
        $lastKey = end($keys);
        $allocated = [];

        foreach ($weights as $key => $weight) {
            $cents = $key === $lastKey
                ? $remaining
                : (int) round($amountInCents * ((float) $weight / $totalWeight));
            $remaining -= $cents;
            $allocated[$key] = round($cents / 100, 2);
        }

        return $allocated;
    }

    /**
     * @param  array<int, float>  $amounts
     */
    private function addAmount(array &$amounts, int $key, float $amount): void
    {
        $amounts[$key] = round(($amounts[$key] ?? 0.0) + $amount, 2);
    }

    private function customerName(?Customer $customer): string
    {
        if (! $customer) {
            return 'Customer';
        }

        if ($customer->company_name) {
            return $customer->company_name;
        }

        $name = trim(($customer->first_name ?? '').' '.($customer->last_name ?? ''));

        return $name !== '' ? $name : 'Customer';
    }

    private function rankingComparator(): callable
    {
        return static function (array $left, array $right): int {
            foreach (['revenue', 'orders', 'items'] as $key) {
                $comparison = $right[$key] <=> $left[$key];
                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            return strcmp((string) $left['name'], (string) $right['name']);
        };
    }

    private function serviceRankingComparator(): callable
    {
        return static function (array $left, array $right): int {
            foreach (['revenue', 'quantity'] as $key) {
                $comparison = $right[$key] <=> $left[$key];
                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            return strcmp((string) $left['name'], (string) $right['name']);
        };
    }
}
