<?php

namespace App\Queries\Customers;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

final class BuildCustomerIndexStats
{
    public function __construct(
        private readonly CustomerIndexFilters $indexFilters
    ) {}

    /**
     * Compatibility metrics keep their historical filtered semantics while
     * the new KPI contract below remains global and stable.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, int>
     */
    public function legacy(
        Builder $filteredQuery,
        User $accountOwner,
        array $context,
        bool $showQuoteOperations,
        bool $showJobOperations,
        ?int $accountId = null
    ): array {
        $accountId ??= (int) $accountOwner->id;
        $recentThreshold = now()->subDays(30);
        $reservationsEnabled = (bool) ($context['capabilities']['reservations'] ?? false);
        $stats = [
            'total' => (clone $filteredQuery)->count(),
            'new' => (clone $filteredQuery)
                ->where('created_at', '>=', $recentThreshold)
                ->count(),
            'with_quotes' => $showQuoteOperations
                ? (clone $filteredQuery)
                    ->whereHas('quotes', fn (Builder $query): Builder => $query->where('user_id', $accountId))
                    ->count()
                : 0,
            'with_works' => $showJobOperations
                ? (clone $filteredQuery)
                    ->whereHas('works', fn (Builder $query): Builder => $query->where('user_id', $accountId))
                    ->count()
                : 0,
            'active' => 0,
        ];

        if ($showQuoteOperations || $showJobOperations || $reservationsEnabled) {
            $stats['active'] = (clone $filteredQuery)
                ->where(function (Builder $query) use (
                    $accountId,
                    $recentThreshold,
                    $reservationsEnabled,
                    $showJobOperations,
                    $showQuoteOperations
                ): void {
                    if ($showQuoteOperations) {
                        $query->whereHas('quotes', fn (Builder $sub): Builder => $sub
                            ->where('user_id', $accountId)
                            ->where('created_at', '>=', $recentThreshold));
                    }
                    if ($showJobOperations) {
                        $method = $showQuoteOperations ? 'orWhereHas' : 'whereHas';
                        $query->{$method}('works', fn (Builder $sub): Builder => $sub
                            ->where('user_id', $accountId)
                            ->where('created_at', '>=', $recentThreshold));
                    }
                    if ($reservationsEnabled) {
                        $method = ($showQuoteOperations || $showJobOperations) ? 'orWhereHas' : 'whereHas';
                        $query->{$method}('reservations', fn (Builder $sub): Builder => $sub
                            ->where('account_id', $accountId)
                            ->where('created_at', '>=', $recentThreshold));
                    }
                })
                ->count();
        }

        return $stats;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function kpis(User $accountOwner, array $context, ?int $accountId = null): array
    {
        $accountId ??= (int) $accountOwner->id;
        $timezone = $accountOwner->company_timezone ?: (string) config('app.timezone', 'UTC');
        $localNow = CarbonImmutable::now($timezone);
        $databaseNow = $localNow->setTimezone(config('app.timezone'));
        $monthStart = $localNow->startOfMonth()->setTimezone(config('app.timezone'));
        $capabilities = (array) ($context['capabilities'] ?? []);

        $customerMetrics = Customer::query()
            ->byUser($accountId)
            ->selectRaw('COUNT(*) as aggregate_total')
            ->selectRaw('COALESCE(SUM(CASE WHEN is_active = ? THEN 1 ELSE 0 END), 0) as aggregate_active', [true])
            ->selectRaw('COALESCE(SUM(CASE WHEN is_active = ? THEN 1 ELSE 0 END), 0) as aggregate_inactive', [false])
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN created_at >= ? AND created_at <= ? THEN 1 ELSE 0 END), 0) as aggregate_new_month',
                [$monthStart, $databaseNow]
            );

        if ($capabilities['campaigns'] ?? false) {
            $customerMetrics->selectRaw(
                'COALESCE(SUM(CASE WHEN is_vip = ? THEN 1 ELSE 0 END), 0) as aggregate_vip',
                [true]
            );
        }

        $metrics = $customerMetrics->first();
        $total = (int) ($metrics?->aggregate_total ?? 0);
        $kpis = [
            'total' => $total,
            'new_this_month' => (int) ($metrics?->aggregate_new_month ?? 0),
            'active' => (int) ($metrics?->aggregate_active ?? 0),
            'inactive' => (int) ($metrics?->aggregate_inactive ?? 0),
        ];

        if ($capabilities['campaigns'] ?? false) {
            $kpis['vip'] = (int) ($metrics?->aggregate_vip ?? 0);
        }

        if ($capabilities['reservations'] ?? false) {
            $kpis += $this->reservationKpis($accountId, $databaseNow, $total);
        }

        if ($capabilities['invoices'] ?? false) {
            $kpis['outstanding'] = $this->outstandingKpi($accountOwner, $accountId);
        }
        if (($capabilities['invoices'] ?? false) || ($capabilities['sales'] ?? false)) {
            $kpis['average_value_per_customer'] = $this->averageValueKpi(
                $accountOwner,
                $total,
                $capabilities,
                $accountId
            );
        }

        return $kpis;
    }

    /**
     * @return array<string, int|float>
     */
    private function reservationKpis(int $accountId, CarbonImmutable $databaseNow, int $total): array
    {
        $recentThreshold = $databaseNow->subDays(30);
        $noNextAppointment = Customer::query()
            ->byUser($accountId)
            ->where('is_active', true)
            ->whereDoesntHave('reservations', fn (Builder $query): Builder => $query
                ->reorder()
                ->where('account_id', $accountId)
                ->whereIn('status', Reservation::ACTIVE_STATUSES)
                ->where('starts_at', '>=', $databaseNow))
            ->count();

        $tenantReservations = Reservation::query()
            ->join('customers', 'customers.id', '=', 'reservations.client_id')
            ->where('reservations.account_id', $accountId)
            ->where('customers.user_id', $accountId);
        $recentCancellations = (clone $tenantReservations)
            ->where('reservations.status', Reservation::STATUS_CANCELLED)
            ->where('reservations.cancelled_at', '>=', $recentThreshold)
            ->distinct()
            ->count('reservations.client_id');
        $recentNoShows = (clone $tenantReservations)
            ->where('reservations.status', Reservation::STATUS_NO_SHOW)
            ->where('reservations.starts_at', '>=', $recentThreshold)
            ->where('reservations.starts_at', '<=', $databaseNow)
            ->distinct()
            ->count('reservations.client_id');

        $completedByCustomer = (clone $tenantReservations)
            ->where('reservations.status', Reservation::STATUS_COMPLETED)
            ->whereNotNull('reservations.client_id')
            ->groupBy('reservations.client_id')
            ->select('reservations.client_id')
            ->selectRaw('COUNT(*) as completed_count');
        $customersWithVisit = DB::query()
            ->fromSub(clone $completedByCustomer, 'completed_customer_visits')
            ->count();
        $returningCustomers = DB::query()
            ->fromSub(clone $completedByCustomer, 'completed_customer_visits')
            ->where('completed_count', '>=', 2)
            ->count();
        $completedAppointments = (clone $tenantReservations)
            ->where('reservations.status', Reservation::STATUS_COMPLETED)
            ->count('reservations.id');

        return [
            'no_next_appointment' => $noNextAppointment,
            'recent_cancellations' => $recentCancellations,
            'recent_no_shows' => $recentNoShows,
            'return_rate' => $customersWithVisit > 0
                ? round(($returningCustomers / $customersWithVisit) * 100, 1)
                : 0.0,
            'average_appointments_per_customer' => $total > 0
                ? round($completedAppointments / $total, 2)
                : 0.0,
        ];
    }

    /**
     * @return array<string, array<string, int|float|string>>
     */
    private function outstandingKpi(User $accountOwner, int $accountId): array
    {
        $currencyCode = $accountOwner->businessCurrencyCode();
        $outstanding = DB::query()
            ->fromSub(
                $this->indexFilters->outstandingBalanceQuery($accountOwner, $accountId),
                'customer_balances'
            )
            ->where('outstanding_balance', '>', 0)
            ->selectRaw('COUNT(*) as customer_count')
            ->selectRaw('COALESCE(SUM(outstanding_balance), 0) as amount')
            ->first();

        return [
            'customers' => (int) ($outstanding?->customer_count ?? 0),
            'amount' => round((float) ($outstanding?->amount ?? 0), 2),
            'currency_code' => $currencyCode,
        ];
    }

    /**
     * @param  array<string, bool>  $capabilities
     * @return array{amount:float, currency_code:string}
     */
    private function averageValueKpi(
        User $accountOwner,
        int $total,
        array $capabilities,
        int $accountId
    ): array {
        $currencyCode = $accountOwner->businessCurrencyCode();
        $tipReversalSql = 'CASE'
            .' WHEN COALESCE(payments.tip_reversed_amount, 0) <= 0 THEN 0'
            .' WHEN COALESCE(payments.tip_reversed_amount, 0) < COALESCE(payments.tip_amount, 0)'
            .' THEN COALESCE(payments.tip_reversed_amount, 0)'
            .' ELSE COALESCE(payments.tip_amount, 0) END';
        $settledValue = Payment::query()
            ->join('customers', 'customers.id', '=', 'payments.customer_id')
            ->where('payments.user_id', $accountId)
            ->where('customers.user_id', $accountId)
            ->where('payments.currency_code', $currencyCode)
            ->whereIn('payments.status', Payment::settledStatuses())
            ->where(function (Builder $sourceQuery) use ($accountId, $capabilities): void {
                $hasSource = false;
                if ($capabilities['invoices'] ?? false) {
                    $sourceQuery->where(function (Builder $invoiceSource) use ($accountId): void {
                        $invoiceSource
                            ->whereNotNull('payments.invoice_id')
                            ->whereExists(function (QueryBuilder $invoiceQuery) use ($accountId): void {
                                $invoiceQuery
                                    ->selectRaw('1')
                                    ->from('invoices')
                                    ->whereColumn('invoices.id', 'payments.invoice_id')
                                    ->where('invoices.user_id', $accountId)
                                    ->whereNull('invoices.deleted_at');
                            });
                    });
                    $hasSource = true;
                }
                if ($capabilities['sales'] ?? false) {
                    $method = $hasSource ? 'orWhere' : 'where';
                    $sourceQuery->{$method}(function (Builder $saleSource) use ($accountId): void {
                        $saleSource
                            ->whereNotNull('payments.sale_id')
                            ->whereExists(function (QueryBuilder $saleQuery) use ($accountId): void {
                                $saleQuery
                                    ->selectRaw('1')
                                    ->from('sales')
                                    ->whereColumn('sales.id', 'payments.sale_id')
                                    ->where('sales.user_id', $accountId);
                            });
                    });
                }
            })
            ->selectRaw(
                "COALESCE(SUM(COALESCE(payments.charged_total, payments.amount + COALESCE(payments.tip_amount, 0)) - {$tipReversalSql}), 0) as amount"
            )
            ->value('amount');

        return [
            'amount' => $total > 0 ? round((float) $settledValue / $total, 2) : 0.0,
            'currency_code' => $currencyCode,
        ];
    }
}
