<?php

namespace App\Queries\Customers;

use App\Enums\CustomerClientType;
use App\Models\Customer;
use App\Models\CustomerPackage;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\User;
use App\Models\VipTier;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class CustomerIndexFilters
{
    /** @var array<int, string> */
    public const INPUT_KEYS = [
        'name',
        'city',
        'country',
        'has_quotes',
        'has_works',
        'status',
        'client_type',
        'is_vip',
        'vip_tier_id',
        'acquisition_source',
        'tags',
        'has_upcoming_appointment',
        'last_appointment_from',
        'last_appointment_to',
        'next_appointment_from',
        'next_appointment_to',
        'appointments_min',
        'appointments_max',
        'cancellations_min',
        'no_shows_min',
        'has_outstanding_balance',
        'outstanding_min',
        'outstanding_max',
        'total_invoiced_min',
        'total_invoiced_max',
        'last_invoice_from',
        'last_invoice_to',
        'payment_statuses',
        'created_from',
        'created_to',
        'has_active_package',
        'package_status',
        'package_remaining_lte',
        'package_expires_within_days',
        'package_is_recurring',
        'package_recurrence_status',
        'quick_filters',
        'quick_filter_mode',
        'operational_filter',
        'sort',
        'direction',
        'per_page',
    ];

    private const LEGACY_TO_QUICK = [
        'unpaid' => 'outstanding_balance',
    ];

    private const OPEN_INVOICE_STATUSES = [
        'sent',
        'awaiting_acceptance',
        'accepted',
        'partial',
        'overdue',
    ];

    private const RESERVATION_FIELDS = [
        'has_upcoming_appointment',
        'last_appointment_from',
        'last_appointment_to',
        'next_appointment_from',
        'next_appointment_to',
        'appointments_min',
        'appointments_max',
        'cancellations_min',
        'no_shows_min',
    ];

    private const FINANCIAL_FIELDS = [
        'has_outstanding_balance',
        'outstanding_min',
        'outstanding_max',
        'total_invoiced_min',
        'total_invoiced_max',
        'last_invoice_from',
        'last_invoice_to',
        'payment_statuses',
    ];

    private const DATE_FIELDS = [
        'last_appointment_from',
        'last_appointment_to',
        'next_appointment_from',
        'next_appointment_to',
        'last_invoice_from',
        'last_invoice_to',
        'created_from',
        'created_to',
    ];

    private const INTEGER_FIELDS = [
        'appointments_min',
        'appointments_max',
        'cancellations_min',
        'no_shows_min',
        'package_remaining_lte',
        'package_expires_within_days',
    ];

    private const MONEY_FIELDS = [
        'outstanding_min',
        'outstanding_max',
        'total_invoiced_min',
        'total_invoiced_max',
    ];

    private const PAYMENT_STATUSES = [
        Payment::STATUS_PENDING,
        Payment::STATUS_PAID,
        Payment::STATUS_COMPLETED,
        Payment::STATUS_FAILED,
        Payment::STATUS_REFUNDED,
        Payment::STATUS_REVERSED,
    ];

    private const PACKAGE_FIELDS = [
        'has_active_package',
        'package_status',
        'package_remaining_lte',
        'package_expires_within_days',
        'package_is_recurring',
        'package_recurrence_status',
    ];

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function normalize(
        array $input,
        User $accountOwner,
        array $context,
        ?int $accountId = null
    ): array {
        $accountId ??= (int) $accountOwner->id;
        $hasCanonicalQuickFilterInput = array_key_exists('quick_filters', $input);
        $filters = Arr::only($input, self::INPUT_KEYS);
        $filters = array_filter(
            $filters,
            static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []
        );

        foreach (['name', 'city', 'country', 'acquisition_source'] as $key) {
            if (! array_key_exists($key, $filters)) {
                continue;
            }

            $value = $this->stringValue($filters[$key]);
            if ($value === '') {
                unset($filters[$key]);
            } else {
                $filters[$key] = mb_substr($value, 0, 255);
            }
        }

        if (! in_array($filters['status'] ?? null, ['active', 'archived'], true)) {
            unset($filters['status']);
        }
        if (! in_array($filters['client_type'] ?? null, CustomerClientType::values(), true)) {
            unset($filters['client_type']);
        }

        foreach (['tags', 'payment_statuses'] as $key) {
            if (! array_key_exists($key, $filters)) {
                continue;
            }

            $limit = $key === 'tags' ? 20 : 6;
            $filters[$key] = collect(Arr::wrap($filters[$key]))
                ->filter(static fn (mixed $value): bool => is_scalar($value))
                ->map(static fn (mixed $value): string => mb_substr(trim((string) $value), 0, 100))
                ->filter()
                ->unique()
                ->take($limit)
                ->values()
                ->all();

            if ($filters[$key] === []) {
                unset($filters[$key]);
            }
        }

        if (isset($filters['payment_statuses'])) {
            $filters['payment_statuses'] = array_values(array_intersect(
                $filters['payment_statuses'],
                self::PAYMENT_STATUSES
            ));
            if ($filters['payment_statuses'] === []) {
                unset($filters['payment_statuses']);
            }
        }

        foreach (self::DATE_FIELDS as $field) {
            if (! isset($filters[$field])) {
                continue;
            }

            $date = $this->validDate($filters[$field]);
            if ($date === null) {
                unset($filters[$field]);
            } else {
                $filters[$field] = $date;
            }
        }

        foreach (self::INTEGER_FIELDS as $field) {
            if (! isset($filters[$field])) {
                continue;
            }

            $value = filter_var($filters[$field], FILTER_VALIDATE_INT);
            if ($value === false || $value < 0 || $value > 1_000_000) {
                unset($filters[$field]);
            } else {
                $filters[$field] = $value;
            }
        }

        foreach (self::MONEY_FIELDS as $field) {
            if (! isset($filters[$field]) || ! is_numeric($filters[$field])) {
                unset($filters[$field]);

                continue;
            }

            $value = (float) $filters[$field];
            if (! is_finite($value) || $value < 0 || $value > 1_000_000_000_000) {
                unset($filters[$field]);
            } else {
                $filters[$field] = $value;
            }
        }

        $this->normalizeRange($filters, 'appointments_min', 'appointments_max');
        $this->normalizeRange($filters, 'outstanding_min', 'outstanding_max');
        $this->normalizeRange($filters, 'total_invoiced_min', 'total_invoiced_max');
        foreach ([
            ['created_from', 'created_to'],
            ['last_appointment_from', 'last_appointment_to'],
            ['next_appointment_from', 'next_appointment_to'],
            ['last_invoice_from', 'last_invoice_to'],
        ] as [$from, $to]) {
            if (isset($filters[$from], $filters[$to]) && $filters[$from] > $filters[$to]) {
                [$filters[$from], $filters[$to]] = [$filters[$to], $filters[$from]];
            }
        }

        foreach ([
            'has_quotes',
            'has_works',
            'is_vip',
            'has_upcoming_appointment',
            'has_outstanding_balance',
            'has_active_package',
            'package_is_recurring',
        ] as $key) {
            if (! array_key_exists($key, $filters)) {
                continue;
            }

            $boolean = $this->booleanValue($filters[$key]);
            if ($boolean === null) {
                unset($filters[$key]);
            } else {
                $filters[$key] = $boolean ? '1' : '0';
            }
        }

        if (! in_array($filters['package_status'] ?? null, CustomerPackage::statuses(), true)) {
            unset($filters['package_status']);
        }
        if (! in_array($filters['package_recurrence_status'] ?? null, [
            CustomerPackage::RECURRENCE_ACTIVE,
            CustomerPackage::RECURRENCE_PAYMENT_DUE,
            CustomerPackage::RECURRENCE_SUSPENDED,
            CustomerPackage::RECURRENCE_CANCELLED,
        ], true)) {
            unset($filters['package_recurrence_status']);
        }

        $capabilities = (array) ($context['capabilities'] ?? []);
        if (! ($capabilities['reservations'] ?? false)) {
            $filters = Arr::except($filters, self::RESERVATION_FIELDS);
        }
        if (! ($capabilities['invoices'] ?? false)) {
            $filters = Arr::except($filters, self::FINANCIAL_FIELDS);
        }
        if (! ($capabilities['packages'] ?? false)) {
            $filters = Arr::except($filters, self::PACKAGE_FIELDS);
        }
        if (! ($capabilities['campaigns'] ?? false)) {
            unset($filters['is_vip'], $filters['vip_tier_id']);
        } elseif (isset($filters['vip_tier_id'])) {
            $tierId = filter_var($filters['vip_tier_id'], FILTER_VALIDATE_INT);
            $tierId = $tierId === false ? 0 : $tierId;
            if (! VipTier::query()->forAccount($accountId)->whereKey($tierId)->exists()) {
                unset($filters['vip_tier_id']);
            } else {
                $filters['vip_tier_id'] = $tierId;
            }
        }

        $availableQuickFilters = $this->availableQuickFilters($context);
        $quickFilters = collect(Arr::wrap($filters['quick_filters'] ?? []))
            ->filter(static fn (mixed $filter): bool => is_scalar($filter))
            ->map(static fn (mixed $filter): string => trim((string) $filter))
            ->map(fn (string $filter): string => self::LEGACY_TO_QUICK[$filter] ?? $filter)
            ->filter(static fn (string $filter): bool => in_array($filter, $availableQuickFilters, true))
            ->take(20);

        $legacyFilter = $this->stringValue($filters['operational_filter'] ?? null);
        $legacyAvailable = ($context['profile'] ?? null) === 'appointment'
            && in_array($legacyFilter, $context['operational_filters'] ?? [], true);
        if ($legacyAvailable && ! $hasCanonicalQuickFilterInput) {
            $quickFilters->push(self::LEGACY_TO_QUICK[$legacyFilter] ?? $legacyFilter);
            $filters['operational_filter'] = $legacyFilter;
        } else {
            unset($filters['operational_filter']);
        }

        $quickFilters = $quickFilters->unique()->values()->all();
        if ($quickFilters === []) {
            unset($filters['quick_filters']);
        } else {
            $filters['quick_filters'] = $quickFilters;
        }
        $filters['quick_filter_mode'] = ($filters['quick_filter_mode'] ?? null) === 'any' ? 'any' : 'all';

        if (! in_array($filters['sort'] ?? null, [
            'company_name',
            'first_name',
            'created_at',
            'quotes_count',
            'works_count',
        ], true)) {
            unset($filters['sort']);
        }
        if (! in_array($filters['direction'] ?? null, ['asc', 'desc'], true)) {
            unset($filters['direction']);
        }
        if (isset($filters['per_page'])) {
            $perPage = filter_var($filters['per_page'], FILTER_VALIDATE_INT);
            if ($perPage === false || $perPage < 1 || $perPage > 1_000) {
                unset($filters['per_page']);
            } else {
                $filters['per_page'] = $perPage;
            }
        }

        return $filters;
    }

    /**
     * Retain the filters already implemented by Customer::scopeFilter().
     * New date and capability-sensitive filters are applied below so their
     * account timezone and tenant scopes remain explicit.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function modelFilters(array $filters): array
    {
        return Arr::only($filters, [
            'name',
            'city',
            'country',
            'has_quotes',
            'has_works',
            'status',
            ...self::PACKAGE_FIELDS,
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $context
     */
    public function apply(
        Builder $query,
        array $filters,
        User $accountOwner,
        array $context,
        ?int $accountId = null
    ): Builder {
        $accountId ??= (int) $accountOwner->id;
        $this->applyAdvancedFilters($query, $filters, $accountOwner, $context, $accountId);
        $this->applyQuickFilters($query, $filters, $accountOwner, $context, $accountId);

        return $query;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, string>
     */
    public function availableQuickFilters(array $context): array
    {
        $capabilities = (array) ($context['capabilities'] ?? []);

        return array_values(array_filter([
            ($capabilities['campaigns'] ?? false) ? 'vip' : null,
            'new',
            'new_this_month',
            ($capabilities['reservations'] ?? false) ? 'no_next_appointment' : null,
            ($capabilities['reservations'] ?? false) ? 'upcoming_appointment' : null,
            ($capabilities['invoices'] ?? false) ? 'outstanding_balance' : null,
            'inactive',
            ($capabilities['reservations'] ?? false) ? 'follow_up_90' : null,
            ($capabilities['packages'] ?? false) ? 'package_low' : null,
            ($capabilities['birthdays'] ?? false) ? 'birthday_upcoming' : null,
        ]));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array{key:string,field:string,value:mixed,type:string}>
     */
    public function activeFilters(array $filters): array
    {
        $active = [];
        foreach ($filters['quick_filters'] ?? [] as $value) {
            $active[] = [
                'key' => 'quick_filters:'.$value,
                'field' => 'quick_filters',
                'value' => $value,
                'type' => 'quick',
            ];
        }

        foreach (Arr::except($filters, [
            'quick_filters',
            'quick_filter_mode',
            'operational_filter',
            'sort',
            'direction',
            'per_page',
        ]) as $field => $value) {
            foreach (Arr::wrap($value) as $item) {
                $active[] = [
                    'key' => $field.':'.(is_bool($item) ? (int) $item : (string) $item),
                    'field' => $field,
                    'value' => $item,
                    'type' => $field === 'name' ? 'search' : 'advanced',
                ];
            }
        }

        return $active;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function options(User $accountOwner, array $context, ?int $accountId = null): array
    {
        $accountId ??= (int) $accountOwner->id;
        $capabilities = (array) ($context['capabilities'] ?? []);
        $options = [
            'client_types' => CustomerClientType::values(),
            'statuses' => ['active', 'archived'],
            'acquisition_sources' => Customer::query()
                ->byUser($accountId)
                ->whereNotNull('refer_by')
                ->where('refer_by', '!=', '')
                ->distinct()
                ->orderBy('refer_by')
                ->limit(100)
                ->pluck('refer_by')
                ->values()
                ->all(),
            'tags' => Customer::query()
                ->byUser($accountId)
                ->whereNotNull('tags')
                ->limit(1000)
                ->get(['tags'])
                ->flatMap(static fn (Customer $customer): array => $customer->tags ?? [])
                ->map(static fn (mixed $tag): string => trim((string) $tag))
                ->filter()
                ->unique()
                ->sort()
                ->take(100)
                ->values()
                ->all(),
        ];

        if ($capabilities['campaigns'] ?? false) {
            $options['vip_tiers'] = VipTier::query()
                ->forAccount($accountId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'code'])
                ->map(static fn (VipTier $tier): array => [
                    'id' => (int) $tier->id,
                    'name' => (string) $tier->name,
                    'code' => (string) $tier->code,
                ])
                ->all();
        }

        if ($capabilities['invoices'] ?? false) {
            $options['payment_statuses'] = self::PAYMENT_STATUSES;
        }

        return $options;
    }

    public function outstandingBalanceQuery(User $accountOwner, ?int $accountId = null): QueryBuilder
    {
        $accountId ??= (int) $accountOwner->id;
        $currencyCode = $accountOwner->businessCurrencyCode();
        $settledPayments = DB::table('payments')
            ->select('invoice_id')
            ->selectRaw('COALESCE(SUM(amount), 0) as settled_amount')
            ->where('user_id', $accountId)
            ->where('currency_code', $currencyCode)
            ->whereIn('status', Payment::settledStatuses())
            ->whereNotNull('invoice_id')
            ->groupBy('invoice_id');

        return DB::table('invoices')
            ->join('customers', 'customers.id', '=', 'invoices.customer_id')
            ->leftJoinSub($settledPayments, 'settled_payments', function ($join): void {
                $join->on('settled_payments.invoice_id', '=', 'invoices.id');
            })
            ->where('invoices.user_id', $accountId)
            ->where('customers.user_id', $accountId)
            ->where('invoices.currency_code', $currencyCode)
            ->whereIn('invoices.status', self::OPEN_INVOICE_STATUSES)
            ->whereNull('invoices.deleted_at')
            ->groupBy('invoices.customer_id')
            ->select('invoices.customer_id')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN invoices.total > COALESCE(settled_payments.settled_amount, 0)'.
                ' THEN invoices.total - COALESCE(settled_payments.settled_amount, 0) ELSE 0 END), 0)'.
                ' as outstanding_balance'
            );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $context
     */
    private function applyAdvancedFilters(
        Builder $query,
        array $filters,
        User $accountOwner,
        array $context,
        int $accountId
    ): void {
        $currencyCode = $accountOwner->businessCurrencyCode();
        $capabilities = (array) ($context['capabilities'] ?? []);

        if (isset($filters['client_type'])) {
            $query->where('client_type', $filters['client_type']);
        }
        if (isset($filters['is_vip']) && ($capabilities['campaigns'] ?? false)) {
            $query->where('is_vip', $this->booleanValue($filters['is_vip']));
        }
        if (isset($filters['vip_tier_id']) && ($capabilities['campaigns'] ?? false)) {
            $query->where('vip_tier_id', (int) $filters['vip_tier_id']);
        }
        if (isset($filters['acquisition_source'])) {
            $query->where('refer_by', $filters['acquisition_source']);
        }
        foreach ($filters['tags'] ?? [] as $tag) {
            $query->whereJsonContains('tags', $tag);
        }

        if (isset($filters['created_from'])) {
            $query->where('customers.created_at', '>=', $this->startOfDate($filters['created_from'], $accountOwner));
        }
        if (isset($filters['created_to'])) {
            $query->where('customers.created_at', '<', $this->endExclusive($filters['created_to'], $accountOwner));
        }

        if ($capabilities['reservations'] ?? false) {
            $this->applyReservationFilters($query, $filters, $accountOwner, $accountId);
        }
        if ($capabilities['invoices'] ?? false) {
            $this->applyFinancialFilters($query, $filters, $accountOwner, $currencyCode, $accountId);
        }
    }

    /** @param array<string, mixed> $filters */
    private function applyReservationFilters(
        Builder $query,
        array $filters,
        User $accountOwner,
        int $accountId
    ): void {
        $now = now();

        if (isset($filters['has_upcoming_appointment'])) {
            $method = $this->booleanValue($filters['has_upcoming_appointment']) ? 'whereHas' : 'whereDoesntHave';
            $query->{$method}('reservations', fn (Builder $reservationQuery): Builder => $reservationQuery
                ->reorder()
                ->where('account_id', $accountId)
                ->whereIn('status', Reservation::ACTIVE_STATUSES)
                ->where('starts_at', '>=', $now));
        }

        if (isset($filters['last_appointment_from'])) {
            $from = $this->startOfDate($filters['last_appointment_from'], $accountOwner);
            $query->whereHas('reservations', fn (Builder $reservationQuery): Builder => $reservationQuery
                ->reorder()
                ->where('account_id', $accountId)
                ->where('status', Reservation::STATUS_COMPLETED)
                ->where('starts_at', '>=', $from));
        }
        if (isset($filters['last_appointment_to'])) {
            $to = $this->endExclusive($filters['last_appointment_to'], $accountOwner);
            $query
                ->whereHas('reservations', fn (Builder $reservationQuery): Builder => $reservationQuery
                    ->reorder()
                    ->where('account_id', $accountId)
                    ->where('status', Reservation::STATUS_COMPLETED))
                ->whereDoesntHave('reservations', fn (Builder $reservationQuery): Builder => $reservationQuery
                    ->reorder()
                    ->where('account_id', $accountId)
                    ->where('status', Reservation::STATUS_COMPLETED)
                    ->where('starts_at', '>=', $to));
        }

        if (isset($filters['next_appointment_from'])) {
            $from = $this->startOfDate($filters['next_appointment_from'], $accountOwner);
            if ($from->lt($now)) {
                $from = CarbonImmutable::instance($now);
            }
            $query
                ->whereHas('reservations', fn (Builder $reservationQuery): Builder => $reservationQuery
                    ->reorder()
                    ->where('account_id', $accountId)
                    ->whereIn('status', Reservation::ACTIVE_STATUSES)
                    ->where('starts_at', '>=', $from))
                ->whereDoesntHave('reservations', fn (Builder $reservationQuery): Builder => $reservationQuery
                    ->reorder()
                    ->where('account_id', $accountId)
                    ->whereIn('status', Reservation::ACTIVE_STATUSES)
                    ->where('starts_at', '>=', $now)
                    ->where('starts_at', '<', $from));
        }
        if (isset($filters['next_appointment_to'])) {
            $to = $this->endExclusive($filters['next_appointment_to'], $accountOwner);
            $query->whereHas('reservations', fn (Builder $reservationQuery): Builder => $reservationQuery
                ->reorder()
                ->where('account_id', $accountId)
                ->whereIn('status', Reservation::ACTIVE_STATUSES)
                ->where('starts_at', '>=', $now)
                ->where('starts_at', '<', $to));
        }

        foreach ([
            'appointments_min' => [Reservation::STATUS_COMPLETED, '>='],
            'appointments_max' => [Reservation::STATUS_COMPLETED, '<='],
            'cancellations_min' => [Reservation::STATUS_CANCELLED, '>='],
            'no_shows_min' => [Reservation::STATUS_NO_SHOW, '>='],
        ] as $field => [$status, $operator]) {
            if (! isset($filters[$field])) {
                continue;
            }
            $count = (int) $filters[$field];
            if ($operator === '>=' && $count === 0) {
                continue;
            }

            $query->whereHas(
                'reservations',
                fn (Builder $reservationQuery): Builder => $reservationQuery
                    ->reorder()
                    ->where('account_id', $accountId)
                    ->where('status', $status),
                $operator,
                $count
            );
        }
    }

    /** @param array<string, mixed> $filters */
    private function applyFinancialFilters(
        Builder $query,
        array $filters,
        User $accountOwner,
        string $currencyCode,
        int $accountId
    ): void {
        $balances = $this->outstandingBalanceQuery($accountOwner, $accountId);

        if (isset($filters['has_outstanding_balance'])) {
            $ids = DB::query()
                ->fromSub(clone $balances, 'customer_balances')
                ->select('customer_id')
                ->where('outstanding_balance', '>', 0);
            $this->booleanValue($filters['has_outstanding_balance'])
                ? $query->whereIn('customers.id', $ids)
                : $query->whereNotIn('customers.id', $ids);
        }
        if (isset($filters['outstanding_min']) && (float) $filters['outstanding_min'] > 0) {
            $query->whereIn('customers.id', DB::query()
                ->fromSub(clone $balances, 'customer_balances')
                ->select('customer_id')
                ->where('outstanding_balance', '>=', (float) $filters['outstanding_min']));
        }
        if (isset($filters['outstanding_max'])) {
            $query->whereNotIn('customers.id', DB::query()
                ->fromSub(clone $balances, 'customer_balances')
                ->select('customer_id')
                ->where('outstanding_balance', '>', (float) $filters['outstanding_max']));
        }

        $invoiceTotals = DB::table('invoices')
            ->join('customers as invoice_customers', 'invoice_customers.id', '=', 'invoices.customer_id')
            ->where('invoices.user_id', $accountId)
            ->where('invoice_customers.user_id', $accountId)
            ->where('invoices.currency_code', $currencyCode)
            ->where('invoices.status', '!=', 'void')
            ->whereNull('invoices.deleted_at')
            ->groupBy('invoices.customer_id')
            ->select('invoices.customer_id')
            ->selectRaw('COALESCE(SUM(invoices.total), 0) as total_invoiced');

        if (isset($filters['total_invoiced_min']) && (float) $filters['total_invoiced_min'] > 0) {
            $query->whereIn('customers.id', DB::query()
                ->fromSub(clone $invoiceTotals, 'customer_invoice_totals')
                ->select('customer_id')
                ->where('total_invoiced', '>=', (float) $filters['total_invoiced_min']));
        }
        if (isset($filters['total_invoiced_max'])) {
            $query->whereNotIn('customers.id', DB::query()
                ->fromSub(clone $invoiceTotals, 'customer_invoice_totals')
                ->select('customer_id')
                ->where('total_invoiced', '>', (float) $filters['total_invoiced_max']));
        }

        if (isset($filters['last_invoice_from'])) {
            $from = $this->startOfDate($filters['last_invoice_from'], $accountOwner);
            $query->whereHas('invoices', fn (Builder $invoiceQuery): Builder => $invoiceQuery
                ->reorder()
                ->where('user_id', $accountId)
                ->where('currency_code', $currencyCode)
                ->whereNull('deleted_at')
                ->where('created_at', '>=', $from));
        }
        if (isset($filters['last_invoice_to'])) {
            $to = $this->endExclusive($filters['last_invoice_to'], $accountOwner);
            $query
                ->whereHas('invoices', fn (Builder $invoiceQuery): Builder => $invoiceQuery
                    ->reorder()
                    ->where('user_id', $accountId)
                    ->where('currency_code', $currencyCode)
                    ->whereNull('deleted_at'))
                ->whereDoesntHave('invoices', fn (Builder $invoiceQuery): Builder => $invoiceQuery
                    ->reorder()
                    ->where('user_id', $accountId)
                    ->where('currency_code', $currencyCode)
                    ->whereNull('deleted_at')
                    ->where('created_at', '>=', $to));
        }
        if (($filters['payment_statuses'] ?? []) !== []) {
            $query->whereExists(function (QueryBuilder $paymentQuery) use ($accountId, $currencyCode, $filters): void {
                $paymentQuery
                    ->selectRaw('1')
                    ->from('payments')
                    ->whereColumn('payments.customer_id', 'customers.id')
                    ->where('payments.user_id', $accountId)
                    ->where('payments.currency_code', $currencyCode)
                    ->whereIn('payments.status', $filters['payment_statuses']);
            });
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $context
     */
    private function applyQuickFilters(
        Builder $query,
        array $filters,
        User $accountOwner,
        array $context,
        int $accountId
    ): void {
        $quickFilters = $filters['quick_filters'] ?? [];
        if ($quickFilters === []) {
            return;
        }

        if (($filters['quick_filter_mode'] ?? 'all') === 'any') {
            $query->where(function (Builder $group) use ($quickFilters, $accountOwner, $context, $accountId): void {
                foreach ($quickFilters as $filter) {
                    $group->orWhere(function (Builder $branch) use ($filter, $accountOwner, $context, $accountId): void {
                        $this->applyQuickFilterPredicate($branch, $filter, $accountOwner, $context, $accountId);
                    });
                }
            });

            return;
        }

        foreach ($quickFilters as $filter) {
            $query->where(function (Builder $branch) use ($filter, $accountOwner, $context, $accountId): void {
                $this->applyQuickFilterPredicate($branch, $filter, $accountOwner, $context, $accountId);
            });
        }
    }

    /** @param array<string, mixed> $context */
    private function applyQuickFilterPredicate(
        Builder $query,
        string $filter,
        User $accountOwner,
        array $context,
        int $accountId
    ): void {
        $timezone = $this->timezone($accountOwner);
        $now = now();

        match ($filter) {
            'vip' => $query->where('is_vip', true),
            'new' => $query->where('created_at', '>=', now()->subDays(30)),
            'new_this_month' => $query->where(
                'created_at',
                '>=',
                CarbonImmutable::now($timezone)->startOfMonth()->setTimezone(config('app.timezone'))
            )->where('created_at', '<=', $now),
            'inactive' => $query->where('is_active', false),
            'no_next_appointment' => $query->whereDoesntHave(
                'reservations',
                fn (Builder $reservationQuery): Builder => $reservationQuery
                    ->reorder()
                    ->where('account_id', $accountId)
                    ->whereIn('status', Reservation::ACTIVE_STATUSES)
                    ->where('starts_at', '>=', $now)
            )->where('is_active', true),
            'upcoming_appointment' => $query->whereHas(
                'reservations',
                fn (Builder $reservationQuery): Builder => $reservationQuery
                    ->reorder()
                    ->where('account_id', $accountId)
                    ->whereIn('status', Reservation::ACTIVE_STATUSES)
                    ->where('starts_at', '>=', $now)
            ),
            'follow_up_90' => $query
                ->where('is_active', true)
                ->whereHas('reservations', fn (Builder $reservationQuery): Builder => $reservationQuery
                    ->reorder()
                    ->where('account_id', $accountId)
                    ->where('status', Reservation::STATUS_COMPLETED))
                ->whereDoesntHave('reservations', fn (Builder $reservationQuery): Builder => $reservationQuery
                    ->reorder()
                    ->where('account_id', $accountId)
                    ->where('status', Reservation::STATUS_COMPLETED)
                    ->where('starts_at', '>=', now()->subDays(90)))
                ->whereDoesntHave('reservations', fn (Builder $reservationQuery): Builder => $reservationQuery
                    ->reorder()
                    ->where('account_id', $accountId)
                    ->whereIn('status', Reservation::ACTIVE_STATUSES)
                    ->where('starts_at', '>=', $now)),
            'package_low' => $query
                ->where('is_active', true)
                ->whereHas('customerPackages', fn (Builder $packageQuery): Builder => $packageQuery
                    ->reorder()
                    ->where('user_id', $accountId)
                    ->where('status', CustomerPackage::STATUS_ACTIVE)
                    ->where('remaining_quantity', '<=', 2)),
            'outstanding_balance' => $query->whereIn(
                'customers.id',
                DB::query()
                    ->fromSub($this->outstandingBalanceQuery($accountOwner, $accountId), 'customer_balances')
                    ->select('customer_id')
                    ->where('outstanding_balance', '>', 0)
            ),
            'birthday_upcoming' => $this->applyUpcomingBirthday($query, $accountOwner),
            default => $query,
        };
    }

    private function applyUpcomingBirthday(Builder $query, User $accountOwner): Builder
    {
        $start = CarbonImmutable::now($this->timezone($accountOwner))->startOfDay();
        $dates = collect(range(0, 30))
            ->map(static fn (int $offset): array => [
                'month' => $start->addDays($offset)->month,
                'day' => $start->addDays($offset)->day,
            ])
            ->unique(static fn (array $date): string => $date['month'].'-'.$date['day']);

        return $query
            ->where('is_active', true)
            ->whereNotNull('birth_date')
            ->where(function (Builder $birthdayQuery) use ($dates): void {
                foreach ($dates as $date) {
                    $birthdayQuery->orWhere(function (Builder $dateQuery) use ($date): void {
                        $dateQuery
                            ->whereMonth('birth_date', $date['month'])
                            ->whereDay('birth_date', $date['day']);
                    });
                }
            });
    }

    private function startOfDate(string $date, User $accountOwner): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date, $this->timezone($accountOwner))
            ->startOfDay()
            ->setTimezone(config('app.timezone'));
    }

    private function endExclusive(string $date, User $accountOwner): CarbonImmutable
    {
        return $this->startOfDate($date, $accountOwner)->addDay();
    }

    private function timezone(User $accountOwner): string
    {
        return $accountOwner->company_timezone ?: (string) config('app.timezone', 'UTC');
    }

    private function booleanValue(mixed $value): ?bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    /** @param array<string, mixed> $filters */
    private function normalizeRange(array &$filters, string $minimum, string $maximum): void
    {
        if (isset($filters[$minimum], $filters[$maximum]) && $filters[$minimum] > $filters[$maximum]) {
            [$filters[$minimum], $filters[$maximum]] = [$filters[$maximum], $filters[$minimum]];
        }
    }

    private function validDate(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
        } catch (\Throwable) {
            return null;
        }

        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
