<?php

namespace App\Policies;

use App\Models\TeamMember;
use App\Models\User;
use App\Models\Work;

class WorkPolicy
{
    public function update(User $user, Work $work): bool
    {
        return $this->canEdit($user, $work);
    }

    public function edit(User $user, Work $work): bool
    {
        return $this->canEdit($user, $work);
    }

    public function view(User $user, Work $work): bool
    {
        $accountId = $user->accountOwnerId();
        if ($work->user_id !== $accountId) {
            return false;
        }

        if ($user->id === $accountId) {
            return true;
        }

        $membership = TeamMember::query()
            ->forAccount($accountId)
            ->active()
            ->where('user_id', $user->id)
            ->first();

        if (!$membership) {
            return false;
        }

        $isAssigned = $work->teamMembers()->whereKey($membership->id)->exists();
        if (!$isAssigned) {
            return false;
        }

        return $membership->hasPermission('jobs.view') || $membership->hasPermission('jobs.edit');
    }

    private function canEdit(User $user, Work $work): bool
    {
        $accountId = $user->accountOwnerId();
        if ($work->user_id !== $accountId) {
            return false;
        }

        if ($user->id === $accountId) {
            return true;
        }

        $membership = TeamMember::query()
            ->forAccount($accountId)
            ->active()
            ->where('user_id', $user->id)
            ->first();

        if (!$membership || !$membership->hasPermission('jobs.edit')) {
            return false;
        }

        return $work->teamMembers()->whereKey($membership->id)->exists();
    }
}

