<?php

namespace App\Jobs;

use App\Services\Reservation\ExpiredWalkInAutoCloser;
use App\Support\QueueWorkload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class CloseExpiredWalkInsForAccountJob implements ShouldBeUnique, ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 900;

    public function __construct(
        public int $accountId
    ) {
        $this->onQueue(QueueWorkload::queue('reservation_reconciliation'));
        $this->timeout = QueueWorkload::timeout('reservation_reconciliation', 120);
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('reservation-walk-in-close:'.$this->accountId))
                ->releaseAfter(60)
                ->expireAfter($this->timeout + 60)
                ->shared(),
        ];
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return QueueWorkload::backoff('reservation_reconciliation', [60, 300, 900]);
    }

    public function uniqueId(): string
    {
        return (string) $this->accountId;
    }

    public function handle(ExpiredWalkInAutoCloser $autoCloser): void
    {
        $autoCloser->closeExpired($this->accountId);
    }
}
