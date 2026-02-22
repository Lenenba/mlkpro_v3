<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\LoyaltyPointLedger;
use App\Models\LoyaltyProgram;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PortalLoyaltyController extends Controller
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
        $customer = $this->portalCustomer($request);
        $accountId = (int) $customer->user_id;

        $filters = $this->validatedFilters($request);
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
            ->where('customer_id', $customer->id);

        $this->applyFilters($entriesQuery, $filters);
        $this->applySorting($entriesQuery, $filters);

        $entries = (clone $entriesQuery)
            ->paginate((int) $filters['per_page'])
            ->withQueryString()
            ->through(function (LoyaltyPointLedger $entry) {
                $meta = is_array($entry->meta) ? $entry->meta : [];

                return [
                    'id' => (int) $entry->id,
                    'customer_id' => (int) $entry->customer_id,
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

        $periodSummary = (clone $entriesQuery)
            ->selectRaw('COALESCE(SUM(CASE WHEN points > 0 THEN points ELSE 0 END), 0) as points_earned')
            ->selectRaw('COALESCE(ABS(SUM(CASE WHEN points < 0 THEN points ELSE 0 END)), 0) as points_spent')
            ->selectRaw('COUNT(*) as movements_count')
            ->first();

        $lifetimeSummary = LoyaltyPointLedger::query()
            ->where('user_id', $accountId)
            ->where('customer_id', $customer->id)
            ->selectRaw('COALESCE(SUM(CASE WHEN points > 0 THEN points ELSE 0 END), 0) as points_earned')
            ->selectRaw('COALESCE(ABS(SUM(CASE WHEN points < 0 THEN points ELSE 0 END)), 0) as points_spent')
            ->selectRaw('COUNT(*) as movements_count')
            ->first();

        return $this->inertiaOrJson('Portal/Loyalty/Index', [
            'customer' => [
                'id' => (int) $customer->id,
                'name' => $this->customerLabel($customer),
            ],
            'filters' => $filters,
            'entries' => $entries,
            'eventOptions' => self::EVENT_OPTIONS,
            'program' => [
                'is_enabled' => (bool) $program->is_enabled,
                'points_per_currency_unit' => (float) $program->points_per_currency_unit,
                'minimum_spend' => (float) $program->minimum_spend,
                'rounding_mode' => (string) $program->rounding_mode,
                'points_label' => (string) ($program->points_label ?: 'points'),
            ],
            'stats' => [
                'balance' => (int) ($customer->loyalty_points_balance ?? 0),
                'points_earned_period' => (int) round((float) ($periodSummary?->points_earned ?? 0)),
                'points_spent_period' => (int) round((float) ($periodSummary?->points_spent ?? 0)),
                'movements_count_period' => (int) ($periodSummary?->movements_count ?? 0),
                'points_earned_lifetime' => (int) round((float) ($lifetimeSummary?->points_earned ?? 0)),
                'points_spent_lifetime' => (int) round((float) ($lifetimeSummary?->points_spent ?? 0)),
                'movements_count_lifetime' => (int) ($lifetimeSummary?->movements_count ?? 0),
            ],
        ]);
    }

    private function portalCustomer(Request $request): Customer
    {
        $customer = $request->user()?->customerProfile;
        if (!$customer) {
            abort(403);
        }

        return $customer;
    }

    private function validatedFilters(Request $request): array
    {
        $validated = $request->validate([
            'period' => ['nullable', Rule::in(['7d', '30d', '90d', 'month', 'custom'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'event' => ['nullable', Rule::in(self::EVENT_OPTIONS)],
            'sort' => ['nullable', Rule::in(self::SORT_OPTIONS)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', Rule::in(self::PER_PAGE_OPTIONS)],
        ]);

        return [
            'period' => $validated['period'] ?? self::DEFAULT_PERIOD,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'event' => $validated['event'] ?? null,
            'sort' => $validated['sort'] ?? 'processed_at',
            'direction' => $validated['direction'] ?? 'desc',
            'per_page' => isset($validated['per_page']) ? (int) $validated['per_page'] : 15,
        ];
    }

    private function applyFilters(Builder $query, array $filters): void
    {
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

    private function customerLabel(Customer $customer): string
    {
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
}
