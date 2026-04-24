<?php

namespace App\Jobs;

use App\Services\Social\SocialPublishingService;
use App\Support\QueueWorkload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PublishSocialPostTargetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public int $targetId
    ) {
        $this->onQueue(QueueWorkload::queue('social_publish', 'social-publish'));
    }

    public function backoff(): array
    {
        return QueueWorkload::backoff('social_publish', [30, 120, 300]);
    }

    public function handle(SocialPublishingService $publishingService): void
    {
        $publishingService->handleTargetPublication($this->targetId);
    }
}
