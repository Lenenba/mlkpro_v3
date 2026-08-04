<?php

namespace App\Jobs;

use App\Support\QueueCanary;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class QueueTopologyCanaryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 15;

    public function __construct(
        public string $runId,
        public string $canaryId,
        public string $profile,
        public string $expectedConnection,
        public string $expectedQueue,
        public string $acknowledgementStore,
        public string $mode,
        public string $appEnvironment,
        public string $release,
        public string $commit,
        public int $acknowledgementTtlSeconds
    ) {
        // The command has already resolved and validated these physical targets.
        $this->connection = $expectedConnection;
        $this->queue = $expectedQueue;
    }

    public function handle(CacheFactory $cache): void
    {
        if ($this->job === null) {
            throw new \LogicException('queue_canary_worker_job_missing');
        }

        $observedTarget = QueueCanary::assertObservedWorkerTarget(
            $this->expectedConnection,
            $this->expectedQueue,
            $this->job->getConnectionName(),
            $this->job->getQueue()
        );

        QueueCanary::acknowledge(
            $cache,
            $this->acknowledgementStore,
            $this->runId,
            $this->canaryId,
            $this->profile,
            $observedTarget['connection'],
            $observedTarget['queue'],
            $this->mode,
            $this->appEnvironment,
            $this->release,
            $this->commit,
            $this->acknowledgementTtlSeconds
        );
    }
}
