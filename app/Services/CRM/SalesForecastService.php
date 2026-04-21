<?php

namespace App\Services\CRM;

use App\Queries\CRM\BuildSalesPipelineIndexData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SalesForecastService
{
    /**
     * @var array<int, array{key: string, label: string}>
     */
    private const FORECAST_CATEGORIES = [
        ['key' => 'pipeline', 'label' => 'Pipeline'],
        ['key' => 'best_case', 'label' => 'Best case'],
        ['key' => 'closed_won', 'label' => 'Closed won'],
        ['key' => 'closed_lost', 'label' => 'Closed lost'],
    ];

    /**
     * @var array<int, array{key: string, label: string, min: int, max: int|null}>
     */
    private const AGING_BUCKETS = [
        ['key' => '0_7', 'label' => '0-7 days', 'min' => 0, 'max' => 7],
        ['key' => '8_14', 'label' => '8-14 days', 'min' => 8, 'max' => 14],
        ['key' => '15_30', 'label' => '15-30 days', 'min' => 15, 'max' => 30],
        ['key' => '31_plus', 'label' => '31+ days', 'min' => 31, 'max' => null],
    ];

    public function __construct(
        private readonly BuildSalesPipelineIndexData $pipelineIndexData,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function execute(int $accountId, array $filters = []): array
    {
        $referenceTime = $this->resolveReferenceTime($filters['reference_time'] ?? null);
        $normalizedFilters = [
            'search' => trim((string) ($filters['search'] ?? '')),
            'customer_id' => $this->normalizeCustomerId($filters['customer_id'] ?? null),
        ];

        $items = $this->pipelineIndexData->resolveCollection(
            $accountId,
            array_filter($normalizedFilters, fn (mixed $value): bool => ! ($value === null || $value === '')),
            $referenceTime
        );

        $openItems = $items->where('stage_state', 'open')->values();
        $wonItems = $items->where('stage_state', 'won')->values();
        $lostItems = $items->where('stage_state', 'lost')->values();
        $wins = $this->buildWinsSummary($wonItems, $referenceTime);

        return [
            'reference_time' => $referenceTime->toIso8601String(),
            'filters' => $normalizedFilters,
            'summary' => [
                'total' => $items->count(),
                'open_count' => $openItems->count(),
                'won_count' => $wonItems->count(),
                'lost_count' => $lostItems->count(),
                'open_amount' => $this->sumAmount($openItems, 'amount_total'),
                'weighted_open_amount' => $this->sumAmount($openItems, 'weighted_amount'),
                'pipeline_open_amount' => $this->sumAmount($openItems->where('forecast_category', 'pipeline')->values(), 'amount_total'),
                'pipeline_weighted_amount' => $this->sumAmount($openItems->where('forecast_category', 'pipeline')->values(), 'weighted_amount'),
                'best_case_open_amount' => $this->sumAmount($openItems->where('forecast_category', 'best_case')->values(), 'amount_total'),
                'best_case_weighted_amount' => $this->sumAmount($openItems->where('forecast_category', 'best_case')->values(), 'weighted_amount'),
                'overdue_next_actions' => $openItems->filter(
                    fn (array $item): bool => (bool) data_get($item, 'signals.has_overdue_next_action', false)
                )->count(),
                'month_to_date_won_amount' => data_get($wins, 'month_to_date.amount_total', 0.0),
                'quarter_to_date_won_amount' => data_get($wins, 'quarter_to_date.amount_total', 0.0),
                'year_to_date_won_amount' => data_get($wins, 'year_to_date.amount_total', 0.0),
            ],
            'categories' => $this->buildForecastCategorySummary($items),
            'stages' => $this->buildStageSummary($items),
            'aging' => $this->buildAgingSummary($openItems),
            'next_actions' => $this->buildNextActionSummary($openItems),
            'wins' => $wins,
        ];
    }

    private function resolveReferenceTime(mixed $value): Carbon
    {
        if (blank($value)) {
            return now();
        }

        return Carbon::parse((string) $value);
    }

    private function normalizeCustomerId(mixed $customerId): ?int
    {
        if ($customerId === null || $customerId === '') {
            return null;
        }

        $value = (int) $customerId;

        return $value > 0 ? $value : null;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function buildForecastCategorySummary(Collection $items): array
    {
        return collect(self::FORECAST_CATEGORIES)
            ->map(function (array $category) use ($items): array {
                $categoryItems = $items
                    ->filter(fn (array $item): bool => ($item['forecast_category'] ?? null) === $category['key'])
                    ->values();

                return [
                    'key' => $category['key'],
                    'label' => $category['label'],
                    'count' => $categoryItems->count(),
                    'amount_total' => $this->sumAmount($categoryItems, 'amount_total'),
                    'weighted_amount' => $this->sumAmount($categoryItems, 'weighted_amount'),
                ];
            })
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function buildStageSummary(Collection $items): array
    {
        return collect(BuildSalesPipelineIndexData::STAGES)
            ->map(function (array $stage) use ($items): array {
                $stageItems = $items
                    ->filter(fn (array $item): bool => ($item['stage_key'] ?? null) === $stage['key'])
                    ->values();

                $ageDays = $stage['state'] === 'open'
                    ? $stageItems
                        ->pluck('age_days')
                        ->filter(fn (mixed $value): bool => is_int($value) || is_float($value))
                        ->values()
                    : collect();

                return [
                    'key' => $stage['key'],
                    'label' => $stage['label'],
                    'state' => $stage['state'],
                    'rank' => $stage['rank'],
                    'count' => $stageItems->count(),
                    'amount_total' => $this->sumAmount($stageItems, 'amount_total'),
                    'weighted_amount' => $this->sumAmount($stageItems, 'weighted_amount'),
                    'average_age_days' => $ageDays->isNotEmpty()
                        ? round((float) $ageDays->avg(), 1)
                        : null,
                    'overdue_next_actions' => $stage['state'] === 'open'
                        ? $stageItems->filter(fn (array $item): bool => (bool) data_get($item, 'signals.has_overdue_next_action', false))->count()
                        : 0,
                ];
            })
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $openItems
     * @return array<int, array<string, mixed>>
     */
    private function buildAgingSummary(Collection $openItems): array
    {
        return collect(self::AGING_BUCKETS)
            ->map(function (array $bucket) use ($openItems): array {
                $bucketItems = $openItems
                    ->filter(function (array $item) use ($bucket): bool {
                        $ageDays = $item['age_days'] ?? null;
                        if (! is_int($ageDays) && ! is_float($ageDays)) {
                            return false;
                        }

                        if ((int) $ageDays < $bucket['min']) {
                            return false;
                        }

                        if ($bucket['max'] !== null && (int) $ageDays > $bucket['max']) {
                            return false;
                        }

                        return true;
                    })
                    ->values();

                return [
                    'key' => $bucket['key'],
                    'label' => $bucket['label'],
                    'count' => $bucketItems->count(),
                    'amount_total' => $this->sumAmount($bucketItems, 'amount_total'),
                    'weighted_amount' => $this->sumAmount($bucketItems, 'weighted_amount'),
                ];
            })
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $openItems
     * @return array<string, array<string, mixed>>
     */
    private function buildNextActionSummary(Collection $openItems): array
    {
        return [
            'overdue' => $this->buildNextActionBucket(
                $openItems->filter(fn (array $item): bool => (bool) data_get($item, 'signals.has_overdue_next_action', false))->values()
            ),
            'scheduled' => $this->buildNextActionBucket(
                $openItems->filter(function (array $item): bool {
                    return filled($item['next_action_at'] ?? null)
                        && ! data_get($item, 'signals.has_overdue_next_action', false);
                })->values()
            ),
            'none' => $this->buildNextActionBucket(
                $openItems->filter(fn (array $item): bool => blank($item['next_action_at'] ?? null))->values()
            ),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function buildNextActionBucket(Collection $items): array
    {
        return [
            'count' => $items->count(),
            'amount_total' => $this->sumAmount($items, 'amount_total'),
            'weighted_amount' => $this->sumAmount($items, 'weighted_amount'),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $wonItems
     * @return array<string, array<string, mixed>>
     */
    private function buildWinsSummary(Collection $wonItems, Carbon $referenceTime): array
    {
        $monthItems = $wonItems
            ->filter(fn (array $item): bool => $this->fallsWithin($item, $referenceTime->copy()->startOfMonth(), $referenceTime))
            ->values();
        $quarterItems = $wonItems
            ->filter(fn (array $item): bool => $this->fallsWithin($item, $referenceTime->copy()->startOfQuarter(), $referenceTime))
            ->values();
        $yearItems = $wonItems
            ->filter(fn (array $item): bool => $this->fallsWithin($item, $referenceTime->copy()->startOfYear(), $referenceTime))
            ->values();

        return [
            'month_to_date' => $this->buildWinWindow($monthItems),
            'quarter_to_date' => $this->buildWinWindow($quarterItems),
            'year_to_date' => $this->buildWinWindow($yearItems),
        ];
    }

    private function fallsWithin(array $item, Carbon $start, Carbon $end): bool
    {
        $wonAt = $this->dateValue(data_get($item, 'opportunity.timestamps.won_at'));

        return $wonAt !== null && $wonAt->betweenIncluded($start, $end);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function buildWinWindow(Collection $items): array
    {
        return [
            'count' => $items->count(),
            'amount_total' => $this->sumAmount($items, 'amount_total'),
            'weighted_amount' => $this->sumAmount($items, 'weighted_amount'),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     */
    private function sumAmount(Collection $items, string $key): float
    {
        return round((float) $items->sum(fn (array $item): float => (float) ($item[$key] ?? 0)), 2);
    }

    private function dateValue(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy();
        }

        if (blank($value)) {
            return null;
        }

        return Carbon::parse((string) $value);
    }
}
