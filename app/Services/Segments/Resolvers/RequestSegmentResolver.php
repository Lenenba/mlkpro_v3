<?php

namespace App\Services\Segments\Resolvers;

use App\Models\Request as LeadRequest;
use App\Models\SavedSegment;
use App\Queries\Requests\BuildRequestInboxIndexData;
use App\Services\Segments\Contracts\SegmentModuleResolver;

class RequestSegmentResolver implements SegmentModuleResolver
{
    public function __construct(
        private readonly BuildRequestInboxIndexData $requestInboxIndexData
    ) {
    }

    public function key(): string
    {
        return SavedSegment::MODULE_REQUEST;
    }

    public function resolve(SavedSegment $segment): array
    {
        $filters = $this->normalizedFilters($segment);
        $items = $this->requestInboxIndexData->resolveCollection((int) $segment->user_id, $filters);

        return [
            'module' => $this->key(),
            'model_class' => LeadRequest::class,
            'ids' => $items->modelKeys(),
            'selected_count' => $items->count(),
            'filters' => $filters,
            'sort' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizedFilters(SavedSegment $segment): array
    {
        $filters = is_array($segment->filters) ? $segment->filters : [];

        if (filled($segment->search_term) && blank($filters['search'] ?? null)) {
            $filters['search'] = $segment->search_term;
        }

        return [
            'search' => $filters['search'] ?? null,
            'status' => $filters['status'] ?? null,
            'customer_id' => $filters['customer_id'] ?? null,
            'queue' => $filters['queue'] ?? null,
        ];
    }
}
