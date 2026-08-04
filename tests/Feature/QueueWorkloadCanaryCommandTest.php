<?php

use App\Jobs\QueueTopologyCanaryJob;
use App\Support\QueueCanary;
use App\Support\QueueWorkload;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Database\Connection;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\Connectors\DatabaseConnector;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Queue\SyncQueue;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('queue.default', 'canary-database');
    config()->set('queue.connections.canary-database', [
        'driver' => 'database',
        'connection' => 'canary-persistent',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 300,
        'after_commit' => false,
    ]);
    config()->set('database.connections.canary-persistent', [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'database' => 'canary-not-opened-by-preflight',
        'username' => 'canary',
        'password' => '',
    ]);
    config()->set('cache.stores.canary-shared', [
        'driver' => 'redis',
        'connection' => 'cache',
    ]);
    config()->set('async.canary.store', 'canary-shared');
    config()->set('async.canary.ack_ttl_seconds', 600);
    config()->set('async.canary.timeout_seconds', 1);
    config()->set('async.canary.max_timeout_seconds', 10);
    config()->set('async.canary.release', null);
    config()->set('async.canary.commit', null);
    config()->set('capacity.baseline.commit', null);
    config()->set('observability.release', null);

    Queue::extend('database', static fn () => new class implements ConnectorInterface
    {
        /** @param  array<string, mixed>  $config */
        public function connect(array $config): DatabaseQueue
        {
            return new DatabaseQueue(
                Mockery::mock(Connection::class),
                (string) ($config['table'] ?? 'jobs'),
                (string) ($config['queue'] ?? 'default'),
                (int) ($config['retry_after'] ?? 300),
                (bool) ($config['after_commit'] ?? false)
            );
        }
    });
});

test('dry run plans every production queue and reports missing operational identity without dispatching', function (string $profile) {
    $exitCode = Artisan::call('queue:workload-canary', [
        'profile' => $profile,
        'connection' => 'canary-database',
        '--dry-run' => true,
        '--json' => true,
    ]);
    $payload = p0005ArtisanJson();
    $queueNames = collect($payload['queues'])->pluck('queue')->all();
    $canaryIds = collect($payload['queues'])->pluck('canary_id')->all();

    expect($exitCode)->toBe(0)
        ->and($payload['status'])->toBe('ready_with_requirements')
        ->and($payload['mode'])->toBe(QueueCanary::MODE_OPERATIONAL)
        ->and($payload['evidence_eligible'])->toBeFalse()
        ->and($payload['dry_run'])->toBeTrue()
        ->and($payload['profile'])->toBe($profile)
        ->and($payload['connection'])->toBe('canary-database')
        ->and($payload['queue_connection'])->toBe([
            'driver' => 'database',
            'transport_class' => DatabaseQueue::class,
            'persistent' => true,
        ])
        ->and($payload['identity']['valid'])->toBeFalse()
        ->and($payload['requirements'])->toContain('queue_canary_environment_not_allowed')
        ->and($payload['requirements'])->toContain('queue_canary_release_missing')
        ->and($payload['requirements'])->toContain('queue_canary_commit_missing')
        ->and($payload['errors'])->toBe([])
        ->and($payload['acknowledgement_store'])->toMatchArray([
            'name' => 'canary-shared',
            'driver' => 'redis',
            'shared' => true,
            'ephemeral' => false,
            'probe' => 'not_run',
        ])
        ->and($queueNames)->toBe(QueueWorkload::workerQueues($profile, 'canary-database'))
        ->and($payload['queue_count'])->toBe(count($queueNames))
        ->and(array_unique($canaryIds))->toHaveCount(count($canaryIds))
        ->and(DB::table('jobs')->count())->toBe(0);
    foreach ($canaryIds as $canaryId) {
        expect(Str::isUuid($canaryId))->toBeTrue();
    }
})->with([
    'operations' => 'operations',
    'plan scans' => 'plan-scans',
    'campaigns' => 'campaigns',
    'social' => 'social',
]);

test('internal database harness is explicitly ineligible as operational evidence', function (string $profile) {
    p0005BindValidIdentity();
    [, $repository] = p0005UseSharedInMemoryCache();
    p0005UseAsynchronousDatabaseHarness($profile);

    $exitCode = Artisan::call('queue:workload-canary', [
        'profile' => $profile,
        'connection' => 'canary-database',
        '--timeout' => 1,
        '--json' => true,
    ]);
    $output = Artisan::output();
    $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
        ->and($payload['status'])->toBe('passed_internal_test')
        ->and($payload['status'])->not->toBe('passed')
        ->and($payload['mode'])->toBe(QueueCanary::MODE_INTERNAL_TEST)
        ->and($payload['evidence_eligible'])->toBeFalse()
        ->and($payload['dry_run'])->toBeFalse()
        ->and($payload['queue_connection'])->toBe([
            'driver' => 'database',
            'transport_class' => DatabaseQueue::class,
            'persistent' => true,
        ])
        ->and($payload['identity'])->toBe([
            'app_env' => 'staging',
            'release' => '2026.08.03-p0.005',
            'commit' => p0005Commit(),
            'valid' => true,
        ])
        ->and($payload['acknowledgement_store']['probe'])->toBe('passed')
        ->and($payload['requirements'])->toBe([])
        ->and($payload['errors'])->toBe([])
        ->and(collect($payload['queues'])->pluck('queue')->all())
        ->toBe(QueueWorkload::workerQueues($profile, 'canary-database'))
        ->and($payload['queues'])->each->toMatchArray(['status' => 'acknowledged'])
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and($output)->not->toContain('async-queue-canary:ack:')
        ->and($output)->not->toContain('async-queue-canary:probe:')
        ->and($output)->not->toContain('hostname')
        ->and($output)->not->toContain('payload');

    foreach ($payload['queues'] as $queueResult) {
        $acknowledgement = $repository->get(QueueCanary::acknowledgementKey(
            $payload['run_id'],
            $queueResult['canary_id']
        ));

        expect($acknowledgement)->toMatchArray([
            'connection' => 'canary-database',
            'queue' => $queueResult['queue'],
            'mode' => QueueCanary::MODE_INTERNAL_TEST,
            'evidence_eligible' => false,
        ]);
    }
})->with([
    'operations' => 'operations',
    'plan scans' => 'plan-scans',
    'campaigns' => 'campaigns',
    'social' => 'social',
]);

test('canary job writes only the observed release-bound acknowledgement', function () {
    p0005BindValidIdentity();
    [$cache, $repository] = p0005UseSharedInMemoryCache();
    $runId = (string) Str::uuid();
    $canaryId = (string) Str::uuid();
    $job = p0005Job($runId, $canaryId);
    $job->setJob(p0005ObservedJob('canary-database', 'social-publish'));

    $job->handle($cache);

    $acknowledgement = $repository->get(QueueCanary::acknowledgementKey($runId, $canaryId));

    expect($job->connection)->toBe('canary-database')
        ->and($job->queue)->toBe('social-publish')
        ->and($job->tries)->toBe(1)
        ->and($job->timeout)->toBe(15)
        ->and($acknowledgement)->toMatchArray([
            'schema_version' => 3,
            'run_id' => $runId,
            'canary_id' => $canaryId,
            'profile' => 'social',
            'connection' => 'canary-database',
            'queue' => 'social-publish',
            'mode' => QueueCanary::MODE_INTERNAL_TEST,
            'evidence_eligible' => false,
            'app_env' => 'staging',
            'release' => '2026.08.03-p0.005',
            'commit' => p0005Commit(),
        ])
        ->and(array_keys($acknowledgement))->toBe([
            'schema_version',
            'run_id',
            'canary_id',
            'profile',
            'connection',
            'queue',
            'mode',
            'evidence_eligible',
            'app_env',
            'release',
            'commit',
            'acknowledged_at',
        ]);
});

test('canary job refuses execution without a real worker job envelope', function () {
    p0005BindValidIdentity();
    [$cache] = p0005UseSharedInMemoryCache();
    $job = p0005Job((string) Str::uuid(), (string) Str::uuid());

    expect(fn () => $job->handle($cache))
        ->toThrow(LogicException::class, 'queue_canary_worker_job_missing');
});

test('canary job refuses wrong observed worker connection or queue', function (string $connection, string $queue) {
    p0005BindValidIdentity();
    [$cache, $repository] = p0005UseSharedInMemoryCache();
    $runId = (string) Str::uuid();
    $canaryId = (string) Str::uuid();
    $job = p0005Job($runId, $canaryId);
    $job->setJob(p0005ObservedJob($connection, $queue));

    expect(fn () => $job->handle($cache))
        ->toThrow(LogicException::class, 'queue_canary_worker_target_mismatch')
        ->and($repository->get(QueueCanary::acknowledgementKey($runId, $canaryId)))->toBeNull();
})->with([
    'wrong connection' => ['other-database', 'social-publish'],
    'wrong queue' => ['canary-database', 'social-automation'],
]);

test('canary job refuses a worker running another configured release identity', function () {
    p0005BindValidIdentity();
    [$cache, $repository] = p0005UseSharedInMemoryCache();
    $runId = (string) Str::uuid();
    $canaryId = (string) Str::uuid();
    $job = p0005Job($runId, $canaryId, release: 'another-release');
    $job->setJob(p0005ObservedJob('canary-database', 'social-publish'));

    expect(fn () => $job->handle($cache))
        ->toThrow(LogicException::class, 'queue_canary_worker_identity_mismatch')
        ->and($repository->get(QueueCanary::acknowledgementKey($runId, $canaryId)))->toBeNull();
});

test('canary job independently refuses a store that became unsafe on the worker', function () {
    p0005BindValidIdentity();
    [$cache] = p0005UseSharedInMemoryCache();
    config()->set('cache.stores.canary-shared', ['driver' => 'array']);
    $job = p0005Job((string) Str::uuid(), (string) Str::uuid());
    $job->setJob(p0005ObservedJob('canary-database', 'social-publish'));

    expect(fn () => $job->handle($cache))
        ->toThrow(LogicException::class, 'ack_store_not_shared');
});

test('command refuses sync null and unknown custom queue drivers', function (string $driver, string $expectedError) {
    if ($driver === 'p0005-sync') {
        Queue::extend('p0005-sync', static fn () => new class implements ConnectorInterface
        {
            /** @param  array<string, mixed>  $config */
            public function connect(array $config): SyncQueue
            {
                return new SyncQueue;
            }
        });
    }
    config()->set('queue.connections.unsafe-canary', [
        'driver' => $driver,
        'queue' => 'default',
        'retry_after' => 300,
    ]);

    $exitCode = Artisan::call('queue:workload-canary', [
        'profile' => 'operations',
        'connection' => 'unsafe-canary',
        '--dry-run' => true,
        '--json' => true,
    ]);
    $payload = p0005ArtisanJson();

    expect($exitCode)->toBe(1)
        ->and($payload['status'])->toBe('failed')
        ->and($payload['queue_connection']['persistent'])->toBeFalse()
        ->and($payload['errors'])->toContain($expectedError);
})->with([
    'sync' => ['sync', 'queue_driver_not_persistent'],
    'null' => ['null', 'queue_driver_not_persistent'],
    'custom connector returning SyncQueue' => ['p0005-sync', 'queue_driver_not_supported'],
]);

test('command refuses a database driver whose resolved connector returns SyncQueue', function () {
    Queue::extend('database', static fn () => new class implements ConnectorInterface
    {
        /** @param  array<string, mixed>  $config */
        public function connect(array $config): SyncQueue
        {
            return new SyncQueue;
        }
    });

    $exitCode = Artisan::call('queue:workload-canary', [
        'profile' => 'operations',
        'connection' => 'canary-database',
        '--dry-run' => true,
        '--json' => true,
    ]);
    $payload = p0005ArtisanJson();

    expect($exitCode)->toBe(1)
        ->and($payload['status'])->toBe('failed')
        ->and($payload['queue_connection'])->toBe([
            'driver' => 'database',
            'transport_class' => SyncQueue::class,
            'persistent' => false,
        ])
        ->and($payload['errors'])->toContain('queue_transport_mismatch')
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('real execution refuses missing release and commit identities before dispatching', function () {
    p0005BindIdentity('staging', '', '');

    $exitCode = Artisan::call('queue:workload-canary', [
        'profile' => 'operations',
        'connection' => 'canary-database',
        '--json' => true,
    ]);
    $payload = p0005ArtisanJson();

    expect($exitCode)->toBe(1)
        ->and($payload['status'])->toBe('failed')
        ->and($payload['evidence_eligible'])->toBeFalse()
        ->and($payload['errors'])->toContain('queue_canary_release_missing')
        ->and($payload['errors'])->toContain('queue_canary_commit_missing')
        ->and($payload['errors'])->not->toContain('queue_canary_environment_not_allowed')
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('real execution refuses an application environment outside staging and production', function () {
    p0005BindIdentity('local', '2026.08.03-p0.005', p0005Commit());

    $exitCode = Artisan::call('queue:workload-canary', [
        'profile' => 'operations',
        'connection' => 'canary-database',
        '--json' => true,
    ]);
    $payload = p0005ArtisanJson();

    expect($exitCode)->toBe(1)
        ->and($payload['status'])->toBe('failed')
        ->and($payload['errors'])->toContain('queue_canary_environment_not_allowed')
        ->and($payload['errors'])->not->toContain('queue_canary_release_missing')
        ->and($payload['errors'])->not->toContain('queue_canary_commit_missing')
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('identity requires a bounded safe release and a complete sha1 or sha256 commit', function () {
    p0005BindIdentity('staging', 'unsafe release/value', 'abc123');

    $identity = QueueCanary::executionIdentity();

    expect($identity['valid'])->toBeFalse()
        ->and($identity['release'])->toBe('')
        ->and($identity['commit'])->toBe('')
        ->and($identity['errors'])->toContain('queue_canary_release_invalid')
        ->and($identity['errors'])->toContain('queue_canary_commit_invalid');

    p0005BindIdentity('production', 'release_2026.08.03+1', str_repeat('a', 64));

    expect(QueueCanary::executionIdentity())->toMatchArray([
        'app_env' => 'production',
        'release' => 'release_2026.08.03+1',
        'commit' => str_repeat('a', 64),
        'valid' => true,
        'errors' => [],
    ]);
});

test('identity refuses configured capacity commit and observability release drift', function () {
    p0005BindValidIdentity();
    config()->set('capacity.baseline.commit', str_repeat('b', 40));
    config()->set('observability.release', 'another-release');

    $identity = QueueCanary::executionIdentity();

    expect($identity['valid'])->toBeFalse()
        ->and($identity['errors'])->toContain('queue_canary_capacity_commit_mismatch')
        ->and($identity['errors'])->toContain('queue_canary_observability_release_mismatch');
});

test('command fails closed when acknowledgements do not arrive before timeout', function () {
    p0005BindValidIdentity();
    p0005UseSharedInMemoryCache();
    p0005UseDatabaseQueueWithoutConsumer();

    $exitCode = Artisan::call('queue:workload-canary', [
        'profile' => 'social',
        'connection' => 'canary-database',
        '--timeout' => 1,
        '--json' => true,
    ]);
    $payload = p0005ArtisanJson();

    expect($exitCode)->toBe(1)
        ->and($payload['status'])->toBe('failed')
        ->and($payload['mode'])->toBe(QueueCanary::MODE_INTERNAL_TEST)
        ->and($payload['evidence_eligible'])->toBeFalse()
        ->and($payload['errors'])->toContain('queue_canary_acknowledgement_timeout')
        ->and($payload['queues'])->each->toMatchArray(['status' => 'timeout'])
        ->and(DB::table('jobs')->count())
        ->toBe(count(QueueWorkload::workerQueues('social', 'canary-database')));
});

test('command reports a redacted acknowledgement store probe failure before dispatch', function () {
    p0005BindValidIdentity();
    $repository = Mockery::mock(Repository::class);
    $repository->shouldReceive('put')->once()->andReturn(false);
    $repository->shouldReceive('get')->once()->andReturn(null);
    $repository->shouldReceive('forget')->once()->andReturn(true);
    p0005BindCacheRepository($repository);

    $exitCode = Artisan::call('queue:workload-canary', [
        'profile' => 'operations',
        'connection' => 'canary-database',
        '--json' => true,
    ]);
    $payload = p0005ArtisanJson();

    expect($exitCode)->toBe(1)
        ->and($payload['status'])->toBe('failed')
        ->and($payload['acknowledgement_store']['probe'])->toBe('failed')
        ->and($payload['errors'])->toBe(['ack_store_probe_failed'])
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('command reports a redacted dispatch failure from a standard database transport subclass', function () {
    p0005BindValidIdentity();
    p0005UseSharedInMemoryCache();
    Queue::extend('database', static fn () => new class implements ConnectorInterface
    {
        /** @param  array<string, mixed>  $config */
        public function connect(array $config): DatabaseQueue
        {
            return new class(Mockery::mock(Connection::class), (string) ($config['table'] ?? 'jobs'), (string) ($config['queue'] ?? 'default'), (int) ($config['retry_after'] ?? 300), (bool) ($config['after_commit'] ?? false)) extends DatabaseQueue
            {
                public function push($job, $data = '', $queue = null): never
                {
                    throw new RuntimeException('sensitive dispatch details');
                }
            };
        }
    });

    $exitCode = Artisan::call('queue:workload-canary', [
        'profile' => 'plan-scans',
        'connection' => 'canary-database',
        '--json' => true,
    ]);
    $output = Artisan::output();
    $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['status'])->toBe('failed')
        ->and($payload['errors'])->toBe(['queue_canary_dispatch_failed'])
        ->and($payload['queues'])->each->toMatchArray(['status' => 'dispatch_failed'])
        ->and($output)->not->toContain('sensitive dispatch details');
});

test('command reports a redacted acknowledgement read failure', function () {
    p0005BindValidIdentity();
    p0005UseDatabaseQueueWithoutConsumer();
    $repository = new class(new ArrayStore) extends Repository
    {
        public function get($key, $default = null): mixed
        {
            if (str_contains((string) $key, ':ack:')) {
                throw new RuntimeException('sensitive read details');
            }

            return parent::get($key, $default);
        }
    };
    p0005BindCacheRepository($repository);

    $exitCode = Artisan::call('queue:workload-canary', [
        'profile' => 'plan-scans',
        'connection' => 'canary-database',
        '--json' => true,
    ]);
    $output = Artisan::output();
    $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['status'])->toBe('failed')
        ->and($payload['errors'])->toContain('ack_store_read_failed')
        ->and($output)->not->toContain('sensitive read details');
});

test('command rejects a malformed acknowledgement instead of accepting partial evidence', function () {
    p0005BindValidIdentity();
    p0005UseDatabaseQueueWithoutConsumer();
    $repository = new class(new ArrayStore) extends Repository
    {
        public function get($key, $default = null): mixed
        {
            if (str_contains((string) $key, ':ack:')) {
                return ['schema_version' => QueueCanary::SCHEMA_VERSION];
            }

            return parent::get($key, $default);
        }
    };
    p0005BindCacheRepository($repository);

    $exitCode = Artisan::call('queue:workload-canary', [
        'profile' => 'plan-scans',
        'connection' => 'canary-database',
        '--json' => true,
    ]);
    $payload = p0005ArtisanJson();

    expect($exitCode)->toBe(1)
        ->and($payload['status'])->toBe('failed')
        ->and($payload['evidence_eligible'])->toBeFalse()
        ->and($payload['errors'])->toContain('queue_canary_acknowledgement_invalid')
        ->and($payload['queues'])->each->toMatchArray(['status' => 'invalid_acknowledgement']);
});

test('command refuses non production worker profiles', function () {
    $exitCode = Artisan::call('queue:workload-canary', [
        'profile' => 'development',
        'connection' => 'canary-database',
        '--dry-run' => true,
        '--json' => true,
    ]);
    $payload = p0005ArtisanJson();

    expect($exitCode)->toBe(1)
        ->and($payload['status'])->toBe('failed')
        ->and($payload['errors'])->toContain('worker_profile_not_production')
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('command refuses acknowledgement stores that are not shared or are ephemeral', function () {
    $cases = [
        'array is process local and ephemeral' => [
            'store' => ['driver' => 'array'],
            'database_connections' => [],
            'errors' => ['ack_store_not_shared', 'ack_store_ephemeral'],
        ],
        'file is not shared between worker hosts' => [
            'store' => ['driver' => 'file', 'path' => storage_path('framework/cache/data')],
            'database_connections' => [],
            'errors' => ['ack_store_not_shared'],
        ],
        'memcached acknowledgement can disappear before evidence is read' => [
            'store' => ['driver' => 'memcached', 'servers' => []],
            'database_connections' => [],
            'errors' => ['ack_store_ephemeral'],
        ],
        'sqlite database is local to one host' => [
            'store' => ['driver' => 'database', 'connection' => 'canary-sqlite', 'table' => 'cache'],
            'database_connections' => [
                'canary-sqlite' => ['driver' => 'sqlite', 'database' => ':memory:'],
            ],
            'errors' => ['ack_store_not_shared'],
        ],
    ];

    foreach ($cases as $case) {
        config()->set('cache.stores.rejected-canary', $case['store']);
        foreach ($case['database_connections'] as $name => $connection) {
            config()->set("database.connections.{$name}", $connection);
        }

        $exitCode = Artisan::call('queue:workload-canary', [
            'profile' => 'operations',
            'connection' => 'canary-database',
            '--store' => 'rejected-canary',
            '--dry-run' => true,
            '--json' => true,
        ]);
        $payload = p0005ArtisanJson();

        expect($exitCode)->toBe(1)
            ->and($payload['status'])->toBe('failed');
        foreach ($case['errors'] as $expectedError) {
            expect($payload['errors'])->toContain($expectedError);
        }

        expect(DB::table('jobs')->count())->toBe(0);
    }
});

test('command validates the wait timeout before dispatching', function (string $timeout, string $expectedError) {
    $exitCode = Artisan::call('queue:workload-canary', [
        'profile' => 'operations',
        'connection' => 'canary-database',
        '--timeout' => $timeout,
        '--dry-run' => true,
        '--json' => true,
    ]);
    $payload = p0005ArtisanJson();

    expect($exitCode)->toBe(1)
        ->and($payload['errors'])->toContain($expectedError)
        ->and(DB::table('jobs')->count())->toBe(0);
})->with([
    'zero' => ['0', 'wait_timeout_out_of_range'],
    'greater than configured maximum' => ['11', 'wait_timeout_out_of_range'],
    'non numeric' => ['sixty', 'wait_timeout_invalid'],
]);

test('timestamps are utc iso 8601 and elapsed durations use a monotonic clock', function () {
    $timestamp = QueueCanary::timestamp();
    $startedAt = QueueCanary::monotonicNow();
    $elapsed = QueueCanary::elapsedMilliseconds($startedAt);

    expect($timestamp)->toMatch('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z\z/')
        ->and((new DateTimeImmutable($timestamp))->getOffset())->toBe(0)
        ->and($elapsed)->toBeGreaterThanOrEqual(0.0);
});

/** @return array<string, mixed> */
function p0005ArtisanJson(): array
{
    return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
}

function p0005Commit(): string
{
    return '0123456789abcdef0123456789abcdef01234567';
}

function p0005BindValidIdentity(): void
{
    p0005BindIdentity('staging', '2026.08.03-p0.005', p0005Commit());
}

function p0005BindIdentity(string $appEnvironment, string $release, string $commit): void
{
    app()->instance('queue-canary.testing.identity', [
        'app_env' => $appEnvironment,
        'release' => $release,
        'commit' => $commit,
    ]);
}

function p0005UseAsynchronousDatabaseHarness(string $profile): void
{
    p0005UseTestDatabaseQueue();
    $queues = QueueWorkload::workerQueues($profile, 'canary-database');

    app()->instance('queue-canary.testing.tick', function () use ($queues): void {
        /** @var Worker $worker */
        $worker = app('queue.worker');
        $options = new WorkerOptions(
            name: 'queue-canary-test',
            backoff: 0,
            memory: 128,
            timeout: 15,
            sleep: 0,
            maxTries: 1,
            force: true
        );

        foreach ($queues as $queue) {
            $worker->runNextJob('canary-database', $queue, $options);
        }
    });
}

function p0005UseDatabaseQueueWithoutConsumer(): void
{
    p0005UseTestDatabaseQueue();
    app()->instance('queue-canary.testing.tick', static function (): void {});
}

function p0005UseTestDatabaseQueue(): void
{
    Queue::extend('database', static fn () => new DatabaseConnector(app('db')));
    config()->set('queue.connections.canary-database.connection', (string) config('database.default'));
}

function p0005Job(
    string $runId,
    string $canaryId,
    string $release = '2026.08.03-p0.005'
): QueueTopologyCanaryJob {
    return new QueueTopologyCanaryJob(
        $runId,
        $canaryId,
        'social',
        'canary-database',
        'social-publish',
        'canary-shared',
        QueueCanary::MODE_INTERNAL_TEST,
        'staging',
        $release,
        p0005Commit(),
        600
    );
}

function p0005ObservedJob(string $connection, string $queue): FakeJob
{
    return new class($connection, $queue) extends FakeJob
    {
        public function __construct(string $connection, string $queue)
        {
            $this->connectionName = $connection;
            $this->queue = $queue;
        }
    };
}

/** @return array{0: CacheFactory, 1: Repository} */
function p0005UseSharedInMemoryCache(): array
{
    $repository = new Repository(new ArrayStore);
    $cache = p0005BindCacheRepository($repository);

    return [$cache, $repository];
}

function p0005BindCacheRepository(Repository $repository): CacheFactory
{
    $cache = Mockery::mock(CacheFactory::class);
    $cache->shouldReceive('store')->with('canary-shared')->andReturn($repository);
    app()->instance(CacheFactory::class, $cache);

    return $cache;
}
