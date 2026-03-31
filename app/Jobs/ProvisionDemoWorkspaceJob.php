<?php

namespace App\Jobs;

use App\Models\DemoWorkspace;
use App\Models\User;
use App\Services\Demo\DemoWorkspaceProvisioner;
use App\Services\Demo\DemoWorkspaceTimelineService;
use App\Support\QueueWorkload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProvisionDemoWorkspaceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public int $workspaceId,
        public int $actorUserId,
        public bool $isReset = false
    ) {
        $this->onQueue(QueueWorkload::queue('demos', 'demo-provisioning'));
    }

    public function handle(
        DemoWorkspaceProvisioner $provisioner,
        DemoWorkspaceTimelineService $timeline
    ): void {
        $workspace = DemoWorkspace::query()->find($this->workspaceId);
        $actor = User::query()->find($this->actorUserId);

        if (! $workspace || ! $actor) {
            return;
        }

        $timeline->record(
            $workspace,
            'demo_workspace.provisioning_started',
            $actor,
            ['is_reset' => $this->isReset],
            $this->isReset
                ? 'Baseline reset provisioning started.'
                : 'Demo provisioning started.'
        );

        try {
            $workspace = $provisioner->provisionQueuedWorkspace($workspace, $actor, $this->isReset);
        } catch (\Throwable $exception) {
            $workspace = $provisioner->markProvisioningFailed($workspace, $exception);

            $timeline->record(
                $workspace,
                'demo_workspace.failed',
                $actor,
                [
                    'is_reset' => $this->isReset,
                    'error' => $workspace->provisioning_error,
                ],
                $this->isReset
                    ? 'Baseline reset failed during provisioning.'
                    : 'Demo provisioning failed.'
            );

            return;
        }

        $timeline->record(
            $workspace,
            $this->isReset ? 'demo_workspace.reset_to_baseline' : 'demo_workspace.ready',
            $actor,
            [
                'is_reset' => $this->isReset,
                'owner_user_id' => $workspace->owner_user_id,
                'provisioning_status' => $workspace->provisioning_status,
            ],
            $this->isReset
                ? 'Workspace reset completed from baseline.'
                : 'Demo workspace provisioning completed.'
        );
    }
}
