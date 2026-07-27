<?php

namespace App\Services;

use App\Services\Capacity\CapacityRunContextService;
use App\Services\Observability\ObservabilityCacheStore;
use App\Services\Observability\TelemetryQueryGuard;
use App\Services\Observability\TelemetryScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Throwable;

class QueueHealthService
{
    private const SAMPLE_KEY = 'queue:samples';

    public function __construct(
        private readonly ObservabilityCacheStore $cache,
        private readonly TelemetryScope $telemetryScope,
        private readonly CapacityRunContextService $runContext,
        private readonly TelemetryQueryGuard $queryGuard
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(bool $record = false, bool $forceRecord = false): array
    {
        return $this->queryGuard->run(fn (): array => $this->buildSummary($record, $forceRecord));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSummary(bool $record, bool $forceRecord): array
    {
        $connectionName = (string) config('queue.default', 'sync');
        $connection = config("queue.connections.{$connectionName}", []);
        $connection = is_array($connection) ? $connection : [];
        $driver = (string) ($connection['driver'] ?? $connectionName);
        $errors = [];

        $backlog = $driver === 'database'
            ? $this->databaseBacklog($connection, $errors)
            : $this->genericBacklog($connectionName, $driver, $connection, $errors);
        $failed = $this->failedJobs($errors);

        $summary = [
            'connection' => $connectionName,
            'driver' => $driver,
            'measurable' => $backlog['measurable'],
            'backlog_measurable' => $backlog['measurable'],
            'oldest_job_measurable' => $backlog['oldest_measurable'],
            'failed_jobs_measurable' => $failed['measurable'],
            'pending_jobs' => $backlog['pending_jobs'],
            'pending_by_queue' => $backlog['pending_by_queue'],
            'ready_jobs' => $backlog['ready_jobs'],
            'delayed_jobs' => $backlog['delayed_jobs'],
            'reserved_jobs' => $backlog['reserved_jobs'],
            'expired_reserved_jobs' => $backlog['expired_reserved_jobs'],
            'backlog_semantics' => $backlog['semantics'],
            'oldest_job_minutes' => $backlog['oldest_job_minutes'],
            'failed_jobs_24h' => $failed['count_24h'],
            'failed_jobs_7d' => $failed['count_7d'],
            'measurement_errors' => array_values(array_unique($errors)),
        ];

        if ($record) {
            $summary['snapshot_recorded'] = $this->recordSnapshot($summary, $forceRecord);
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return array<string, mixed>
     */
    public function summaryForScope(array $scope): array
    {
        $samples = $this->telemetryScope->filter(
            $this->cache->get(self::SAMPLE_KEY),
            $scope,
            $this->retentionHours()
        );

        if ($samples === []) {
            return array_merge($this->unknownBacklog(), [
                'connection' => (string) config('queue.default', 'unknown'),
                'driver' => (string) data_get(config('queue.connections'), config('queue.default').'.driver', 'unknown'),
                'backlog_measurable' => false,
                'failed_jobs_measurable' => false,
                'failed_jobs_24h' => null,
                'failed_jobs_7d' => null,
                'measurement_errors' => ['queue_scope_samples_missing'],
                'snapshot_count' => 0,
                'truncated' => false,
                'by_scenario' => [],
            ]);
        }

        $scopeId = $this->telemetryScope->idFor($scope);
        $snapshotCount = $scopeId === null
            ? count($samples)
            : max(count($samples), $this->cache->integer($this->scopedCounterKey($scopeId, null)));
        $summary = $this->aggregateSamples($samples, $snapshotCount);
        $summary['by_scenario'] = collect($samples)
            ->filter(fn (array $sample): bool => is_string($sample['scenario_key'] ?? null)
                && trim((string) $sample['scenario_key']) !== '')
            ->groupBy('scenario_key')
            ->map(function ($scenarioSamples, string $scenarioKey) use ($scopeId): array {
                $scenarioSamples = $scenarioSamples->values()->all();
                $count = $scopeId === null
                    ? count($scenarioSamples)
                    : max(
                        count($scenarioSamples),
                        $this->cache->integer($this->scopedCounterKey($scopeId, $scenarioKey))
                    );

                return $this->aggregateSamples($scenarioSamples, $count);
            })
            ->all();

        return $summary;
    }

    /**
     * @param  array<int, array<string, mixed>>  $samples
     * @return array<string, mixed>
     */
    private function aggregateSamples(array $samples, int $snapshotCount): array
    {
        $pendingByQueue = collect($samples)
            ->flatMap(fn (array $sample): array => collect($sample['pending_by_queue'] ?? [])
                ->map(fn ($count, $queue): array => ['queue' => (string) $queue, 'count' => (int) $count])
                ->values()
                ->all())
            ->groupBy('queue')
            ->map(fn ($items): int => (int) $items->max('count'))
            ->sortKeys()
            ->all();

        return [
            'connection' => (string) ($samples[array_key_last($samples)]['connection'] ?? config('queue.default')),
            'driver' => (string) ($samples[array_key_last($samples)]['driver'] ?? 'unknown'),
            'measurable' => collect($samples)->every(fn (array $sample): bool => (bool) ($sample['backlog_measurable'] ?? false)),
            'backlog_measurable' => collect($samples)->every(fn (array $sample): bool => (bool) ($sample['backlog_measurable'] ?? false)),
            'oldest_job_measurable' => collect($samples)->every(fn (array $sample): bool => (bool) ($sample['oldest_job_measurable'] ?? false)),
            'failed_jobs_measurable' => collect($samples)->every(fn (array $sample): bool => (bool) ($sample['failed_jobs_measurable'] ?? false)),
            'pending_jobs' => $this->maximumNumeric($samples, 'pending_jobs'),
            'pending_by_queue' => $pendingByQueue,
            'ready_jobs' => $this->maximumNumeric($samples, 'ready_jobs'),
            'delayed_jobs' => $this->maximumNumeric($samples, 'delayed_jobs'),
            'reserved_jobs' => $this->maximumNumeric($samples, 'reserved_jobs'),
            'expired_reserved_jobs' => $this->maximumNumeric($samples, 'expired_reserved_jobs'),
            'backlog_semantics' => (string) ($samples[array_key_last($samples)]['backlog_semantics'] ?? 'unknown'),
            'oldest_job_minutes' => $this->maximumNumeric($samples, 'oldest_job_minutes'),
            'failed_jobs_24h' => $this->maximumNumeric($samples, 'failed_jobs_24h'),
            'failed_jobs_7d' => $this->maximumNumeric($samples, 'failed_jobs_7d'),
            'measurement_errors' => collect($samples)
                ->flatMap(fn (array $sample): array => is_array($sample['measurement_errors'] ?? null)
                    ? $sample['measurement_errors']
                    : [])
                ->filter(fn ($error): bool => is_string($error))
                ->unique()
                ->values()
                ->all(),
            'snapshot_count' => $snapshotCount,
            'sample_count' => count($samples),
            'truncated' => $snapshotCount > count($samples),
            'coverage' => $this->snapshotCoverage($samples),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $samples
     * @return array{first_recorded_at: string|null, last_recorded_at: string|null, duration_seconds: float|null, max_gap_seconds: float|null}
     */
    private function snapshotCoverage(array $samples): array
    {
        $timestamps = collect($samples)
            ->pluck('recorded_at')
            ->filter(fn ($value): bool => is_string($value) && trim($value) !== '')
            ->map(function (string $value): ?Carbon {
                try {
                    return Carbon::parse($value)->utc();
                } catch (Throwable) {
                    return null;
                }
            })
            ->filter(fn ($value): bool => $value instanceof Carbon)
            ->sortBy(fn (Carbon $timestamp): string => $timestamp->format('U.u'))
            ->values();

        if ($timestamps->isEmpty()) {
            return [
                'first_recorded_at' => null,
                'last_recorded_at' => null,
                'duration_seconds' => null,
                'max_gap_seconds' => null,
            ];
        }

        $maximumGap = 0.0;
        foreach ($timestamps->sliding(2) as $pair) {
            $maximumGap = max(
                $maximumGap,
                (float) $pair->first()->diffInMicroseconds($pair->last()) / 1_000_000
            );
        }

        /** @var Carbon $first */
        $first = $timestamps->first();
        /** @var Carbon $last */
        $last = $timestamps->last();

        return [
            'first_recorded_at' => $first->format('Y-m-d\TH:i:s.u\Z'),
            'last_recorded_at' => $last->format('Y-m-d\TH:i:s.u\Z'),
            'duration_seconds' => round((float) $first->diffInMicroseconds($last) / 1_000_000, 3),
            'max_gap_seconds' => round($maximumGap, 3),
        ];
    }

    /**
     * @param  array<string, mixed>  $connection
     * @param  array<int, string>  $errors
     * @return array<string, mixed>
     */
    private function databaseBacklog(array $connection, array &$errors): array
    {
        $database = $this->databaseConnection($connection['connection'] ?? null);
        $table = trim((string) ($connection['table'] ?? 'jobs')) ?: 'jobs';

        try {
            if (! Schema::connection($database)->hasTable($table)) {
                $errors[] = 'queue_table_missing';

                return $this->unknownBacklog();
            }

            $query = DB::connection($database)->table($table);
            $now = now()->timestamp;
            $retryAfter = max(1, (int) ($connection['retry_after'] ?? 90));
            $expiredBefore = $now - $retryAfter;
            $readyByQueue = (clone $query)
                ->where(function ($ready) use ($expiredBefore, $now): void {
                    $ready->where(function ($available) use ($now): void {
                        $available->whereNull('reserved_at')->where('available_at', '<=', $now);
                    })->orWhere(function ($expired) use ($expiredBefore): void {
                        $expired->whereNotNull('reserved_at')->where('reserved_at', '<=', $expiredBefore);
                    });
                })
                ->selectRaw('queue, COUNT(*) as aggregate')
                ->groupBy('queue')
                ->orderBy('queue')
                ->pluck('aggregate', 'queue')
                ->map(static fn ($count): int => (int) $count)
                ->all();
            $delayedByQueue = (clone $query)
                ->whereNull('reserved_at')
                ->where('available_at', '>', $now)
                ->selectRaw('queue, COUNT(*) as aggregate')
                ->groupBy('queue')
                ->pluck('aggregate', 'queue')
                ->map(static fn ($count): int => (int) $count)
                ->all();
            $pendingByQueue = collect($readyByQueue)
                ->mergeRecursive($delayedByQueue)
                ->map(static fn ($count): int => is_array($count) ? array_sum($count) : (int) $count)
                ->sortKeys()
                ->all();
            $reservedJobs = (int) (clone $query)
                ->whereNotNull('reserved_at')
                ->where('reserved_at', '>', $expiredBefore)
                ->count();
            $expiredReservedJobs = (int) (clone $query)
                ->whereNotNull('reserved_at')
                ->where('reserved_at', '<=', $expiredBefore)
                ->count();
            $oldestAvailableAt = (clone $query)
                ->whereNull('reserved_at')
                ->where('available_at', '<=', $now)
                ->min('available_at');
            $oldestExpiredReservedAt = (clone $query)
                ->whereNotNull('reserved_at')
                ->where('reserved_at', '<=', $expiredBefore)
                ->min('reserved_at');
            $readyAges = [];
            if ($oldestAvailableAt !== null) {
                $readyAges[] = max(0, $now - (int) $oldestAvailableAt) / 60;
            }
            if ($oldestExpiredReservedAt !== null) {
                $readyAges[] = max(0, $now - ((int) $oldestExpiredReservedAt + $retryAfter)) / 60;
            }

            return [
                'measurable' => true,
                'oldest_measurable' => true,
                'semantics' => 'ready_plus_delayed_excluding_active_reserved',
                'pending_jobs' => array_sum($pendingByQueue),
                'pending_by_queue' => $pendingByQueue,
                'ready_jobs' => array_sum($readyByQueue),
                'delayed_jobs' => array_sum($delayedByQueue),
                'reserved_jobs' => $reservedJobs,
                'expired_reserved_jobs' => $expiredReservedJobs,
                'oldest_job_minutes' => $readyAges === [] ? 0.0 : round(max($readyAges), 1),
            ];
        } catch (Throwable $exception) {
            $errors[] = 'queue_backlog_read_failed:'.$exception::class;

            return $this->unknownBacklog();
        }
    }

    /**
     * @param  array<string, mixed>  $connection
     * @param  array<int, string>  $errors
     * @return array<string, mixed>
     */
    private function genericBacklog(
        string $connectionName,
        string $driver,
        array $connection,
        array &$errors
    ): array {
        if (in_array($driver, ['sync', 'null'], true)) {
            $errors[] = 'queue_backlog_not_persistent';

            return $this->unknownBacklog();
        }

        $pendingByQueue = [];

        try {
            $queue = Queue::connection($connectionName);

            foreach ($this->queueNames($connection) as $queueName) {
                $pendingByQueue[$queueName] = (int) $queue->size($queueName);
            }

            ksort($pendingByQueue);
            $errors[] = 'queue_oldest_job_age_unmeasurable';

            return [
                'measurable' => true,
                'oldest_measurable' => false,
                'semantics' => 'driver_reported_size',
                'pending_jobs' => array_sum($pendingByQueue),
                'pending_by_queue' => $pendingByQueue,
                'ready_jobs' => null,
                'delayed_jobs' => null,
                'reserved_jobs' => null,
                'expired_reserved_jobs' => null,
                'oldest_job_minutes' => null,
            ];
        } catch (Throwable $exception) {
            $errors[] = 'queue_backlog_read_failed:'.$exception::class;

            return $this->unknownBacklog();
        }
    }

    /**
     * @param  array<int, string>  $errors
     * @return array{measurable: bool, count_24h: int|null, count_7d: int|null}
     */
    private function failedJobs(array &$errors): array
    {
        $driver = (string) config('queue.failed.driver', 'database-uuids');
        if (! in_array($driver, ['database', 'database-uuids'], true)) {
            $errors[] = 'failed_jobs_backend_not_measurable';

            return ['measurable' => false, 'count_24h' => null, 'count_7d' => null];
        }

        $database = $this->databaseConnection(config('queue.failed.database'));
        $table = trim((string) config('queue.failed.table', 'failed_jobs')) ?: 'failed_jobs';

        try {
            if (! Schema::connection($database)->hasTable($table)) {
                $errors[] = 'failed_jobs_table_missing';

                return ['measurable' => false, 'count_24h' => null, 'count_7d' => null];
            }

            $query = DB::connection($database)->table($table);

            return [
                'measurable' => true,
                'count_24h' => (int) (clone $query)->where('failed_at', '>=', now()->subDay())->count(),
                'count_7d' => (int) (clone $query)->where('failed_at', '>=', now()->subDays(7))->count(),
            ];
        } catch (Throwable $exception) {
            $errors[] = 'failed_jobs_read_failed:'.$exception::class;

            return ['measurable' => false, 'count_24h' => null, 'count_7d' => null];
        }
    }

    /**
     * @param  array<string, mixed>  $connection
     * @return array<int, string>
     */
    private function queueNames(array $connection): array
    {
        $queues = collect(config('async.workloads', []))
            ->filter(fn ($workload): bool => is_array($workload))
            ->pluck('queue')
            ->filter(fn ($queue): bool => is_string($queue) && trim($queue) !== '')
            ->map(fn (string $queue): string => trim($queue));

        $defaultQueue = $connection['queue'] ?? 'default';
        if (is_string($defaultQueue) && trim($defaultQueue) !== '') {
            $queues->push(trim($defaultQueue));
        }

        return $queues->unique()->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function unknownBacklog(): array
    {
        return [
            'measurable' => false,
            'oldest_measurable' => false,
            'semantics' => 'unknown',
            'pending_jobs' => null,
            'pending_by_queue' => [],
            'ready_jobs' => null,
            'delayed_jobs' => null,
            'reserved_jobs' => null,
            'expired_reserved_jobs' => null,
            'oldest_job_minutes' => null,
        ];
    }

    private function databaseConnection(mixed $connection): ?string
    {
        if (! is_string($connection) || trim($connection) === '') {
            return null;
        }

        return trim($connection);
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function recordSnapshot(array $summary, bool $force): bool
    {
        if (! config('observability.enabled', false) || ($scopeId = $this->telemetryScope->activeId()) === null) {
            return false;
        }

        $scenarioKey = $this->runContext->activeScenarioKey();
        $slotBucket = $force
            ? 'boundary:'.now()->utc()->format('YmdHis')
            : now()->utc()->format('YmdHi');
        $snapshotSlot = implode(':', [
            'queue:snapshot-slot',
            $scopeId,
            $scenarioKey === null ? 'none' : sha1($scenarioKey),
            $slotBucket,
        ]);
        if (! $this->cache->add($snapshotSlot, 'pending', 2)) {
            return ($this->cache->values([$snapshotSlot])[$snapshotSlot] ?? null) === 'committed';
        }

        if (! $this->cache->append(self::SAMPLE_KEY, [
            ...$this->telemetryScope->tags(),
            'scenario_key' => $scenarioKey,
            ...$summary,
            'recorded_at' => now()->toIso8601String(),
        ], $this->sampleSize(), $this->retentionHours())) {
            $this->cache->forget($snapshotSlot);

            return false;
        }

        $this->cache->increment(
            $this->scopedCounterKey($scopeId, null),
            $this->retentionHours() + 1
        );
        if ($scenarioKey !== null) {
            $this->cache->increment(
                $this->scopedCounterKey($scopeId, $scenarioKey),
                $this->retentionHours() + 1
            );
        }

        if (! $this->cache->put($snapshotSlot, 'committed', 2)) {
            $this->cache->forget($snapshotSlot);

            return false;
        }

        return true;
    }

    /**
     * @param  array<int, array<string, mixed>>  $samples
     */
    private function maximumNumeric(array $samples, string $key): int|float|null
    {
        $values = collect($samples)
            ->pluck($key)
            ->filter(fn ($value): bool => is_numeric($value))
            ->map(fn ($value): float => (float) $value);

        if ($values->isEmpty()) {
            return null;
        }

        $maximum = (float) $values->max();

        return floor($maximum) === $maximum ? (int) $maximum : round($maximum, 1);
    }

    private function sampleSize(): int
    {
        return max(10, (int) config('observability.queue.sample_size', 150));
    }

    private function retentionHours(): int
    {
        return max(24, (int) config('observability.queue.retention_hours', 24));
    }

    private function scopedCounterKey(string $scopeId, ?string $scenarioKey): string
    {
        return 'queue:scoped:'.$scopeId.':'.($scenarioKey === null ? 'all' : sha1($scenarioKey)).':snapshots';
    }
}
