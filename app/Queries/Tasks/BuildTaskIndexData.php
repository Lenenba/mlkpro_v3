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
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class BuildTaskIndexData
{
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
        ]);
        $filters['priority'] = $this->normalizePriorityFilter($filters['priority'] ?? null);
        $filters['follow_up'] = $this->normalizeFollowUpFilter($filters['follow_up'] ?? null);
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

        $tasksQuery = $query
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_date')
            ->orderByRaw("CASE priority
                WHEN 'urgent' THEN 0
                WHEN 'high' THEN 1
                WHEN 'normal' THEN 2
                WHEN 'low' THEN 3
                ELSE 2
            END")
            ->orderByDesc('created_at');

        $view = $filters['view'];
        $useFullList = in_array($view, ['board', 'schedule', 'team'], true);

        if ($useFullList) {
            $items = $tasksQuery
                ->get()
                ->map(fn (Task $task) => $this->sanitizeTaskAssignments($task, $hasTeamMembersFeature));
            $perPage = max($items->count(), 1);
            $tasks = new LengthAwarePaginator(
                $items,
                $items->count(),
                $perPage,
                1,
                [
                    'path' => $request->url(),
                    'query' => $request->query(),
                ]
            );
        } else {
            $tasks = $tasksQuery
                ->simplePaginate(15)
                ->through(fn (Task $task) => $this->sanitizeTaskAssignments($task, $hasTeamMembersFeature))
                ->withQueryString();
        }

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
            'tasks' => $tasks,
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

    private function sanitizeTaskAssignments(Task $task, bool $hasTeamMembersFeature): Task
    {
        if ($hasTeamMembersFeature) {
            return $task;
        }

        $task->setAttribute('assigned_team_member_id', null);
        $task->setRelation('assignee', null);

        return $task;
    }

    private function normalizePriorityFilter(?string $priority): ?string
    {
        $normalized = is_string($priority) ? trim(strtolower($priority)) : null;

        return in_array($normalized, Task::PRIORITIES, true) ? $normalized : null;
    }

    private function normalizeFollowUpFilter(?string $followUp): ?string
    {
        $normalized = is_string($followUp) ? trim(strtolower($followUp)) : null;

        return in_array($normalized, ['today', 'overdue'], true) ? $normalized : null;
    }
}
