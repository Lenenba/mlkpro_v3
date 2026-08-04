<?php

use App\Services\Capacity\CapacityPreflightService;
use App\Services\Capacity\CapacityReportService;
use App\Services\Capacity\CapacityRunContextService;
use App\Services\Capacity\CapacityRunnerResultService;
use App\Services\Capacity\CapacityScenarioCatalog;
use App\Services\Observability\RequestMetricsService;
use App\Services\QueueHealthService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Carbon::setTestNow('2026-07-27T12:01:00Z');

    config()->set('observability.enabled', true);
    config()->set('observability.cache.store', 'array');
    config()->set('observability.cache.prefix', 'p0-006-capacity-tests');
    config()->set('observability.release', 'p0-006-test-release');
    config()->set('observability.request.max_scope_samples', 500);
    config()->set('queue.default', 'database');
    config()->set('capacity.allowed_staging_environments', ['p0-006-staging']);

    Cache::store('array')->flush();
});

afterEach(function () {
    Carbon::setTestNow();
});

function p0006DashboardScenario(array $overrides = []): array
{
    return array_replace_recursive([
        'label' => 'Dashboard usage',
        'route_name' => 'dashboard',
        'method' => 'GET',
        'accepted_status_codes' => [200],
        'protocol' => [
            'authentication' => 'authenticated_session',
            'csrf' => false,
            'fixture_strategy' => 'repeat',
            'unique_by' => [],
            'preparation' => [],
            'fixture_reference' => 'external:dashboard-owner',
            'outcome' => ['strategy' => 'status_code'],
        ],
        'profile' => [
            'virtual_users' => 1,
            'duration' => '1m',
            'ramp_up' => '10s',
            'request_interval_ms' => 1000,
            'request_timeout_ms' => 10000,
            'minimum_completed_requests' => 1,
        ],
        'safety' => [
            'mode' => 'read_only',
            'requires_isolated_tenant' => false,
            'external_effects' => [],
        ],
        'blocker' => [
            'reason' => null,
            'owner' => null,
            'review_at' => null,
        ],
        'targets' => [
            'min_samples' => 1,
            'p95_ms' => 500,
            'p99_ms' => 750,
            'error_count_24h' => 0,
        ],
        'remediation' => ['Profile the dashboard request.'],
    ], $overrides);
}

function p0006ConfigureCompleteBaseline(): void
{
    config()->set('app.env', 'p0-006-staging');
    config()->set('observability.request.tracked_routes', ['dashboard']);
    config()->set('capacity.scenarios', [
        'dashboard_usage' => p0006DashboardScenario(),
    ]);
    config()->set('capacity.baseline', [
        'run_id' => 'p0-006-guardrail-run',
        'environment' => 'p0-006-staging',
        'commit' => '0123456789abcdef0123456789abcdef01234567',
        'started_at' => '2026-07-27T12:00:00Z',
        'ended_at' => '2026-07-27T12:10:00Z',
        'traffic' => 'approved isolated capacity harness',
        'runner' => 'k6@0.52.0',
        'runner_hash' => hash('sha256', 'approved-test-runner'),
        'fixture_hash' => hash('sha256', 'approved-test-fixtures'),
        'allowed_origins' => 'https://capacity-staging.example.test',
        'exclusions' => 'customer data, raw responses',
        'mode' => 'staging',
        'representative' => true,
        'approved' => true,
        'approval_reference' => 'CHANGE-P0-006-TEST',
        'queue_canaries_verified' => true,
        'isolated_tenant_verified' => true,
        'owner' => 'capacity-owner',
        'validator' => 'capacity-validator',
    ]);
}

/**
 * @param  array<int, array<string, mixed>>  $samples
 * @param  array<string, int|float>  $latency
 * @return array<string, mixed>
 */
function p0006CompleteScenarioRun(
    array $samples,
    bool $recordQueueSnapshots = true,
    bool $importRunnerResult = true,
    ?int $runnerCompletedRequests = null,
    array $latency = ['p50' => 100, 'p95' => 200, 'p99' => 300, 'max' => 400],
    bool $coverQueueInterval = true
): array {
    p0006ConfigureCompleteBaseline();

    $runContext = app(CapacityRunContextService::class);
    expect($runContext->start('dashboard_usage'))->toBeTrue();

    if ($recordQueueSnapshots) {
        app(QueueHealthService::class)->summary(record: true);
    }

    foreach ($samples as $sample) {
        app(RequestMetricsService::class)->recordRouteSample(
            'dashboard',
            (float) ($sample['duration_ms'] ?? 50),
            (int) ($sample['status_code'] ?? 200),
            [
                'method' => $sample['method'] ?? 'GET',
                'business_success' => $sample['business_success'] ?? true,
                'response_body_bytes' => $sample['response_body_bytes'] ?? 500,
                'query_count' => $sample['query_count'] ?? 2,
                'query_time_ms' => $sample['query_time_ms'] ?? 5.0,
            ]
        );
    }

    if ($coverQueueInterval) {
        Carbon::setTestNow('2026-07-27T12:02:00Z');
    }
    if ($recordQueueSnapshots) {
        app(QueueHealthService::class)->summary(record: true);
    }
    if (! $coverQueueInterval) {
        Carbon::setTestNow('2026-07-27T12:02:00Z');
    }
    expect($runContext->stop('dashboard_usage'))->toBeTrue();

    Carbon::setTestNow('2026-07-27T12:11:00Z');
    if ($importRunnerResult) {
        $preflight = \Mockery::mock(CapacityPreflightService::class);
        $preflight->shouldReceive('summary')->andReturn([
            'ready' => true,
            'issues' => [],
        ]);
        app()->instance(CapacityPreflightService::class, $preflight);

        $scenario = collect(app(CapacityScenarioCatalog::class)->all())
            ->firstWhere('key', 'dashboard_usage');
        $completed = $runnerCompletedRequests ?? count($samples);

        app(CapacityRunnerResultService::class)->ingest([
            'schema_version' => CapacityRunnerResultService::SCHEMA_VERSION,
            'run_id' => 'p0-006-guardrail-run',
            'environment' => 'p0-006-staging',
            'commit' => '0123456789abcdef0123456789abcdef01234567',
            'scenario_key' => 'dashboard_usage',
            'manifest_hash' => $scenario['manifest_hash'],
            'fixture_hash' => hash('sha256', 'approved-test-fixtures'),
            'baseline_fingerprint' => app(CapacityRunnerResultService::class)->baselineFingerprint(),
            'target_origin_hash' => hash('sha256', 'https://capacity-staging.example.test'),
            'runner' => 'k6@0.52.0',
            'runner_hash' => hash('sha256', 'approved-test-runner'),
            'started_at' => '2026-07-27T12:01:00Z',
            'ended_at' => '2026-07-27T12:02:00Z',
            'virtual_users' => 1,
            'duration_seconds' => 60,
            'ramp_up_seconds' => 10,
            'request_interval_ms' => 1000,
            'request_timeout_ms' => 10000,
            'attempted_requests' => $completed,
            'completed_requests' => $completed,
            'transport_errors' => 0,
            'assertion_failures' => 0,
            'client_latency_ms' => $latency,
        ]);
    }

    return app(CapacityReportService::class)->summary();
}

test('P0-006 exposes exactly the seven executable scenarios with their methods and routes', function () {
    $expected = [
        'dashboard_usage' => ['method' => 'GET', 'route_names' => ['dashboard']],
        'customer_detail_access' => ['method' => 'GET', 'route_names' => ['customer.show']],
        'reservation_creation' => ['method' => 'POST', 'route_names' => ['client.reservations.store']],
        'sales_creation' => ['method' => 'POST', 'route_names' => ['sales.store']],
        'public_request_submission' => ['method' => 'POST', 'route_names' => ['public.requests.store']],
        'public_store_browse' => ['method' => 'GET', 'route_names' => ['public.store.show']],
        'public_store_checkout' => ['method' => 'POST', 'route_names' => ['public.store.checkout']],
    ];

    $catalog = app(CapacityScenarioCatalog::class);
    $actual = collect($catalog->all())
        ->mapWithKeys(fn (array $scenario): array => [
            $scenario['key'] => [
                'method' => $scenario['method'],
                'route_names' => $scenario['route_names'],
            ],
        ])
        ->all();

    expect($actual)->toBe($expected)
        ->and($catalog->issues())->toBe([]);

    $checkout = collect($catalog->all())->firstWhere('key', 'public_store_checkout');
    expect($checkout['protocol']['fixture_strategy'])->toBe('one_shot')
        ->and($checkout['protocol']['unique_by'])->toBe([
            ['headers.Cookie'],
            ['body.email'],
        ])
        ->and($checkout['protocol']['preparation'])->toBe([
            [
                'method' => 'POST',
                'route_name' => 'public.store.cart.add',
                'route_uri' => '/store/{slug}/cart',
                'accepted_status_codes' => [200],
                'authentication' => 'public_cart_session',
                'csrf' => true,
                'share_session_headers' => true,
                'outcome' => ['strategy' => 'json_key_present', 'field' => 'cart'],
            ],
        ]);

    foreach ($catalog->all() as $scenario) {
        expect($scenario['manifest_hash'])->toMatch('/^[a-f0-9]{64}$/');
        foreach ($scenario['route_names'] as $routeName) {
            $route = Route::getRoutes()->getByName($routeName);

            expect($route)->not->toBeNull()
                ->and($route->methods())->toContain($scenario['method']);
        }
    }
});

test('capacity scenario request cadence must be a positive canonical profile integer', function () {
    config()->set('capacity.scenarios', [
        'dashboard_usage' => p0006DashboardScenario([
            'profile' => ['request_interval_ms' => 0],
        ]),
    ]);

    expect(app(CapacityScenarioCatalog::class)->issues())->toContain(
        'Scenario dashboard_usage profile request_interval_ms must be a positive integer.'
    );
});

test('capacity scenario request timeout must stay inside the signed safe range', function () {
    config()->set('capacity.scenarios', [
        'dashboard_usage' => p0006DashboardScenario([
            'profile' => ['request_timeout_ms' => 499],
        ]),
    ]);

    expect(app(CapacityScenarioCatalog::class)->issues())->toContain(
        'Scenario dashboard_usage profile request_timeout_ms must be an integer between 500 and 60000.'
    );
});

test('capacity scenarios reject unsafe controlled-write fixture contracts and load envelopes', function () {
    config()->set('capacity.scenarios', [
        'dashboard_usage' => p0006DashboardScenario([
            'protocol' => [
                'fixture_strategy' => 'repeat',
                'unique_by' => [],
            ],
            'profile' => [
                'virtual_users' => 15,
                'duration' => '10m',
                'ramp_up' => '2m',
                'request_interval_ms' => 44_999,
                'minimum_request_interval_ms' => 45_000,
                'minimum_completed_requests' => 500,
            ],
            'safety' => [
                'mode' => 'controlled_write',
                'requires_isolated_tenant' => true,
            ],
        ]),
    ]);

    $catalog = app(CapacityScenarioCatalog::class);

    expect($catalog->maximumTheoreticalRequests([
        'virtual_users' => 15,
        'duration' => '10m',
        'ramp_up' => '2m',
        'request_interval_ms' => 45_000,
    ]))->toBe(187)
        ->and($catalog->maximumTheoreticalRequests([
            'virtual_users' => 20,
            'duration' => '10m',
            'ramp_up' => '2m',
            'request_interval_ms' => 45_000,
        ]))->toBe(250)
        ->and($catalog->maximumTheoreticalRequests([
            'virtual_users' => 10,
            'duration' => '10m',
            'ramp_up' => '2m',
            'request_interval_ms' => 45_000,
        ]))->toBe(125)
        ->and($catalog->issues())->toContain(
            'Scenario dashboard_usage profile request_interval_ms is below its signed safety minimum.'
        )
        ->toContain('Scenario dashboard_usage minimum_completed_requests exceeds its theoretical request budget.')
        ->toContain('Scenario dashboard_usage controlled_write safety must use one_shot fixtures.')
        ->toContain('Scenario dashboard_usage controlled_write safety must define at least one semantic unique_by group.');
});

test('a non-persistent queue reports unknown measurements and fails strict health validation', function () {
    config()->set('queue.default', 'sync');
    config()->set('queue.failed.driver', 'null');

    $health = app(QueueHealthService::class)->summary();

    expect($health['backlog_measurable'])->toBeFalse()
        ->and($health['oldest_job_measurable'])->toBeFalse()
        ->and($health['failed_jobs_measurable'])->toBeFalse()
        ->and($health['pending_jobs'])->toBeNull()
        ->and($health['oldest_job_minutes'])->toBeNull()
        ->and($health['failed_jobs_24h'])->toBeNull()
        ->and($health['measurement_errors'])->toContain('queue_backlog_not_persistent')
        ->and($health['measurement_errors'])->toContain('failed_jobs_backend_not_measurable');

    $exitCode = Artisan::call('queue:health', ['--json' => true, '--strict' => true]);
    $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['pending_jobs'])->toBeNull()
        ->and($payload['failed_jobs_24h'])->toBeNull();
});

test('an incomplete capacity context cannot pass strict validation', function () {
    config()->set('observability.request.tracked_routes', ['dashboard']);
    config()->set('capacity.scenarios', [
        'dashboard_usage' => p0006DashboardScenario(),
    ]);
    config()->set('capacity.baseline', [
        'run_id' => 'p0-006-context-test',
        'environment' => 'testing',
        'commit' => null,
        'started_at' => now()->subMinutes(5)->toIso8601String(),
        'ended_at' => now()->addMinutes(5)->toIso8601String(),
        'traffic' => 'one controlled virtual user',
        'exclusions' => 'none',
        'owner' => 'capacity-owner',
        'validator' => null,
    ]);

    $summary = app(CapacityReportService::class)->summary();

    expect($summary['baseline_context']['status'])->toBe('incomplete')
        ->and($summary['baseline_context']['missing'])->toContain('commit')
        ->and($summary['baseline_context']['missing'])->toContain('runner')
        ->and($summary['baseline_context']['missing'])->toContain('validator')
        ->and($summary['scenarios'][0]['status'])->toBe('insufficient_data')
        ->and($summary['status'])->not->toBe('healthy');

    $exitCode = Artisan::call('capacity:report', ['--json' => true, '--strict' => true]);
    $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['baseline_context']['status'])->toBe('incomplete');
});

test('capacity plan and scenario start fail closed when the runtime preflight is not ready', function () {
    p0006ConfigureCompleteBaseline();

    $planExit = Artisan::call('capacity:plan', ['--json' => true]);
    $plan = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($planExit)->toBe(1)
        ->and($plan['status'])->toBe('invalid')
        ->and($plan['baseline_fingerprint'])->toBe(
            app(CapacityRunnerResultService::class)->baselineFingerprint()
        )
        ->and($plan['preflight']['ready'])->toBeFalse()
        ->and($plan['issues'])->toContain('cache_store_not_shared');

    $startExit = Artisan::call('capacity:scenario:start', ['scenario' => 'dashboard_usage']);

    expect($startExit)->toBe(1)
        ->and(Artisan::output())->toContain('capacity execution plan is not ready');
});

test('a scenario cannot start when the baseline window cannot contain the full profile', function () {
    p0006ConfigureCompleteBaseline();
    Carbon::setTestNow('2026-07-27T12:09:30Z');
    $this->mock(CapacityPreflightService::class, function ($mock): void {
        $mock->shouldReceive('summary')->once()->andReturn([
            'ready' => true,
            'issues' => [],
        ]);
    });

    $exitCode = Artisan::call('capacity:scenario:start', ['scenario' => 'dashboard_usage']);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('does not have enough time left');
});

test('a scenario can only run once inside the same baseline scope', function () {
    p0006ConfigureCompleteBaseline();
    $runContext = app(CapacityRunContextService::class);

    expect($runContext->start('dashboard_usage'))->toBeTrue()
        ->and($runContext->stop('dashboard_usage'))->toBeTrue()
        ->and($runContext->start('dashboard_usage'))->toBeFalse();
});

test('a forced queue boundary snapshot bypasses minute deduplication', function () {
    p0006ConfigureCompleteBaseline();
    $runContext = app(CapacityRunContextService::class);
    $queueHealth = app(QueueHealthService::class);
    expect($runContext->start('dashboard_usage'))->toBeTrue();

    expect($queueHealth->summary(record: true)['snapshot_recorded'])->toBeTrue();
    Carbon::setTestNow('2026-07-27T12:01:45Z');
    expect($queueHealth->summary(record: true)['snapshot_recorded'])->toBeTrue();

    $context = app(CapacityReportService::class)->baselineContext();
    $scope = [
        'environment' => $context['environment'],
        'release' => config('observability.release'),
        'run_id' => $context['run_id'],
        'commit' => $context['commit'],
        'started_at' => data_get($context, 'period.started_at'),
        'ended_at' => data_get($context, 'period.ended_at'),
    ];

    expect(data_get($queueHealth->summaryForScope($scope), 'by_scenario.dashboard_usage.snapshot_count'))->toBe(1)
        ->and($queueHealth->summary(record: true, forceRecord: true)['snapshot_recorded'])->toBeTrue()
        ->and(data_get($queueHealth->summaryForScope($scope), 'by_scenario.dashboard_usage.snapshot_count'))->toBe(2)
        ->and(data_get(
            $queueHealth->summaryForScope($scope),
            'by_scenario.dashboard_usage.coverage.last_recorded_at'
        ))->toBe('2026-07-27T12:01:45.000000Z');
});

test('a complete isolated run passes only with queue snapshots and matching external evidence', function () {
    $summary = p0006CompleteScenarioRun([
        ['status_code' => 200, 'method' => 'GET', 'business_success' => true],
    ]);
    $scenario = $summary['scenarios'][0];

    expect($scenario['status'])->toBe('pass')
        ->and(data_get($scenario, 'observed.runner_completed_requests'))->toBe(1)
        ->and(data_get($scenario, 'observed.telemetry_matches_runner'))->toBeTrue()
        ->and(data_get($scenario, 'observed.client_latency_ms.p95'))->toBe(200.0)
        ->and(data_get($scenario, 'observed.queue.snapshot_count'))->toBe(2);
});

test('the report invalidates external evidence when the approved baseline identity changes', function (
    string $baselineField,
    bool|string $changedValue
) {
    $initialScenario = p0006CompleteScenarioRun([
        ['status_code' => 200, 'method' => 'GET', 'business_success' => true],
    ])['scenarios'][0];

    expect($initialScenario['status'])->toBe('pass');

    config()->set("capacity.baseline.{$baselineField}", $changedValue);

    $scenario = app(CapacityReportService::class)->summary()['scenarios'][0];

    expect($scenario['status'])->toBe('insufficient_data')
        ->and(data_get($scenario, 'observed.runner_result'))->toBeNull()
        ->and(collect($scenario['failures'])->contains(
            fn (string $failure): bool => str_contains($failure, 'external runner')
        ))->toBeTrue();
})->with([
    'runner script hash changed' => ['runner_hash', hash('sha256', 'changed-test-runner')],
    'traffic contract changed' => ['traffic', 'different approved traffic contract'],
    'isolated tenant attestation changed' => ['isolated_tenant_verified', false],
]);

test('missing runner evidence or queue snapshots keeps a scenario inconclusive', function (
    bool $recordQueueSnapshots,
    bool $importRunnerResult,
    string $expectedFailure
) {
    $scenario = p0006CompleteScenarioRun(
        [['status_code' => 200, 'method' => 'GET', 'business_success' => true]],
        $recordQueueSnapshots,
        $importRunnerResult
    )['scenarios'][0];

    expect($scenario['status'])->toBe('insufficient_data')
        ->and(collect($scenario['failures'])->contains(
            fn (string $failure): bool => str_contains($failure, $expectedFailure)
        ))->toBeTrue();
})->with([
    'runner result missing' => [true, false, 'external runner'],
    'queue snapshots missing' => [false, true, 'queue snapshots'],
]);

test('queue snapshots must cover the runner interval instead of only bracketing the command calls', function () {
    $scenario = p0006CompleteScenarioRun(
        [['status_code' => 200, 'method' => 'GET', 'business_success' => true]],
        coverQueueInterval: false
    )['scenarios'][0];

    expect($scenario['status'])->toBe('insufficient_data')
        ->and(data_get($scenario, 'observed.queue.coverage_ready'))->toBeFalse()
        ->and(data_get($scenario, 'observed.queue.coverage_issues'))->toContain(
            'queue_snapshot_coverage_ends_too_early'
        );
});

test('runner and internal telemetry count mismatches keep the result inconclusive', function () {
    $scenario = p0006CompleteScenarioRun(
        [['status_code' => 200, 'method' => 'GET', 'business_success' => true]],
        runnerCompletedRequests: 2
    )['scenarios'][0];

    expect($scenario['status'])->toBe('insufficient_data')
        ->and($scenario['observed']['telemetry_matches_runner'])->toBeFalse()
        ->and(collect($scenario['failures'])->implode(' '))->toContain('counts differ');
});

test('external client p95 and p99 are the latency gates', function () {
    $scenario = p0006CompleteScenarioRun(
        [['status_code' => 200, 'method' => 'GET', 'business_success' => true]],
        latency: ['p50' => 400, 'p95' => 600, 'p99' => 800, 'max' => 900]
    )['scenarios'][0];

    expect($scenario['status'])->toBe('fail')
        ->and(collect($scenario['failures'])->implode(' '))->toContain('client latency p95')
        ->and(collect($scenario['failures'])->implode(' '))->toContain('client latency p99');
});

test('a 4xx response or an unexpected method can never pass a capacity scenario', function (
    string $method,
    int $statusCode,
    string $invalidMetric
) {
    $scenario = p0006CompleteScenarioRun([[
        'method' => $method,
        'status_code' => $statusCode,
        'business_success' => false,
    ]])['scenarios'][0];

    expect($scenario['status'])->toBe('insufficient_data')
        ->and($scenario['observed']['sample_count_in_scope'])->toBe(1)
        ->and($scenario['observed']['valid_sample_count_in_scope'])->toBe(0)
        ->and($scenario['observed'][$invalidMetric])->toBe(1)
        ->and($scenario['failures'])->not->toBeEmpty();
})->with([
    '4xx response' => ['GET', 422, 'invalid_response_count'],
    'unexpected method' => ['POST', 200, 'unexpected_method_count'],
]);

test('a business outcome failure is rejected even when enough valid samples remain', function () {
    config()->set('capacity.scenarios.dashboard_usage.targets.min_samples', 1);
    $scenario = p0006CompleteScenarioRun([
        ['status_code' => 200, 'method' => 'GET', 'business_success' => true],
        ['status_code' => 200, 'method' => 'GET', 'business_success' => false],
    ])['scenarios'][0];

    expect($scenario['status'])->toBe('fail')
        ->and($scenario['observed']['invalid_business_outcome_count'])->toBe(1);
});

test('a formal execution blocker always produces a blocked scenario', function () {
    $reason = 'The isolated staging tenant is not available.';

    config()->set('observability.request.tracked_routes', ['dashboard']);
    config()->set('capacity.scenarios', [
        'dashboard_usage' => p0006DashboardScenario([
            'blocker' => [
                'reason' => $reason,
                'owner' => 'capacity-owner',
                'review_at' => now()->addDay()->toIso8601String(),
            ],
        ]),
    ]);

    $scenario = app(CapacityReportService::class)->summary()['scenarios'][0];

    expect($scenario['status'])->toBe('blocked')
        ->and($scenario['failures'])->toBe([$reason])
        ->and($scenario['status'])->not->toBe('pass');
});

test('expired or invalid scenario blockers invalidate the execution catalog', function (
    string $reviewAt,
    string $expectedIssue
) {
    config()->set('observability.request.tracked_routes', ['dashboard']);
    config()->set('capacity.scenarios', [
        'dashboard_usage' => p0006DashboardScenario([
            'blocker' => [
                'reason' => 'Temporary capacity execution blocker.',
                'owner' => 'capacity-owner',
                'review_at' => $reviewAt,
            ],
        ]),
    ]);

    expect(app(CapacityScenarioCatalog::class)->issues())->toContain($expectedIssue);
})->with([
    'expired' => [
        '2026-07-27T11:59:00Z',
        'Scenario dashboard_usage blocker review_at must be in the future.',
    ],
    'invalid' => [
        'not-a-date',
        'Scenario dashboard_usage blocker review_at must be a valid future timestamp.',
    ],
]);

test('controlled write scenarios must require an isolated tenant', function () {
    p0006ConfigureCompleteBaseline();
    config()->set('capacity.scenarios.dashboard_usage.safety.mode', 'controlled_write');
    config()->set('capacity.scenarios.dashboard_usage.safety.requires_isolated_tenant', false);
    config()->set('capacity.scenarios.dashboard_usage.protocol.fixture_strategy', 'one_shot');
    config()->set('capacity.scenarios.dashboard_usage.protocol.unique_by', [['body.p0006_fixture_id']]);

    expect(app(CapacityScenarioCatalog::class)->issues())->toContain(
        'Scenario dashboard_usage controlled_write safety must require an isolated tenant.'
    );
});

test('unblocked isolated writes require an explicit tenant attestation before start', function () {
    p0006ConfigureCompleteBaseline();
    config()->set('capacity.scenarios.dashboard_usage.safety.mode', 'controlled_write');
    config()->set('capacity.scenarios.dashboard_usage.safety.requires_isolated_tenant', true);
    config()->set('capacity.scenarios.dashboard_usage.protocol.fixture_strategy', 'one_shot');
    config()->set('capacity.scenarios.dashboard_usage.protocol.unique_by', [['body.p0006_fixture_id']]);
    config()->set('capacity.baseline.isolated_tenant_verified', false);

    $context = app(CapacityReportService::class)->baselineContext();
    $exitCode = Artisan::call('capacity:scenario:start', ['scenario' => 'dashboard_usage']);

    expect($context['status'])->toBe('incomplete')
        ->and($context['issues'])->toContain(
            'An isolated tenant must be explicitly verified for unblocked controlled-write scenarios.'
        )
        ->and($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('requires an explicitly verified isolated tenant')
        ->and(app(CapacityRunContextService::class)->activeScenarioKey())->toBeNull();

    config()->set('capacity.baseline.isolated_tenant_verified', true);
    $verifiedContext = app(CapacityReportService::class)->baselineContext();

    expect($verifiedContext['status'])->toBe('complete')
        ->and($verifiedContext['isolated_tenant_verified'])->toBeTrue();
});

test('read only scenarios do not require an isolated tenant attestation', function () {
    p0006ConfigureCompleteBaseline();
    config()->set('capacity.baseline.isolated_tenant_verified', false);

    $context = app(CapacityReportService::class)->baselineContext();

    expect($context['status'])->toBe('complete')
        ->and($context['isolated_tenant_verified'])->toBeFalse()
        ->and($context['issues'])->not->toContain(
            'An isolated tenant must be explicitly verified for unblocked controlled-write scenarios.'
        );
});

test('an invalid baseline runner hash makes the execution context incomplete', function () {
    p0006ConfigureCompleteBaseline();
    config()->set('capacity.baseline.runner_hash', 'not-a-sha256');

    $context = app(CapacityReportService::class)->baselineContext();

    expect($context['status'])->toBe('incomplete')
        ->and($context['issues'])->toContain(
            'Baseline runner_hash must be a 64-character SHA-256 hexadecimal digest.'
        );
});

test('staging mode rejects environments outside the explicit allowlist', function () {
    p0006ConfigureCompleteBaseline();
    config()->set('app.env', 'prod-like-alias');
    config()->set('capacity.baseline.environment', 'prod-like-alias');

    $context = app(CapacityReportService::class)->baselineContext();

    expect($context['status'])->toBe('incomplete')
        ->and($context['issues'])->toContain(
            'A staging baseline environment must be present in the explicit staging allowlist.'
        );
});

test('a baseline cannot be accepted before its configured period has ended', function () {
    p0006ConfigureCompleteBaseline();

    $summary = app(CapacityReportService::class)->summary();

    expect($summary['baseline_context']['status'])->toBe('complete')
        ->and($summary['baseline_context']['period_complete'])->toBeFalse()
        ->and(in_array($summary['status'], ['healthy', 'accepted_with_blockers'], true))->toBeFalse();
});
