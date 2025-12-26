<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\Task;
use App\Models\TaskMaterial;
use App\Models\TeamMember;
use App\Services\UsageLimitService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->only([
            'search',
            'status',
        ]);

        $user = Auth::user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        $this->authorize('viewAny', Task::class);

        $membership = $user && $user->id !== $accountId
            ? $user->teamMembership()->first()
            : null;
        $isAdminMember = $membership && $membership->role === 'admin';

        $query = Task::query()
            ->forAccount($accountId)
            ->with(['assignee.user:id,name', 'materials.product:id,name,unit,price'])
            ->when(
                $filters['search'] ?? null,
                fn($query, $search) => $query->where(function ($sub) use ($search) {
                    $sub->where('title', 'like', '%' . $search . '%')
                        ->orWhere('description', 'like', '%' . $search . '%');
                })
            )
            ->when(
                $filters['status'] ?? null,
                fn($query, $status) => in_array($status, Task::STATUSES, true)
                    ? $query->where('status', $status)
                    : null
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
        ];

        $tasks = $query
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_date')
            ->orderByDesc('created_at')
            ->simplePaginate(15)
            ->withQueryString();

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
        if ($user && ($user->id === $accountId || ($isAdminMember && ($membership->hasPermission('tasks.create') || $membership->hasPermission('tasks.edit'))))) {
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

        return inertia('Task/Index', [
            'tasks' => $tasks,
            'filters' => $filters,
            'statuses' => Task::STATUSES,
            'teamMembers' => $teamMembers,
            'stats' => $stats,
            'count' => $totalCount,
            'materialProducts' => $materialProducts,
            'canCreate' => $user ? $user->can('create', Task::class) : false,
            'canManage' => $canManage,
            'canDelete' => $canDelete,
            'canEditStatus' => $canEditStatus,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Task::class);

        $user = Auth::user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        if ($user) {
            app(UsageLimitService::class)->enforceLimit($user, 'tasks');
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'status' => ['nullable', 'string', Rule::in(Task::STATUSES)],
            'due_date' => 'nullable|date',
            'assigned_team_member_id' => [
                'nullable',
                'integer',
                Rule::exists('team_members', 'id')->where('account_id', $accountId),
            ],
            'customer_id' => [
                'nullable',
                'integer',
                Rule::exists('customers', 'id')->where('user_id', $accountId),
            ],
            'product_id' => [
                'nullable',
                'integer',
                Rule::exists('products', 'id')->where('user_id', $accountId),
            ],
            'materials' => 'nullable|array',
            'materials.*.id' => 'nullable|integer',
            'materials.*.product_id' => [
                'nullable',
                'integer',
                Rule::exists('products', 'id')->where('user_id', $accountId),
            ],
            'materials.*.label' => 'nullable|string|max:255',
            'materials.*.description' => 'nullable|string|max:2000',
            'materials.*.unit' => 'nullable|string|max:50',
            'materials.*.quantity' => 'nullable|numeric|min:0',
            'materials.*.unit_price' => 'nullable|numeric|min:0',
            'materials.*.billable' => 'nullable|boolean',
            'materials.*.sort_order' => 'nullable|integer|min:0',
            'materials.*.source_service_id' => [
                'nullable',
                'integer',
                Rule::exists('products', 'id')->where('user_id', $accountId),
            ],
        ]);

        $status = $validated['status'] ?? 'todo';

        $task = Task::create([
            'account_id' => $accountId,
            'created_by_user_id' => Auth::id(),
            'assigned_team_member_id' => $validated['assigned_team_member_id'] ?? null,
            'customer_id' => $validated['customer_id'] ?? null,
            'product_id' => $validated['product_id'] ?? null,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'status' => $status,
            'due_date' => $validated['due_date'] ?? null,
            'completed_at' => $status === 'done' ? now() : null,
        ]);

        if ($request->has('materials')) {
            $this->syncTaskMaterials($task, $validated['materials'] ?? [], $accountId, false, $user);
        }

        if ($status === 'in_progress') {
            $this->applyMaterialStock($task, Auth::user());
        }

        return redirect()->back()->with('success', 'Task created.');
    }

    public function update(Request $request, Task $task): RedirectResponse
    {
        $this->authorize('update', $task);

        $user = Auth::user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        if ($task->status === 'done') {
            return redirect()->back()->withErrors([
                'task' => 'This task is locked after completion.',
            ]);
        }

        $membership = $user && $user->id !== $accountId
            ? $user->teamMembership()->first()
            : null;

        $isManager = $user && ($user->id === $accountId || ($membership && $membership->role === 'admin'));

        $rules = [
            'status' => ['required', 'string', Rule::in(Task::STATUSES)],
        ];

        if ($isManager) {
            $rules = array_merge($rules, [
                'title' => 'required|string|max:255',
                'description' => 'nullable|string|max:5000',
                'due_date' => 'nullable|date',
                'assigned_team_member_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('team_members', 'id')->where('account_id', $accountId),
                ],
                'customer_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('customers', 'id')->where('user_id', $accountId),
                ],
                'product_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('products', 'id')->where('user_id', $accountId),
                ],
                'materials' => 'nullable|array',
                'materials.*.id' => 'nullable|integer',
                'materials.*.product_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('products', 'id')->where('user_id', $accountId),
                ],
                'materials.*.label' => 'nullable|string|max:255',
                'materials.*.description' => 'nullable|string|max:2000',
                'materials.*.unit' => 'nullable|string|max:50',
                'materials.*.quantity' => 'nullable|numeric|min:0',
                'materials.*.unit_price' => 'nullable|numeric|min:0',
                'materials.*.billable' => 'nullable|boolean',
                'materials.*.sort_order' => 'nullable|integer|min:0',
                'materials.*.source_service_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('products', 'id')->where('user_id', $accountId),
                ],
            ]);
        }

        $validated = $request->validate($rules);

        $updates = [
            'status' => $validated['status'],
        ];

        if ($isManager) {
            $updates['title'] = $validated['title'];
            $updates['description'] = $validated['description'] ?? null;
            $updates['due_date'] = $validated['due_date'] ?? null;
            $updates['assigned_team_member_id'] = $validated['assigned_team_member_id'] ?? null;
            $updates['customer_id'] = $validated['customer_id'] ?? null;
            $updates['product_id'] = $validated['product_id'] ?? null;
        }

        $wasInProgress = $task->status === 'in_progress';
        $wasDone = $task->status === 'done';
        $isDone = $updates['status'] === 'done';

        if ($isDone) {
            $updates['completed_at'] = $task->completed_at ?? now();
        } else {
            $updates['completed_at'] = null;
        }

        $task->update($updates);

        if ($isManager && $request->has('materials')) {
            $this->syncTaskMaterials($task, $validated['materials'] ?? [], $accountId, $wasInProgress, $user);
        }

        if (!$wasInProgress && $updates['status'] === 'in_progress') {
            $this->applyMaterialStock($task, $user);
        }

        if (!$wasDone && $isDone) {
            app(\App\Services\TaskBillingService::class)
                ->handleTaskCompleted($task, $user);
        }

        return redirect()->back()->with('success', 'Task updated.');
    }

    public function destroy(Task $task): RedirectResponse
    {
        $this->authorize('delete', $task);

        $task->delete();

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
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values();

        $productIds = $stockAlreadyMoved
            ? $materialProductIds
                ->merge($existingUsage->keys()->map(fn($id) => (int) $id))
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
            ->map(function ($material, $index) use ($productMap, $task) {
                $productId = isset($material['product_id']) ? (int) $material['product_id'] : null;
                $product = $productId ? $productMap->get($productId) : null;
                $label = trim((string) ($material['label'] ?? ''));
                if (!$label && $product) {
                    $label = $product->name;
                }

                if (!$label) {
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
            ->filter(fn($item) => !empty($item['product_id']))
            ->groupBy('product_id')
            ->map(fn($items) => (float) $items->sum('quantity'));

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
            ->filter(fn($material) => $material->product_id && !$material->stock_moved_at)
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

        $movedIds = [];

        DB::transaction(function () use ($materials, $productMap, $task, $actor, &$movedIds) {
            foreach ($materials as $material) {
                $product = $productMap->get($material->product_id);
                if (!$product) {
                    continue;
                }

                $quantity = (int) round((float) $material->quantity);
                if ($quantity <= 0) {
                    continue;
                }

                $product->stock = max(0, $product->stock - $quantity);
                $product->save();

                ProductStockMovement::create([
                    'product_id' => $product->id,
                    'user_id' => $actor?->id,
                    'type' => 'out',
                    'quantity' => -abs($quantity),
                    'note' => 'Task usage',
                ]);

                $movedIds[] = $material->id;
            }
        });

        if ($movedIds) {
            TaskMaterial::query()
                ->whereIn('id', $movedIds)
                ->update(['stock_moved_at' => now()]);
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

        DB::transaction(function () use ($productIds, $oldUsage, $newUsage, $productMap, $actor) {
            foreach ($productIds as $productId) {
                $product = $productMap->get($productId);
                if (!$product) {
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
                $delta = $deltaQuantity > 0 ? -abs($deltaQuantity) : abs($deltaQuantity);

                $product->stock = max(0, $product->stock + $delta);
                $product->save();

                ProductStockMovement::create([
                    'product_id' => $product->id,
                    'user_id' => $actor?->id,
                    'type' => $type,
                    'quantity' => $delta,
                    'note' => 'Task materials update',
                ]);
            }
        });
    }
}
