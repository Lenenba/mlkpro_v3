<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use App\Support\PlatformPermissions;
use Illuminate\Support\Collection;

class SupportAssignmentService
{
    public function __construct(private SupportSettingsService $settings)
    {
    }

    public function agents(): Collection
    {
        $roleIds = Role::query()
            ->whereIn('name', ['superadmin', 'admin'])
            ->pluck('id')
            ->filter()
            ->values();

        if ($roleIds->isEmpty()) {
            return collect();
        }

        $users = User::query()
            ->whereIn('role_id', $roleIds)
            ->with('platformAdmin')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role_id']);

        return $users->filter(fn (User $user) => $user->hasPlatformPermission(PlatformPermissions::SUPPORT_MANAGE))
            ->values();
    }

    public function nextAssignee(): ?User
    {
        $agents = $this->agents();
        if ($agents->isEmpty()) {
            return null;
        }

        $lastId = $this->settings->lastAssigneeId();
        if (!$lastId) {
            $next = $agents->first();
            $this->settings->setLastAssigneeId($next?->id);
            return $next;
        }

        $currentIndex = $agents->search(fn (User $user) => $user->id === $lastId);
        if ($currentIndex === false) {
            $next = $agents->first();
            $this->settings->setLastAssigneeId($next?->id);
            return $next;
        }

        $nextIndex = ($currentIndex + 1) % $agents->count();
        $next = $agents->get($nextIndex);
        $this->settings->setLastAssigneeId($next?->id);

        return $next;
    }
}
