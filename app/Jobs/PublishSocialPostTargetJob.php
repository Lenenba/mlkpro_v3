<?php

namespace App\Jobs;

use App\Services\Social\SocialPublishingService;
use App\Support\QueueWorkload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class PublishSocialPostTargetJob implements ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public bool $failOnTimeout = true;

    public int $timeout;

    public function __construct(
        public int $targetId
    ) {
        $this->onQueue(QueueWorkload::queue('social_publish'));
        $this->timeout = QueueWorkload::timeout('social_publish');
    }

    public function backoff(): array
    {
        return QueueWorkload::backoff('social_publish', [30, 120, 300]);
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('social-post-target:'.$this->targetId))
                ->dontRelease()
                ->expireAfter($this->timeout + 60)
                ->shared(),
        ];
    }

    public function handle(SocialPublishingService $publishingService): void
    {
        $publishingService->handleTargetPublication($this->targetId);
    }

    public function failed(?Throwable $exception): void
    {
        app(SocialPublishingService::class)->markTargetPublicationFailed($this->targetId, $exception);
    }
}
