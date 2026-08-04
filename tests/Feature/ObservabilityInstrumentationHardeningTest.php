<?php

use App\Services\Capacity\CapacityRunContextService;
use App\Services\Observability\ErrorMetricsService;
use App\Services\Observability\ObservabilityReportService;
use App\Services\Observability\RequestMetricsService;
use App\Services\Observability\SlowQueryService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Monolog\Handler\TestHandler;
use Monolog\Logger as MonologLogger;

beforeEach(function () {
    config()->set('observability.enabled', true);
    config()->set('observability.cache.store', 'array');
    config()->set('observability.cache.prefix', 'p0-006-tests-'.Str::lower(Str::random(12)));
    config()->set('observability.release', 'p0-006-instrumentation-test-release');
    config()->set('observability.request.slow_ms', 10_000);
    config()->set('observability.request.sample_size', 25);
    config()->set('observability.request.retention_hours', 24);
    config()->set('queue.default', 'database');

    Cache::store('array')->flush();
});

/**
 * @param  array<string, mixed>|null  $scope
 */
function p0006RequestMetric(string $routeName, ?array $scope = null): ?array
{
    return collect(app(RequestMetricsService::class)->summary($scope))
        ->firstWhere('route_name', $routeName);
}

it('records a slow query safely when no HTTP request is bound', function () {
    config()->set('observability.query.slow_ms', 1);
    $previousRequest = app()->bound('request') ? app('request') : null;
    app()->forgetInstance('request');

    try {
        app(SlowQueryService::class)->recordExecutedQuery(new QueryExecuted(
            'select * from jobs where id = ?',
            [123],
            500,
            DB::connection()
        ));
    } finally {
        if ($previousRequest instanceof Request) {
            app()->instance('request', $previousRequest);
        }
    }

    $sample = app(SlowQueryService::class)->summary()['recent'][0] ?? null;

    expect($sample)->not->toBeNull()
        ->and($sample['route_name'])->toBeNull();
});

function p0006StartInstrumentationScenario(string $scenarioKey): CapacityRunContextService
{
    config()->set('capacity.baseline.run_id', 'p0-006-instrumentation-'.$scenarioKey);
    config()->set('capacity.baseline.commit', '0123456789abcdef0123456789abcdef01234567');
    config()->set('capacity.baseline.started_at', now()->subMinute()->utc()->toIso8601String());
    config()->set('capacity.baseline.ended_at', now()->addMinutes(5)->utc()->toIso8601String());

    $runContext = app(CapacityRunContextService::class);
    expect($runContext->start($scenarioKey))->toBeTrue();

    return $runContext;
}

/**
 * @return array<string, mixed>
 */
function p0006InstrumentationScope(): array
{
    return [
        'environment' => (string) config('app.env'),
        'release' => config('observability.release'),
        'run_id' => config('capacity.baseline.run_id'),
        'commit' => config('capacity.baseline.commit'),
        'started_at' => config('capacity.baseline.started_at'),
        'ended_at' => config('capacity.baseline.ended_at'),
    ];
}

it('captures response body size and database query metrics for a request', function () {
    $routeName = 'p0-006.metrics';
    $body = 'observable-body';
    config()->set('observability.request.tracked_routes', [$routeName]);

    Route::middleware('web')->get('/p0-006/metrics', function () use ($body) {
        DB::select('select 1');

        return response($body, 200, ['Content-Type' => 'text/plain']);
    })->name($routeName);

    $this->get('/p0-006/metrics')
        ->assertOk()
        ->assertSeeText($body);

    $metric = p0006RequestMetric($routeName);

    expect($metric)->not->toBeNull()
        ->and(data_get($metric, 'response_body_bytes.p50'))->toBe((float) strlen($body))
        ->and(data_get($metric, 'query_count.p50'))->toBeGreaterThanOrEqual(1)
        ->and(data_get($metric, 'query_time_ms.p50'))->toBeFloat()
        ->and(data_get($metric, 'query_time_ms.p50'))->toBeGreaterThanOrEqual(0.0);
});

it('records request metrics when the downstream request throws', function () {
    $routeName = 'p0-006.exception';
    config()->set('observability.request.tracked_routes', [$routeName]);

    Route::middleware('web')->get('/p0-006/exception', function (): never {
        DB::select('select 1');

        throw new RuntimeException('Expected test exception');
    })->name($routeName);

    $this->withoutExceptionHandling();

    expect(fn () => $this->get('/p0-006/exception'))
        ->toThrow(RuntimeException::class, 'Expected test exception');

    $metric = p0006RequestMetric($routeName);

    expect($metric)->not->toBeNull()
        ->and($metric['count_24h'])->toBe(1)
        ->and($metric['error_count_24h'])->toBe(1)
        ->and(data_get($metric, 'status_classes.5xx'))->toBe(1)
        ->and(data_get($metric, 'query_count.p50'))->toBeGreaterThanOrEqual(1)
        ->and(data_get($metric, 'response_body_bytes.p50'))->toBeNull();
});

it('classifies validation exceptions as 422 instead of application errors', function () {
    $routeName = 'p0-006.validation-exception';
    config()->set('observability.request.tracked_routes', [$routeName]);

    Route::middleware('web')->post('/p0-006/validation-exception', function (): never {
        throw ValidationException::withMessages(['field' => ['Invalid test value.']]);
    })->name($routeName);

    $this->withoutExceptionHandling();

    expect(fn () => $this->post('/p0-006/validation-exception'))
        ->toThrow(ValidationException::class);

    $metric = p0006RequestMetric($routeName);

    expect($metric)->not->toBeNull()
        ->and($metric['error_count_24h'])->toBe(0)
        ->and(data_get($metric, 'status_codes.422'))->toBe(1)
        ->and(data_get($metric, 'status_classes.4xx'))->toBe(1)
        ->and(data_get($metric, 'status_classes.5xx'))->toBeNull();
});

it('keeps HTTP success separate from the configured business outcome', function () {
    $routeName = 'p0-006.business-outcome';
    config()->set('observability.request.tracked_routes', [$routeName]);
    config()->set('capacity.scenarios', [
        'business_outcome_probe' => [
            'label' => 'Business outcome probe',
            'route_name' => $routeName,
            'method' => 'POST',
            'accepted_status_codes' => [201],
            'protocol' => [
                'authentication' => 'public',
                'csrf' => false,
                'fixture_reference' => 'external:test-probe',
                'outcome' => [
                    'strategy' => 'json_field_equals',
                    'field' => 'tone',
                    'value' => 'success',
                ],
            ],
            'profile' => [
                'virtual_users' => 1,
                'duration' => '1m',
                'ramp_up' => '1s',
                'request_interval_ms' => 1000,
                'request_timeout_ms' => 10000,
                'minimum_completed_requests' => 1,
            ],
            'safety' => [
                'mode' => 'controlled_write',
                'requires_isolated_tenant' => true,
                'external_effects' => [],
            ],
            'blocker' => ['reason' => null, 'owner' => null, 'review_at' => null],
            'targets' => ['min_samples' => 1, 'p95_ms' => 500, 'p99_ms' => 750, 'error_count_24h' => 0],
            'remediation' => [],
        ],
    ]);

    Route::middleware('web')->post('/p0-006/business-outcome', fn () => response()->json([
        'tone' => 'warning',
    ], 201))->name($routeName);

    $runContext = p0006StartInstrumentationScenario('business_outcome_probe');
    $scope = p0006InstrumentationScope();
    $this->postJson('/p0-006/business-outcome')->assertCreated();
    expect($runContext->stop('business_outcome_probe'))->toBeTrue();

    $metric = p0006RequestMetric($routeName, $scope);

    expect($metric)->not->toBeNull()
        ->and($metric['business_success_count'])->toBe(0)
        ->and($metric['business_failure_count'])->toBe(1)
        ->and(data_get($metric, 'status_codes.201'))->toBe(1);
});

it('fails a JSON business assertion when the successful response is malformed', function () {
    $routeName = 'p0-006.malformed-business-outcome';
    config()->set('observability.request.tracked_routes', [$routeName]);
    config()->set('capacity.scenarios', [
        'malformed_business_outcome_probe' => [
            'label' => 'Malformed business outcome probe',
            'route_name' => $routeName,
            'method' => 'POST',
            'accepted_status_codes' => [201],
            'protocol' => [
                'authentication' => 'public',
                'csrf' => false,
                'fixture_reference' => 'external:malformed-test-probe',
                'outcome' => [
                    'strategy' => 'json_field_equals',
                    'field' => 'tone',
                    'value' => 'success',
                ],
            ],
            'profile' => [
                'virtual_users' => 1,
                'duration' => '1m',
                'ramp_up' => '1s',
                'request_interval_ms' => 1000,
                'request_timeout_ms' => 10000,
                'minimum_completed_requests' => 1,
            ],
            'safety' => [
                'mode' => 'controlled_write',
                'requires_isolated_tenant' => true,
                'external_effects' => [],
            ],
            'blocker' => ['reason' => null, 'owner' => null, 'review_at' => null],
            'targets' => ['min_samples' => 1, 'p95_ms' => 500, 'p99_ms' => 750, 'error_count_24h' => 0],
            'remediation' => [],
        ],
    ]);

    Route::middleware('web')->post(
        '/p0-006/malformed-business-outcome',
        fn () => response('{malformed', 201, ['Content-Type' => 'application/json'])
    )->name($routeName);

    $runContext = p0006StartInstrumentationScenario('malformed_business_outcome_probe');
    $scope = p0006InstrumentationScope();
    $this->postJson('/p0-006/malformed-business-outcome')->assertCreated();
    expect($runContext->stop('malformed_business_outcome_probe'))->toBeTrue();

    $metric = p0006RequestMetric($routeName, $scope);

    expect($metric)->not->toBeNull()
        ->and($metric['business_success_count'])->toBe(0)
        ->and($metric['business_failure_count'])->toBe(1);
});

it('keeps client sentinels out of reports cache samples and structured logs', function () {
    $routeName = 'p0-006.private';
    $sentinel = 'client-secret-98765';
    $channel = 'p0_006_telemetry_'.Str::lower(Str::random(8));
    $namespace = (string) config('observability.cache.prefix');

    config()->set('observability.request.tracked_routes', [$routeName]);
    config()->set('observability.request.slow_ms', 1);
    config()->set('observability.query.slow_ms', 1);
    config()->set('observability.log_channel', $channel);
    config()->set("logging.channels.{$channel}", [
        'driver' => 'monolog',
        'handler' => TestHandler::class,
        'level' => 'debug',
    ]);

    Log::forgetChannel($channel);
    $logger = Log::channel($channel)->getLogger();
    expect($logger)->toBeInstanceOf(MonologLogger::class);
    $handler = $logger->getHandlers()[0];
    expect($handler)->toBeInstanceOf(TestHandler::class);

    Route::middleware('web')->get('/p0-006/private/{account}', function (Request $request) use ($sentinel) {
        $currentRouteName = (string) $request->route()?->getName();

        app(SlowQueryService::class)->record(
            "select * from customers where email = '{$sentinel}' -- {$sentinel}",
            500,
            [
                'connection' => 'testing',
                'bindings_count' => 0,
                'route_name' => $currentRouteName,
            ]
        );
        app(ErrorMetricsService::class)->record(
            new RuntimeException("Customer payload {$sentinel}"),
            $request
        );
        usleep(2_000);

        return response('safe');
    })->name($routeName);

    $this->get("/p0-006/private/{$sentinel}")->assertOk();

    $reportPayload = json_encode(
        app(ObservabilityReportService::class)->summary(),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
    );
    $routeHash = sha1($routeName);
    $cachePayload = json_encode([
        Cache::store('array')->get("{$namespace}:requests:samples:{$routeHash}"),
        Cache::store('array')->get("{$namespace}:queries:samples"),
        Cache::store('array')->get("{$namespace}:queries:route-samples:{$routeHash}"),
        Cache::store('array')->get("{$namespace}:errors:samples"),
    ], JSON_THROW_ON_ERROR);
    $logPayload = json_encode(collect($handler->getRecords())
        ->map(fn ($record): array => [
            'message' => $record->message,
            'context' => $record->context,
        ])
        ->all(), JSON_THROW_ON_ERROR);

    expect($reportPayload)->not->toContain($sentinel)
        ->and($cachePayload)->not->toContain($sentinel)
        ->and($logPayload)->not->toContain($sentinel)
        ->and($reportPayload)->toContain('/p0-006/private/{account}');
});

it('keeps exact request counters after the bounded sample buffer is full', function () {
    $routeName = 'p0-006.counter';
    config()->set('observability.request.tracked_routes', [$routeName]);
    config()->set('observability.request.sample_size', 25);

    foreach (range(1, 40) as $sampleNumber) {
        app(RequestMetricsService::class)->recordRouteSample($routeName, $sampleNumber, 200, [
            'method' => 'GET',
            'response_body_bytes' => 100 + $sampleNumber,
            'query_count' => 2,
            'query_time_ms' => 1.5,
        ]);
    }

    $metric = p0006RequestMetric($routeName);

    expect($metric)->not->toBeNull()
        ->and($metric['count_24h'])->toBe(40)
        ->and($metric['sample_count_24h'])->toBe(25)
        ->and($metric['truncated'])->toBeTrue();
});

it('reports unavailable data quality and records nothing when observability is disabled', function () {
    $routeName = 'p0-006.disabled';
    config()->set('observability.request.tracked_routes', [$routeName]);
    config()->set('observability.enabled', false);

    Route::middleware('web')->get('/p0-006/disabled', fn () => response('disabled'))
        ->name($routeName);

    $this->get('/p0-006/disabled')->assertOk();

    $report = app(ObservabilityReportService::class)->summary();

    expect(p0006RequestMetric($routeName))->toBeNull()
        ->and(data_get($report, 'data_quality.status'))->toBe('unavailable')
        ->and(data_get($report, 'data_quality.issues'))->toContain('observability_disabled')
        ->and($report['status'])->toBe('unknown');
});

it('keeps successful requests successful when the observability logger fails', function () {
    $routeName = 'p0-006.logger-failure';
    config()->set('observability.request.tracked_routes', [$routeName]);
    config()->set('observability.request.slow_ms', 1);
    config()->set('observability.log_channel', 'broken-observability');

    Log::shouldReceive('channel')
        ->once()
        ->with('broken-observability')
        ->andThrow(new RuntimeException('Logger unavailable'));

    Route::middleware('web')->get('/p0-006/logger-failure', function () {
        usleep(2_000);

        return response('business-response');
    })->name($routeName);

    $this->get('/p0-006/logger-failure')
        ->assertOk()
        ->assertSeeText('business-response');

    expect(p0006RequestMetric($routeName))->not->toBeNull();
});

it('keeps successful requests successful and reports unavailable quality when the cache fails', function () {
    $routeName = 'p0-006.cache-failure';
    config()->set('observability.request.tracked_routes', [$routeName]);
    config()->set('observability.cache.store', 'missing-observability-store');

    Route::middleware('web')->get('/p0-006/cache-failure', fn () => response('business-response'))
        ->name($routeName);

    $this->get('/p0-006/cache-failure')
        ->assertOk()
        ->assertSeeText('business-response');

    $report = app(ObservabilityReportService::class)->summary();

    expect(data_get($report, 'data_quality.status'))->toBe('unavailable')
        ->and(data_get($report, 'data_quality.issues'))->toContain('telemetry_reads_failed')
        ->and(data_get($report, 'data_quality.storage.dropped_writes'))->toBeGreaterThan(0)
        ->and(data_get($report, 'data_quality.storage.read_failures'))->toBeGreaterThan(0)
        ->and($report['status'])->not->toBe('healthy');
});
