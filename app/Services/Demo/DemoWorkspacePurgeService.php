<?php

namespace App\Services\Demo;

use App\Models\DemoWorkspace;
use App\Services\AccountDeletionService;

class DemoWorkspacePurgeService
{
    public function __construct(private AccountDeletionService $accountDeletionService)
    {
    }

    public function purge(DemoWorkspace $workspace): void
    {
        $owner = $workspace->owner()->first();

        if ($owner) {
            $this->accountDeletionService->deleteAccount($owner);

            return;
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
