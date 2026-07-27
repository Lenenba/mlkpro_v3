<?php

use App\Services\Capacity\CapacityRunContextService;
use App\Services\Observability\ErrorMetricsService;
use App\Services\Observability\ObservabilityCacheStore;
use App\Services\Observability\RequestMetricsService;
use App\Services\Observability\SlowQueryService;
use App\Services\Observability\TelemetryScope;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('app.env', 'testing');
    config()->set('observability.enabled', true);
    config()->set('observability.release', 'p0-006-release');
    config()->set('observability.cache.store', 'array');
    config()->set('observability.cache.prefix', 'p0-006-scoped-'.Str::lower(Str::random(12)));
    config()->set('observability.request.tracked_routes', ['dashboard']);
    config()->set('observability.request.sample_size', 25);
    config()->set('observability.request.max_scope_samples', 50);
    config()->set('observability.request.slow_ms', 10_000);
    config()->set('observability.request.retention_hours', 24);

    Cache::store('array')->flush();
    Carbon::setTestNow('2026-07-27T12:00:00Z');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * @return array<string, mixed>
 */
function p0006ScopedRequestScope(string $runId = 'run-scoped-requests'): array
{
    $scope = [
        'environment' => 'testing',
        'release' => 'p0-006-release',
        'run_id' => $runId,
        'commit' => '0123456789abcdef0123456789abcdef01234567',
        'started_at' => '2026-07-27T11:55:00Z',
        'ended_at' => '2026-07-27T12:20:00Z',
    ];

    config()->set('capacity.baseline.run_id', $scope['run_id']);
    config()->set('capacity.baseline.commit', $scope['commit']);
    config()->set('capacity.baseline.started_at', $scope['started_at']);
    config()->set('capacity.baseline.ended_at', $scope['ended_at']);

    return [
        ...$scope,
        'scope_id' => app(TelemetryScope::class)->idFor($scope),
        'route_names' => ['dashboard'],
    ];
}

it('returns the atomic counter value after each increment', function () {
    $cache = app(ObservabilityCacheStore::class);

    expect($cache->incrementValue('test:sequence', 24))->toBe(1)
        ->and($cache->incrementValue('test:sequence', 24, 2))->toBe(3);
});

it('keeps the scenario captured at request start after the scenario is stopped', function () {
    $scope = p0006ScopedRequestScope('run-captured-request-context');
    $runContext = app(CapacityRunContextService::class);
    $metrics = app(RequestMetricsService::class);
    expect($runContext->start('dashboard_usage'))->toBeTrue();

    $request = Request::create('/dashboard', 'GET');
    $request->setRouteResolver(fn () => Route::getRoutes()->getByName('dashboard'));
    $metrics->beginRequest($request);
    $captured = $metrics->finishRequest($request);

    expect($runContext->stop('dashboard_usage'))->toBeTrue();
    $metrics->record($request, 200, 25, 100, $captured, true);

    $scoped = collect($metrics->summary($scope))->firstWhere('route_name', 'dashboard');

    expect($scoped)->not->toBeNull()
        ->and(data_get($scoped, 'by_scenario.dashboard_usage.count_24h'))->toBe(1)
        ->and(data_get($scoped, 'by_scenario.dashboard_usage.business_success_count'))->toBe(1);
});

it('captures every scoped request separately and keeps the operational buffer isolated', function () {
    $scope = p0006ScopedRequestScope();
    expect(app(CapacityRunContextService::class)->start('dashboard_usage'))->toBeTrue();

    foreach (range(1, 30) as $sampleNumber) {
        app(RequestMetricsService::class)->recordRouteSample('dashboard', $sampleNumber, 200, [
            'method' => 'GET',
            'business_success' => true,
        ]);
    }
    expect(app(CapacityRunContextService::class)->stop('dashboard_usage'))->toBeTrue();
    foreach (range(1, 2) as $sampleNumber) {
        app(RequestMetricsService::class)->recordRouteSample('dashboard', $sampleNumber, 200, [
            'method' => 'GET',
            'business_success' => true,
        ]);
    }

    $scoped = collect(app(RequestMetricsService::class)->summary($scope))
        ->firstWhere('route_name', 'dashboard');

    expect($scoped)->not->toBeNull()
        ->and($scoped['sampling_strategy'])->toBe('complete_scope_capture')
        ->and($scoped['count_24h'])->toBe(30)
        ->and($scoped['sample_count_24h'])->toBe(30)
        ->and($scoped['truncated'])->toBeFalse()
        ->and(data_get($scoped, 'by_scenario.dashboard_usage.count_24h'))->toBe(30)
        ->and(data_get($scoped, 'by_scenario.dashboard_usage.sample_count_24h'))->toBe(30)
        ->and(data_get($scoped, 'by_scenario.dashboard_usage.business_success_count'))->toBe(30);

    Carbon::setTestNow('2026-07-27T12:21:00Z');
    app(RequestMetricsService::class)->recordRouteSample('dashboard', 40, 200, [
        'method' => 'GET',
        'business_success' => true,
    ]);

    $operational = collect(app(RequestMetricsService::class)->summary())
        ->firstWhere('route_name', 'dashboard');
    $scopedAfterRun = collect(app(RequestMetricsService::class)->summary($scope))
        ->firstWhere('route_name', 'dashboard');

    expect($operational)->not->toBeNull()
        ->and($operational['sampling_strategy'])->toBe('bounded_operational_buffer')
        ->and($operational['count_24h'])->toBe(3)
        ->and($operational['sample_count_24h'])->toBe(3)
        ->and($operational['truncated'])->toBeFalse()
        ->and($scopedAfterRun['count_24h'])->toBe(30)
        ->and($scopedAfterRun['sample_count_24h'])->toBe(30)
        ->and(data_get($scopedAfterRun, 'by_scenario.dashboard_usage.count_24h'))->toBe(30);
});

it('keeps exact per-scenario counters when the scoped capture limit is reached', function () {
    config()->set('observability.request.max_scope_samples', 3);
    $scope = p0006ScopedRequestScope('run-scoped-limit');
    expect(app(CapacityRunContextService::class)->start('dashboard_usage'))->toBeTrue();

    foreach (range(1, 5) as $sampleNumber) {
        app(RequestMetricsService::class)->recordRouteSample('dashboard', $sampleNumber, 200, [
            'method' => 'GET',
            'business_success' => true,
        ]);
    }

    $scoped = collect(app(RequestMetricsService::class)->summary($scope))
        ->firstWhere('route_name', 'dashboard');

    expect($scoped)->not->toBeNull()
        ->and($scoped['count_24h'])->toBe(5)
        ->and($scoped['sample_count_24h'])->toBe(3)
        ->and($scoped['truncated'])->toBeTrue()
        ->and(data_get($scoped, 'by_scenario.dashboard_usage.count_24h'))->toBe(5)
        ->and(data_get($scoped, 'by_scenario.dashboard_usage.sample_count_24h'))->toBe(3)
        ->and(data_get($scoped, 'by_scenario.dashboard_usage.truncated'))->toBeTrue();
});

it('counts arbitrary minute buckets correctly across an hour boundary', function () {
    config()->set('observability.cache.counter_bucket_minutes', 7);
    config()->set('observability.request.tracked_routes', ['bucket-boundary']);
    config()->set('observability.request.sample_size', 25);
    config()->set('capacity.baseline.run_id', null);

    Carbon::setTestNow('2026-07-27T12:59:00Z');
    foreach (range(1, 40) as $sampleNumber) {
        app(RequestMetricsService::class)->recordRouteSample('bucket-boundary', $sampleNumber, 200);
    }

    Carbon::setTestNow('2026-07-27T13:02:00Z');
    $metric = collect(app(RequestMetricsService::class)->summary())
        ->firstWhere('route_name', 'bucket-boundary');

    expect($metric)->not->toBeNull()
        ->and($metric['count_24h'])->toBe(40)
        ->and($metric['sample_count_24h'])->toBe(25)
        ->and($metric['truncated'])->toBeTrue();
});

it('epoch-aligns arbitrary counter buckets in every telemetry service', function () {
    config()->set('observability.cache.counter_bucket_minutes', 7);
    $timestamp = Carbon::parse('2026-07-27T13:02:37.456789Z');
    $bucketSeconds = 7 * 60;

    foreach ([RequestMetricsService::class, SlowQueryService::class, ErrorMetricsService::class] as $serviceClass) {
        $method = new ReflectionMethod($serviceClass, 'counterBucket');
        $bucket = $method->invoke(app($serviceClass), $timestamp);

        expect($bucket)->toBeInstanceOf(Carbon::class)
            ->and($bucket->getTimestamp() % $bucketSeconds)->toBe(0)
            ->and($bucket->lessThanOrEqualTo($timestamp))->toBeTrue()
            ->and($bucket->copy()->addSeconds($bucketSeconds)->greaterThan($timestamp))->toBeTrue();
    }
});
