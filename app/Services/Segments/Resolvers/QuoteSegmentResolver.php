<?php

namespace App\Services\Segments\Resolvers;

use App\Models\Quote;
use App\Models\SavedSegment;
use App\Queries\Quotes\BuildQuoteRecoveryIndexData;
use App\Services\Segments\Contracts\SegmentModuleResolver;

class QuoteSegmentResolver implements SegmentModuleResolver
{
    public function __construct(
        private readonly BuildQuoteRecoveryIndexData $quoteRecoveryIndexData
    ) {}

    public function key(): string
    {
        return SavedSegment::MODULE_QUOTE;
    }

    public function resolve(SavedSegment $segment): array
    {
        $filters = $this->normalizedFilters($segment);
        $items = $this->quoteRecoveryIndexData->resolveCollection((int) $segment->user_id, $filters);

        return [
            'module' => $this->key(),
            'model_class' => Quote::class,
            'ids' => $items->modelKeys(),
            'selected_count' => $items->count(),
            'filters' => [
                'search' => $filters['search'] ?? null,
                'status' => $filters['status'] ?? null,
                'customer_id' => $filters['customer_id'] ?? null,
                'total_min' => $filters['total_min'] ?? null,
                'total_max' => $filters['total_max'] ?? null,
                'created_from' => $filters['created_from'] ?? null,
                'created_to' => $filters['created_to'] ?? null,
                'has_deposit' => $filters['has_deposit'] ?? null,
                'has_tax' => $filters['has_tax'] ?? null,
                'queue' => $filters['queue'] ?? null,
            ],
            'sort' => [
                'column' => $filters['sort'],
                'direction' => $filters['direction'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizedFilters(SavedSegment $segment): array
    {
        $filters = is_array($segment->filters) ? $segment->filters : [];
        $sort = is_array($segment->sort) ? $segment->sort : [];

        if (filled($segment->search_term) && blank($filters['search'] ?? null)) {
            $filters['search'] = $segment->search_term;
        }

        $filters['sort'] = $sort['column'] ?? $sort['sort'] ?? null;
        $filters['direction'] = $sort['direction'] ?? null;

        return $filters;
    }
}
