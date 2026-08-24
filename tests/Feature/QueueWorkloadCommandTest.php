<?php

use App\Support\QueueWorkload;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    config()->set('queue.default', 'database');
});

test('queue workload audit exposes a valid inventory as json', function () {
    $exitCode = Artisan::call('queue:workload-audit', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['errors'])->toBe([])
        ->and($payload['unassigned_workloads'])->toBe([])
        ->and($payload['unassigned_production_workloads'])->toBe([])
        ->and(collect($payload['external_checks'])->contains(
            fn (string $check): bool => str_contains($check, 'sqs')
                && str_contains($check, 'visibility timeout')
        ))->toBeTrue()
        ->and(array_keys($payload['workloads']))->toEqualCanonicalizing(array_keys(config('async.workloads')));
});

test('queue workload audit returns a failure for an orphaned workload', function () {
    config()->set('async.workloads.orphaned', [
        'queue' => 'orphaned-test',
        'backoff' => [30],
    ]);

    $exitCode = Artisan::call('queue:workload-audit', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['unassigned_workloads'])->toContain('orphaned')
        ->and($payload['errors'])->toContain('Async workload [orphaned] is not assigned to any worker.');
});

test('queue workload worker dry run resolves overridden queues and workload timeout', function () {
    config()->set('async.workloads.plan_scans.queue', 'plan-scans-test');

    $exitCode = Artisan::call('queue:workloads', [
        'profile' => 'plan-scans',
        '--dry-run' => true,
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['command'])->toBe('queue:work')
        ->and($payload['profile'])->toBe('plan-scans')
        ->and($payload['connection'])->toBe(config('queue.default'))
        ->and($payload['queues'])->toBe(['plan-scans-test'])
        ->and($payload['timeout'])->toBe(240)
        ->and($payload['memory'])->toBe(512)
        ->and($payload['tries'])->toBe(QueueWorkload::workerTries('plan-scans'));
});

test('queue workload worker dry run supports listen mode and explicit overrides', function () {
    $exitCode = Artisan::call('queue:workloads', [
        'profile' => 'social',
        'connection' => 'database',
        '--listen' => true,
        '--dry-run' => true,
        '--json' => true,
        '--timeout' => 180,
        '--tries' => 4,
    ]);
    $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['command'])->toBe('queue:listen')
        ->and($payload['profile'])->toBe('social')
        ->and($payload['connection'])->toBe('database')
        ->and($payload['queues'])->toBe([
            config('async.workloads.social_publish.queue'),
            config('async.workloads.social_automation.queue'),
        ])
        ->and($payload['timeout'])->toBe(180)
        ->and($payload['tries'])->toBe(4);
});

test('queue workload worker refuses a timeout below the profile maximum', function () {
    $exitCode = Artisan::call('queue:workloads', [
        'profile' => 'plan-scans',
        '--dry-run' => true,
        '--timeout' => 60,
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('cannot be lower')
        ->and($output)->toContain('240');
});

test('operations worker dry run keeps retries enabled without an override', function () {
    $exitCode = Artisan::call('queue:workloads', [
        'profile' => 'operations',
        '--dry-run' => true,
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['tries'])->toBe(3)
        ->and($payload['queues'][array_key_last($payload['queues'])])
        ->toBe(config('queue.connections.database.queue'));
});

test('worker dry run uses the explicit connection default queue', function () {
    config()->set('queue.connections.priority-database', [
        ...config('queue.connections.database'),
        'queue' => 'priority-default',
    ]);

    $exitCode = Artisan::call('queue:workloads', [
        'profile' => 'operations',
        'connection' => 'priority-database',
        '--dry-run' => true,
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['connection'])->toBe('priority-database')
        ->and($payload['queues'][array_key_last($payload['queues'])])->toBe('priority-default')
        ->and($payload['queues'])->not->toContain(config('queue.connections.database.queue'));
});

test('worker dry run refuses unknown and non worker queue connections', function (string $connection) {
    if ($connection === 'null') {
        config()->set('queue.connections.null', ['driver' => 'null']);
    }

    $exitCode = Artisan::call('queue:workloads', [
        'profile' => 'operations',
        'connection' => $connection,
        '--dry-run' => true,
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain($connection);
})->with([
    'unknown connection' => 'missing-connection',
    'sync driver' => 'sync',
    'null driver' => 'null',
]);

test('queue workload audit refuses an invalid default worker connection', function (string $connection) {
    if ($connection === 'null') {
        config()->set('queue.connections.null', ['driver' => 'null']);
    }

    config()->set('queue.default', $connection);

    $exitCode = Artisan::call('queue:workload-audit', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and(collect($payload['errors'])->contains(
            fn (string $error): bool => str_contains($error, $connection)
        ))->toBeTrue();
})->with([
    'unknown connection' => 'missing-connection',
    'sync driver' => 'sync',
    'null driver' => 'null',
]);

test('queue workload audit rejects production profiles sharing a physical queue', function () {
    $defaultQueue = (string) config('queue.connections.database.queue', 'default');
    config()->set('async.workloads.plan_scans.queue', $defaultQueue);

    $exitCode = Artisan::call('queue:workload-audit', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    $collision = collect($payload['errors'])->first(
        fn (string $error): bool => str_contains($error, $defaultQueue)
            && str_contains($error, 'operations')
            && str_contains($error, 'plan-scans')
    );

    expect($exitCode)->toBe(1)
        ->and($collision)->not->toBeNull();
});

test('queue workload worker dry run prints a readable profile without starting a worker', function () {
    $exitCode = Artisan::call('queue:workloads', [
        'profile' => 'operations',
        '--dry-run' => true,
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Resolved worker profile: operations')
        ->and($output)->toContain('Command: queue:work')
        ->and($output)->toContain('Queues: '.implode(',', QueueWorkload::workerQueues('operations')));
});
