<?php

namespace App\Queries\Requests;

use App\Models\Request as LeadRequest;
use App\Services\Requests\LeadTriageClassifier;
use App\Support\DataTablePagination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class BuildRequestInboxIndexData
{
    public function __construct(
        private readonly LeadTriageClassifier $classifier
    ) {
    }

    public function execute(int $accountId, Request $request): array
    {
        $filters = $request->only([
            'search',
            'status',
            'customer_id',
            'view',
            'queue',
        ]);
        $filters['per_page'] = DataTablePagination::fromRequest($request);
        $filters['view'] = in_array($filters['view'] ?? null, ['table', 'board'], true)
            ? $filters['view']
            : 'table';
        $filters['queue'] = $this->normalizeQueueFilter($filters['queue'] ?? null);

        $baseQuery = $this->baseQuery($accountId, $filters);

        $classifiedItems = $this->classifyInboxItems(
            (clone $baseQuery)->with([
                'customer:id,company_name,first_name,last_name,email,phone',
                'quote:id,number,status,customer_id,request_id',
                'assignee:id,user_id,account_id',
                'assignee.user:id,name',
            ])->get(),
            now()
        );
        $stats = $this->statsForClassifiedItems($classifiedItems);
        $sortedItems = $this->sortInboxItems(
            $this->filterInboxItems($classifiedItems, $filters['queue'])
        );

        return [
            'requests' => $this->paginateInboxItems($sortedItems, $request, $filters),
            'filters' => $filters,
            'stats' => $stats,
        ];
    }

    public function resolveCollection(int $accountId, array $filters = [], ?Carbon $referenceTime = null): Collection
    {
        $normalizedFilters = [
            'search' => $filters['search'] ?? null,
            'status' => $filters['status'] ?? null,
            'customer_id' => $filters['customer_id'] ?? null,
            'queue' => $this->normalizeQueueFilter($filters['queue'] ?? null),
        ];

        $classifiedItems = $this->classifyInboxItems(
            (clone $this->baseQuery($accountId, $normalizedFilters))->with([
                'customer:id,company_name,first_name,last_name,email,phone',
                'quote:id,number,status,customer_id,request_id',
                'assignee:id,user_id,account_id',
                'assignee.user:id,name',
            ])->get(),
            $referenceTime?->copy() ?? now()
        );

        return $this->sortInboxItems(
            $this->filterInboxItems($classifiedItems, $normalizedFilters['queue'])
        );
    }

    private function baseQuery(int $accountId, array $filters): Builder
    {
        return LeadRequest::query()
            ->where('user_id', $accountId)
            ->when(
                $filters['search'] ?? null,
                function (Builder $query, string $search): void {
                    $query->where(function (Builder $sub) use ($search): void {
                        $sub->where('title', 'like', '%'.$search.'%')
                            ->orWhere('service_type', 'like', '%'.$search.'%')
                            ->orWhere('description', 'like', '%'.$search.'%')
                            ->orWhere('contact_name', 'like', '%'.$search.'%')
                            ->orWhere('contact_email', 'like', '%'.$search.'%')
                            ->orWhere('contact_phone', 'like', '%'.$search.'%')
                            ->orWhere('external_customer_id', 'like', '%'.$search.'%');
                    });
                }
            )
            ->when(
                $filters['status'] ?? null,
                function (Builder $query, string $status): void {
                    if (! in_array($status, LeadRequest::STATUSES, true)) {
                        return;
                    }

                    $query->where('status', $status);
                }
            )
            ->when(
                $filters['customer_id'] ?? null,
                fn (Builder $query, mixed $customerId) => $query->where('customer_id', $customerId)
            );
    }

    private function classifyInboxItems(Collection $items, Carbon $referenceTime): Collection
    {
        return $items
            ->map(function (LeadRequest $lead) use ($referenceTime): LeadRequest {
                $classified = $this->classifier->classify($lead, $referenceTime);

                $lead->setAttribute('first_response_at', $classified['first_response_at']);
                $lead->setAttribute('last_activity_at', $classified['last_activity_at']);
                $lead->setAttribute('sla_due_at', $classified['sla_due_at']);
                $lead->setAttribute('triage_priority', $classified['triage_priority']);
                $lead->setAttribute('risk_level', $classified['risk_level']);
                $lead->setAttribute('stale_since_at', $classified['stale_since_at']);
                $lead->setAttribute('effective_due_at', $classified['effective_due_at']);
                $lead->setAttribute('days_since_activity', $classified['days_since_activity']);
                $lead->setAttribute('triage_queue', $classified['queue']);
                $lead->setAttribute('triage_is_open', $classified['is_open']);
                $lead->setAttribute('triage_is_new', $classified['is_new']);
                $lead->setAttribute('triage_is_due_soon', $classified['is_due_soon']);
                $lead->setAttribute('triage_is_stale', $classified['is_stale']);
                $lead->setAttribute('triage_is_breached', $classified['is_breached']);

                return $lead;
            })
            ->values();
    }

    private function filterInboxItems(Collection $items, ?string $queue): Collection
    {
        if ($queue === null) {
            return $items;
        }

        return $items
            ->filter(fn (LeadRequest $lead): bool => $lead->getAttribute('triage_queue') === $queue)
            ->values();
    }

    private function sortInboxItems(Collection $items): Collection
    {
        return $items
            ->sort(function (LeadRequest $left, LeadRequest $right): int {
                $queueComparison = $this->queueRank((string) $left->getAttribute('triage_queue'))
                    <=> $this->queueRank((string) $right->getAttribute('triage_queue'));
                if ($queueComparison !== 0) {
                    return $queueComparison;
                }

                $priorityComparison = ((int) $right->getAttribute('triage_priority'))
                    <=> ((int) $left->getAttribute('triage_priority'));
                if ($priorityComparison !== 0) {
                    return $priorityComparison;
                }

                $dueComparison = $this->compareNullableDates(
                    $left->getAttribute('effective_due_at'),
                    $right->getAttribute('effective_due_at')
                );
                if ($dueComparison !== 0) {
                    return $dueComparison;
                }

                $createdComparison = $this->compareNullableDates(
                    $left->created_at,
                    $right->created_at
                );
                if ($createdComparison !== 0) {
                    return $createdComparison;
                }

                return $left->id <=> $right->id;
            })
            ->values();
    }

    private function paginateInboxItems(Collection $items, Request $request, array $filters): LengthAwarePaginator
    {
        if (($filters['view'] ?? 'table') === 'board') {
            $perPage = max($items->count(), 1);

            return new LengthAwarePaginator(
                $items,
                $items->count(),
                $perPage,
                1,
                [
                    'path' => $request->url(),
                    'query' => $request->query(),
                ]
            );
        }

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

    private function statsForClassifiedItems(Collection $items): array
    {
        $openStatuses = [
            LeadRequest::STATUS_NEW,
            LeadRequest::STATUS_CALL_REQUESTED,
            LeadRequest::STATUS_CONTACTED,
            LeadRequest::STATUS_QUALIFIED,
            LeadRequest::STATUS_QUOTE_SENT,
        ];

        return [
            'total' => $items->count(),
            'new' => $items->where('status', LeadRequest::STATUS_NEW)->count(),
            'in_progress' => $items->whereIn('status', $openStatuses)->count(),
            'new_queue' => $items->filter(
                fn (LeadRequest $lead): bool => $lead->getAttribute('triage_queue') === LeadTriageClassifier::QUEUE_NEW
            )->count(),
            'won' => $items->where('status', LeadRequest::STATUS_WON)->count(),
            'lost' => $items->where('status', LeadRequest::STATUS_LOST)->count(),
            'unassigned' => $items->filter(fn (LeadRequest $lead): bool => blank($lead->assigned_team_member_id))->count(),
            'due_soon' => $items->filter(
                fn (LeadRequest $lead): bool => $lead->getAttribute('triage_queue') === LeadTriageClassifier::QUEUE_DUE_SOON
            )->count(),
            'stale' => $items->filter(
                fn (LeadRequest $lead): bool => $lead->getAttribute('triage_queue') === LeadTriageClassifier::QUEUE_STALE
            )->count(),
            'breached' => $items->filter(
                fn (LeadRequest $lead): bool => $lead->getAttribute('triage_queue') === LeadTriageClassifier::QUEUE_BREACHED
            )->count(),
        ];
    }

    private function normalizeQueueFilter(?string $queue): ?string
    {
        $normalized = is_string($queue) ? trim($queue) : null;

        return in_array($normalized, [
            LeadTriageClassifier::QUEUE_NEW,
            LeadTriageClassifier::QUEUE_DUE_SOON,
            LeadTriageClassifier::QUEUE_STALE,
            LeadTriageClassifier::QUEUE_BREACHED,
            LeadTriageClassifier::QUEUE_ACTIVE,
            LeadTriageClassifier::QUEUE_CLOSED,
        ], true)
            ? $normalized
            : null;
    }

    private function queueRank(string $queue): int
    {
        return match ($queue) {
            LeadTriageClassifier::QUEUE_BREACHED => 0,
            LeadTriageClassifier::QUEUE_DUE_SOON => 1,
            LeadTriageClassifier::QUEUE_NEW => 2,
            LeadTriageClassifier::QUEUE_STALE => 3,
            LeadTriageClassifier::QUEUE_ACTIVE => 4,
            default => 5,
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
