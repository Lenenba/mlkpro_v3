<?php

namespace App\Queries\Works;

use App\Models\Customer;
use App\Models\User;
use App\Models\Work;
use App\Services\TaskTimingService;
use App\Support\DataTablePagination;
use Illuminate\Http\Request;

class BuildWorkIndexData
{
    public function execute(?User $user, int $accountId, bool $isAccountOwner, Request $request): array
    {
        $filters = $request->only([
            'search',
            'status',
            'customer_id',
            'start_from',
            'start_to',
            'sort',
            'direction',
        ]);
        $filters['per_page'] = DataTablePagination::fromRequest($request);

        $baseQuery = Work::query()
            ->filter($filters)
            ->byUser($accountId);

        if (! $isAccountOwner) {
            $membership = $user?->teamMembership()->first();
            if ($membership) {
                $baseQuery->whereHas('teamMembers', fn ($query) => $query->whereKey($membership->id));
            } else {
                $baseQuery->whereRaw('1=0');
            }
        }

        $sort = in_array($filters['sort'] ?? null, ['start_date', 'created_at', 'status', 'total', 'job_title'], true)
            ? $filters['sort']
            : 'start_date';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $today = TaskTimingService::todayForAccountId($accountId);

        $works = (clone $baseQuery)
            ->with(['customer', 'invoice'])
            ->withAvg('ratings', 'rating')
            ->withCount('ratings')
            ->withCount([
                'tasks as overdue_tasks_count' => function ($query) use ($today) {
                    $query->whereNotNull('due_date')
                        ->where('status', '!=', 'done')
                        ->whereDate('due_date', '<', $today);
                },
            ])
            ->orderBy($sort, $direction)
            ->simplePaginate((int) $filters['per_page'])
            ->withQueryString();

        $scheduledStatuses = [Work::STATUS_TO_SCHEDULE, Work::STATUS_SCHEDULED];
        $inProgressStatuses = [Work::STATUS_EN_ROUTE, Work::STATUS_IN_PROGRESS];
        $completedStatuses = [
            Work::STATUS_TECH_COMPLETE,
            Work::STATUS_PENDING_REVIEW,
            Work::STATUS_VALIDATED,
            Work::STATUS_AUTO_VALIDATED,
            Work::STATUS_CLOSED,
            Work::STATUS_COMPLETED,
        ];

        $stats = [
            'total' => (clone $baseQuery)->count(),
            'scheduled' => (clone $baseQuery)->whereIn('status', $scheduledStatuses)->count(),
            'in_progress' => (clone $baseQuery)->whereIn('status', $inProgressStatuses)->count(),
            'completed' => (clone $baseQuery)->whereIn('status', $completedStatuses)->count(),
            'cancelled' => (clone $baseQuery)->where('status', Work::STATUS_CANCELLED)->count(),
        ];

        $customersQuery = Customer::byUser($accountId)->orderBy('company_name');
        if (! $isAccountOwner) {
            $customerIds = (clone $baseQuery)
                ->select('customer_id')
                ->distinct()
                ->pluck('customer_id');
            $customersQuery->whereIn('id', $customerIds);
        }

        $customers = $customersQuery->get(['id', 'company_name', 'first_name', 'last_name']);

        return [
            'works' => $works,
            'filters' => $filters,
            'stats' => $stats,
            'customers' => $customers,
        ];
    }
}
