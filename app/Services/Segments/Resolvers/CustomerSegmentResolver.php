<?php

namespace App\Services\Segments\Resolvers;

use App\Models\Customer;
use App\Models\SavedSegment;
use App\Models\User;
use App\Queries\Customers\BuildCustomerOperationalIndexData;
use App\Queries\Customers\CustomerIndexFilters;
use App\Services\CompanyFeatureService;
use App\Services\Segments\Contracts\SegmentModuleResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

class CustomerSegmentResolver implements SegmentModuleResolver
{
    public function key(): string
    {
        return SavedSegment::MODULE_CUSTOMER;
    }

    public function resolve(SavedSegment $segment): array
    {
        $requestedFilters = $this->normalizedFilters($segment);
        $sort = $this->normalizedSort($segment);
        $accountId = (int) $segment->user_id;
        $accountOwner = User::query()->find($accountId);
        $operationalIndexData = app(BuildCustomerOperationalIndexData::class);

        if ($accountOwner) {
            $context = $operationalIndexData->context($accountOwner);
        } else {
            $context = [];
        }
        $appointmentProfile = ($context['profile'] ?? null) === 'appointment';
        $featureService = app(CompanyFeatureService::class);
        $showQuoteOperations = $accountOwner
            && ! $appointmentProfile
            && $featureService->hasFeature($accountOwner, 'quotes');
        $showJobOperations = $accountOwner
            && ! $appointmentProfile
            && $featureService->hasFeature($accountOwner, 'jobs');

        if (! $showQuoteOperations) {
            unset($requestedFilters['has_quotes']);
        }
        if (! $showJobOperations) {
            unset($requestedFilters['has_works']);
        }
        if (
            ($sort['column'] === 'quotes_count' && ! $showQuoteOperations)
            || ($sort['column'] === 'works_count' && ! $showJobOperations)
        ) {
            $sort = [
                'column' => 'created_at',
                'direction' => 'desc',
            ];
        }
        if (! ($context['capabilities']['packages'] ?? false)) {
            unset(
                $requestedFilters['has_active_package'],
                $requestedFilters['package_status'],
                $requestedFilters['package_remaining_lte'],
                $requestedFilters['package_expires_within_days'],
                $requestedFilters['package_is_recurring'],
                $requestedFilters['package_recurrence_status']
            );
        }

        if (! $accountOwner) {
            return [
                'module' => $this->key(),
                'model_class' => Customer::class,
                'ids' => [],
                'selected_count' => 0,
                'filters' => [],
                'sort' => $sort,
            ];
        }

        $indexFilters = app(CustomerIndexFilters::class);
        $filters = $indexFilters->normalize($requestedFilters, $accountOwner, $context, $accountId);
        $query = Customer::query()
            ->filter($indexFilters->modelFilters($filters))
            ->byUser($accountId);
        $indexFilters->apply($query, $filters, $accountOwner, $context, $accountId);

        if ($sort['column'] === 'quotes_count') {
            $query->withCount([
                'quotes as quotes_count' => fn (Builder $builder) => $builder->where('user_id', $accountId),
            ]);
        } elseif ($sort['column'] === 'works_count') {
            $query->withCount([
                'works as works_count' => fn (Builder $builder) => $builder->where('user_id', $accountId),
            ]);
        }

        $items = $query
            ->orderBy($sort['column'], $sort['direction'])
            ->orderBy('id')
            ->get(['id']);

        return [
            'module' => $this->key(),
            'model_class' => Customer::class,
            'ids' => $items->modelKeys(),
            'selected_count' => $items->count(),
            'filters' => $filters,
            'sort' => $sort,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizedFilters(SavedSegment $segment): array
    {
        $filters = is_array($segment->filters) ? $segment->filters : [];

        if (filled($segment->search_term) && blank($filters['name'] ?? null)) {
            $filters['name'] = $segment->search_term;
        }

        return Arr::only($filters, CustomerIndexFilters::INPUT_KEYS);
    }

    /**
     * @return array{column: string, direction: string}
     */
    private function normalizedSort(SavedSegment $segment): array
    {
        $sort = is_array($segment->sort) ? $segment->sort : [];
        $column = $sort['column'] ?? $sort['sort'] ?? null;
        $direction = $sort['direction'] ?? 'desc';

        return [
            'column' => in_array($column, ['company_name', 'first_name', 'created_at', 'quotes_count', 'works_count'], true)
                ? $column
                : 'created_at',
            'direction' => $direction === 'asc' ? 'asc' : 'desc',
        ];
    }
}
