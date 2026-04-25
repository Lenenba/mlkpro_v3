<?php

namespace App\Http\Controllers;

use App\Actions\Tasks\AssignTaskAction;
use App\Http\Requests\Tasks\AssignTaskRequest;
use App\Http\Requests\Tasks\StoreTaskRequest;
use App\Http\Requests\Tasks\UpdateTaskRequest;
use App\Models\Product;
use App\Models\Request as LeadRequest;
use App\Models\Task;
use App\Models\TaskMaterial;
use App\Models\TeamMember;
use App\Models\Work;
use App\Queries\Tasks\BuildTaskIndexData;
use App\Services\CompanyFeatureService;
use App\Services\InventoryService;
use App\Services\TaskLifecycleNotificationService;
use App\Services\TaskStatusHistoryService;
use App\Services\TaskTimingService;
use App\Services\UsageLimitService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class TaskController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();
        $isOwner = $user && $user->id === $accountId;

        $this->authorize('viewAny', Task::class);

        $props = app(BuildTaskIndexData::class)->execute($user, $accountId, $isOwner, $request);
        $props['canCreate'] = $user ? $user->can('create', Task::class) : false;

        return $this->inertiaOrJson('Task/Index', $props);
    }

    public function show(Request $request, Task $task)
    {
        $this->authorize('view', $task);

        $user = Auth::user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();
        $hasTeamMembersFeature = $user
            ? app(CompanyFeatureService::class)->hasFeature($user, 'team_members')
            : false;

        if ($task->account_id !== $accountId) {
            abort(404);
        }

        $membership = $user && $user->id !== $accountId
            ? $user->teamMembership()->first()
            : null;
        $isAdminMember = $membership && $membership->role === 'admin';

        if ($membership && $membership->role !== 'admin' && $task->assigned_team_member_id !== $membership->id) {
            abort(403);
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

        $task->load([
            'assignee.user:id,name',
            'materials.product:id,name,unit,price',
            'media.user:id,name',
            'work.quote.property',
            'customer.properties',
            'customer.defaultProperty',
            'statusHistories.user:id,name',
        ]);

        if ($task->relationLoaded('media')) {
            $mediaPayload = $task->media
                ->sortByDesc('created_at')
                ->values()
                ->map(function ($media) {
                    $path = $media->path;
                    $url = $path
                        ? (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')
                            ? $path
                            : Storage::disk('public')->url($path))
                        : null;

                    $source = $media->meta['source'] ?? null;
                    $uploadedBy = $source === 'client-public' ? null : $media->user?->name;

                    return [
                        'id' => $media->id,
                        'type' => $media->type,
                        'media_type' => $media->media_type,
                        'path' => $path,
                        'url' => $url,
                        'note' => $media->meta['note'] ?? null,
                        'source' => $source,
                        'uploaded_by' => $uploadedBy,
                        'uploaded_at' => $media->created_at,
                    ];
                });

            $task->setRelation('media', $mediaPayload);
        }

        $property = $task->work?->quote?->property
            ?? $task->customer?->defaultProperty
            ?? $task->customer?->properties?->first();

        $location = $property
            ? [
                'id' => $property->id,
                'type' => $property->type,
                'address' => $property->full_address,
                'street1' => $property->street1,
                'street2' => $property->street2,
                'city' => $property->city,
                'state' => $property->state,
                'zip' => $property->zip,
                'country' => $property->country,
            ]
            : null;

        $task->setAttribute('location', $location);

        if (! $hasTeamMembersFeature) {
            $this->sanitizeTaskAssignments($task);
        }

        $teamMembers = collect();
        if ($canManage && $hasTeamMembersFeature) {
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

        return $this->inertiaOrJson('Task/Show', [
            'task' => $task,
            'statuses' => Task::STATUSES,
            'teamMembers' => $teamMembers,
            'materialProducts' => $materialProducts,
            'works' => $works,
            'canManage' => $canManage,
            'canEditStatus' => $canEditStatus,
            'canDelete' => $canDelete,
        ]);
    }

    public function store(StoreTaskRequest $request)
    {
        $this->authorize('create', Task::class);

        $user = Auth::user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();
        $hasTeamMembersFeature = $user
            ? app(CompanyFeatureService::class)->hasFeature($user, 'team_members')
            : false;

        if ($user) {
            app(UsageLimitService::class)->enforceLimit($user, 'tasks');
        }

        $validated = $request->validated();
        if (! $hasTeamMembersFeature) {
            $validated['assigned_team_member_id'] = null;
        }

        $work = null;
        $workId = $validated['work_id'] ?? null;
        if ($workId) {
            $work = Work::query()->where('user_id', $accountId)->find($workId);
        }

        $lead = null;
        $requestId = $validated['request_id'] ?? null;
        if ($requestId) {
            $lead = LeadRequest::query()
                ->where('user_id', $accountId)
                ->find($requestId);

            if ($lead) {
                $this->ensureLeadIsMutable(
                    $lead,
                    'request_id',
                    'Archived prospects must be restored before they can receive new tasks.'
                );
            }
        }

        $status = $validated['status'] ?? Task::STATUS_TODO;
        $isDone = $status === Task::STATUS_DONE;
        $isCancelled = $status === Task::STATUS_CANCELLED;
        $timezone = TaskTimingService::resolveTimezoneForAccountId($accountId);
        $dueDateValue = $validated['due_date'] ?? null;
        $dueDate = $dueDateValue ? Carbon::parse($dueDateValue, $timezone)->startOfDay() : null;
        $startTime = $work?->start_time;
        $endTime = $work?->end_time;
        if (! $work) {
            $startTime = $this->normalizeTime($validated['start_time'] ?? null);
            $endTime = $this->normalizeTime($validated['end_time'] ?? null);
        }

        if ($status === Task::STATUS_IN_PROGRESS && $dueDate && TaskTimingService::isDueDateInFuture($dueDate, Carbon::now($timezone))) {
            return $this->validationErrorResponse(
                $request,
                'status',
                'This task cannot be marked in progress before its scheduled date.'
            );
        }

        $completedAt = TaskTimingService::normalizeCompletedAt($validated['completed_at'] ?? null, $timezone);
        if ($isDone && ! $completedAt) {
            $completedAt = now();
        }

        if ($isDone && TaskTimingService::shouldRequireCompletionReason($dueDate, $completedAt)
            && empty($validated['completion_reason'])) {
            return $this->validationErrorResponse(
                $request,
                'completion_reason',
                'A completion reason is required when the actual date differs from the planned date.'
            );
        }

        $cancellationReason = $this->normalizeOptionalText($validated['cancellation_reason'] ?? null);
        $cancelledAt = TaskTimingService::normalizeCompletedAt($validated['cancelled_at'] ?? null, $timezone);
        if ($isCancelled && ! $cancelledAt) {
            $cancelledAt = now();
        }

        if ($isCancelled && ! $cancellationReason) {
            return $this->validationErrorResponse(
                $request,
                'cancellation_reason',
                'A cancellation reason is required when a task is cancelled.'
            );
        }

        $isLateOpenTask = in_array($status, Task::OPEN_STATUSES, true)
            && $dueDate
            && $dueDate->lt(Carbon::now($timezone)->startOfDay());
        $delayReason = $isLateOpenTask
            ? $this->normalizeOptionalText($validated['delay_reason'] ?? null)
            : null;

        $conflictTask = $this->findScheduleConflict(
            $accountId,
            $validated['assigned_team_member_id'] ?? null,
            $validated['due_date'] ?? null,
            $startTime,
            $endTime
        );
        if ($conflictTask) {
            return $this->scheduleConflictResponse($request, $conflictTask);
        }

        $task = Task::create([
            'account_id' => $accountId,
            'created_by_user_id' => Auth::id(),
            'assigned_team_member_id' => $validated['assigned_team_member_id'] ?? null,
            'customer_id' => $work ? $work->customer_id : ($validated['customer_id'] ?? $lead?->customer_id),
            'product_id' => $validated['product_id'] ?? null,
            'work_id' => $work?->id,
            'request_id' => $lead?->id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'status' => $status,
            'due_date' => $validated['due_date'] ?? null,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'completed_at' => $isDone ? $completedAt : null,
            'cancelled_at' => $isCancelled ? $cancelledAt : null,
            'completion_reason' => $isDone ? ($validated['completion_reason'] ?? null) : null,
            'cancellation_reason' => $isCancelled ? $cancellationReason : null,
            'delay_reason' => $delayReason,
            'delay_started_at' => $isLateOpenTask ? now() : null,
        ]);

        if ($request->has('materials')) {
            $this->syncTaskMaterials($task, $validated['materials'] ?? [], $accountId, false, $user);
        }

        if ($status === 'in_progress') {
            $this->applyMaterialStock($task, Auth::user());
        }

        app(TaskStatusHistoryService::class)->record($task, $user, [
            'from_status' => null,
            'to_status' => $task->status,
            'action' => $task->isCancelled() ? 'cancelled' : 'created',
            'reason_code' => $task->completion_reason,
            'note' => $task->cancellation_reason ?: $task->delay_reason,
            'metadata' => [
                'delay_reason' => $task->delay_reason,
                'cancellation_reason' => $task->cancellation_reason,
            ],
        ]);

        if ($task->isCancelled()) {
            app(TaskLifecycleNotificationService::class)->sendCancelled($task, $user);
        }

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Task created.',
                'task' => $task->fresh(['assignee.user', 'materials.product']),
            ], 201);
        }

        return redirect()->back()->with('success', 'Task created.');
    }

    public function update(UpdateTaskRequest $request, Task $task)
    {
        $this->authorize('update', $task);

        $user = Auth::user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();
        $hasTeamMembersFeature = $user
            ? app(CompanyFeatureService::class)->hasFeature($user, 'team_members')
            : false;

        if ($task->isClosed()) {
            return $this->lockedTaskResponse($request, $task);
        }

        $task->loadMissing('request');
        if ($task->request) {
            $this->ensureLeadIsMutable(
                $task->request,
                'task',
                'Tasks linked to archived prospects are read-only until the prospect is restored.'
            );
        }

        $isManager = $request->isManager();
        $validated = $request->validated();
        if ($isManager && ! $hasTeamMembersFeature) {
            $validated['assigned_team_member_id'] = null;
        }

        if ($isManager && array_key_exists('request_id', $validated) && $validated['request_id']) {
            $nextLead = LeadRequest::query()
                ->where('user_id', $accountId)
                ->find($validated['request_id']);

            if ($nextLead) {
                $this->ensureLeadIsMutable(
                    $nextLead,
                    'request_id',
                    'Archived prospects must be restored before they can receive tasks.'
                );
            }
        }

        $updates = [
            'status' => $validated['status'],
        ];

        $startTime = $task->start_time;
        $endTime = $task->end_time;
        if ($isManager) {
            $updates['title'] = $validated['title'];
            $updates['description'] = $validated['description'] ?? null;
            $updates['due_date'] = $validated['due_date'] ?? null;
            $updates['assigned_team_member_id'] = $validated['assigned_team_member_id'] ?? null;
            $updates['product_id'] = $validated['product_id'] ?? null;
            $updates['request_id'] = $validated['request_id'] ?? null;

            $work = null;
            $workId = $validated['work_id'] ?? null;
            if ($workId) {
                $work = Work::query()->where('user_id', $accountId)->find($workId);
            }

            if ($work) {
                $updates['work_id'] = $work->id;
                $updates['customer_id'] = $work->customer_id;
                $startTime = $work->start_time;
                $endTime = $work->end_time;
            } else {
                $updates['work_id'] = null;
                $updates['customer_id'] = $validated['customer_id'] ?? null;
                if (array_key_exists('start_time', $validated) || array_key_exists('end_time', $validated)) {
                    $startTime = $this->normalizeTime($validated['start_time'] ?? null);
                    $endTime = $this->normalizeTime($validated['end_time'] ?? null);
                }
            }

            $updates['start_time'] = $startTime;
            $updates['end_time'] = $endTime;
        }

        if ($isManager) {
            $assignedId = $updates['assigned_team_member_id'] ?? $task->assigned_team_member_id;
            $dueDate = array_key_exists('due_date', $updates)
                ? $updates['due_date']
                : ($task->due_date ? $task->due_date->toDateString() : null);

            $conflictTask = $this->findScheduleConflict(
                $accountId,
                $assignedId,
                $dueDate,
                $startTime,
                $endTime,
                $task->id
            );
            if ($conflictTask) {
                return $this->scheduleConflictResponse($request, $conflictTask);
            }
        }

        $wasInProgress = $task->status === Task::STATUS_IN_PROGRESS;
        $wasDone = $task->status === Task::STATUS_DONE;
        $previousDelayStartedAt = $task->delay_started_at?->toDateTimeString();
        $isDone = $updates['status'] === Task::STATUS_DONE;
        $isCancelled = $updates['status'] === Task::STATUS_CANCELLED;

        $timezone = TaskTimingService::resolveTimezoneForAccountId($accountId);
        $dueDateValue = array_key_exists('due_date', $updates)
            ? $updates['due_date']
            : ($task->due_date ? $task->due_date->toDateString() : null);
        $dueDate = $dueDateValue ? Carbon::parse($dueDateValue, $timezone)->startOfDay() : null;

        if ($updates['status'] === Task::STATUS_IN_PROGRESS && $dueDate && TaskTimingService::isDueDateInFuture($dueDate, Carbon::now($timezone))) {
            return $this->validationErrorResponse(
                $request,
                'status',
                'This task cannot be marked in progress before its scheduled date.'
            );
        }

        $completionReason = $validated['completion_reason'] ?? null;
        $completedAt = TaskTimingService::normalizeCompletedAt($validated['completed_at'] ?? null, $timezone);
        if ($isDone && ! $completedAt) {
            $completedAt = now();
        }

        if ($isDone && TaskTimingService::shouldRequireCompletionReason($dueDate, $completedAt)
            && empty($completionReason)) {
            return $this->validationErrorResponse(
                $request,
                'completion_reason',
                'A completion reason is required when the actual date differs from the planned date.'
            );
        }

        $cancellationReason = $this->normalizeOptionalText($validated['cancellation_reason'] ?? null);
        $cancelledAt = TaskTimingService::normalizeCompletedAt($validated['cancelled_at'] ?? null, $timezone);
        if ($isCancelled && ! $cancelledAt) {
            $cancelledAt = now();
        }

        if ($isCancelled && ! $cancellationReason) {
            return $this->validationErrorResponse(
                $request,
                'cancellation_reason',
                'A cancellation reason is required when a task is cancelled.'
            );
        }

        $delayReasonProvided = array_key_exists('delay_reason', $validated);
        $delayReason = $delayReasonProvided
            ? $this->normalizeOptionalText($validated['delay_reason'] ?? null)
            : $task->delay_reason;

        if ($isDone) {
            $updates['completed_at'] = $completedAt;
            $updates['completion_reason'] = $completionReason;
            $updates['cancelled_at'] = null;
            $updates['cancellation_reason'] = null;
            $updates['delay_started_at'] = null;
            $updates['delay_reason'] = $delayReason;
        } elseif ($isCancelled) {
            $updates['completed_at'] = null;
            $updates['completion_reason'] = null;
            $updates['cancelled_at'] = $cancelledAt;
            $updates['cancellation_reason'] = $cancellationReason;
            $updates['delay_started_at'] = null;
            $updates['delay_reason'] = $delayReason;
        } else {
            $updates['completed_at'] = null;
            $updates['completion_reason'] = null;
            $updates['cancelled_at'] = null;
            $updates['cancellation_reason'] = null;
            if ($dueDate && $dueDate->lt(Carbon::now($timezone)->startOfDay())) {
                $updates['delay_started_at'] = $task->delay_started_at ?? now();
                $updates['delay_reason'] = $delayReason;
            } else {
                $updates['delay_started_at'] = null;
                $updates['delay_reason'] = null;
            }
        }

        $previousStatus = $task->status;
        $statusChanged = $previousStatus !== $updates['status'];
        $completedAtChanged = ($task->completed_at?->toDateTimeString() ?? null) !== ($updates['completed_at']?->toDateTimeString() ?? null);
        $completionReasonChanged = ($task->completion_reason ?? null) !== ($updates['completion_reason'] ?? null);
        $cancelledAtChanged = ($task->cancelled_at?->toDateTimeString() ?? null) !== ($updates['cancelled_at']?->toDateTimeString() ?? null);
        $cancellationReasonChanged = ($task->cancellation_reason ?? null) !== ($updates['cancellation_reason'] ?? null);
        $delayReasonChanged = ($task->delay_reason ?? null) !== ($updates['delay_reason'] ?? null);
        $delayStartedAtChanged = $previousDelayStartedAt !== ($updates['delay_started_at']?->toDateTimeString() ?? null);

        $task->update($updates);

        if ($isManager && $request->has('materials')) {
            $this->syncTaskMaterials($task, $validated['materials'] ?? [], $accountId, $wasInProgress, $user);
        }

        if (! $wasInProgress && $updates['status'] === Task::STATUS_IN_PROGRESS) {
            $this->applyMaterialStock($task, $user);
        }

        if (! $wasDone && $isDone) {
            app(\App\Services\TaskBillingService::class)
                ->handleTaskCompleted($task, $user);
        }

        if ($statusChanged || $completedAtChanged || $completionReasonChanged || $cancelledAtChanged || $cancellationReasonChanged || $delayReasonChanged || $delayStartedAtChanged) {
            $historyAction = 'manual';
            if ($task->isCancelled() && $previousStatus !== Task::STATUS_CANCELLED) {
                $historyAction = 'cancelled';
            } elseif ($delayReasonChanged && ! $statusChanged && $task->isOpen()) {
                $historyAction = 'delay_reason_updated';
            }

            app(TaskStatusHistoryService::class)->record($task, $user, [
                'from_status' => $previousStatus,
                'to_status' => $task->status,
                'action' => $historyAction,
                'reason_code' => $task->completion_reason,
                'note' => $task->cancellation_reason ?: ($delayReasonChanged ? $task->delay_reason : null),
                'metadata' => [
                    'delay_reason' => $task->delay_reason,
                    'cancellation_reason' => $task->cancellation_reason,
                ],
            ]);
        }

        if ($task->isCancelled() && $previousStatus !== Task::STATUS_CANCELLED) {
            app(TaskLifecycleNotificationService::class)->sendCancelled($task, $user);
        } elseif (! $previousDelayStartedAt && $task->delay_started_at) {
            app(TaskLifecycleNotificationService::class)->sendOverdue($task, $user);
        }

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Task updated.',
                'task' => $task->fresh(['assignee.user', 'materials.product']),
            ]);
        }

        return redirect()->back()->with('success', 'Task updated.');
    }

    public function assign(AssignTaskRequest $request, Task $task, AssignTaskAction $assignTask)
    {
        $this->authorize('update', $task);

        $user = Auth::user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();
        $hasTeamMembersFeature = $user
            ? app(CompanyFeatureService::class)->hasFeature($user, 'team_members')
            : false;

        if ($task->account_id !== $accountId) {
            abort(404);
        }

        $task->loadMissing('request');
        if ($task->request) {
            $this->ensureLeadIsMutable(
                $task->request,
                'task',
                'Tasks linked to archived prospects are read-only until the prospect is restored.'
            );
        }

        $validated = $request->validated();
        $nextAssigneeId = $hasTeamMembersFeature
            && isset($validated['assigned_team_member_id']) && $validated['assigned_team_member_id']
                ? (int) $validated['assigned_team_member_id']
                : null;

        $task = $assignTask->execute($task, $nextAssigneeId, $user);

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Task assignee updated.',
                'task' => $task->fresh(['assignee.user']),
            ]);
        }

        return redirect()->back();
    }

    public function destroy(Task $task)
    {
        $this->authorize('delete', $task);

        $task->loadMissing('request');
        if ($task->request) {
            $this->ensureLeadIsMutable(
                $task->request,
                'task',
                'Tasks linked to archived prospects are read-only until the prospect is restored.'
            );
        }

        if ($task->isCancelled()) {
            return $this->validationErrorResponse(
                request(),
                'task',
                'Cancelled tasks are kept for history and cannot be deleted.'
            );
        }

        $task->delete();

        if ($this->shouldReturnJson()) {
            return response()->json([
                'message' => 'Task deleted.',
            ]);
        }

        return redirect()->back()->with('success', 'Task deleted.');
    }

    private function syncTaskMaterials(Task $task, array $materials, int $accountId, bool $stockAlreadyMoved, $actor = null): void
    {
        $existingUsage = $stockAlreadyMoved
            ? $task->materials()
                ->whereNotNull('product_id')
                ->selectRaw('product_id, SUM(quantity) as quantity')
                ->groupBy('product_id')
                ->pluck('quantity', 'product_id')
            : collect();

        $materialProductIds = collect($materials)
            ->pluck('product_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $productIds = $stockAlreadyMoved
            ? $materialProductIds
                ->merge($existingUsage->keys()->map(fn ($id) => (int) $id))
                ->unique()
                ->values()
            : $materialProductIds;

        $productMap = $productIds->isNotEmpty()
            ? Product::query()
                ->products()
                ->byUser($accountId)
                ->whereIn('id', $productIds)
                ->get()
                ->keyBy('id')
            : collect();

        $payload = collect($materials)
            ->map(function ($material, $index) use ($productMap, $stockAlreadyMoved) {
                $productId = isset($material['product_id']) ? (int) $material['product_id'] : null;
                $product = $productId ? $productMap->get($productId) : null;
                $label = trim((string) ($material['label'] ?? ''));
                if (! $label && $product) {
                    $label = $product->name;
                }

                if (! $label) {
                    return null;
                }

                $quantity = isset($material['quantity']) ? (float) $material['quantity'] : 1;
                $unitPrice = isset($material['unit_price'])
                    ? (float) $material['unit_price']
                    : (float) ($product?->price ?? 0);

                $stockMovedAt = null;
                if ($stockAlreadyMoved && $product) {
                    $stockMovedAt = now();
                }

                return [
                    'product_id' => $product?->id,
                    'warehouse_id' => isset($material['warehouse_id']) ? (int) $material['warehouse_id'] : null,
                    'lot_id' => isset($material['lot_id']) ? (int) $material['lot_id'] : null,
                    'source_service_id' => isset($material['source_service_id'])
                        ? (int) $material['source_service_id']
                        : null,
                    'label' => $label,
                    'description' => $material['description'] ?? null,
                    'unit' => $material['unit'] ?? $product?->unit ?? null,
                    'quantity' => max(0, $quantity),
                    'unit_price' => max(0, $unitPrice),
                    'billable' => isset($material['billable']) ? (bool) $material['billable'] : true,
                    'sort_order' => isset($material['sort_order']) ? (int) $material['sort_order'] : $index,
                    'stock_moved_at' => $stockMovedAt,
                ];
            })
            ->filter()
            ->values();

        $newUsage = $payload
            ->filter(fn ($item) => ! empty($item['product_id']))
            ->groupBy('product_id')
            ->map(fn ($items) => (float) $items->sum('quantity'));

        if ($stockAlreadyMoved && ($existingUsage->isNotEmpty() || $newUsage->isNotEmpty())) {
            $this->applyMaterialStockDelta($existingUsage, $newUsage, $productMap, $actor);
        }

        $task->materials()->delete();

        if ($payload->isEmpty()) {
            return;
        }

        $task->materials()->createMany($payload->all());
    }

    private function applyMaterialStock(Task $task, $actor = null): void
    {
        $task->loadMissing('materials');

        $materials = $task->materials
            ->filter(fn ($material) => $material->product_id && ! $material->stock_moved_at)
            ->values();

        if ($materials->isEmpty()) {
            return;
        }

        $productIds = $materials->pluck('product_id')->unique()->values();
        $productMap = Product::query()
            ->products()
            ->byUser($task->account_id)
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        $inventoryService = app(InventoryService::class);
        $defaultWarehouse = $inventoryService->resolveDefaultWarehouse($task->account_id);
        $movedIds = [];

        foreach ($materials as $material) {
            $product = $productMap->get($material->product_id);
            if (! $product) {
                continue;
            }

            $quantity = (int) round((float) $material->quantity);
            if ($quantity <= 0) {
                continue;
            }

            $warehouseId = $material->warehouse_id ?: $defaultWarehouse->id;

            $inventoryService->adjust($product, $quantity, 'out', [
                'actor_id' => $actor?->id,
                'warehouse_id' => $warehouseId,
                'account_id' => $task->account_id,
                'reason' => 'task_usage',
                'note' => 'Task usage',
                'reference_type' => Task::class,
                'reference_id' => $task->id,
            ]);

            $movedIds[] = $material->id;
        }

        if ($movedIds) {
            TaskMaterial::query()
                ->whereIn('id', $movedIds)
                ->update(['stock_moved_at' => now()]);

            TaskMaterial::query()
                ->whereIn('id', $movedIds)
                ->whereNull('warehouse_id')
                ->update(['warehouse_id' => $defaultWarehouse->id]);
        }
    }

    private function applyMaterialStockDelta($oldUsage, $newUsage, $productMap, $actor = null): void
    {
        $productIds = collect($oldUsage->keys())
            ->merge($newUsage->keys())
            ->unique()
            ->values();

        if ($productIds->isEmpty()) {
            return;
        }

        $inventoryService = app(InventoryService::class);

        foreach ($productIds as $productId) {
            $product = $productMap->get($productId);
            if (! $product) {
                continue;
            }

            $oldQty = (float) ($oldUsage[$productId] ?? 0);
            $newQty = (float) ($newUsage[$productId] ?? 0);
            $diff = $newQty - $oldQty;

            if (abs($diff) < 0.01) {
                continue;
            }

            $deltaQuantity = (int) round($diff);
            if ($deltaQuantity === 0) {
                continue;
            }

            $type = $deltaQuantity > 0 ? 'out' : 'in';
            $quantity = abs($deltaQuantity);
            $warehouse = $inventoryService->resolveDefaultWarehouse($product->user_id);

            $inventoryService->adjust($product, $quantity, $type, [
                'actor_id' => $actor?->id,
                'warehouse' => $warehouse,
                'account_id' => $product->user_id,
                'reason' => 'task_materials_update',
                'note' => 'Task materials update',
            ]);
        }
    }

    private function scheduleConflictResponse(Request $request, Task $conflictTask)
    {
        $conflictLabel = $conflictTask->title ?: 'another task';
        $message = 'This team member is already assigned to '.$conflictLabel.' at this time.';

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => $message,
                'errors' => [
                    'assigned_team_member_id' => [$message],
                ],
            ], 422);
        }

        return redirect()->back()->withErrors([
            'assigned_team_member_id' => $message,
        ]);
    }

    private function findScheduleConflict(
        int $accountId,
        ?int $assignedTeamMemberId,
        ?string $dueDate,
        ?string $startTime,
        ?string $endTime,
        ?int $ignoreTaskId = null
    ): ?Task {
        if (! $assignedTeamMemberId || ! $dueDate || ! $startTime) {
            return null;
        }

        $date = $this->normalizeDate($dueDate);
        $start = $this->normalizeTime($startTime);
        $end = $this->normalizeTime($endTime) ?: $start;
        if (! $date || ! $start) {
            return null;
        }

        $existingTasks = Task::query()
            ->forAccount($accountId)
            ->where('assigned_team_member_id', $assignedTeamMemberId)
            ->whereDate('due_date', $date)
            ->whereNotNull('start_time')
            ->whereNotIn('status', Task::CLOSED_STATUSES)
            ->when($ignoreTaskId, fn ($query) => $query->where('id', '!=', $ignoreTaskId))
            ->get(['id', 'title', 'start_time', 'end_time']);

        $newStart = $this->timeToMinutes($start);
        $newEnd = $this->timeToMinutes($end);
        if ($newStart === null || $newEnd === null) {
            return null;
        }

        foreach ($existingTasks as $task) {
            $taskStart = $this->normalizeTime($task->start_time);
            if (! $taskStart) {
                continue;
            }
            $taskEnd = $this->normalizeTime($task->end_time) ?: $taskStart;
            $taskStartMin = $this->timeToMinutes($taskStart);
            $taskEndMin = $this->timeToMinutes($taskEnd);

            if ($taskStartMin === null || $taskEndMin === null) {
                continue;
            }

            $overlaps = $newStart <= $taskEndMin && $newEnd >= $taskStartMin;
            if ($overlaps) {
                return $task;
            }
        }

        return null;
    }

    private function normalizeDate(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable $exception) {
            return null;
        }
    }

    private function normalizeTime(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        foreach (['H:i:s', 'H:i'] as $format) {
            try {
                return Carbon::createFromFormat($format, $value)->format('H:i:s');
            } catch (\Throwable $exception) {
                continue;
            }
        }

        try {
            return Carbon::parse($value)->format('H:i:s');
        } catch (\Throwable $exception) {
            return null;
        }
    }

    private function timeToMinutes(string $time): ?int
    {
        $parts = explode(':', $time);
        if (count($parts) < 2) {
            return null;
        }

        $hours = (int) $parts[0];
        $minutes = (int) $parts[1];

        return ($hours * 60) + $minutes;
    }

    private function sanitizeTaskAssignments(Task $task): void
    {
        $task->setAttribute('assigned_team_member_id', null);
        $task->setRelation('assignee', null);
    }

    private function normalizeOptionalText($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function validationErrorResponse(Request $request, string $field, string $message)
    {
        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => $message,
                'errors' => [
                    $field => [$message],
                ],
            ], 422);
        }

        return redirect()->back()->withErrors([
            $field => $message,
        ]);
    }

    private function lockedTaskResponse(Request $request, Task $task)
    {
        $message = $task->isCancelled()
            ? 'This task is locked after cancellation.'
            : 'This task is locked after completion.';

        return $this->validationErrorResponse($request, 'task', $message);
    }
}
