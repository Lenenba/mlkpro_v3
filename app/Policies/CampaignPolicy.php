<?php

namespace App\Policies;

use App\Models\Campaign;
use App\Models\User;

class CampaignPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManageCampaigns($user);
    }

    public function view(User $user, Campaign $campaign): bool
    {
        return $this->isSameAccount($user, $campaign) && $this->canManageCampaigns($user);
    }

    public function create(User $user): bool
    {
        return $this->canManageCampaigns($user);
    }

    public function update(User $user, Campaign $campaign): bool
    {
        return $this->isSameAccount($user, $campaign) && $this->canManageCampaigns($user);
    }

    public function delete(User $user, Campaign $campaign): bool
    {
        return $this->isSameAccount($user, $campaign) && $this->canManageCampaigns($user);
    }

    public function send(User $user, Campaign $campaign): bool
    {
        if (!$this->isSameAccount($user, $campaign)) {
            return false;
        }

        if ($user->id === $campaign->user_id) {
            return true;
        }

        $membership = $user->relationLoaded('teamMembership')
            ? $user->teamMembership
            : $user->teamMembership()->first();

        return (bool) $membership?->hasPermission('campaigns.send');
    }

    private function canManageCampaigns(User $user): bool
    {
        $ownerId = $user->accountOwnerId();
        $owner = $ownerId === $user->id
            ? $user
            : User::query()->select(['id'])->find($ownerId);

        if (!$owner) {
            return false;
        }

        if ($user->id === $owner->id) {
            return true;
        }

        $membership = $user->relationLoaded('teamMembership')
            ? $user->teamMembership
            : $user->teamMembership()->first();

        return (bool) $membership?->hasPermission('campaigns.manage');
    }

    private function isSameAccount(User $user, Campaign $campaign): bool
    {
        return (int) $user->accountOwnerId() === (int) $campaign->user_id;
    }
}
