<?php

use App\Support\QueueWorkload;
use Illuminate\Support\Facades\File;

uses(Tests\TestCase::class);

beforeEach(function () {
    config()->set('queue.default', 'database');
});

test('every workload referenced by application code exists in async configuration', function () {
    $referencedWorkloads = [];

    foreach (asyncQueueSourceFiles() as $file) {
        preg_match_all(
            '/QueueWorkload::(?:queue|backoff)\s*\(\s*[\'\"]([^\'\"]+)[\'\"]/',
            File::get($file),
            $matches
        );

        array_push($referencedWorkloads, ...$matches[1]);
    }

    $referencedWorkloads = array_values(array_unique($referencedWorkloads));
    sort($referencedWorkloads);

    $configuredWorkloads = array_keys(config('async.workloads', []));
    sort($configuredWorkloads);

    expect($referencedWorkloads)->not->toBeEmpty()
        ->and(array_values(array_diff($referencedWorkloads, $configuredWorkloads)))->toBe([]);
});

test('application queue routing is resolved through QueueWorkload', function () {
    $hardCodedCalls = [];

    foreach (asyncQueueSourceFiles() as $file) {
        $source = File::get($file);
        preg_match_all('/->onQueue\s*\(/', $source, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as [, $offset]) {
            $snippet = substr($source, $offset, 180);

            if (! preg_match('/^->onQueue\s*\(\s*QueueWorkload::queue\s*\(/', $snippet)) {
                $hardCodedCalls[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
            }
        }
    }

    expect(array_values(array_unique($hardCodedCalls)))->toBe([]);
});

test('async inventory assigns every workload to a production worker', function () {
    $inventory = QueueWorkload::inventory();

    expect($inventory['errors'])->toBe([])
        ->and($inventory['unassigned_workloads'])->toBe([])
        ->and($inventory['unassigned_production_workloads'])->toBe([])
        ->and(array_keys($inventory['workloads']))->toEqualCanonicalizing(array_keys(config('async.workloads')))
        ->and(array_keys($inventory['workers']))->toEqualCanonicalizing(array_keys(config('async.workers')));
});

test('worker profiles follow queue overrides and protect long running workloads', function () {
    config()->set('async.workloads.plan_scans.queue', 'plan-scans-override');

    expect(QueueWorkload::workerQueues('plan-scans', 'database'))->toBe(['plan-scans-override'])
        ->and(QueueWorkload::workerQueues('development', 'database'))->toContain('plan-scans-override')
        ->and(QueueWorkload::workerQueues('operations', 'database'))->toContain('default')
        ->and(QueueWorkload::workerTimeout('plan-scans'))->toBe(240)
        ->and(QueueWorkload::workerTimeout('development'))->toBeGreaterThanOrEqual(240)
        ->and((int) config('queue.connections.database.retry_after'))->toBeGreaterThan(240)
        ->and((int) config('queue.connections.redis.retry_after'))->toBeGreaterThan(240);
});

test('worker profiles preserve retry capacity and deliberate queue priority', function () {
    $defaultQueue = (string) config('queue.connections.database.queue', 'default');
    $developmentQueues = QueueWorkload::workerQueues('development', 'database');
    $operationsQueues = QueueWorkload::workerQueues('operations', 'database');
    $campaignQueues = QueueWorkload::workerQueues('campaigns', 'database');
    $socialQueues = QueueWorkload::workerQueues('social', 'database');

    expect((int) config('async.workers.development.tries'))->toBeGreaterThanOrEqual(3)
        ->and((int) config('async.workers.operations.tries'))->toBeGreaterThanOrEqual(3)
        ->and($developmentQueues[array_key_last($developmentQueues)])->toBe($defaultQueue)
        ->and($operationsQueues[array_key_last($operationsQueues)])->toBe($defaultQueue)
        ->and(array_search(QueueWorkload::queue('campaigns_send'), $developmentQueues, true))
        ->toBeLessThan(array_search(QueueWorkload::queue('campaigns_dispatch'), $developmentQueues, true))
        ->and(array_search(QueueWorkload::queue('social_publish'), $developmentQueues, true))
        ->toBeLessThan(array_search(QueueWorkload::queue('social_automation'), $developmentQueues, true))
        ->and($campaignQueues)->toBe([
            QueueWorkload::queue('campaigns_send'),
            QueueWorkload::queue('campaigns_dispatch'),
            QueueWorkload::queue('campaigns_maintenance'),
        ])
        ->and($socialQueues)->toBe([
            QueueWorkload::queue('social_publish'),
            QueueWorkload::queue('social_automation'),
        ]);
});

test('inventory reports orphaned workloads and invalid worker references', function () {
    config()->set('async.workloads.orphaned', [
        'queue' => 'orphaned-test',
        'backoff' => [30],
    ]);
    config()->set('async.workers.operations.workloads', [
        ...config('async.workers.operations.workloads'),
        'missing-workload',
    ]);

    $inventory = QueueWorkload::inventory();

    expect($inventory['unassigned_workloads'])->toContain('orphaned')
        ->and($inventory['unassigned_production_workloads'])->toContain('orphaned')
        ->and($inventory['errors'])->toContain('Async workload [orphaned] is not assigned to any worker.')
        ->and($inventory['errors'])->toContain('Async workload [orphaned] is not assigned to a production worker.')
        ->and($inventory['errors'])->toContain('Async worker [operations] references unknown workload [missing-workload].');
});

test('inventory rejects a physical queue shared by production worker profiles', function () {
    $defaultQueue = (string) config('queue.connections.database.queue', 'default');
    config()->set('async.workloads.plan_scans.queue', $defaultQueue);

    $inventory = QueueWorkload::inventory();
    $collision = collect($inventory['errors'])->first(
        fn (string $error): bool => str_contains($error, $defaultQueue)
            && str_contains($error, 'operations')
            && str_contains($error, 'plan-scans')
    );

    expect($inventory['errors'])->not->toBeEmpty()
        ->and($collision)->not->toBeNull();
});

test('unknown workload and worker names fail closed', function () {
    expect(fn () => QueueWorkload::queue('missing-workload'))
        ->toThrow(LogicException::class, 'Async workload [missing-workload] is not configured.')
        ->and(fn () => QueueWorkload::workerQueues('missing-worker'))
        ->toThrow(InvalidArgumentException::class, 'Async worker [missing-worker] is not configured.');
});

/**
 * @return array<int, string>
 */
function asyncQueueSourceFiles(): array
{
    return [
        ...array_map(
            static fn (SplFileInfo $file): string => $file->getPathname(),
            File::allFiles(app_path())
        ),
        base_path('routes/console.php'),
    ];
}
