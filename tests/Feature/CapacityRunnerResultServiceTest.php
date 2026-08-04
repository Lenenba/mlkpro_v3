<?php

use App\Services\Capacity\CapacityPreflightService;
use App\Services\Capacity\CapacityRunContextService;
use App\Services\Capacity\CapacityRunnerResultService;
use App\Services\Capacity\CapacityRunnerResultValidationException;
use App\Services\Capacity\CapacityScenarioCatalog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    Carbon::setTestNow('2026-07-27T12:20:00Z');

    config()->set('app.env', 'testing');
    config()->set('observability.cache.store', 'array');
    config()->set('observability.cache.prefix', 'capacity-runner-result-tests');
    config()->set('observability.release', 'p0-006-runner-result-test');
    config()->set('capacity.allowed_staging_environments', ['testing']);
    config()->set('capacity.baseline', [
        'run_id' => 'p0-006-runner-test',
        'environment' => 'testing',
        'commit' => 'deadc0de',
        'started_at' => '2026-07-27T12:00:00+00:00',
        'ended_at' => '2026-07-27T12:15:00+00:00',
        'traffic' => 'synthetic',
        'runner' => 'k6@0.52.0',
        'runner_hash' => hash('sha256', 'approved-k6-script'),
        'fixture_hash' => hash('sha256', 'approved-capacity-fixtures'),
        'allowed_origins' => 'https://capacity.example.test',
        'exclusions' => 'none',
        'mode' => 'staging',
        'representative' => true,
        'approved' => true,
        'approval_reference' => 'P0-006-RUNNER-TEST',
        'queue_canaries_verified' => true,
        'isolated_tenant_verified' => true,
        'owner' => 'capacity-owner',
        'validator' => 'capacity-validator',
    ]);
    config()->set('capacity.scenarios.dashboard_usage.profile', [
        'virtual_users' => 25,
        'duration' => '10m',
        'ramp_up' => '2m',
        'request_interval_ms' => 1000,
        'request_timeout_ms' => 10000,
        'minimum_completed_requests' => 250,
    ]);
    config()->set('capacity.scenarios.dashboard_usage.targets.min_samples', 25);

    Cache::store('array')->flush();

    $this->mock(CapacityPreflightService::class, function ($mock): void {
        $mock->shouldReceive('summary')->andReturn([
            'ready' => true,
            'issues' => [],
        ]);
    });

    Carbon::setTestNow('2026-07-27T12:00:30Z');
    $runContext = app(CapacityRunContextService::class);
    expect($runContext->start('dashboard_usage'))->toBeTrue();
    Carbon::setTestNow('2026-07-27T12:11:30Z');
    expect($runContext->stop('dashboard_usage'))->toBeTrue();
    Carbon::setTestNow('2026-07-27T12:20:00Z');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function capacityRunnerPayload(array $overrides = []): array
{
    $scenario = collect(app(CapacityScenarioCatalog::class)->all())
        ->firstWhere('key', 'dashboard_usage');

    return array_replace_recursive([
        'schema_version' => CapacityRunnerResultService::SCHEMA_VERSION,
        'run_id' => 'p0-006-runner-test',
        'environment' => 'testing',
        'commit' => 'deadc0de',
        'scenario_key' => 'dashboard_usage',
        'manifest_hash' => $scenario['manifest_hash'],
        'fixture_hash' => hash('sha256', 'approved-capacity-fixtures'),
        'baseline_fingerprint' => app(CapacityRunnerResultService::class)->baselineFingerprint(),
        'target_origin_hash' => hash('sha256', 'https://capacity.example.test'),
        'runner' => 'k6@0.52.0',
        'runner_hash' => strtoupper(hash('sha256', 'approved-k6-script')),
        'started_at' => '2026-07-27T12:01:00+00:00',
        'ended_at' => '2026-07-27T12:11:00+00:00',
        'virtual_users' => 25,
        'duration_seconds' => 600,
        'ramp_up_seconds' => 120,
        'request_interval_ms' => 1000,
        'request_timeout_ms' => 10000,
        'attempted_requests' => 250,
        'completed_requests' => 250,
        'transport_errors' => 0,
        'assertion_failures' => 0,
        'client_latency_ms' => [
            'p50' => 120.25,
            'p95' => 280.5,
            'p99' => 410.75,
            'max' => 650,
        ],
    ], $overrides);
}

test('external runner evidence is strictly validated canonicalized and stored by telemetry scope', function () {
    $service = app(CapacityRunnerResultService::class);
    $validated = $service->validate(capacityRunnerPayload());

    expect($validated)
        ->not->toHaveKey('scope_id')
        ->and($validated)->not->toHaveKey('recorded_at')
        ->and($validated['runner_hash'])->toBe(hash('sha256', 'approved-k6-script'))
        ->and($validated['started_at'])->toBe('2026-07-27T12:01:00.000000Z')
        ->and($validated['ended_at'])->toBe('2026-07-27T12:11:00.000000Z')
        ->and($service->latestForCurrentScope('dashboard_usage'))->toBeNull();

    $stored = $service->ingest(capacityRunnerPayload());
    $scopeId = $service->currentScopeId();

    expect($scopeId)->toMatch('/^[a-f0-9]{64}$/')
        ->and($stored['scope_id'])->toBe($scopeId)
        ->and($stored['recorded_at'])->toBe('2026-07-27T12:20:00.000000Z')
        ->and($service->latestForCurrentScope('dashboard_usage'))->toBe($stored)
        ->and($service->latestForScope($scopeId, 'dashboard_usage'))->toBe($stored);
});

test('baseline fingerprint uses the canonical identity shared with the Node runner', function () {
    config()->set('observability.release', 'release/é');
    config()->set('capacity.baseline', [
        'run_id' => 'run',
        'environment' => 'staging',
        'commit' => 'abc',
        'started_at' => '2026-07-27T12:00:00Z',
        'ended_at' => '2026-07-27T12:10:00Z',
        'traffic' => 'synthetic',
        'runner' => 'node',
        'runner_hash' => str_repeat('A', 64),
        'fixture_hash' => str_repeat('B', 64),
        'allowed_origins' => 'https://staging.example.test',
        'exclusions' => ' none, raw ',
        'mode' => 'staging',
        'representative' => true,
        'approved' => true,
        'approval_reference' => 'CHANGE/1',
        'queue_canaries_verified' => true,
        'isolated_tenant_verified' => true,
        'owner' => 'owner',
        'validator' => 'validator',
    ]);
    $canonicalJson = '{"allowed_origins":["https:\/\/staging.example.test"],"approval_reference":"CHANGE\/1","approved":true,"commit":"abc","environment":"staging","exclusions":["none","raw"],"fixture_hash":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","isolated_tenant_verified":true,"mode":"staging","owner":"owner","period":{"ended_at":"2026-07-27T12:10:00Z","started_at":"2026-07-27T12:00:00Z"},"queue_canaries_verified":true,"release":"release\/\u00e9","representative":true,"run_id":"run","runner":"node","runner_hash":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","traffic":"synthetic","validator":"validator"}';

    expect(app(CapacityRunnerResultService::class)->baselineFingerprint())
        ->toBe(hash('sha256', $canonicalJson));
});

test('external runner evidence rejects unknown or raw fields and identity mismatches', function () {
    $payload = capacityRunnerPayload([
        'commit' => 'different-commit',
        'runner' => 'unapproved-runner',
        'manifest_hash' => str_repeat('a', 64),
        'fixture_hash' => str_repeat('c', 64),
        'baseline_fingerprint' => str_repeat('b', 64),
        'target_origin_hash' => str_repeat('d', 64),
        'request_timeout_ms' => 9999,
        'raw_responses' => [['body' => 'sensitive']],
        'client_latency_ms' => [
            'raw_samples' => [10, 20],
        ],
    ]);

    try {
        app(CapacityRunnerResultService::class)->ingest($payload);
        $this->fail('Invalid external runner evidence was accepted.');
    } catch (CapacityRunnerResultValidationException $exception) {
        expect($exception->errors())
            ->toContain('raw_responses is not allowed in a capacity runner result.')
            ->toContain('client_latency_ms.raw_samples is not allowed.')
            ->toContain('commit does not match the configured baseline.')
            ->toContain('runner does not match the configured baseline.')
            ->toContain('fixture_hash does not match the configured baseline.')
            ->toContain('target_origin_hash does not match an approved baseline origin.')
            ->toContain('baseline_fingerprint does not match the current baseline identity.')
            ->toContain('manifest_hash does not match the current scenario manifest.')
            ->toContain('request_timeout_ms must match the scenario profile (10000).');
    }
});

test('runner evidence requires the current preflight to remain ready', function () {
    $this->mock(CapacityPreflightService::class, function ($mock): void {
        $mock->shouldReceive('summary')->once()->andReturn([
            'ready' => false,
            'issues' => ['queue_backlog_unmeasurable'],
        ]);
    });

    expect(fn () => app(CapacityRunnerResultService::class)->ingest(capacityRunnerPayload()))
        ->toThrow(
            CapacityRunnerResultValidationException::class,
            'The current capacity preflight is not ready to accept runner evidence.'
        );
});

test('runner evidence requires a complete uncancelled lifecycle for the same scope', function () {
    config()->set('capacity.baseline.run_id', 'scope-without-lifecycle');

    expect(fn () => app(CapacityRunnerResultService::class)->ingest(capacityRunnerPayload([
        'run_id' => 'scope-without-lifecycle',
    ])))->toThrow(
        CapacityRunnerResultValidationException::class,
        'Runner evidence requires a completed started-to-stopped capacity scenario lifecycle.'
    );

    config()->set('capacity.baseline.run_id', 'cancelled-scope');
    Carbon::setTestNow('2026-07-27T12:00:30Z');
    $runContext = app(CapacityRunContextService::class);
    expect($runContext->start('dashboard_usage'))->toBeTrue();
    Carbon::setTestNow('2026-07-27T12:00:45Z');
    expect($runContext->cancel('dashboard_usage'))->toBeTrue();
    Carbon::setTestNow('2026-07-27T12:20:00Z');

    expect(fn () => app(CapacityRunnerResultService::class)->ingest(capacityRunnerPayload([
        'run_id' => 'cancelled-scope',
    ])))->toThrow(
        CapacityRunnerResultValidationException::class,
        'The capacity scenario lifecycle was cancelled and cannot accept runner evidence.'
    );
});

test('runner timestamps must be contained by the lifecycle markers', function () {
    config()->set('capacity.baseline.run_id', 'narrow-lifecycle-scope');
    Carbon::setTestNow('2026-07-27T12:02:00Z');
    $runContext = app(CapacityRunContextService::class);
    expect($runContext->start('dashboard_usage'))->toBeTrue();
    Carbon::setTestNow('2026-07-27T12:10:00Z');
    expect($runContext->stop('dashboard_usage'))->toBeTrue();
    Carbon::setTestNow('2026-07-27T12:20:00Z');

    try {
        app(CapacityRunnerResultService::class)->ingest(capacityRunnerPayload([
            'run_id' => 'narrow-lifecycle-scope',
        ]));
        $this->fail('Evidence outside the lifecycle markers was accepted.');
    } catch (CapacityRunnerResultValidationException $exception) {
        expect($exception->errors())
            ->toContain('runner started_at must be on or after the capacity scenario start marker.')
            ->toContain('runner ended_at must be on or before the capacity scenario stop marker.');
    }
});

test('runner evidence is rejected while the current scenario has a formal blocker', function () {
    config()->set('capacity.scenarios.dashboard_usage.blocker', [
        'reason' => 'The staging route is under maintenance.',
        'owner' => 'capacity-owner',
        'review_at' => '2026-07-28T12:00:00Z',
    ]);

    expect(fn () => app(CapacityRunnerResultService::class)->ingest(capacityRunnerPayload()))
        ->toThrow(
            CapacityRunnerResultValidationException::class,
            'scenario_key is blocked by an active formal capacity blocker.'
        );
});

test('external runner evidence enforces the scenario profile counters assertions and latency order', function () {
    $payload = capacityRunnerPayload([
        'started_at' => '2026-07-27T11:59:59Z',
        'ended_at' => '2026-07-27T12:16:00Z',
        'virtual_users' => 24,
        'duration_seconds' => 599,
        'ramp_up_seconds' => 119,
        'request_interval_ms' => 999,
        'attempted_requests' => 24,
        'completed_requests' => 21,
        'transport_errors' => 2,
        'assertion_failures' => 1,
        'client_latency_ms' => [
            'p50' => 300,
            'p95' => 200,
            'p99' => 400,
            'max' => 350,
        ],
    ]);

    try {
        app(CapacityRunnerResultService::class)->ingest($payload);
        $this->fail('Invalid external runner measurements were accepted.');
    } catch (CapacityRunnerResultValidationException $exception) {
        expect($exception->errors())
            ->toContain('started_at must be inside the configured baseline period.')
            ->toContain('ended_at must be inside the configured baseline period.')
            ->toContain('virtual_users must match the scenario profile (25).')
            ->toContain('duration_seconds must match the scenario profile (600).')
            ->toContain('ramp_up_seconds must match the scenario profile (120).')
            ->toContain('request_interval_ms must match the scenario profile (1000).')
            ->toContain('attempted_requests must be at least 25 for this scenario.')
            ->toContain('completed_requests must be at least 25 for this scenario.')
            ->toContain('attempted_requests must satisfy the scenario load envelope (250).')
            ->toContain('completed_requests must satisfy the scenario load envelope (250).')
            ->toContain('transport_errors must be zero for an accepted capacity result.')
            ->toContain('assertion_failures must be zero for an accepted capacity result.')
            ->toContain('attempted_requests must equal completed_requests plus transport_errors.')
            ->toContain('Client latency must be monotonic: p50 <= p95 <= p99 <= max.');
    }
});

test('runner evidence cannot exceed the signed theoretical request budget', function () {
    $catalog = app(CapacityScenarioCatalog::class);
    $profile = config('capacity.scenarios.dashboard_usage.profile');
    if (! is_array($profile)) {
        $this->fail('The dashboard scenario profile was not configured.');
    }
    $maximum = $catalog->maximumTheoreticalRequests($profile);
    if (! is_int($maximum)) {
        $this->fail('The dashboard scenario did not produce a deterministic request budget.');
    }

    try {
        app(CapacityRunnerResultService::class)->ingest(capacityRunnerPayload([
            'attempted_requests' => $maximum + 1,
            'completed_requests' => $maximum + 1,
        ]));
        $this->fail('Runner evidence above the signed request budget was accepted.');
    } catch (CapacityRunnerResultValidationException $exception) {
        expect($exception->errors())
            ->toContain("attempted_requests exceeds the signed theoretical request budget ({$maximum}).")
            ->toContain("completed_requests exceeds the signed theoretical request budget ({$maximum}).");
    }
});

test('runner timestamps allow only the configured real-world duration drift', function () {
    config()->set('capacity.runner_results.duration_tolerance_seconds', 2);

    $accepted = app(CapacityRunnerResultService::class)->validate(capacityRunnerPayload([
        'ended_at' => '2026-07-27T12:10:59Z',
    ]));

    expect($accepted['ended_at'])->toBe('2026-07-27T12:10:59.000000Z');

    $payload = capacityRunnerPayload([
        'ended_at' => '2026-07-27T12:10:57Z',
    ]);

    expect(fn () => app(CapacityRunnerResultService::class)->ingest($payload))
        ->toThrow(
            CapacityRunnerResultValidationException::class,
            'ended_at minus started_at must match duration_seconds within the configured tolerance.'
        );

    config()->set('capacity.runner_results.duration_tolerance_seconds', 30);

    expect(fn () => app(CapacityRunnerResultService::class)->ingest(capacityRunnerPayload([
        'ended_at' => '2026-07-27T12:11:06Z',
    ])))->toThrow(
        CapacityRunnerResultValidationException::class,
        'ended_at minus started_at must match duration_seconds within the configured tolerance.'
    );
});

test('runner evidence cannot declare a result that has not ended yet', function () {
    Carbon::setTestNow('2026-07-27T12:05:00Z');

    expect(fn () => app(CapacityRunnerResultService::class)->ingest(capacityRunnerPayload()))
        ->toThrow(
            CapacityRunnerResultValidationException::class,
            'ended_at cannot be in the future.'
        );
});

test('runner evidence must match the approved script hash', function () {
    config()->set('capacity.baseline.runner_hash', hash('sha256', 'different-approved-script'));

    expect(fn () => app(CapacityRunnerResultService::class)->ingest(capacityRunnerPayload()))
        ->toThrow(
            CapacityRunnerResultValidationException::class,
            'runner_hash does not match the configured baseline.'
        );
});

test('runner evidence independently rejects an unauthorized configured baseline', function (
    string $field,
    mixed $value,
    string $expectedError
) {
    config()->set("capacity.baseline.{$field}", $value);

    expect(fn () => app(CapacityRunnerResultService::class)->ingest(capacityRunnerPayload()))
        ->toThrow(CapacityRunnerResultValidationException::class, $expectedError);
})->with([
    'not representative' => [
        'representative',
        false,
        'The configured baseline must be explicitly marked representative.',
    ],
    'not approved' => [
        'approved',
        false,
        'The configured baseline execution must be explicitly approved.',
    ],
    'queue canaries not verified' => [
        'queue_canaries_verified',
        false,
        'The configured baseline must verify the P0-005 queue canaries.',
    ],
    'isolated tenant not verified' => [
        'isolated_tenant_verified',
        false,
        'The configured baseline must verify an isolated tenant for controlled-write scenarios.',
    ],
]);

test('runner evidence cannot leak into another baseline scope or survive a manifest change', function () {
    $service = app(CapacityRunnerResultService::class);
    $stored = $service->ingest(capacityRunnerPayload());
    $originalScope = $stored['scope_id'];

    config()->set('capacity.baseline.run_id', 'another-run');

    expect($service->currentScopeId())->not->toBe($originalScope)
        ->and($service->latestForCurrentScope('dashboard_usage'))->toBeNull()
        ->and($service->latestForScope($originalScope, 'dashboard_usage'))->toBe($stored);

    config()->set('capacity.baseline.run_id', 'p0-006-runner-test');
    config()->set('capacity.baseline.runner_hash', hash('sha256', 'changed-runner-script'));

    expect($service->latestForCurrentScope('dashboard_usage'))->toBeNull()
        ->and($service->latestForScope($originalScope, 'dashboard_usage'))->toBe($stored);

    config()->set('capacity.baseline.runner_hash', hash('sha256', 'approved-k6-script'));
    config()->set('capacity.scenarios.dashboard_usage.targets.p95_ms', 699);

    expect($service->latestForScope($originalScope, 'dashboard_usage'))->toBeNull();
});

test('the import command accepts only a bounded file inside the controlled import directory', function () {
    $directory = storage_path('app/capacity-imports');
    $file = 'runner-result-'.Str::uuid().'.json';
    $path = $directory.DIRECTORY_SEPARATOR.$file;
    File::ensureDirectoryExists($directory);
    File::put($path, json_encode(capacityRunnerPayload(), JSON_THROW_ON_ERROR));

    try {
        $exitCode = Artisan::call('capacity:result:import', [
            'scenario' => 'dashboard_usage',
            'file' => $file,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['status'])->toBe('accepted')
            ->and($payload['scenario_key'])->toBe('dashboard_usage')
            ->and($payload['errors'])->toBe([])
            ->and(app(CapacityRunnerResultService::class)->latestForCurrentScope('dashboard_usage'))
            ->not->toBeNull();
    } finally {
        File::delete($path);
    }

    $exitCode = Artisan::call('capacity:result:import', [
        'scenario' => 'dashboard_usage',
        'file' => '../outside.json',
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['status'])->toBe('invalid')
        ->and(collect($payload['errors'])->implode(' '))->toContain('plain filename');
});
