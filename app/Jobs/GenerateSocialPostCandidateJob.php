<?php

namespace App\Jobs;

use App\Services\Social\SocialAutomationRunnerService;
use App\Support\QueueWorkload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateSocialPostCandidateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public int $ruleId,
        public bool $dryRun = false,
    ) {
        $this->onQueue(QueueWorkload::queue('social_automation', 'social-automation'));
    }

    public function backoff(): array
    {
        return QueueWorkload::backoff('social_automation', [60, 300, 900]);
    }

    public function handle(SocialAutomationRunnerService $runnerService): void
    {
        $runnerService->runRuleById($this->ruleId, $this->dryRun);
    }
}
