<?php

namespace App\Queries\CRM;

use App\Services\CRM\SalesForecastService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class BuildSalesManagerDashboardData
{
    /**
     * @var array<int, array{key: string, label: string}>
     */
    private const NEXT_ACTION_BUCKETS = [
        ['key' => 'overdue', 'label' => 'Overdue'],
        ['key' => 'scheduled', 'label' => 'Scheduled'],
        ['key' => 'none', 'label' => 'No next action'],
    ];

    /**
     * @var array<int, array{key: string, label: string}>
     */
    private const WIN_WINDOWS = [
        ['key' => 'month_to_date', 'label' => 'Month to date'],
        ['key' => 'quarter_to_date', 'label' => 'Quarter to date'],
        ['key' => 'year_to_date', 'label' => 'Year to date'],
    ];

    public function __construct(
        private readonly BuildSalesPipelineIndexData $pipelineIndexData,
        private readonly BuildSalesInboxIndexData $salesInboxIndexData,
        private readonly BuildSalesInboxAnalyticsData $salesInboxAnalyticsData,
        private readonly SalesForecastService $salesForecastService,
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

        $activeFilters = array_filter($normalizedFilters, fn (mixed $value): bool => ! ($value === null || $value === ''));

        $pipelineItems = $this->pipelineIndexData->resolveCollection($accountId, $activeFilters, $referenceTime);
        $inboxItems = $this->salesInboxIndexData->resolveCollection($accountId, $activeFilters, $referenceTime);
        $forecast = $this->salesForecastService->execute($accountId, [
            ...$activeFilters,
            'reference_time' => $referenceTime->toIso8601String(),
        ]);
        $inboxStats = $this->salesInboxAnalyticsData->execute($inboxItems);
        $weightedOpenAmount = (float) data_get($forecast, 'summary.weighted_open_amount', 0.0);
        $weightedPipelineCategories = collect(data_get($forecast, 'categories', []));
        $quotePullThrough = $this->buildQuotePullThrough($pipelineItems);

        return [
            'reference_time' => $referenceTime->toIso8601String(),
            'filters' => [
                'search' => $normalizedFilters['search'],
                'customer_id' => $normalizedFilters['customer_id'],
                'reference_time' => $referenceTime->toIso8601String(),
            ],
            'summary' => [
                'open_count' => (int) data_get($forecast, 'summary.open_count', 0),
                'open_amount' => (float) data_get($forecast, 'summary.open_amount', 0.0),
                'weighted_open_amount' => $weightedOpenAmount,
                'month_to_date_won_amount' => (float) data_get($forecast, 'summary.month_to_date_won_amount', 0.0),
                'month_to_date_won_count' => (int) data_get($forecast, 'wins.month_to_date.count', 0),
                'overdue_next_actions' => (int) data_get($forecast, 'summary.overdue_next_actions', 0),
                'quote_pull_through' => $quotePullThrough,
            ],
            'weighted_pipeline' => $this->buildWeightedPipelineSummary(
                $weightedPipelineCategories,
                (float) $weightedPipelineCategories->sum(
                    fn (array $category): float => (float) ($category['weighted_amount'] ?? 0.0)
                )
            ),
            'stage_aging' => $this->buildStageAgingSummary(
                collect(data_get($forecast, 'stages', [])),
                $weightedOpenAmount
            ),
            'next_actions' => $this->buildNextActionSummary(data_get($forecast, 'next_actions', [])),
            'wins' => $this->buildWinsSummary(data_get($forecast, 'wins', [])),
            'queues' => $this->buildQueueSummary($inboxStats),
            'attention_items' => $inboxItems->take(5)->values()->all(),
            'options' => [
                'customers' => $this->buildCustomerOptions($pipelineItems),
            ],
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
     * @return array<string, mixed>
     */
    private function buildQuotePullThrough(Collection $items): array
    {
        $quotedItems = $items
            ->filter(fn (array $item): bool => (bool) data_get($item, 'signals.has_quote', false))
            ->values();
        $wonCount = $quotedItems->where('stage_state', 'won')->count();
        $lostCount = $quotedItems->where('stage_state', 'lost')->count();
        $openCount = $quotedItems->where('stage_state', 'open')->count();
        $total = $quotedItems->count();

        return [
            'total' => $total,
            'won' => $wonCount,
            'open' => $openCount,
            'lost' => $lostCount,
            'rate' => $total > 0 ? round(($wonCount / $total) * 100, 1) : 0.0,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $categories
     * @return array<int, array<string, mixed>>
     */
    private function buildWeightedPipelineSummary(Collection $categories, float $weightedTotal): array
    {
        return $categories
            ->map(function (array $category) use ($weightedTotal): array {
                $weightedAmount = (float) ($category['weighted_amount'] ?? 0.0);

                return [
                    'key' => (string) ($category['key'] ?? ''),
                    'label' => (string) ($category['label'] ?? ''),
                    'count' => (int) ($category['count'] ?? 0),
                    'amount_total' => (float) ($category['amount_total'] ?? 0.0),
                    'weighted_amount' => $weightedAmount,
                    'share_percent' => $weightedTotal > 0
                        ? round(($weightedAmount / $weightedTotal) * 100, 1)
                        : 0.0,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $stages
     * @return array<int, array<string, mixed>>
     */
    private function buildStageAgingSummary(Collection $stages, float $weightedOpenAmount): array
    {
        return $stages
            ->filter(fn (array $stage): bool => ($stage['state'] ?? null) === 'open')
            ->map(function (array $stage) use ($weightedOpenAmount): array {
                $weightedAmount = (float) ($stage['weighted_amount'] ?? 0.0);

                return [
                    'key' => (string) ($stage['key'] ?? ''),
                    'label' => (string) ($stage['label'] ?? ''),
                    'count' => (int) ($stage['count'] ?? 0),
                    'amount_total' => (float) ($stage['amount_total'] ?? 0.0),
                    'weighted_amount' => $weightedAmount,
                    'average_age_days' => $stage['average_age_days'] === null
                        ? null
                        : (float) $stage['average_age_days'],
                    'overdue_next_actions' => (int) ($stage['overdue_next_actions'] ?? 0),
                    'share_percent' => $weightedOpenAmount > 0
                        ? round(($weightedAmount / $weightedOpenAmount) * 100, 1)
                        : 0.0,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $nextActions
     * @return array<int, array<string, mixed>>
     */
    private function buildNextActionSummary(array $nextActions): array
    {
        return collect(self::NEXT_ACTION_BUCKETS)
            ->map(function (array $bucket) use ($nextActions): array {
                return [
                    'key' => $bucket['key'],
                    'label' => $bucket['label'],
                    'count' => (int) data_get($nextActions, $bucket['key'].'.count', 0),
                    'amount_total' => (float) data_get($nextActions, $bucket['key'].'.amount_total', 0.0),
                    'weighted_amount' => (float) data_get($nextActions, $bucket['key'].'.weighted_amount', 0.0),
                ];
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $wins
     * @return array<int, array<string, mixed>>
     */
    private function buildWinsSummary(array $wins): array
    {
        return collect(self::WIN_WINDOWS)
            ->map(function (array $window) use ($wins): array {
                return [
                    'key' => $window['key'],
                    'label' => $window['label'],
                    'count' => (int) data_get($wins, $window['key'].'.count', 0),
                    'amount_total' => (float) data_get($wins, $window['key'].'.amount_total', 0.0),
                    'weighted_amount' => (float) data_get($wins, $window['key'].'.weighted_amount', 0.0),
                ];
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $stats
     * @return array<int, array<string, mixed>>
     */
    private function buildQueueSummary(array $stats): array
    {
        return collect(BuildSalesInboxIndexData::QUEUES)
            ->map(function (string $queue) use ($stats): array {
                return [
                    'key' => $queue,
                    'count' => (int) data_get($stats, 'by_queue.'.$queue.'.count', 0),
                    'amount_total' => (float) data_get($stats, 'by_queue.'.$queue.'.amount_total', 0.0),
                    'weighted_amount' => (float) data_get($stats, 'by_queue.'.$queue.'.weighted_amount', 0.0),
                ];
            })
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array<int, array{value: int, label: string}>
     */
    private function buildCustomerOptions(Collection $items): array
    {
        return $items
            ->map(fn (array $item): ?array => data_get($item, 'customer'))
            ->filter(fn (?array $customer): bool => is_array($customer) && filled($customer['id'] ?? null))
            ->unique('id')
            ->sortBy(fn (array $customer): string => mb_strtolower((string) ($customer['name'] ?? '')))
            ->values()
            ->map(fn (array $customer): array => [
                'value' => (int) $customer['id'],
                'label' => (string) ($customer['name'] ?? ('Customer #'.$customer['id'])),
            ])
            ->all();
    }
}
