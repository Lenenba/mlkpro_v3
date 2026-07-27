<?php

use App\Services\Capacity\CapacityPreflightService;
use App\Services\Observability\ObservabilityCacheStore;
use App\Services\Observability\TelemetryQueryGuard;
use App\Services\QueueHealthService;

beforeEach(function () {
    config()->set('observability.enabled', true);
    config()->set('observability.release', 'p0-006-preflight-test');
});

test('capacity preflight is ready when telemetry storage and queue measurements are healthy', function () {
    $guard = new TelemetryQueryGuard;
    $cache = Mockery::mock(ObservabilityCacheStore::class);
    $queue = Mockery::mock(QueueHealthService::class);
    $writtenValue = null;

    $cache->shouldReceive('put')
        ->once()
        ->andReturnUsing(function (string $key, mixed $value, int $ttlHours) use (&$writtenValue, $guard): bool {
            expect($guard->active())->toBeTrue()
                ->and($key)->toStartWith('health:preflight-probe:')
                ->and($ttlHours)->toBe(1);

            $writtenValue = $value;

            return true;
        });
    $cache->shouldReceive('values')
        ->once()
        ->andReturnUsing(function (array $keys) use (&$writtenValue, $guard): array {
            expect($guard->active())->toBeTrue()
                ->and($keys)->toHaveCount(1);

            return [$keys[0] => $writtenValue];
        });
    $cache->shouldReceive('health')
        ->once()
        ->andReturn([
            'store' => 'redis',
            'driver' => 'redis',
            'shared' => true,
            'low_overhead' => true,
            'namespace' => 'preflight-test',
            'dropped_writes' => 0,
            'read_failures' => 0,
        ]);
    $queue->shouldReceive('summary')
        ->once()
        ->andReturnUsing(function () use ($guard): array {
            expect($guard->active())->toBeTrue();

            return p0006HealthyPreflightQueue();
        });

    $summary = (new CapacityPreflightService($cache, $queue, $guard))->summary();

    expect($summary['ready'])->toBeTrue()
        ->and($summary['issues'])->toBe([])
        ->and($summary['observability'])->toMatchArray([
            'enabled' => true,
            'release' => 'p0-006-preflight-test',
            'release_present' => true,
        ])
        ->and($summary['cache']['probe'])->toBe([
            'write_healthy' => true,
            'read_healthy' => true,
        ])
        ->and($summary['queue']['backlog_measurable'])->toBeTrue()
        ->and($guard->active())->toBeFalse();
});

test('capacity preflight reports every unavailable prerequisite with stable issue codes', function () {
    config()->set('observability.enabled', false);
    config()->set('observability.release', null);

    $guard = new TelemetryQueryGuard;
    $cache = Mockery::mock(ObservabilityCacheStore::class);
    $queue = Mockery::mock(QueueHealthService::class);

    $cache->shouldReceive('put')->once()->andReturnFalse();
    $cache->shouldReceive('values')->once()->andReturn([]);
    $cache->shouldReceive('health')->once()->andReturn([
        'store' => 'array',
        'driver' => 'array',
        'shared' => false,
        'low_overhead' => false,
        'namespace' => 'preflight-test',
        'dropped_writes' => 2,
        'read_failures' => 1,
    ]);
    $queue->shouldReceive('summary')->once()->andReturn([
        'backlog_measurable' => false,
        'oldest_job_measurable' => false,
        'failed_jobs_measurable' => false,
        'pending_jobs' => null,
        'oldest_job_minutes' => null,
        'failed_jobs_24h' => null,
        'measurement_errors' => [
            'queue_backlog_not_persistent',
            'failed_jobs_backend_not_measurable',
        ],
    ]);

    $summary = (new CapacityPreflightService($cache, $queue, $guard))->summary();

    expect($summary['ready'])->toBeFalse()
        ->and($summary['issues'])->toBe([
            'observability_disabled',
            'release_missing',
            'cache_store_not_redis',
            'cache_store_not_shared',
            'cache_store_not_low_overhead',
            'cache_write_probe_failed',
            'cache_read_probe_failed',
            'telemetry_writes_dropped',
            'telemetry_reads_failed',
            'queue_backlog_unmeasurable',
            'queue_oldest_job_unmeasurable',
            'failed_jobs_unmeasurable',
            'queue_measurement_errors_present',
        ])
        ->and($summary['observability']['release'])->toBeNull()
        ->and($guard->active())->toBeFalse();
});

test('capacity preflight refuses to start load when queue thresholds are already exceeded', function () {
    config()->set('capacity.shared.queue.max_pending_jobs', 0);
    config()->set('capacity.shared.queue.max_oldest_job_minutes', 0);
    config()->set('capacity.shared.queue.max_failed_jobs_24h', 0);

    $guard = new TelemetryQueryGuard;
    $cache = Mockery::mock(ObservabilityCacheStore::class);
    $queue = Mockery::mock(QueueHealthService::class);
    $writtenValue = null;

    $cache->shouldReceive('put')->once()->andReturnUsing(
        function (string $key, mixed $value, int $ttlHours) use (&$writtenValue): bool {
            $writtenValue = $value;

            return true;
        }
    );
    $cache->shouldReceive('values')->once()->andReturnUsing(function (array $keys) use (&$writtenValue): array {
        return [$keys[0] => $writtenValue];
    });
    $cache->shouldReceive('health')->once()->andReturn([
        'store' => 'redis',
        'driver' => 'redis',
        'shared' => true,
        'low_overhead' => true,
        'namespace' => 'preflight-test',
        'dropped_writes' => 0,
        'read_failures' => 0,
    ]);
    $queue->shouldReceive('summary')->once()->andReturn([
        ...p0006HealthyPreflightQueue(),
        'pending_jobs' => 1,
        'oldest_job_minutes' => 1,
        'failed_jobs_24h' => 1,
    ]);

    $summary = (new CapacityPreflightService($cache, $queue, $guard))->summary();

    expect($summary['ready'])->toBeFalse()
        ->and($summary['issues'])->toBe([
            'queue_pending_jobs_above_threshold',
            'queue_oldest_job_above_threshold',
            'queue_failed_jobs_above_threshold',
        ]);
});

test('capacity preflight fails closed when health dependencies throw', function () {
    $guard = new TelemetryQueryGuard;
    $cache = Mockery::mock(ObservabilityCacheStore::class);
    $queue = Mockery::mock(QueueHealthService::class);

    $cache->shouldReceive('put')->once()->andThrow(new RuntimeException('cache unavailable'));
    $cache->shouldReceive('health')->once()->andThrow(new RuntimeException('cache unavailable'));
    $queue->shouldReceive('summary')->once()->andThrow(new RuntimeException('queue unavailable'));

    $service = new CapacityPreflightService($cache, $queue, $guard);
    $issues = $service->issues();

    expect($issues)->toContain('cache_health_check_failed')
        ->and($issues)->toContain('cache_write_probe_failed')
        ->and($issues)->toContain('cache_read_probe_failed')
        ->and($issues)->toContain('queue_backlog_unmeasurable')
        ->and($issues)->toContain('queue_oldest_job_unmeasurable')
        ->and($issues)->toContain('failed_jobs_unmeasurable')
        ->and($guard->active())->toBeFalse();
});

/**
 * @return array<string, mixed>
 */
function p0006HealthyPreflightQueue(): array
{
    return [
        'connection' => 'redis',
        'driver' => 'redis',
        'measurable' => true,
        'backlog_measurable' => true,
        'oldest_job_measurable' => true,
        'failed_jobs_measurable' => true,
        'pending_jobs' => 0,
        'oldest_job_minutes' => 0,
        'failed_jobs_24h' => 0,
        'measurement_errors' => [],
    ];
}
