<?php

namespace App\Modules\AiAssistant\Policies;

use App\Models\TeamMember;
use App\Models\User;
use App\Modules\AiAssistant\Models\AiAssistantSetting;

class AiAssistantSettingPolicy
{
    public function view(User $user, AiAssistantSetting $setting): bool
    {
        return $this->canManageAiAssistant($user, (int) $setting->tenant_id);
    }

    public function update(User $user, AiAssistantSetting $setting): bool
    {
        return $this->canManageAiAssistant($user, (int) $setting->tenant_id);
    }

    public function manage(User $user): bool
    {
        return $this->canManageAiAssistant($user, (int) $user->accountOwnerId());
    }

    private function canManageAiAssistant(User $user, int $tenantId): bool
    {
        if ($user->isClient()) {
            return false;
        }

        if ((int) $user->id === $tenantId) {
            return true;
        }

        $membership = TeamMember::query()
            ->forAccount($tenantId)
            ->active()
            ->where('user_id', (int) $user->id)
            ->first();

        return (bool) $membership
            && ($membership->role === 'admin' || $membership->hasPermission('reservations.manage'));
    }
}
