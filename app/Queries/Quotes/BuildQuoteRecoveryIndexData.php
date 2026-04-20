<?php

namespace App\Queries\Quotes;

use App\Models\Quote;
use App\Services\Quotes\QuoteRecoveryPriorityScorer;
use App\Support\DataTablePagination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class BuildQuoteRecoveryIndexData
{
    public const QUEUE_ACTIVE = 'active';
    public const QUEUE_CLOSED = 'closed';
    public const QUEUE_DUE = 'due';
    public const QUEUE_EXPIRED = 'expired';
    public const QUEUE_HIGH_VALUE = 'high_value';
    public const QUEUE_NEVER_FOLLOWED = 'never_followed';
    public const QUEUE_VIEWED_NOT_ACCEPTED = 'viewed_not_accepted';

    private const DUE_WINDOW_HOURS = 48;
    private const EXPIRED_AFTER_DAYS = 14;
    private const HIGH_VALUE_THRESHOLD = 1000;

    public function __construct(
        private readonly QuoteRecoveryPriorityScorer $priorityScorer,
        private readonly BuildQuoteRecoveryAnalyticsData $analyticsData
    ) {
    }

    public function execute(int $accountId, Request $request): array
    {
        $filters = $request->only([
            'search',
            'status',
            'customer_id',
            'total_min',
            'total_max',
            'created_from',
            'created_to',
            'has_deposit',
            'has_tax',
            'sort',
            'direction',
            'queue',
        ]);
        $filters['per_page'] = DataTablePagination::fromRequest($request);
        $filters['sort'] = $this->normalizeSort($filters['sort'] ?? null);
        $filters['direction'] = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $filters['queue'] = $this->normalizeQueueFilter($filters['queue'] ?? null);

        $classifiedItems = $this->classifyQuotes(
            $this->baseQuery($accountId, $filters)
                ->with(['customer', 'property'])
                ->withAvg('ratings', 'rating')
                ->withCount('ratings')
                ->get(),
            now()
        );

        $filteredItems = $this->filterQuotes($classifiedItems, $filters['queue']);
        $sortedItems = $this->sortQuotes($filteredItems, $filters);

        return [
            'quotes' => $this->paginateQuotes($sortedItems, $request, $filters),
            'filters' => $filters,
            'count' => $sortedItems->count(),
            'stats' => $this->analyticsData->execute($sortedItems),
            'topQuotes' => $this->topQuotes($sortedItems),
        ];
    }

    public function resolveCollection(int $accountId, array $filters = [], ?Carbon $referenceTime = null): Collection
    {
        $normalizedFilters = [
            'search' => $filters['search'] ?? null,
            'status' => $filters['status'] ?? null,
            'customer_id' => $filters['customer_id'] ?? null,
            'total_min' => $filters['total_min'] ?? null,
            'total_max' => $filters['total_max'] ?? null,
            'created_from' => $filters['created_from'] ?? null,
            'created_to' => $filters['created_to'] ?? null,
            'has_deposit' => $filters['has_deposit'] ?? null,
            'has_tax' => $filters['has_tax'] ?? null,
            'sort' => $this->normalizeSort($filters['sort'] ?? null),
            'direction' => ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc',
            'queue' => $this->normalizeQueueFilter($filters['queue'] ?? null),
        ];

        $classifiedItems = $this->classifyQuotes(
            $this->baseQuery($accountId, $normalizedFilters)
                ->with(['customer', 'property'])
                ->withAvg('ratings', 'rating')
                ->withCount('ratings')
                ->get(),
            $referenceTime?->copy() ?? now()
        );

        return $this->sortQuotes(
            $this->filterQuotes($classifiedItems, $normalizedFilters['queue']),
            $normalizedFilters
        );
    }

    private function baseQuery(int $accountId, array $filters): Builder
    {
        $statusFilter = $filters['status'] ?? null;
        $showArchived = $statusFilter === 'archived';
        $filtersForQuery = $filters;

        if ($showArchived) {
            $filtersForQuery['status'] = null;
        }

        return Quote::query()
            ->filter($filtersForQuery)
            ->when(
                $showArchived,
                fn (Builder $query) => $query->byUserWithArchived($accountId)->archived(),
                fn (Builder $query) => $query->byUser($accountId)
            )
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    private function classifyQuotes(Collection $quotes, Carbon $referenceTime): Collection
    {
        return $quotes
            ->map(function (Quote $quote) use ($referenceTime): Quote {
                $classified = $this->classifyQuote($quote, $referenceTime);
                $priority = $this->priorityScorer->score($quote, $classified['queue'], $classified, $referenceTime);

                $quote->setAttribute('effective_last_sent_at', $classified['effective_last_sent_at']);
                $quote->setAttribute('quote_age_days', $classified['quote_age_days']);
                $quote->setAttribute('recovery_queue', $classified['queue']);
                $quote->setAttribute('recovery_is_open', $classified['is_open']);
                $quote->setAttribute('recovery_is_never_followed', $classified['is_never_followed']);
                $quote->setAttribute('recovery_is_due', $classified['is_due']);
                $quote->setAttribute('recovery_is_viewed_not_accepted', $classified['is_viewed_not_accepted']);
                $quote->setAttribute('recovery_is_expired', $classified['is_expired']);
                $quote->setAttribute('recovery_is_high_value', $classified['is_high_value']);
                $quote->setAttribute('recovery_priority', $priority['score']);
                $quote->setAttribute('recovery_priority_label', $priority['label']);
                $quote->setAttribute('recovery_priority_reason', $priority['reason']);

                return $quote;
            })
            ->values();
    }

    private function classifyQuote(Quote $quote, Carbon $referenceTime): array
    {
        $isArchived = $quote->isArchived();
        $status = (string) $quote->status;
        $effectiveLastSentAt = $this->resolveLastSentAt($quote);
        $nextFollowUpAt = $this->dateValue($quote->next_follow_up_at);
        $lastViewedAt = $this->dateValue($quote->last_viewed_at);
        $followUpCount = (int) ($quote->follow_up_count ?? 0);
        $isOpen = ! $isArchived && in_array($status, ['draft', 'sent'], true);

        $isViewedNotAccepted = ! $isArchived
            && $status === 'sent'
            && $lastViewedAt !== null
            && $quote->accepted_at === null;
        $isDue = ! $isArchived
            && $status === 'sent'
            && $nextFollowUpAt !== null
            && $nextFollowUpAt->lte($referenceTime->copy()->addHours(self::DUE_WINDOW_HOURS));
        $isHighValue = $isOpen && (float) $quote->total >= self::HIGH_VALUE_THRESHOLD;
        $isNeverFollowed = ! $isArchived
            && $status === 'sent'
            && $followUpCount === 0;
        $isExpired = ! $isArchived
            && $status === 'sent'
            && $effectiveLastSentAt !== null
            && $effectiveLastSentAt->lte($referenceTime->copy()->subDays(self::EXPIRED_AFTER_DAYS));
        $quoteAgeDays = $effectiveLastSentAt
            ? $referenceTime->diffInDays($effectiveLastSentAt)
            : null;

        return [
            'queue' => $this->resolveQueue(
                $isOpen,
                $isViewedNotAccepted,
                $isDue,
                $isHighValue,
                $isNeverFollowed,
                $isExpired
            ),
            'is_open' => $isOpen,
            'is_never_followed' => $isNeverFollowed,
            'is_due' => $isDue,
            'is_viewed_not_accepted' => $isViewedNotAccepted,
            'is_expired' => $isExpired,
            'is_high_value' => $isHighValue,
            'effective_last_sent_at' => $effectiveLastSentAt,
            'quote_age_days' => $quoteAgeDays,
            'next_follow_up_at' => $nextFollowUpAt,
            'last_viewed_at' => $lastViewedAt,
        ];
    }

    private function filterQuotes(Collection $items, ?string $queue): Collection
    {
        if ($queue === null) {
            return $items;
        }

        return $items
            ->filter(fn (Quote $quote): bool => $quote->getAttribute('recovery_queue') === $queue)
            ->values();
    }

    private function sortQuotes(Collection $items, array $filters): Collection
    {
        $sort = $filters['sort'] ?? 'recovery_priority';
        $direction = $filters['direction'] ?? 'desc';

        return $items
            ->sort(function (Quote $left, Quote $right) use ($sort, $direction): int {
                $comparison = $this->compareSortValues(
                    $sort,
                    $this->sortableValue($left, $sort),
                    $this->sortableValue($right, $sort)
                );

                if ($comparison !== 0) {
                    return $direction === 'asc' ? $comparison : -$comparison;
                }

                $priorityComparison = ((int) $right->getAttribute('recovery_priority'))
                    <=> ((int) $left->getAttribute('recovery_priority'));
                if ($priorityComparison !== 0) {
                    return $priorityComparison;
                }

                $queueComparison = $this->queueRank((string) $left->getAttribute('recovery_queue'))
                    <=> $this->queueRank((string) $right->getAttribute('recovery_queue'));
                if ($queueComparison !== 0) {
                    return $queueComparison;
                }

                $createdComparison = $this->compareNullableDates($left->created_at, $right->created_at);
                if ($createdComparison !== 0) {
                    return $createdComparison;
                }

                return $left->id <=> $right->id;
            })
            ->values();
    }

    private function paginateQuotes(Collection $items, Request $request, array $filters): LengthAwarePaginator
    {
        $perPage = max(1, (int) ($filters['per_page'] ?? DataTablePagination::defaultPerPage()));
        $page = max(1, (int) $request->query('page', 1));

        return new LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );
    }

    private function topQuotes(Collection $items): array
    {
        return $items
            ->sortByDesc(fn (Quote $quote): float => (float) $quote->total)
            ->take(5)
            ->values()
            ->all();
    }

    private function normalizeQueueFilter(?string $queue): ?string
    {
        $normalized = is_string($queue) ? trim($queue) : null;

        return in_array($normalized, [
            self::QUEUE_NEVER_FOLLOWED,
            self::QUEUE_DUE,
            self::QUEUE_VIEWED_NOT_ACCEPTED,
            self::QUEUE_EXPIRED,
            self::QUEUE_HIGH_VALUE,
            self::QUEUE_ACTIVE,
            self::QUEUE_CLOSED,
        ], true)
            ? $normalized
            : null;
    }

    private function normalizeSort(?string $sort): string
    {
        return in_array($sort, [
            'created_at',
            'job_title',
            'number',
            'quote_age_days',
            'recovery_priority',
            'status',
            'total',
        ], true)
            ? $sort
            : 'recovery_priority';
    }

    private function resolveQueue(
        bool $isOpen,
        bool $isViewedNotAccepted,
        bool $isDue,
        bool $isHighValue,
        bool $isNeverFollowed,
        bool $isExpired
    ): string {
        if (! $isOpen) {
            return self::QUEUE_CLOSED;
        }

        if ($isViewedNotAccepted) {
            return self::QUEUE_VIEWED_NOT_ACCEPTED;
        }

        if ($isDue) {
            return self::QUEUE_DUE;
        }

        if ($isHighValue) {
            return self::QUEUE_HIGH_VALUE;
        }

        if ($isNeverFollowed) {
            return self::QUEUE_NEVER_FOLLOWED;
        }

        if ($isExpired) {
            return self::QUEUE_EXPIRED;
        }

        return self::QUEUE_ACTIVE;
    }

    private function queueRank(string $queue): int
    {
        return match ($queue) {
            self::QUEUE_VIEWED_NOT_ACCEPTED => 0,
            self::QUEUE_DUE => 1,
            self::QUEUE_HIGH_VALUE => 2,
            self::QUEUE_NEVER_FOLLOWED => 3,
            self::QUEUE_EXPIRED => 4,
            self::QUEUE_ACTIVE => 5,
            default => 6,
        };
    }

    private function sortableValue(Quote $quote, string $sort): mixed
    {
        return match ($sort) {
            'recovery_priority' => (int) $quote->getAttribute('recovery_priority'),
            'quote_age_days' => $quote->getAttribute('quote_age_days'),
            'total' => (float) $quote->total,
            'created_at' => $quote->created_at,
            'status' => (string) $quote->status,
            'number' => (string) ($quote->number ?? ''),
            'job_title' => (string) ($quote->job_title ?? ''),
            default => $quote->created_at,
        };
    }

    private function compareSortValues(string $sort, mixed $left, mixed $right): int
    {
        return match ($sort) {
            'recovery_priority', 'quote_age_days' => ((int) ($left ?? 0)) <=> ((int) ($right ?? 0)),
            'total' => ((float) ($left ?? 0)) <=> ((float) ($right ?? 0)),
            'created_at' => $this->compareNullableDates($left, $right),
            default => strcasecmp((string) $left, (string) $right),
        };
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

    private function resolveLastSentAt(Quote $quote): ?Carbon
    {
        return $this->dateValue($quote->last_sent_at)
            ?: $this->dateValue($quote->created_at);
    }

    private function dateValue(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy();
        }

        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value);
    }

    private function timestamp(mixed $value): ?int
    {
        if ($value instanceof Carbon) {
            return $value->getTimestamp();
        }

        if (blank($value)) {
            return null;
        }

        return Carbon::parse($value)->getTimestamp();
    }
}
