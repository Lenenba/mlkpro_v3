<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Quote;
use App\Models\Task;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class GlobalSearchController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $query = trim((string) $request->query('q', ''));
        if (mb_strlen($query) < 2) {
            return response()->json([
                'query' => $query,
                'groups' => [],
            ]);
        }

        $accountId = $user->accountOwnerId();
        $ownerAccount = $user->id === $accountId ? $user : User::query()->find($accountId);
        $like = '%' . str_replace('%', '\\%', $query) . '%';
        $groups = [];
        $limit = 6;

        $customers = Customer::query()
            ->byUser($accountId)
            ->where(function ($customerQuery) use ($like) {
                $customerQuery->where('company_name', 'like', $like)
                    ->orWhere('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like);
            })
            ->orderBy('company_name')
            ->limit($limit)
            ->get(['id', 'first_name', 'last_name', 'company_name', 'email', 'phone']);

        if ($customers->isNotEmpty()) {
            $groups[] = [
                'type' => 'customers',
                'items' => $customers->map(function (Customer $customer) {
                    $name = $customer->company_name
                        ?: trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''))
                        ?: $customer->email
                        ?: 'Customer';
                    $subtitle = $customer->email ?: $customer->phone;

                    return [
                        'id' => $customer->id,
                        'title' => $name,
                        'subtitle' => $subtitle,
                        'url' => route('customer.show', $customer->id),
                    ];
                })->values(),
            ];
        }

        $membership = $user->relationLoaded('teamMembership')
            ? $user->teamMembership
            : $user->teamMembership()->first();
        $teamPermissions = $membership?->permissions ?? [];

        $canTasks = $user->id === $accountId
            || in_array('tasks.view', $teamPermissions, true)
            || in_array('tasks.edit', $teamPermissions, true);
        $tasksEnabled = $ownerAccount?->hasCompanyFeature('tasks') ?? false;

        if ($canTasks && $tasksEnabled) {
            $tasks = Task::query()
                ->forAccount($accountId)
                ->where(function ($taskQuery) use ($like) {
                    $taskQuery->where('title', 'like', $like)
                        ->orWhere('description', 'like', $like);
                })
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get(['id', 'title', 'description', 'status', 'due_date']);

            if ($tasks->isNotEmpty()) {
                $groups[] = [
                    'type' => 'tasks',
                    'items' => $tasks->map(function (Task $task) {
                        $subtitle = $task->status ? Str::headline($task->status) : null;
                        if ($task->due_date) {
                            $subtitle = trim(($subtitle ? $subtitle . ' · ' : '') . $task->due_date->toDateString());
                        }

                        return [
                            'id' => $task->id,
                            'title' => $task->title ?: 'Task',
                            'subtitle' => $subtitle,
                            'url' => route('task.show', $task->id),
                        ];
                    })->values(),
                ];
            }
        }

        $canQuotes = $user->id === $accountId
            || in_array('quotes.view', $teamPermissions, true)
            || in_array('quotes.edit', $teamPermissions, true)
            || in_array('quotes.send', $teamPermissions, true);
        $quotesEnabled = $ownerAccount?->hasCompanyFeature('quotes') ?? false;

        if ($canQuotes && $quotesEnabled) {
            $quotes = Quote::query()
                ->byUser($accountId)
                ->where(function ($quoteQuery) use ($like) {
                    $quoteQuery->where('number', 'like', $like)
                        ->orWhere('job_title', 'like', $like)
                        ->orWhere('notes', 'like', $like);
                })
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get(['id', 'number', 'job_title', 'status', 'customer_id']);

            if ($quotes->isNotEmpty()) {
                $groups[] = [
                    'type' => 'quotes',
                    'items' => $quotes->map(function (Quote $quote) {
                        $title = $quote->number ?: ($quote->job_title ?: 'Quote');
                        $subtitle = $quote->job_title && $quote->number
                            ? $quote->job_title
                            : ($quote->status ? Str::headline($quote->status) : null);

                        return [
                            'id' => $quote->id,
                            'title' => $title,
                            'subtitle' => $subtitle,
                            'url' => route('customer.quote.show', $quote->id),
                        ];
                    })->values(),
                ];
            }
        }

        $canEmployeeProfile = $ownerAccount?->hasCompanyFeature('performance') ?? false;
        $canTeamDirectory = $ownerAccount?->hasCompanyFeature('team_members') ?? false;

        if ($user->id === $accountId && ($canEmployeeProfile || $canTeamDirectory)) {
            $employees = collect();

            $owner = User::query()
                ->whereKey($accountId)
                ->where(function ($ownerQuery) use ($like) {
                    $ownerQuery->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('phone_number', 'like', $like);
                })
                ->first(['id', 'name', 'email']);

            if ($owner) {
                $employees->push([
                    'id' => $owner->id,
                    'title' => $owner->name ?: $owner->email ?: 'Owner',
                    'subtitle' => $owner->email,
                    'url' => $canEmployeeProfile
                        ? route('performance.employee.show', $owner->id)
                        : route('team.index'),
                ]);
            }

            $memberRows = TeamMember::query()
                ->forAccount($accountId)
                ->with('user:id,name,email')
                ->where(function ($memberQuery) use ($like) {
                    $memberQuery->where('title', 'like', $like)
                        ->orWhere('phone', 'like', $like)
                        ->orWhereHas('user', function ($userQuery) use ($like) {
                            $userQuery->where('name', 'like', $like)
                                ->orWhere('email', 'like', $like);
                        });
                })
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get();

            foreach ($memberRows as $member) {
                $memberUser = $member->user;
                if (!$memberUser) {
                    continue;
                }

                $employees->push([
                    'id' => $memberUser->id,
                    'title' => $memberUser->name ?: $memberUser->email ?: 'Employee',
                    'subtitle' => $member->title ?: $memberUser->email,
                    'url' => $canEmployeeProfile
                        ? route('performance.employee.show', $memberUser->id)
                        : route('team.index'),
                ]);
            }

            if ($employees->isNotEmpty()) {
                $groups[] = [
                    'type' => 'employees',
                    'items' => $employees->unique('id')->values(),
                ];
            }
        }

        return response()->json([
            'query' => $query,
            'groups' => $groups,
        ]);
    }
}
