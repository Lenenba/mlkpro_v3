<?php

namespace App\Queries\Customers;

use App\Models\Customer;
use App\Models\CustomerPackage;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\CompanyFeatureService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class BuildCustomerOperationalIndexData
{
    private const PROFILE_APPOINTMENT = 'appointment';

    private const PROFILE_GENERIC = 'generic';

    private const NEW_CUSTOMER_DAYS = 30;

    private const FOLLOW_UP_DAYS = 90;

    private const UPCOMING_BIRTHDAY_DAYS = 30;

    private const LOW_PACKAGE_REMAINING_QUANTITY = 2;

    private const OPEN_INVOICE_STATUSES = [
        'sent',
        'awaiting_acceptance',
        'accepted',
        'partial',
        'overdue',
    ];

    public function __construct(
        private readonly CompanyFeatureService $featureService
    ) {}

    /**
     * Build an explicit UI contract. The packages capability mirrors the
     * catalog-offer navigation: any products, services, or sales workspace can
     * expose packages even though packages do not yet have a standalone flag.
     *
     * @return array{
     *     profile:string,
     *     sector:string,
     *     capabilities:array<string, bool>,
     *     operational_filters:array<int, string>
     * }
     */
    public function context(User $accountOwner): array
    {
        $sector = strtolower(trim((string) $accountOwner->company_sector));
        $companyType = strtolower(trim((string) $accountOwner->company_type));
        $reservations = $this->featureService->hasFeature($accountOwner, 'reservations');
        $profile = $companyType === 'services'
            && $reservations
            && in_array($sector, ['salon', 'wellness'], true)
            ? self::PROFILE_APPOINTMENT
            : self::PROFILE_GENERIC;

        $products = $this->featureService->hasFeature($accountOwner, 'products');
        $services = $this->featureService->hasFeature($accountOwner, 'services');
        $sales = $this->featureService->hasFeature($accountOwner, 'sales');

        $capabilities = [
            'reservations' => $reservations,
            'team_members' => $this->featureService->hasFeature($accountOwner, 'team_members'),
            'loyalty' => $this->featureService->hasFeature($accountOwner, 'loyalty'),
            'invoices' => $this->featureService->hasFeature($accountOwner, 'invoices'),
            'sales' => $sales,
            'campaigns' => $this->featureService->hasFeature($accountOwner, 'campaigns'),
            'packages' => $products || $services || $sales,
            // Birth dates are customer profile data, but the operational
            // birthday workflow is intentionally limited to appointment views.
            'birthdays' => $profile === self::PROFILE_APPOINTMENT,
        ];

        return [
            'profile' => $profile,
            'sector' => $sector,
            'capabilities' => $capabilities,
            'operational_filters' => $this->availableOperationalFilters($profile, $capabilities),
        ];
    }

    public function normalizeOperationalFilter(mixed $filter, array $context): ?string
    {
        $filter = trim((string) $filter);
        if ($filter === '' || ($context['profile'] ?? null) !== self::PROFILE_APPOINTMENT) {
            return null;
        }

        return in_array($filter, $context['operational_filters'] ?? [], true)
            ? $filter
            : null;
    }

    /**
     * Apply actor-level permissions without ever enabling a module capability
     * that is disabled for the tenant.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, bool>  $permissions
     * @return array<string, mixed>
     */
    public function restrictCapabilities(array $context, array $permissions): array
    {
        foreach ($permissions as $capability => $allowed) {
            if (! array_key_exists($capability, $context['capabilities'] ?? [])) {
                continue;
            }

            $context['capabilities'][$capability] = (bool) $context['capabilities'][$capability]
                && $allowed;
        }

        $context['operational_filters'] = $this->availableOperationalFilters(
            (string) ($context['profile'] ?? self::PROFILE_GENERIC),
            (array) ($context['capabilities'] ?? [])
        );

        return $context;
    }

    public function applyOperationalFilter(
        Builder $query,
        ?string $filter,
        User $accountOwner,
        array $context
    ): Builder {
        if ($filter === null || ($context['profile'] ?? null) !== self::PROFILE_APPOINTMENT) {
            return $query;
        }

        $accountId = (int) $accountOwner->id;
        $currencyCode = $accountOwner->businessCurrencyCode();
        $birthdayWindowStart = now(
            $accountOwner->company_timezone ?: config('app.timezone')
        )->startOfDay();

        return match ($filter) {
            'vip' => $query->where('is_vip', true),
            'new' => $query
                ->where('is_active', true)
                ->where('created_at', '>=', now()->subDays(self::NEW_CUSTOMER_DAYS)),
            'no_next_appointment' => $query
                ->where('is_active', true)
                ->whereDoesntHave('reservations', fn (Builder $reservationQuery): Builder => $reservationQuery
                    ->reorder()
                    ->where('account_id', $accountId)
                    ->whereIn('status', Reservation::ACTIVE_STATUSES)
                    ->where('starts_at', '>=', now())),
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
                    ->where('starts_at', '>=', now()->subDays(self::FOLLOW_UP_DAYS)))
                ->whereDoesntHave('reservations', fn (Builder $reservationQuery): Builder => $reservationQuery
                    ->reorder()
                    ->where('account_id', $accountId)
                    ->whereIn('status', Reservation::ACTIVE_STATUSES)
                    ->where('starts_at', '>=', now())),
            'package_low' => $query
                ->where('is_active', true)
                ->whereHas('customerPackages', fn (Builder $packageQuery): Builder => $packageQuery
                    ->reorder()
                    ->where('user_id', $accountId)
                    ->where('status', CustomerPackage::STATUS_ACTIVE)
                    ->where('remaining_quantity', '<=', self::LOW_PACKAGE_REMAINING_QUANTITY)),
            'unpaid' => $query->whereHas('invoices', function (Builder $invoiceQuery) use ($accountId, $currencyCode): void {
                $settledStatuses = Payment::settledStatuses();
                $placeholders = implode(', ', array_fill(0, count($settledStatuses), '?'));

                $invoiceQuery
                    ->reorder()
                    ->where('invoices.user_id', $accountId)
                    ->where('invoices.currency_code', $currencyCode)
                    ->whereIn('invoices.status', self::OPEN_INVOICE_STATUSES)
                    ->whereRaw(
                        "invoices.total > COALESCE((SELECT SUM(payments.amount) FROM payments WHERE payments.invoice_id = invoices.id AND payments.user_id = ? AND payments.currency_code = ? AND payments.status IN ({$placeholders})), 0)",
                        [$accountId, $currencyCode, ...$settledStatuses]
                    );
            }),
            'birthday_upcoming' => $this->applyUpcomingBirthdayFilter($query, $birthdayWindowStart),
            default => $query,
        };
    }

    /**
     * @param  Collection<int, Customer>  $customers
     */
    public function appendOperationalSummaries(
        Collection $customers,
        User $accountOwner,
        array $context
    ): void {
        if ($customers->isEmpty()) {
            return;
        }

        $currencyCode = $accountOwner->businessCurrencyCode();
        $profileIsAppointment = ($context['profile'] ?? null) === self::PROFILE_APPOINTMENT;
        $capabilities = (array) ($context['capabilities'] ?? []);

        if (! $profileIsAppointment) {
            $customers->each(function (Customer $customer) use ($currencyCode): void {
                $customer->setAttribute('operational_summary', $this->emptySummary(
                    $customer,
                    $currencyCode
                ));
            });

            return;
        }

        $customerIds = $customers->modelKeys();
        $accountId = (int) $accountOwner->id;
        $lastVisits = collect();
        $nextAppointments = collect();
        $usualMemberRows = collect();

        if ($capabilities['reservations'] ?? false) {
            $lastVisits = $this->lastVisits($customerIds, $accountId);
            $nextAppointments = $this->nextAppointments($customerIds, $accountId);

            if ($capabilities['team_members'] ?? false) {
                $usualMemberRows = $this->usualTeamMembers($customerIds, $accountId);
            }
        }

        $serviceNames = Product::query()
            ->where('user_id', $accountId)
            ->whereIn('id', $lastVisits->pluck('service_id')->filter()->unique()->values())
            ->pluck('name', 'id');

        $teamMemberIds = $nextAppointments
            ->pluck('team_member_id')
            ->merge($usualMemberRows->pluck('team_member_id'))
            ->filter()
            ->unique()
            ->values();
        $teamMembers = ($capabilities['team_members'] ?? false)
            ? TeamMember::query()
                ->forAccount($accountId)
                ->whereIn('id', $teamMemberIds)
                ->with('user:id,name')
                ->get(['id', 'user_id', 'title'])
                ->keyBy('id')
            : collect();

        $activePackages = ($capabilities['packages'] ?? false)
            ? $this->activePackages($customerIds, $accountId)
            : collect();
        $paymentSummaries = ($capabilities['invoices'] ?? false) || ($capabilities['sales'] ?? false)
            ? $this->paymentSummaries($customerIds, $accountId, $currencyCode, $capabilities)
            : collect();
        $invoiceSummaries = ($capabilities['invoices'] ?? false)
            ? $this->invoiceSummaries($customerIds, $accountId, $currencyCode)
            : collect();

        $now = now();
        $customers->each(function (Customer $customer) use (
            $activePackages,
            $capabilities,
            $currencyCode,
            $invoiceSummaries,
            $lastVisits,
            $nextAppointments,
            $now,
            $paymentSummaries,
            $serviceNames,
            $teamMembers,
            $usualMemberRows
        ): void {
            $customerId = (int) $customer->id;
            $lastVisit = $lastVisits->get($customerId);
            $nextAppointment = $nextAppointments->get($customerId);
            $usualMemberRow = $usualMemberRows->get($customerId);
            $usualMember = $usualMemberRow
                ? $teamMembers->get((int) $usualMemberRow->team_member_id)
                : null;
            $nextMember = $nextAppointment
                ? $teamMembers->get((int) $nextAppointment->team_member_id)
                : null;
            $activePackage = $activePackages->get($customerId);
            $paymentSummary = $paymentSummaries->get($customerId);
            $invoiceSummary = $invoiceSummaries->get($customerId);

            $customer->setAttribute('operational_summary', [
                'lifecycle_status' => $this->lifecycleStatus($customer, $lastVisit, $nextAppointment, $now),
                'last_visit' => $lastVisit ? [
                    'starts_at' => $lastVisit->starts_at,
                    'service_name' => $serviceNames->get((int) $lastVisit->service_id),
                ] : null,
                'next_appointment' => $nextAppointment ? [
                    'starts_at' => $nextAppointment->starts_at,
                    'team_member_name' => $this->teamMemberName($nextMember),
                ] : null,
                'usual_team_member' => $usualMember ? [
                    'id' => (int) $usualMember->id,
                    'name' => $this->teamMemberName($usualMember),
                ] : null,
                'loyalty_points' => ($capabilities['loyalty'] ?? false)
                    ? (int) ($customer->loyalty_points_balance ?? 0)
                    : null,
                'active_package' => $activePackage ? [
                    'name' => data_get($activePackage->source_details, 'offer_package.name')
                        ?: $activePackage->offerPackage?->name
                        ?: 'Forfait',
                    'remaining_quantity' => (int) $activePackage->remaining_quantity,
                ] : null,
                'total_spent' => ($capabilities['invoices'] ?? false) || ($capabilities['sales'] ?? false)
                    ? round((float) ($paymentSummary?->total_spent ?? 0), 2)
                    : null,
                'tip_total' => ($capabilities['invoices'] ?? false) || ($capabilities['sales'] ?? false)
                    ? round((float) ($paymentSummary?->tip_total ?? 0), 2)
                    : null,
                'unpaid_balance' => ($capabilities['invoices'] ?? false)
                    ? round((float) ($invoiceSummary['balance'] ?? 0), 2)
                    : null,
                'unpaid_invoice_id' => ($capabilities['invoices'] ?? false)
                    ? ($invoiceSummary['invoice_id'] ?? null)
                    : null,
                'currency_code' => $currencyCode,
            ]);
        });
    }

    /**
     * @param  array<string, bool>  $capabilities
     * @return array<int, string>
     */
    private function availableOperationalFilters(string $profile, array $capabilities): array
    {
        if ($profile !== self::PROFILE_APPOINTMENT) {
            return [];
        }

        return array_values(array_filter([
            ($capabilities['campaigns'] ?? false) ? 'vip' : null,
            'new',
            ($capabilities['reservations'] ?? false) ? 'no_next_appointment' : null,
            ($capabilities['reservations'] ?? false) ? 'follow_up_90' : null,
            ($capabilities['packages'] ?? false) ? 'package_low' : null,
            ($capabilities['invoices'] ?? false) ? 'unpaid' : null,
            ($capabilities['birthdays'] ?? false) ? 'birthday_upcoming' : null,
        ]));
    }

    private function applyUpcomingBirthdayFilter(
        Builder $query,
        CarbonInterface $windowStart
    ): Builder {
        $dates = collect(range(0, self::UPCOMING_BIRTHDAY_DAYS))
            ->map(function (int $offset) use ($windowStart): array {
                $date = $windowStart->copy()->addDays($offset);

                return [
                    'month' => (int) $date->month,
                    'day' => (int) $date->day,
                ];
            })
            ->unique(fn (array $date): string => $date['month'].'-'.$date['day'])
            ->values();

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

    /**
     * @param  array<int, int>  $customerIds
     * @return Collection<int, Reservation>
     */
    private function lastVisits(array $customerIds, int $accountId): Collection
    {
        return Reservation::query()
            ->where('reservations.account_id', $accountId)
            ->whereIn('reservations.client_id', $customerIds)
            ->where('reservations.status', Reservation::STATUS_COMPLETED)
            ->where('reservations.id', function ($latestQuery) use ($accountId): void {
                $latestQuery
                    ->select('latest_reservation.id')
                    ->from('reservations as latest_reservation')
                    ->whereColumn('latest_reservation.client_id', 'reservations.client_id')
                    ->where('latest_reservation.account_id', $accountId)
                    ->where('latest_reservation.status', Reservation::STATUS_COMPLETED)
                    ->orderByDesc('latest_reservation.starts_at')
                    ->orderByDesc('latest_reservation.id')
                    ->limit(1);
            })
            ->get(['id', 'client_id', 'service_id', 'starts_at'])
            ->keyBy(fn (Reservation $reservation): int => (int) $reservation->client_id);
    }

    /**
     * @param  array<int, int>  $customerIds
     * @return Collection<int, Reservation>
     */
    private function nextAppointments(array $customerIds, int $accountId): Collection
    {
        $now = now();

        return Reservation::query()
            ->where('reservations.account_id', $accountId)
            ->whereIn('reservations.client_id', $customerIds)
            ->whereIn('reservations.status', Reservation::ACTIVE_STATUSES)
            ->where('reservations.starts_at', '>=', $now)
            ->where('reservations.id', function ($nextQuery) use ($accountId, $now): void {
                $nextQuery
                    ->select('next_reservation.id')
                    ->from('reservations as next_reservation')
                    ->whereColumn('next_reservation.client_id', 'reservations.client_id')
                    ->where('next_reservation.account_id', $accountId)
                    ->whereIn('next_reservation.status', Reservation::ACTIVE_STATUSES)
                    ->where('next_reservation.starts_at', '>=', $now)
                    ->orderBy('next_reservation.starts_at')
                    ->orderBy('next_reservation.id')
                    ->limit(1);
            })
            ->get(['id', 'client_id', 'team_member_id', 'starts_at'])
            ->keyBy(fn (Reservation $reservation): int => (int) $reservation->client_id);
    }

    /**
     * @param  array<int, int>  $customerIds
     * @return Collection<int, object>
     */
    private function usualTeamMembers(array $customerIds, int $accountId): Collection
    {
        return Reservation::query()
            ->select(['client_id', 'team_member_id'])
            ->selectRaw('COUNT(*) as completed_visits_count')
            ->selectRaw('MAX(starts_at) as latest_completed_visit_at')
            ->where('account_id', $accountId)
            ->whereIn('client_id', $customerIds)
            ->whereNotNull('team_member_id')
            ->where('status', Reservation::STATUS_COMPLETED)
            ->groupBy('client_id', 'team_member_id')
            ->orderBy('client_id')
            ->orderByDesc('completed_visits_count')
            ->orderByDesc('latest_completed_visit_at')
            ->orderBy('team_member_id')
            ->get()
            ->groupBy(fn (Reservation $reservation): int => (int) $reservation->client_id)
            ->map(fn (Collection $rows): Reservation => $rows->first());
    }

    /**
     * @param  array<int, int>  $customerIds
     * @return Collection<int, CustomerPackage>
     */
    private function activePackages(array $customerIds, int $accountId): Collection
    {
        return CustomerPackage::query()
            ->forAccount($accountId)
            ->whereIn('customer_id', $customerIds)
            ->where('status', CustomerPackage::STATUS_ACTIVE)
            ->with('offerPackage:id,name')
            ->orderBy('remaining_quantity')
            ->orderByRaw('CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expires_at')
            ->orderBy('id')
            ->get([
                'id',
                'customer_id',
                'offer_package_id',
                'remaining_quantity',
                'source_details',
                'expires_at',
            ])
            ->groupBy(fn (CustomerPackage $package): int => (int) $package->customer_id)
            ->map(fn (Collection $packages): CustomerPackage => $packages->first());
    }

    /**
     * @param  array<int, int>  $customerIds
     * @param  array<string, bool>  $capabilities
     * @return Collection<int, object>
     */
    private function paymentSummaries(
        array $customerIds,
        int $accountId,
        string $currencyCode,
        array $capabilities
    ): Collection {
        $tipReversalSql = 'CASE'
            .' WHEN COALESCE(tip_reversed_amount, 0) <= 0 THEN 0'
            .' WHEN COALESCE(tip_reversed_amount, 0) < COALESCE(tip_amount, 0) THEN COALESCE(tip_reversed_amount, 0)'
            .' ELSE COALESCE(tip_amount, 0) END';
        $query = Payment::query()
            ->select('customer_id')
            ->selectRaw(
                "COALESCE(SUM(COALESCE(charged_total, amount + COALESCE(tip_amount, 0)) - {$tipReversalSql}), 0) as total_spent"
            )
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN COALESCE(tip_amount, 0) > {$tipReversalSql} THEN COALESCE(tip_amount, 0) - {$tipReversalSql} ELSE 0 END), 0) as tip_total"
            )
            ->where('user_id', $accountId)
            ->whereIn('customer_id', $customerIds)
            ->where('currency_code', $currencyCode)
            ->whereIn('status', Payment::settledStatuses());

        if (($capabilities['invoices'] ?? false) && ! ($capabilities['sales'] ?? false)) {
            $query->whereNotNull('invoice_id');
        } elseif (($capabilities['sales'] ?? false) && ! ($capabilities['invoices'] ?? false)) {
            $query->whereNotNull('sale_id');
        }

        return $query
            ->groupBy('customer_id')
            ->get()
            ->keyBy(fn (Payment $payment): int => (int) $payment->customer_id);
    }

    /**
     * @param  array<int, int>  $customerIds
     * @return Collection<int, array{balance:float, invoice_id:?int}>
     */
    private function invoiceSummaries(
        array $customerIds,
        int $accountId,
        string $currencyCode
    ): Collection {
        return Invoice::query()
            ->byUser($accountId)
            ->whereIn('customer_id', $customerIds)
            ->whereIn('status', self::OPEN_INVOICE_STATUSES)
            ->where('currency_code', $currencyCode)
            ->withSum([
                'payments as payments_sum_amount' => fn (Builder $paymentQuery): Builder => $paymentQuery
                    ->where('payments.user_id', $accountId)
                    ->where('payments.currency_code', $currencyCode)
                    ->whereIn('status', Payment::settledStatuses()),
            ], 'amount')
            ->oldest('created_at')
            ->oldest('id')
            ->get(['id', 'customer_id', 'total', 'currency_code', 'created_at'])
            ->groupBy(fn (Invoice $invoice): int => (int) $invoice->customer_id)
            ->map(function (Collection $invoices): array {
                $unpaidInvoices = $invoices
                    ->filter(fn (Invoice $invoice): bool => $invoice->balance_due > 0);

                return [
                    'balance' => round((float) $unpaidInvoices->sum(
                        fn (Invoice $invoice): float => $invoice->balance_due
                    ), 2),
                    'invoice_id' => $unpaidInvoices->isNotEmpty()
                        ? (int) $unpaidInvoices->first()->id
                        : null,
                ];
            });
    }

    private function lifecycleStatus(
        Customer $customer,
        ?Reservation $lastVisit,
        ?Reservation $nextAppointment,
        mixed $now
    ): string {
        if (! $customer->is_active) {
            return 'inactive';
        }

        if ($customer->created_at && $customer->created_at->gte($now->copy()->subDays(self::NEW_CUSTOMER_DAYS))) {
            return 'new';
        }

        if (
            $nextAppointment
            || ($lastVisit?->starts_at && $lastVisit->starts_at->gte($now->copy()->subDays(self::FOLLOW_UP_DAYS)))
        ) {
            return 'active';
        }

        return $lastVisit ? 'follow_up' : 'inactive';
    }

    private function teamMemberName(?TeamMember $teamMember): ?string
    {
        if (! $teamMember) {
            return null;
        }

        return $teamMember->user?->name ?: $teamMember->title;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySummary(Customer $customer, string $currencyCode): array
    {
        return [
            'lifecycle_status' => $customer->is_active ? 'active' : 'inactive',
            'last_visit' => null,
            'next_appointment' => null,
            'usual_team_member' => null,
            'loyalty_points' => null,
            'active_package' => null,
            'total_spent' => null,
            'tip_total' => null,
            'unpaid_balance' => null,
            'unpaid_invoice_id' => null,
            'currency_code' => $currencyCode,
        ];
    }
}
