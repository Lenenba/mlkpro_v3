<?php

namespace App\Services\Segments\Resolvers;

use App\Models\Customer;
use App\Models\SavedSegment;
use App\Models\User;
use App\Queries\Customers\BuildCustomerOperationalIndexData;
use App\Services\CompanyFeatureService;
use App\Services\Segments\Contracts\SegmentModuleResolver;
use Illuminate\Database\Eloquent\Builder;

class CustomerSegmentResolver implements SegmentModuleResolver
{
    public function key(): string
    {
        return SavedSegment::MODULE_CUSTOMER;
    }

    public function resolve(SavedSegment $segment): array
    {
        $filters = $this->normalizedFilters($segment);
        $sort = $this->normalizedSort($segment);
        $accountId = (int) $segment->user_id;
        $accountOwner = User::query()->find($accountId);
        $operationalIndexData = app(BuildCustomerOperationalIndexData::class);

        if ($accountOwner) {
            $context = $operationalIndexData->context($accountOwner);
            $operationalFilter = $operationalIndexData->normalizeOperationalFilter(
                $filters['operational_filter'] ?? null,
                $context
            );
        } else {
            $context = [];
            $operationalFilter = null;
        }
        $appointmentProfile = ($context['profile'] ?? null) === 'appointment';
        $featureService = app(CompanyFeatureService::class);
        $showQuoteOperations = $accountOwner
            && ! $appointmentProfile
            && $featureService->hasFeature($accountOwner, 'quotes');
        $showJobOperations = $accountOwner
            && ! $appointmentProfile
            && $featureService->hasFeature($accountOwner, 'jobs');

        if ($operationalFilter === null) {
            unset($filters['operational_filter']);
        } else {
            $filters['operational_filter'] = $operationalFilter;
        }
        if (! $showQuoteOperations) {
            unset($filters['has_quotes']);
        }
        if (! $showJobOperations) {
            unset($filters['has_works']);
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
                $filters['has_active_package'],
                $filters['package_status'],
                $filters['package_remaining_lte'],
                $filters['package_expires_within_days'],
                $filters['package_is_recurring'],
                $filters['package_recurrence_status']
            );
        }

        $query = Customer::query()
            ->filter($filters)
            ->byUser($accountId);

        if ($accountOwner) {
            $operationalIndexData->applyOperationalFilter(
                $query,
                $operationalFilter,
                $accountOwner,
                $context
            );
        }

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

        return [
            'name' => $filters['name'] ?? null,
            'city' => $filters['city'] ?? null,
            'country' => $filters['country'] ?? null,
            'has_quotes' => $filters['has_quotes'] ?? null,
            'has_works' => $filters['has_works'] ?? null,
            'status' => $filters['status'] ?? null,
            'created_from' => $filters['created_from'] ?? null,
            'created_to' => $filters['created_to'] ?? null,
            'is_vip' => $filters['is_vip'] ?? null,
            'vip_tier_id' => $filters['vip_tier_id'] ?? null,
            'has_active_package' => $filters['has_active_package'] ?? null,
            'package_status' => $filters['package_status'] ?? null,
            'package_remaining_lte' => $filters['package_remaining_lte'] ?? null,
            'package_expires_within_days' => $filters['package_expires_within_days'] ?? null,
            'package_is_recurring' => $filters['package_is_recurring'] ?? null,
            'package_recurrence_status' => $filters['package_recurrence_status'] ?? null,
            'operational_filter' => $filters['operational_filter'] ?? null,
        ];
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
