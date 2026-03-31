<?php

namespace App\Services\Demo;

use App\Models\DemoWorkspace;
use App\Models\User;
use App\Services\AccountDeletionService;

class DemoWorkspacePurgeService
{
    public function __construct(
        private AccountDeletionService $accountDeletionService,
        private DemoWorkspaceTimelineService $timeline,
    ) {}

    public function purge(DemoWorkspace $workspace, ?User $actor = null): void
    {
        if ($workspace->trashed()) {
            return;
        }

        $owner = $workspace->owner()->first();
        $purgedAt = now();
        $wasExpired = $workspace->isExpired();

        if ($wasExpired) {
            $this->timeline->record($workspace, 'demo_workspace.expired', $actor, [
                'expires_at' => $workspace->expires_at?->toIso8601String(),
            ], 'Demo workspace reached its expiration date.');
        }

        $workspace->forceFill([
            'owner_user_id' => null,
            'access_email' => null,
            'access_password' => null,
            'extra_access_roles' => [],
            'extra_access_credentials' => [],
            'seed_summary' => null,
            'provisioning_status' => 'purged',
            'provisioning_progress' => 100,
            'provisioning_stage' => 'Purged',
            'provisioning_error' => null,
            'purged_at' => $purgedAt,
        ])->save();

        $this->timeline->record($workspace, 'demo_workspace.purged', $actor, [
            'company_name' => $workspace->company_name,
            'owner_user_id' => $owner?->id,
            'purged_at' => $purgedAt->toIso8601String(),
            'was_expired' => $wasExpired,
        ], $wasExpired
            ? 'Expired demo workspace purged and tenant data removed.'
            : 'Demo workspace purged and tenant data removed.');

        if ($owner) {
            $this->accountDeletionService->deleteAccount($owner);
        }

        $workspace->delete();
    }

    public function purgeExpired(): int
    {
        $count = 0;

        DemoWorkspace::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->with('owner')
            ->orderBy('id')
            ->get()
            ->each(function (DemoWorkspace $workspace) use (&$count) {
                $this->purge($workspace);
                $count++;
            });

        return $count;
    }
}
