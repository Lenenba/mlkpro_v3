<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\TeamMember;
use App\Models\User;

class TaskPolicy
{
    public function viewAny(User $user): bool
    {
        $accountId = $user->accountOwnerId();
        if ($user->id === $accountId) {
            return true;
        }

        $membership = $this->membership($user, $accountId);
        if (!$membership) {
            return false;
        }

        return $membership->hasPermission('tasks.view') || $membership->hasPermission('tasks.edit') || $membership->hasPermission('tasks.create');
    }

    public function view(User $user, Task $task): bool
    {
        $accountId = $user->accountOwnerId();
        if ($task->account_id !== $accountId) {
            return false;
        }

        if ($user->id === $accountId) {
            return true;
        }

        $membership = $this->membership($user, $accountId);
        if (!$membership) {
            return false;
        }

        if ($membership->role === 'admin') {
            return $membership->hasPermission('tasks.view') || $membership->hasPermission('tasks.edit');
        }

        if ($task->assigned_team_member_id !== $membership->id) {
            return false;
        }

        return $membership->hasPermission('tasks.view') || $membership->hasPermission('tasks.edit');
    }

    public function create(User $user): bool
    {
        $accountId = $user->accountOwnerId();
        if ($user->id === $accountId) {
            return true;
        }

        $membership = $this->membership($user, $accountId);
        if (!$membership || $membership->role !== 'admin') {
            return false;
        }

        return $membership->hasPermission('tasks.create');
    }

    public function update(User $user, Task $task): bool
    {
        $accountId = $user->accountOwnerId();
        if ($task->account_id !== $accountId) {
            return false;
        }

        if ($user->id === $accountId) {
            return true;
        }

        $membership = $this->membership($user, $accountId);
        if (!$membership) {
            return false;
        }

        if ($membership->role === 'admin') {
            return $membership->hasPermission('tasks.edit');
        }

        if ($task->assigned_team_member_id !== $membership->id) {
            return false;
        }

        return $membership->hasPermission('tasks.edit');
    }

    public function delete(User $user, Task $task): bool
    {
        $accountId = $user->accountOwnerId();
        if ($task->account_id !== $accountId) {
            return false;
        }

        if ($user->id === $accountId) {
            return true;
        }

        $membership = $this->membership($user, $accountId);
        if (!$membership || $membership->role !== 'admin') {
            return false;
        }

        return $membership->hasPermission('tasks.delete');
    }

    private function membership(User $user, int $accountId): ?TeamMember
    {
        return TeamMember::query()
            ->forAccount($accountId)
            ->active()
            ->where('user_id', $user->id)
            ->first();
    }
}

