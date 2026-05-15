<?php

namespace App\Modules\AiAssistant\Policies;

use App\Models\TeamMember;
use App\Models\User;
use App\Modules\AiAssistant\Models\AiConversation;

class AiConversationPolicy
{
    public function viewAny(User $user): bool
    {
        return ! $user->isClient() && $this->isInternalMember($user, (int) $user->accountOwnerId());
    }

    public function view(User $user, AiConversation $conversation): bool
    {
        return ! $user->isClient()
            && $this->isInternalMember($user, (int) $conversation->tenant_id);
    }

    public function reply(User $user, AiConversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }

    public function manageActions(User $user, AiConversation $conversation): bool
    {
        return $this->canManageAiAssistant($user, (int) $conversation->tenant_id);
    }

    private function isInternalMember(User $user, int $tenantId): bool
    {
        if ((int) $user->id === $tenantId) {
            return true;
        }

        return TeamMember::query()
            ->forAccount($tenantId)
            ->active()
            ->where('user_id', (int) $user->id)
            ->exists();
    }

    private function canManageAiAssistant(User $user, int $tenantId): bool
    {
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
