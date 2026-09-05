<?php

namespace App\Queries\Tasks;

use App\Models\Product;
use App\Models\Request as LeadRequest;
use App\Models\Task;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Work;
use App\Services\CompanyFeatureService;
use App\Services\TaskTimingService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class BuildTaskIndexData
{
    private const BOARD_OPEN_TASKS_PER_STATUS = 250;

    private const BOARD_CLOSED_TASKS_PER_STATUS = 75;

    private const SCHEDULE_TASK_LIMIT = 500;

    private const SCHEDULE_ALL_TASK_LIMIT = 300;

    private const TEAM_ALL_TASK_LIMIT = 120;

    private const API_TASKS_PER_PAGE = 100;

    private const SCHEDULE_RANGES = ['week', '2weeks', 'month', 'all'];

    public function execute(?User $user, int $accountId, bool $isOwner, Request $request): array
    {
        $hasTeamMembersFeature = $user
            ? app(CompanyFeatureService::class)->hasFeature($user, 'team_members')
            : false;

        $filters = $request->only([
            'search',
            'status',
            'priority',
            'follow_up',
            'view',
            'range',
        ]);
        $filters['status'] = $this->normalizeStatusFilter($filters['status'] ?? null);
        $filters['priority'] = $this->normalizePriorityFilter($filters['priority'] ?? null);
        $filters['follow_up'] = $this->normalizeFollowUpFilter($filters['follow_up'] ?? null);
        $filters['range'] = $this->normalizeScheduleRange($filters['range'] ?? null);
        $allowedViews = $isOwner && $hasTeamMembersFeature
            ? ['board', 'schedule', 'team']
            : ['board', 'schedule'];
        $filters['view'] = in_array($filters['view'] ?? null, $allowedViews, true)
            ? $filters['view']
            : 'board';
        $today = now(TaskTimingService::resolveTimezoneForAccountId($accountId))->toDateString();

        $membership = $user && $user->id !== $accountId
            ? $user->teamMembership()->first()
            : null;
        $isAdminMember = $membership && $membership->role === 'admin';

        $query = Task::query()
            ->forAccount($accountId)
            ->with([
                'assignee.user:id,name',
                'materials.product:id,name,unit,price',
                'request:id,title,contact_name,status',
            ])
            ->when(
                $filters['search'] ?? null,
                fn ($query, $search) => $query->where(function ($sub) use ($search) {
                    $sub->where('title', 'like', '%'.$search.'%')
                        ->orWhere('description', 'like', '%'.$search.'%')
                        ->orWhereHas('request', function ($requestQuery) use ($search) {
                            $requestQuery->where('title', 'like', '%'.$search.'%')
                                ->orWhere('contact_name', 'like', '%'.$search.'%');
                        });
                })
            )
            ->when(
                $filters['status'] ?? null,
                fn ($query, $status) => in_array($status, Task::STATUSES, true)
                    ? $query->where('status', $status)
                    : null
            )
            ->when(
                $filters['priority'] ?? null,
                fn ($query, $priority) => in_array($priority, Task::PRIORITIES, true)
                    ? $query->where('priority', $priority)
                    : null
            )
            ->when(
                $filters['follow_up'] ?? null,
                function ($query, $followUp) use ($today) {
                    if ($followUp === 'today') {
                        return $query->dueToday($today);
                    }

                    if ($followUp === 'overdue') {
                        return $query->overdue($today);
                    }

                    return null;
                }
            );

        if ($membership && $membership->role !== 'admin') {
            $query->where('assigned_team_member_id', $membership->id);
        }

        $totalCount = (clone $query)->count();
        $stats = [
            'total' => $totalCount,
            'todo' => (clone $query)->where('status', 'todo')->count(),
            'in_progress' => (clone $query)->where('status', 'in_progress')->count(),
            'done' => (clone $query)->where('status', 'done')->count(),
            'cancelled' => (clone $query)->where('status', Task::STATUS_CANCELLED)->count(),
        ];

        $view = $filters['view'];
        $taskWindow = $this->buildTaskWindow(
            query: $query,
            view: $view,
            status: $filters['status'],
            range: $filters['range'],
            accountId: $accountId,
            stats: $stats,
            request: $request,
            hasTeamMembersFeature: $hasTeamMembersFeature,
        );

        $canManage = $user
            ? ($user->id === $accountId || ($isAdminMember && $membership->hasPermission('tasks.edit')))
            : false;

        $canEditStatus = $user
            ? ($user->id === $accountId || ($membership && $membership->hasPermission('tasks.edit')))
            : false;

        $canDelete = $user
            ? ($user->id === $accountId || ($isAdminMember && $membership->hasPermission('tasks.delete')))
            : false;

        $teamMembers = collect();
        if (
            $hasTeamMembersFeature
            && $user
            && ($user->id === $accountId || ($isAdminMember && ($membership->hasPermission('tasks.create') || $membership->hasPermission('tasks.edit'))))
        ) {
            $teamMembers = TeamMember::query()
                ->forAccount($accountId)
                ->active()
                ->with('user:id,name')
                ->orderBy('created_at')
                ->get(['id', 'user_id', 'role']);
        }
        $materialProducts = Product::query()
            ->products()
            ->byUser($accountId)
            ->orderBy('name')
            ->get(['id', 'name', 'unit', 'price']);

        $works = Work::query()
            ->byUser($accountId)
            ->with('customer:id,company_name,first_name,last_name')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get(['id', 'job_title', 'number', 'customer_id', 'status']);

        $prospects = LeadRequest::query()
            ->byUser($accountId)
            ->active()
            ->orderByDesc('last_activity_at')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get(['id', 'title', 'contact_name', 'status']);

        return [
            'tasks' => $taskWindow['tasks'],
            'taskWindow' => $taskWindow['meta'],
            'filters' => $filters,
            'statuses' => Task::STATUSES,
            'priorities' => Task::PRIORITIES,
            'teamMembers' => $teamMembers,
            'stats' => $stats,
            'count' => $totalCount,
            'materialProducts' => $materialProducts,
            'works' => $works,
            'prospects' => $prospects,
            'canManage' => $canManage,
            'canDelete' => $canDelete,
            'canEditStatus' => $canEditStatus,
            'canViewTeam' => $isOwner && $hasTeamMembersFeature,
        ];
    }

    /**
     * @param  Builder<Task>  $query
     * @param  array{total: int, todo: int, in_progress: int, done: int, cancelled: int}  $stats
     * @return array{
     *     tasks: LengthAwarePaginator<int, Task>,
     *     meta: array{
     *         loaded_count: int,
     *         available_count: int,
     *         matching_count: int,
     *         truncated: bool,
     *         limit: int,
     *         range: string,
     *         range_start: string|null,
     *         range_end: string|null,
     *         status_counts: array{total: int, todo: int, in_progress: int, done: int, cancelled: int}
     *     }
     * }
     */
    private function buildTaskWindow(
        Builder $query,
        string $view,
        ?string $status,
        string $range,
        int $accountId,
        array $stats,
        Request $request,
        bool $hasTeamMembersFeature,
    ): array {
        $rangeStart = null;
        $rangeEnd = null;
        $windowQuery = clone $query;

        if (in_array($view, ['schedule', 'team'], true) && $range !== 'all') {
            [$rangeStart, $rangeEnd] = $this->scheduleRangeBounds($range, $accountId);
            $rangeEndExclusive = Carbon::parse($rangeEnd)->addDay()->toDateString();
            $windowQuery
                ->where('due_date', '>=', $rangeStart)
                ->where('due_date', '<', $rangeEndExclusive);
        }

        $availableCount = (clone $windowQuery)->count();

        if ($request->is('api/*')) {
            $limit = min(max($request->integer('per_page', self::API_TASKS_PER_PAGE), 1), self::API_TASKS_PER_PAGE);
            $tasks = $this->orderTasks($windowQuery, $status)
                ->paginate($limit)
                ->withQueryString()
                ->through(fn (Task $task) => $this->sanitizeTaskAssignments($task, $hasTeamMembersFeature));
            $loadedCount = $tasks->count();
        } elseif ($view === 'board' && $status === null) {
            $items = collect();
            $limit = 0;

            foreach (Task::STATUSES as $taskStatus) {
                $statusLimit = $this->boardStatusLimit($taskStatus);
                $limit += $statusLimit;
                $statusTasks = $this->orderTasks(
                    (clone $windowQuery)->where('status', $taskStatus),
                    $taskStatus,
                )
                    ->limit($statusLimit)
                    ->get();
                $items = $items->concat($statusTasks);
            }
        } else {
            $limit = $this->taskWindowLimit($view, $status, $range);
            $items = $this->orderTasks(
                $windowQuery,
                $range === 'all' && in_array($view, ['schedule', 'team'], true) ? null : $status,
                $range === 'all' && in_array($view, ['schedule', 'team'], true),
            )
                ->limit($limit)
                ->get();
        }

        if (! isset($tasks)) {
            $items = $items
                ->map(fn (Task $task) => $this->sanitizeTaskAssignments($task, $hasTeamMembersFeature))
                ->values();
            $loadedCount = $items->count();
            $tasks = new LengthAwarePaginator(
                $items,
                $loadedCount,
                max($loadedCount, 1),
                1,
                [
                    'path' => $request->url(),
                    'query' => $request->query(),
                ]
            );
        }

        return [
            'tasks' => $tasks,
            'meta' => [
                'loaded_count' => $loadedCount,
                'available_count' => $availableCount,
                'matching_count' => $stats['total'],
                'truncated' => $availableCount > $loadedCount,
                'limit' => $limit,
                'range' => $range,
                'range_start' => $rangeStart,
                'range_end' => $rangeEnd,
                'status_counts' => $stats,
            ],
        ];
    }

    /** @param Builder<Task> $query */
    private function orderTasks(Builder $query, ?string $status, bool $openTasksFirst = false): Builder
    {
        if ($openTasksFirst) {
            return $query
                ->orderByRaw("CASE WHEN status IN ('todo', 'in_progress') THEN 0 ELSE 1 END")
                ->orderByRaw("CASE WHEN status IN ('todo', 'in_progress') AND due_date IS NULL THEN 1 ELSE 0 END")
                ->orderByRaw("CASE WHEN status IN ('todo', 'in_progress') THEN due_date END")
                ->orderByRaw("CASE WHEN status IN ('todo', 'in_progress') THEN CASE priority
                    WHEN 'urgent' THEN 0
                    WHEN 'high' THEN 1
                    WHEN 'normal' THEN 2
                    WHEN 'low' THEN 3
                    ELSE 2
                END END")
                ->orderByRaw("CASE WHEN status IN ('done', 'cancelled') THEN COALESCE(completed_at, cancelled_at, updated_at) END DESC")
                ->orderByDesc('id');
        }

        if (in_array($status, Task::CLOSED_STATUSES, true)) {
            $closedAtColumn = $status === Task::STATUS_DONE ? 'completed_at' : 'cancelled_at';

            return $query
                ->orderByDesc($closedAtColumn)
                ->orderByDesc('updated_at')
                ->orderByDesc('id');
        }

        return $query
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_date')
            ->orderByRaw("CASE priority
                WHEN 'urgent' THEN 0
                WHEN 'high' THEN 1
                WHEN 'normal' THEN 2
                WHEN 'low' THEN 3
                ELSE 2
            END")
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    private function boardStatusLimit(?string $status): int
    {
        return in_array($status, Task::CLOSED_STATUSES, true)
            ? self::BOARD_CLOSED_TASKS_PER_STATUS
            : self::BOARD_OPEN_TASKS_PER_STATUS;
    }

    private function taskWindowLimit(string $view, ?string $status, string $range): int
    {
        if ($view === 'board') {
            return $this->boardStatusLimit($status);
        }

        if ($range !== 'all') {
            return self::SCHEDULE_TASK_LIMIT;
        }

        return $view === 'team'
            ? self::TEAM_ALL_TASK_LIMIT
            : self::SCHEDULE_ALL_TASK_LIMIT;
    }

    /** @return array{0: string, 1: string} */
    private function scheduleRangeBounds(string $range, int $accountId): array
    {
        $today = now(TaskTimingService::resolveTimezoneForAccountId($accountId));

        if ($range === 'month') {
            return [
                $today->copy()->startOfMonth()->toDateString(),
                $today->copy()->endOfMonth()->toDateString(),
            ];
        }

        $start = $today->copy()->startOfWeek(Carbon::MONDAY);
        $days = $range === '2weeks' ? 13 : 6;

        return [
            $start->toDateString(),
            $start->copy()->addDays($days)->toDateString(),
        ];
    }

    private function sanitizeTaskAssignments(Task $task, bool $hasTeamMembersFeature): Task
    {
        if ($hasTeamMembersFeature) {
            return $task;
        }

        $task->setAttribute('assigned_team_member_id', null);
        $task->setRelation('assignee', null);

        return $task;
    }

    private function normalizePriorityFilter(mixed $priority): ?string
    {
        $normalized = is_string($priority) ? trim(strtolower($priority)) : null;

        return in_array($normalized, Task::PRIORITIES, true) ? $normalized : null;
    }

    private function normalizeStatusFilter(mixed $status): ?string
    {
        $normalized = is_string($status) ? trim(strtolower($status)) : null;

        return in_array($normalized, Task::STATUSES, true) ? $normalized : null;
    }

    private function normalizeFollowUpFilter(mixed $followUp): ?string
    {
        $normalized = is_string($followUp) ? trim(strtolower($followUp)) : null;

        return in_array($normalized, ['today', 'overdue'], true) ? $normalized : null;
    }

    private function normalizeScheduleRange(mixed $range): string
    {
        $normalized = is_string($range) ? trim(strtolower($range)) : null;

        return in_array($normalized, self::SCHEDULE_RANGES, true) ? $normalized : 'week';
    }
}
