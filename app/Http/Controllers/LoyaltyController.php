<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\LoyaltyPointLedger;
use App\Models\LoyaltyProgram;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LoyaltyController extends Controller
{
    private const DEFAULT_PERIOD = '30d';

    private const EVENT_OPTIONS = [
        LoyaltyPointLedger::EVENT_ACCRUAL,
        LoyaltyPointLedger::EVENT_REFUND,
        LoyaltyPointLedger::EVENT_REDEMPTION,
        LoyaltyPointLedger::EVENT_REDEMPTION_REVERSAL,
    ];

    private const SORT_OPTIONS = [
        'processed_at',
        'event',
        'points',
        'amount',
    ];

    private const PER_PAGE_OPTIONS = [10, 15, 25, 50];

    public function index(Request $request)
    {
        $accountId = $this->accountIdForLoyaltyOrFail($request);

        $filters = $this->validatedFilters($request, $accountId);
        $program = LoyaltyProgram::query()->firstOrCreate(
            ['user_id' => $accountId],
            [
                'is_enabled' => true,
                'points_per_currency_unit' => 1,
                'minimum_spend' => 0,
                'rounding_mode' => LoyaltyProgram::ROUND_FLOOR,
                'points_label' => 'points',
            ]
        );

        $entriesQuery = LoyaltyPointLedger::query()
            ->where('user_id', $accountId)
            ->with([
                'customer:id,company_name,first_name,last_name',
            ]);

        $this->applyFilters($entriesQuery, $filters);
        $this->applySorting($entriesQuery, $filters);

        $entries = (clone $entriesQuery)
            ->paginate((int) $filters['per_page'])
            ->withQueryString()
            ->through(function (LoyaltyPointLedger $entry) {
                $meta = is_array($entry->meta) ? $entry->meta : [];

                return [
                    'id' => $entry->id,
                    'customer_id' => $entry->customer_id,
                    'customer_name' => $this->customerLabel($entry->customer),
                    'event' => (string) $entry->event,
                    'points' => (int) $entry->points,
                    'amount' => (float) $entry->amount,
                    'payment_id' => $entry->payment_id ? (int) $entry->payment_id : null,
                    'sale_id' => isset($meta['sale_id']) ? (int) $meta['sale_id'] : null,
                    'sale_number' => isset($meta['sale_number']) ? (string) $meta['sale_number'] : null,
                    'reason' => isset($meta['reason']) ? (string) $meta['reason'] : null,
                    'processed_at' => $entry->processed_at ?: $entry->created_at,
                ];
            });

        $movements = (clone $entriesQuery)
            ->selectRaw('COALESCE(SUM(CASE WHEN points > 0 THEN points ELSE 0 END), 0) as points_earned')
            ->selectRaw('COALESCE(ABS(SUM(CASE WHEN points < 0 THEN points ELSE 0 END)), 0) as points_spent')
            ->selectRaw('COUNT(*) as movements_count')
            ->first();

        $activeCustomers = (int) Customer::query()
            ->where('user_id', $accountId)
            ->where('loyalty_points_balance', '>', 0)
            ->count();

        $totalBalance = (int) Customer::query()
            ->where('user_id', $accountId)
            ->sum('loyalty_points_balance');

        $customerStats = Customer::query()
            ->where('user_id', $accountId)
            ->when(
                $filters['customer_id'] !== null,
                fn(Builder $query) => $query->whereKey((int) $filters['customer_id'])
            )
            ->withSum([
                'loyaltyPointLedgers as points_earned' => fn(Builder $query) => $query
                    ->where('user_id', $accountId)
                    ->whereIn('event', [
                        LoyaltyPointLedger::EVENT_ACCRUAL,
                        LoyaltyPointLedger::EVENT_REDEMPTION_REVERSAL,
                    ]),
            ], 'points')
            ->withSum([
                'loyaltyPointLedgers as points_spent_raw' => fn(Builder $query) => $query
                    ->where('user_id', $accountId)
                    ->where('event', LoyaltyPointLedger::EVENT_REDEMPTION),
            ], 'points')
            ->withSum([
                'loyaltyPointLedgers as points_refunded_raw' => fn(Builder $query) => $query
                    ->where('user_id', $accountId)
                    ->where('event', LoyaltyPointLedger::EVENT_REFUND),
            ], 'points')
            ->orderByDesc('loyalty_points_balance')
            ->orderBy('company_name')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->limit(20)
            ->get([
                'id',
                'company_name',
                'first_name',
                'last_name',
                'loyalty_points_balance',
            ])
            ->map(function (Customer $customer) {
                return [
                    'id' => $customer->id,
                    'name' => $this->customerLabel($customer),
                    'balance' => (int) ($customer->loyalty_points_balance ?? 0),
                    'points_earned' => max(0, (int) round((float) ($customer->points_earned ?? 0))),
                    'points_spent' => abs((int) round((float) ($customer->points_spent_raw ?? 0))),
                    'points_refunded' => abs((int) round((float) ($customer->points_refunded_raw ?? 0))),
                ];
            })
            ->values();

        $customerOptions = Customer::query()
            ->where('user_id', $accountId)
            ->orderBy('company_name')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'company_name', 'first_name', 'last_name'])
            ->map(fn(Customer $customer) => [
                'id' => $customer->id,
                'name' => $this->customerLabel($customer),
            ])
            ->values();

        return $this->inertiaOrJson('Loyalty/Index', [
            'filters' => $filters,
            'entries' => $entries,
            'customers' => $customerOptions,
            'eventOptions' => self::EVENT_OPTIONS,
            'customerStats' => $customerStats,
            'program' => [
                'is_enabled' => (bool) $program->is_enabled,
                'points_per_currency_unit' => (float) $program->points_per_currency_unit,
                'minimum_spend' => (float) $program->minimum_spend,
                'rounding_mode' => (string) $program->rounding_mode,
                'points_label' => (string) ($program->points_label ?: 'points'),
            ],
            'stats' => [
                'points_earned' => (int) round((float) ($movements?->points_earned ?? 0)),
                'points_spent' => (int) round((float) ($movements?->points_spent ?? 0)),
                'movements_count' => (int) ($movements?->movements_count ?? 0),
                'active_customers' => $activeCustomers,
                'total_balance' => $totalBalance,
            ],
        ]);
    }

    private function validatedFilters(Request $request, int $accountId): array
    {
        $validated = $request->validate([
            'period' => ['nullable', Rule::in(['7d', '30d', '90d', 'month', 'custom'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'search' => ['nullable', 'string', 'max:120'],
            'customer_id' => [
                'nullable',
                Rule::exists('customers', 'id')->where(
                    fn($query) => $query->where('user_id', $accountId)
                ),
            ],
            'event' => ['nullable', Rule::in(self::EVENT_OPTIONS)],
            'sort' => ['nullable', Rule::in(self::SORT_OPTIONS)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', Rule::in(self::PER_PAGE_OPTIONS)],
        ]);

        return [
            'period' => $validated['period'] ?? self::DEFAULT_PERIOD,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'search' => isset($validated['search']) ? trim((string) $validated['search']) : '',
            'customer_id' => isset($validated['customer_id']) ? (int) $validated['customer_id'] : null,
            'event' => $validated['event'] ?? null,
            'sort' => $validated['sort'] ?? 'processed_at',
            'direction' => $validated['direction'] ?? 'desc',
            'per_page' => isset($validated['per_page']) ? (int) $validated['per_page'] : 15,
        ];
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $searchQuery) use ($search): void {
                $eventSearch = strtolower($search);
                $searchQuery->whereHas('customer', function (Builder $customerQuery) use ($search): void {
                    $customerQuery
                        ->where('company_name', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%");
                });

                if (in_array($eventSearch, self::EVENT_OPTIONS, true)) {
                    $searchQuery->orWhere('event', $eventSearch);
                }

                if (is_numeric($search)) {
                    $numericSearch = (int) $search;
                    $searchQuery
                        ->orWhere('payment_id', $numericSearch)
                        ->orWhere('customer_id', $numericSearch);
                }
            });
        }

        if ($filters['customer_id']) {
            $query->where('customer_id', (int) $filters['customer_id']);
        }

        if (!empty($filters['event'])) {
            $query->where('event', (string) $filters['event']);
        }

        $this->applyPeriodFilter(
            $query,
            (string) ($filters['period'] ?? self::DEFAULT_PERIOD),
            $filters['from'] ?? null,
            $filters['to'] ?? null
        );
    }

    private function applyPeriodFilter(Builder $query, string $period, ?string $from, ?string $to): void
    {
        $period = in_array($period, ['7d', '30d', '90d', 'month', 'custom'], true)
            ? $period
            : self::DEFAULT_PERIOD;

        if ($period === 'custom') {
            if ($from) {
                $query->whereDate('processed_at', '>=', $from);
            }
            if ($to) {
                $query->whereDate('processed_at', '<=', $to);
            }

            return;
        }

        $now = now();
        [$start, $end] = match ($period) {
            '7d' => [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()],
            '90d' => [$now->copy()->subDays(89)->startOfDay(), $now->copy()->endOfDay()],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            default => [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()],
        };

        $query->whereBetween('processed_at', [$start, $end]);
    }

    private function applySorting(Builder $query, array $filters): void
    {
        $sort = in_array(($filters['sort'] ?? null), self::SORT_OPTIONS, true)
            ? (string) $filters['sort']
            : 'processed_at';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $query
            ->orderBy($sort, $direction)
            ->orderByDesc('id');
    }

    private function customerLabel(?Customer $customer): string
    {
        if (!$customer) {
            return 'Client inconnu';
        }

        if ($customer->company_name) {
            return (string) $customer->company_name;
        }

        $fullName = trim(
            implode(' ', array_filter([
                $customer->first_name,
                $customer->last_name,
            ]))
        );

        if ($fullName !== '') {
            return $fullName;
        }

        return 'Client #' . $customer->id;
    }

    private function accountIdForLoyaltyOrFail(Request $request): int
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $accountId = (int) $user->accountOwnerId();
        if ((int) $user->id === $accountId) {
            return $accountId;
        }

        $membership = $user->relationLoaded('teamMembership')
            ? $user->teamMembership
            : $user->teamMembership()->first();

        $canManageLoyalty = ($membership?->hasPermission('sales.manage') ?? false)
            || ($membership?->hasPermission('jobs.edit') ?? false)
            || ($membership?->hasPermission('tasks.edit') ?? false);

        if (!$canManageLoyalty) {
            abort(403);
        }

        return $accountId;
    }
}
