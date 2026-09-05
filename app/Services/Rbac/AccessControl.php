<?php

namespace App\Services\Rbac;

use App\Models\TeamMember;
use App\Models\User;

class AccessControl
{
    public function userHasPermission(User $user, string $permission, ?int $accountId = null): bool
    {
        if ($user->isSuperadmin()) {
            return true;
        }

        $accountId ??= $user->accountOwnerId();

        if ($user->isOwner() && (int) $user->id === (int) $accountId) {
            return true;
        }

        $membership = $this->membershipFor($user, $accountId);

        return (bool) $membership?->hasPermission($permission);
    }

    private function membershipFor(User $user, int $accountId): ?TeamMember
    {
        if (
            $user->relationLoaded('teamMembership')
            && $user->teamMembership
            && (int) $user->teamMembership->account_id === $accountId
            && $user->teamMembership->is_active
        ) {
            $membership = $user->teamMembership;
            $membership->loadMissing('companyRole.permissions');

            return $membership;
        }

        return TeamMember::query()
            ->where('user_id', $user->id)
            ->where('account_id', $accountId)
            ->where('is_active', true)
            ->with('companyRole.permissions')
            ->first();
    }
}
