<?php

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
    config()->set('capacity.baseline', [
        'run_id' => 'p0-006-runner-test',
        'environment' => 'testing',
        'commit' => 'deadc0de',
        'started_at' => '2026-07-27T12:00:00+00:00',
        'ended_at' => '2026-07-27T12:15:00+00:00',
        'runner' => 'k6@0.52.0',
        'runner_hash' => hash('sha256', 'approved-k6-script'),
    ]);
    config()->set('capacity.scenarios.dashboard_usage.profile', [
        'virtual_users' => 25,
        'duration' => '10m',
        'ramp_up' => '2m',
        'minimum_completed_requests' => 250,
    ]);
    config()->set('capacity.scenarios.dashboard_usage.targets.min_samples', 25);

    Cache::store('array')->flush();
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
        'schema_version' => 1,
        'run_id' => 'p0-006-runner-test',
        'environment' => 'testing',
        'commit' => 'deadc0de',
        'scenario_key' => 'dashboard_usage',
        'manifest_hash' => $scenario['manifest_hash'],
        'runner' => 'k6@0.52.0',
        'runner_hash' => strtoupper(hash('sha256', 'approved-k6-script')),
        'started_at' => '2026-07-27T12:01:00+00:00',
        'ended_at' => '2026-07-27T12:11:00+00:00',
        'virtual_users' => 25,
        'duration_seconds' => 600,
        'ramp_up_seconds' => 120,
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

test('external runner evidence rejects unknown or raw fields and identity mismatches', function () {
    $payload = capacityRunnerPayload([
        'commit' => 'different-commit',
        'runner' => 'unapproved-runner',
        'manifest_hash' => str_repeat('a', 64),
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
            ->toContain('manifest_hash does not match the current scenario manifest.');
    }
});

test('external runner evidence enforces the scenario profile counters assertions and latency order', function () {
    $payload = capacityRunnerPayload([
        'started_at' => '2026-07-27T11:59:59Z',
        'ended_at' => '2026-07-27T12:16:00Z',
        'virtual_users' => 24,
        'duration_seconds' => 599,
        'ramp_up_seconds' => 119,
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

test('runner timestamps must cover exactly the declared duration', function () {
    $payload = capacityRunnerPayload([
        'ended_at' => '2026-07-27T12:10:59Z',
    ]);

    expect(fn () => app(CapacityRunnerResultService::class)->ingest($payload))
        ->toThrow(
            CapacityRunnerResultValidationException::class,
            'ended_at minus started_at must equal duration_seconds.'
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
