<?php

namespace App\Jobs;

use App\Services\Social\SocialPublishingService;
use App\Support\QueueWorkload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

final class ProcessSocialDeliveryOutboxJob implements ShouldBeUnique, ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public bool $failOnTimeout = true;

    public int $timeout;

    public int $uniqueFor = 300;

    public function __construct(
        public int $outboxId,
    ) {
        $this->onQueue(QueueWorkload::queue('social_publish'));
        $this->timeout = QueueWorkload::timeout('social_publish');
    }

    /**
     * @return array<int, int>
     */
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
            (new WithoutOverlapping('social-delivery-outbox:'.$this->outboxId))
                ->dontRelease()
                ->expireAfter($this->timeout + 60)
                ->shared(),
        ];
    }

    public function uniqueId(): string
    {
        return (string) $this->outboxId;
    }

    public function handle(SocialPublishingService $publishingService): void
    {
        $publishingService->handleOutboxPublication($this->outboxId);
    }
}
