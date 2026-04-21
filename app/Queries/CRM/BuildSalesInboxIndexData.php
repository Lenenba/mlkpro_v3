<?php

namespace App\Queries\CRM;

use App\Support\DataTablePagination;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class BuildSalesInboxIndexData
{
    public const QUEUE_OVERDUE = 'overdue';

    public const QUEUE_NO_NEXT_ACTION = 'no_next_action';

    public const QUEUE_QUOTED = 'quoted';

    public const QUEUE_NEEDS_QUOTE = 'needs_quote';

    public const QUEUE_ACTIVE = 'active';

    public const QUEUES = [
        self::QUEUE_OVERDUE,
        self::QUEUE_NO_NEXT_ACTION,
        self::QUEUE_QUOTED,
        self::QUEUE_NEEDS_QUOTE,
        self::QUEUE_ACTIVE,
    ];

    private const QUEUE_RANKS = [
        self::QUEUE_OVERDUE => 10,
        self::QUEUE_NO_NEXT_ACTION => 20,
        self::QUEUE_QUOTED => 30,
        self::QUEUE_NEEDS_QUOTE => 40,
        self::QUEUE_ACTIVE => 50,
    ];

    public function __construct(
        private readonly BuildSalesPipelineIndexData $pipelineIndexData,
        private readonly BuildSalesInboxAnalyticsData $analyticsData,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(int $accountId, Request $request): array
    {
        $referenceTime = $this->resolveReferenceTime($request->query('reference_time'));
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'queue' => $this->normalizeQueue($request->query('queue')),
            'stage' => $this->normalizeStage($request->query('stage')),
            'per_page' => DataTablePagination::fromRequest($request),
            'reference_time' => $referenceTime->toIso8601String(),
        ];

        $classifiedItems = $this->resolveCollection($accountId, $filters, $referenceTime);
        $visibleItems = $this->filterByQueue($classifiedItems, $filters['queue'] ?? null);
        $paginator = $this->paginateItems($visibleItems, $request, (int) ($filters['per_page'] ?? DataTablePagination::defaultPerPage()));

        return [
            'items' => $paginator,
            'count' => $visibleItems->count(),
            'filters' => $filters,
            'stats' => $this->analyticsData->execute($classifiedItems),
            'queues' => $this->buildQueueSummary($classifiedItems),
            'reference_time' => $referenceTime->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function resolveCollection(int $accountId, array $filters = [], ?Carbon $referenceTime = null): Collection
    {
        $referenceTime = $referenceTime?->copy() ?? now();
        $pipelineItems = $this->pipelineIndexData->resolveCollection($accountId, [
            'search' => trim((string) ($filters['search'] ?? '')),
            'stage' => $this->normalizeStage($filters['stage'] ?? null),
            'customer_id' => $this->normalizeCustomerId($filters['customer_id'] ?? null),
            'state' => 'open',
        ], $referenceTime);

        return $pipelineItems
            ->map(fn (array $item): array => $this->classifyItem($item, $referenceTime))
            ->sort(fn (array $left, array $right): int => $this->compareItems($left, $right))
            ->values();
    }

    /**
     * @return array<int, string>
     */
    public static function stageOptions(): array
    {
        return collect(BuildSalesPipelineIndexData::STAGES)
            ->where('state', 'open')
            ->pluck('key')
            ->values()
            ->all();
    }

    private function normalizeQueue(mixed $queue): ?string
    {
        $normalized = is_string($queue) ? trim($queue) : null;

        return in_array($normalized, self::QUEUES, true) ? $normalized : null;
    }

    private function normalizeStage(mixed $stage): ?string
    {
        $normalized = is_string($stage) ? trim($stage) : null;

        return in_array($normalized, self::stageOptions(), true) ? $normalized : null;
    }

    private function normalizeCustomerId(mixed $customerId): ?int
    {
        if ($customerId === null || $customerId === '') {
            return null;
        }

        $value = (int) $customerId;

        return $value > 0 ? $value : null;
    }

    private function resolveReferenceTime(mixed $value): Carbon
    {
        if (blank($value)) {
            return now();
        }

        return Carbon::parse((string) $value);
    }

    /**
     * @return array<string, mixed>
     */
    private function classifyItem(array $item, Carbon $referenceTime): array
    {
        $hasQuote = (bool) data_get($item, 'signals.has_quote', false);
        $nextActionAt = $this->dateValue($item['next_action_at'] ?? null);
        $isOverdue = $nextActionAt?->lte($referenceTime) ?? false;
        $queue = $this->resolveQueue($item, $hasQuote, $nextActionAt, $isOverdue);

        return array_merge($item, [
            'queue' => $queue,
            'queue_rank' => self::QUEUE_RANKS[$queue] ?? 999,
            'next_action_state' => $isOverdue ? 'overdue' : ($nextActionAt ? 'scheduled' : 'none'),
            'primary_subject_type' => data_get($item, 'primary_subject_type')
                ?? data_get($item, 'crm_links.subject.type')
                ?? ($hasQuote ? 'quote' : 'request'),
            'primary_subject_id' => data_get($item, 'primary_subject_id')
                ?? data_get($item, 'crm_links.subject.id')
                ?? ($hasQuote ? data_get($item, 'quote.id') : data_get($item, 'request.id')),
        ]);
    }

    private function resolveQueue(array $item, bool $hasQuote, ?Carbon $nextActionAt, bool $isOverdue): string
    {
        if ($isOverdue) {
            return self::QUEUE_OVERDUE;
        }

        if (! $nextActionAt) {
            return self::QUEUE_NO_NEXT_ACTION;
        }

        if (($item['stage_key'] ?? null) === 'quoted') {
            return self::QUEUE_QUOTED;
        }

        if (! $hasQuote && ($item['stage_key'] ?? null) === 'qualified') {
            return self::QUEUE_NEEDS_QUOTE;
        }

        return self::QUEUE_ACTIVE;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return Collection<int, array<string, mixed>>
     */
    private function filterByQueue(Collection $items, ?string $queue): Collection
    {
        if ($queue === null) {
            return $items->values();
        }

        return $items
            ->filter(fn (array $item): bool => ($item['queue'] ?? null) === $queue)
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function buildQueueSummary(Collection $items): array
    {
        return collect(self::QUEUES)
            ->map(function (string $queue) use ($items): array {
                $queueItems = $items
                    ->filter(fn (array $item): bool => ($item['queue'] ?? null) === $queue)
                    ->values();

                return [
                    'key' => $queue,
                    'count' => $queueItems->count(),
                    'amount_total' => round((float) $queueItems->sum(fn (array $item): float => (float) ($item['amount_total'] ?? 0)), 2),
                    'weighted_amount' => round((float) $queueItems->sum(fn (array $item): float => (float) ($item['weighted_amount'] ?? 0)), 2),
                ];
            })
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     */
    private function paginateItems(Collection $items, Request $request, int $perPage): LengthAwarePaginator
    {
        $page = max(1, (int) $request->query('page', 1));

        return new LengthAwarePaginator(
            $items->forPage($page, $perPage)->values()->all(),
            $items->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );
    }

    private function compareItems(array $left, array $right): int
    {
        $queueComparison = $this->compareNumericValues($left['queue_rank'] ?? null, $right['queue_rank'] ?? null);
        if ($queueComparison !== 0) {
            return $queueComparison;
        }

        $nextActionComparison = $this->compareNullableDates($left['next_action_at'] ?? null, $right['next_action_at'] ?? null);
        if ($nextActionComparison !== 0) {
            return $nextActionComparison;
        }

        $stageComparison = $this->compareNumericValues($right['stage_rank'] ?? null, $left['stage_rank'] ?? null);
        if ($stageComparison !== 0) {
            return $stageComparison;
        }

        $amountComparison = $this->compareNumericValues($right['amount_total'] ?? null, $left['amount_total'] ?? null);
        if ($amountComparison !== 0) {
            return $amountComparison;
        }

        $openedComparison = $this->compareNullableDates($left['opened_at'] ?? null, $right['opened_at'] ?? null);
        if ($openedComparison !== 0) {
            return $openedComparison;
        }

        return strcmp((string) ($left['key'] ?? ''), (string) ($right['key'] ?? ''));
    }

    private function compareNumericValues(mixed $left, mixed $right): int
    {
        if ($left === null && $right === null) {
            return 0;
        }

        if ($left === null) {
            return 1;
        }

        if ($right === null) {
            return -1;
        }

        return (float) $left <=> (float) $right;
    }

    private function compareNullableDates(mixed $left, mixed $right): int
    {
        $leftTimestamp = $this->timestamp($left);
        $rightTimestamp = $this->timestamp($right);

        if ($leftTimestamp === null && $rightTimestamp === null) {
            return 0;
        }

        if ($leftTimestamp === null) {
            return 1;
        }

        if ($rightTimestamp === null) {
            return -1;
        }

        return $leftTimestamp <=> $rightTimestamp;
    }

    private function timestamp(mixed $value): ?int
    {
        if ($value instanceof Carbon) {
            return $value->getTimestamp();
        }

        if (blank($value)) {
            return null;
        }

        return Carbon::parse((string) $value)->getTimestamp();
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
