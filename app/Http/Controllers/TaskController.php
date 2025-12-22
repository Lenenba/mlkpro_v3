<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\TeamMember;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

        $query = Task::query()
            ->forAccount($accountId)
            ->with(['assignee.user:id,name'])
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

        $tasks = $query
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_date')
            ->orderByDesc('created_at')
            ->simplePaginate(15)
            ->withQueryString();

        $canManage = $user
            ? ($user->id === $accountId || ($membership && $membership->role === 'admin'))
            : false;

        $teamMembers = collect();
        if ($canManage) {
            $teamMembers = TeamMember::query()
                ->forAccount($accountId)
                ->active()
                ->with('user:id,name')
                ->orderBy('created_at')
                ->get(['id', 'user_id', 'role']);
        }

        return inertia('Task/Index', [
            'tasks' => $tasks,
            'filters' => $filters,
            'statuses' => Task::STATUSES,
            'teamMembers' => $teamMembers,
            'canCreate' => $user ? $user->can('create', Task::class) : false,
            'canManage' => $canManage,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Task::class);

        $user = Auth::user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

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
        ]);

        $status = $validated['status'] ?? 'todo';

        Task::create([
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

        return redirect()->back()->with('success', 'Task created.');
    }

    public function update(Request $request, Task $task): RedirectResponse
    {
        $this->authorize('update', $task);

        $user = Auth::user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

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

        if ($updates['status'] === 'done') {
            $updates['completed_at'] = $task->completed_at ?? now();
        } else {
            $updates['completed_at'] = null;
        }

        $task->update($updates);

        return redirect()->back()->with('success', 'Task updated.');
    }

    public function destroy(Task $task): RedirectResponse
    {
        $this->authorize('delete', $task);

        $task->delete();

        return redirect()->back()->with('success', 'Task deleted.');
    }
}
