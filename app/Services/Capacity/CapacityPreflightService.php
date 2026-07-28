<?php

namespace App\Services\Capacity;

use App\Services\Observability\ObservabilityCacheStore;
use App\Services\Observability\TelemetryQueryGuard;
use App\Services\QueueHealthService;
use Throwable;

class CapacityPreflightService
{
    public function __construct(
        private readonly ObservabilityCacheStore $cache,
        private readonly QueueHealthService $queueHealth,
        private readonly TelemetryQueryGuard $queryGuard
    ) {}

    /**
     * @return array<int, string>
     */
    public function issues(): array
    {
        return $this->summary()['issues'];
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return $this->queryGuard->run(function (): array {
            $enabled = (bool) config('observability.enabled', false);
            $release = trim((string) config('observability.release', ''));
            $cacheProbe = $this->cacheProbe();
            $cacheHealth = $this->cacheHealth();
            $queue = $this->queueSummary();
            $issues = [];

            if (! $enabled) {
                $issues[] = 'observability_disabled';
            }
            if ($release === '') {
                $issues[] = 'release_missing';
            }
            if (($cacheHealth['health_check_error'] ?? null) !== null) {
                $issues[] = 'cache_health_check_failed';
            }
            if (($cacheHealth['driver'] ?? null) !== 'redis') {
                $issues[] = 'cache_store_not_redis';
            }
            if (! ($cacheHealth['shared'] ?? false)) {
                $issues[] = 'cache_store_not_shared';
            }
            if (! ($cacheHealth['low_overhead'] ?? false)) {
                $issues[] = 'cache_store_not_low_overhead';
            }
            if (! ($cacheProbe['write_healthy'] ?? false)) {
                $issues[] = 'cache_write_probe_failed';
            }
            if (! ($cacheProbe['read_healthy'] ?? false)) {
                $issues[] = 'cache_read_probe_failed';
            }
            if ((int) ($cacheHealth['dropped_writes'] ?? 0) > 0) {
                $issues[] = 'telemetry_writes_dropped';
            }
            if ((int) ($cacheHealth['read_failures'] ?? 0) > 0) {
                $issues[] = 'telemetry_reads_failed';
            }
            if (! ($queue['backlog_measurable'] ?? false)) {
                $issues[] = 'queue_backlog_unmeasurable';
            }
            if (! ($queue['oldest_job_measurable'] ?? false)) {
                $issues[] = 'queue_oldest_job_unmeasurable';
            }
            if (! ($queue['failed_jobs_measurable'] ?? false)) {
                $issues[] = 'failed_jobs_unmeasurable';
            }
            if (($queue['measurement_errors'] ?? []) !== []) {
                $issues[] = 'queue_measurement_errors_present';
            }
            if (is_numeric($queue['pending_jobs'] ?? null)
                && (float) $queue['pending_jobs'] > (float) config('capacity.shared.queue.max_pending_jobs', 250)) {
                $issues[] = 'queue_pending_jobs_above_threshold';
            }
            if (is_numeric($queue['oldest_job_minutes'] ?? null)
                && (float) $queue['oldest_job_minutes'] > (float) config('capacity.shared.queue.max_oldest_job_minutes', 10)) {
                $issues[] = 'queue_oldest_job_above_threshold';
            }
            if (is_numeric($queue['failed_jobs_24h'] ?? null)
                && (float) $queue['failed_jobs_24h'] > (float) config('capacity.shared.queue.max_failed_jobs_24h', 5)) {
                $issues[] = 'queue_failed_jobs_above_threshold';
            }

            $issues = array_values(array_unique($issues));

            return [
                'ready' => $issues === [],
                'observability' => [
                    'enabled' => $enabled,
                    'release' => $release !== '' ? $release : null,
                    'release_present' => $release !== '',
                ],
                'cache' => [
                    ...$cacheHealth,
                    'probe' => $cacheProbe,
                ],
                'queue' => $queue,
                'issues' => $issues,
            ];
        });
    }

    /**
     * @return array{write_healthy: bool, read_healthy: bool}
     */
    private function cacheProbe(): array
    {
        try {
            $token = bin2hex(random_bytes(16));
            $key = 'health:preflight-probe:'.hash('sha256', $token);
            $writeHealthy = $this->cache->put($key, $token, 1);
            $stored = $this->cache->values([$key])[$key] ?? null;

            return [
                'write_healthy' => $writeHealthy,
                'read_healthy' => $writeHealthy
                    && is_string($stored)
                    && hash_equals($token, $stored),
            ];
        } catch (Throwable) {
            return [
                'write_healthy' => false,
                'read_healthy' => false,
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function cacheHealth(): array
    {
        try {
            return $this->cache->health();
        } catch (Throwable $exception) {
            return [
                'store' => (string) config('observability.cache.store', config('cache.default', 'unknown')),
                'driver' => 'unknown',
                'shared' => false,
                'low_overhead' => false,
                'namespace' => null,
                'dropped_writes' => 0,
                'read_failures' => 0,
                'health_check_error' => $exception::class,
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function queueSummary(): array
    {
        try {
            return $this->queueHealth->summary();
        } catch (Throwable $exception) {
            return [
                'connection' => (string) config('queue.default', 'unknown'),
                'driver' => 'unknown',
                'measurable' => false,
                'backlog_measurable' => false,
                'oldest_job_measurable' => false,
                'failed_jobs_measurable' => false,
                'pending_jobs' => null,
                'oldest_job_minutes' => null,
                'failed_jobs_24h' => null,
                'measurement_errors' => ['queue_health_check_failed:'.$exception::class],
            ];
        }
    }
}
