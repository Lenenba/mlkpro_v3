<?php

namespace App\Support;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Queue\BeanstalkdQueue;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\RedisQueue;
use Illuminate\Queue\SqsQueue;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Throwable;

final class QueueCanary
{
    public const MODE_INTERNAL_TEST = 'internal_test';

    public const MODE_OPERATIONAL = 'operational';

    public const SCHEMA_VERSION = 3;

    private const ALLOWED_ENVIRONMENTS = ['staging', 'production'];

    private const PERSISTENT_QUEUE_DRIVERS = ['database', 'redis', 'sqs', 'beanstalkd'];

    private const TEST_IDENTITY_BINDING = 'queue-canary.testing.identity';

    private const TEST_TICK_BINDING = 'queue-canary.testing.tick';

    /**
     * @return array{store: string, driver: string, shared: bool, ephemeral: bool, errors: array<int, string>}
     */
    public static function storeStatus(?string $requestedStore = null): array
    {
        $store = self::storeName($requestedStore);
        $definition = config("cache.stores.{$store}");

        if (! is_array($definition)) {
            return [
                'store' => $store,
                'driver' => 'unknown',
                'shared' => false,
                'ephemeral' => false,
                'errors' => ['ack_store_not_configured'],
            ];
        }

        $driver = strtolower(trim((string) ($definition['driver'] ?? '')));
        if ($driver === '') {
            return [
                'store' => $store,
                'driver' => 'unknown',
                'shared' => false,
                'ephemeral' => false,
                'errors' => ['ack_store_driver_missing'],
            ];
        }

        $ephemeral = in_array($driver, ['array', 'memcached', 'null', 'octane'], true);
        $shared = match ($driver) {
            'redis', 'dynamodb', 'memcached' => true,
            'database' => self::databaseStoreIsShared($definition),
            default => false,
        };
        $errors = [];

        if (! $shared) {
            $errors[] = 'ack_store_not_shared';
        }
        if ($ephemeral) {
            $errors[] = 'ack_store_ephemeral';
        }

        return [
            'store' => $store,
            'driver' => $driver,
            'shared' => $shared,
            'ephemeral' => $ephemeral,
            'errors' => $errors,
        ];
    }

    /**
     * @return array{store: string, driver: string, shared: bool, ephemeral: bool, errors: array<int, string>}
     */
    public static function assertUsableStore(?string $requestedStore = null): array
    {
        $status = self::storeStatus($requestedStore);

        if ($status['errors'] !== []) {
            throw new LogicException($status['errors'][0]);
        }

        return $status;
    }

    /**
     * Resolve the configured connection and verify the actual Laravel transport class.
     *
     * @return array{connection: string, driver: string, transport_class: string|null, persistent: bool, errors: array<int, string>}
     */
    public static function queueConnectionStatus(string $connection): array
    {
        $connection = trim($connection);
        $definition = $connection !== '' ? config("queue.connections.{$connection}") : null;

        if (! is_array($definition)) {
            return self::queueStatus($connection, 'unknown', null, ['queue_connection_not_configured']);
        }

        $driver = strtolower(trim((string) ($definition['driver'] ?? '')));
        if ($driver === '') {
            return self::queueStatus($connection, 'unknown', null, ['queue_driver_missing']);
        }

        if (in_array($driver, ['sync', 'null'], true)) {
            return self::queueStatus($connection, $driver, null, ['queue_driver_not_persistent']);
        }

        if (! in_array($driver, self::PERSISTENT_QUEUE_DRIVERS, true)) {
            return self::queueStatus($connection, $driver, null, ['queue_driver_not_supported']);
        }

        $errors = $driver === 'database' ? self::databaseQueueErrors($definition) : [];
        if ($errors !== []) {
            return self::queueStatus($connection, $driver, null, $errors);
        }

        try {
            $transport = app('queue')->connection($connection);
        } catch (Throwable) {
            return self::queueStatus($connection, $driver, null, ['queue_connection_resolution_failed']);
        }

        $transportClass = get_class($transport);
        $expectedClass = self::expectedTransportClass($driver);
        if (! ($transport instanceof $expectedClass)) {
            return self::queueStatus($connection, $driver, $transportClass, ['queue_transport_mismatch']);
        }

        return self::queueStatus($connection, $driver, $transportClass, []);
    }

    /**
     * @return array{app_env: string, release: string, commit: string, valid: bool, errors: array<int, string>}
     */
    public static function executionIdentity(): array
    {
        $identity = self::testingIdentity() ?? [
            'app_env' => app()->environment(),
            'release' => config('async.canary.release'),
            'commit' => config('async.canary.commit'),
        ];
        $appEnvironment = strtolower(trim((string) ($identity['app_env'] ?? '')));
        $rawRelease = trim((string) ($identity['release'] ?? ''));
        $rawCommit = strtolower(trim((string) ($identity['commit'] ?? '')));
        $release = '';
        $commit = '';
        $errors = [];

        if (! in_array($appEnvironment, self::ALLOWED_ENVIRONMENTS, true)) {
            $errors[] = 'queue_canary_environment_not_allowed';
        }

        if ($rawRelease === '') {
            $errors[] = 'queue_canary_release_missing';
        } elseif (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:+-]{0,127}\z/', $rawRelease) !== 1) {
            $errors[] = 'queue_canary_release_invalid';
        } else {
            $release = $rawRelease;
        }

        if ($rawCommit === '') {
            $errors[] = 'queue_canary_commit_missing';
        } elseif (preg_match('/\A(?:[a-f0-9]{40}|[a-f0-9]{64})\z/', $rawCommit) !== 1) {
            $errors[] = 'queue_canary_commit_invalid';
        } else {
            $commit = $rawCommit;
        }

        $capacityCommit = strtolower(trim((string) config('capacity.baseline.commit')));
        if ($commit !== '' && $capacityCommit !== '' && ! hash_equals($commit, $capacityCommit)) {
            $errors[] = 'queue_canary_capacity_commit_mismatch';
        }

        $observabilityRelease = trim((string) config('observability.release'));
        if ($release !== '' && $observabilityRelease !== '' && ! hash_equals($release, $observabilityRelease)) {
            $errors[] = 'queue_canary_observability_release_mismatch';
        }

        return [
            'app_env' => $appEnvironment,
            'release' => $release,
            'commit' => $commit,
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }

    public static function mode(): string
    {
        if (app()->runningUnitTests()
            && (app()->bound(self::TEST_IDENTITY_BINDING) || app()->bound(self::TEST_TICK_BINDING))) {
            return self::MODE_INTERNAL_TEST;
        }

        return self::MODE_OPERATIONAL;
    }

    public static function probe(CacheFactory $cache, string $store, string $runId): void
    {
        self::assertIdentifier($runId);

        $key = self::prefix().':probe:'.hash('sha256', $runId);
        $value = hash('sha256', random_bytes(32));
        $repository = $cache->store($store);

        try {
            $written = $repository->put($key, $value, now()->addSeconds(30));
            $read = $repository->get($key);

            if ($written !== true || ! is_string($read) || ! hash_equals($value, $read)) {
                throw new RuntimeException('ack_store_probe_failed');
            }
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException && $exception->getMessage() === 'ack_store_probe_failed') {
                throw $exception;
            }

            throw new RuntimeException('ack_store_probe_failed', previous: $exception);
        } finally {
            try {
                $repository->forget($key);
            } catch (Throwable) {
                // The probe expires after 30 seconds even when best-effort cleanup fails.
            }
        }
    }

    public static function acknowledge(
        CacheFactory $cache,
        string $store,
        string $runId,
        string $canaryId,
        string $profile,
        string $observedConnection,
        string $observedQueue,
        string $mode,
        string $appEnvironment,
        string $release,
        string $commit,
        int $ttlSeconds
    ): void {
        self::assertUsableStore($store);
        self::assertIdentifier($runId);
        self::assertIdentifier($canaryId);
        self::assertWorkerMode($mode);
        self::assertWorkerIdentity($appEnvironment, $release, $commit);

        $written = $cache->store($store)->put(
            self::acknowledgementKey($runId, $canaryId),
            [
                'schema_version' => self::SCHEMA_VERSION,
                'run_id' => $runId,
                'canary_id' => $canaryId,
                'profile' => $profile,
                'connection' => $observedConnection,
                'queue' => $observedQueue,
                'mode' => $mode,
                'evidence_eligible' => $mode === self::MODE_OPERATIONAL,
                'app_env' => $appEnvironment,
                'release' => $release,
                'commit' => $commit,
                'acknowledged_at' => self::timestamp(),
            ],
            now()->addSeconds(self::normalizeTtl($ttlSeconds))
        );

        if ($written !== true) {
            throw new RuntimeException('queue_canary_acknowledgement_write_failed');
        }
    }

    public static function readAcknowledgement(
        CacheFactory $cache,
        string $store,
        string $runId,
        string $canaryId
    ): mixed {
        return $cache->store($store)->get(self::acknowledgementKey($runId, $canaryId));
    }

    public static function acknowledgementMatches(
        mixed $acknowledgement,
        string $runId,
        string $canaryId,
        string $profile,
        string $connection,
        string $queue,
        string $mode,
        string $appEnvironment,
        string $release,
        string $commit
    ): bool {
        if (! is_array($acknowledgement)) {
            return false;
        }

        $observedConnection = $acknowledgement['connection'] ?? null;
        $observedQueue = $acknowledgement['queue'] ?? null;

        return ($acknowledgement['schema_version'] ?? null) === self::SCHEMA_VERSION
            && self::sameString($acknowledgement['run_id'] ?? null, $runId)
            && self::sameString($acknowledgement['canary_id'] ?? null, $canaryId)
            && self::sameString($acknowledgement['profile'] ?? null, $profile)
            && self::sameString($observedConnection, $connection)
            && is_string($observedQueue)
            && self::queuesMatch($connection, $queue, $observedQueue)
            && self::sameString($acknowledgement['mode'] ?? null, $mode)
            && ($acknowledgement['evidence_eligible'] ?? null) === ($mode === self::MODE_OPERATIONAL)
            && self::sameString($acknowledgement['app_env'] ?? null, $appEnvironment)
            && self::sameString($acknowledgement['release'] ?? null, $release)
            && self::sameString($acknowledgement['commit'] ?? null, $commit)
            && is_string($acknowledgement['acknowledged_at'] ?? null)
            && trim((string) $acknowledgement['acknowledged_at']) !== '';
    }

    /**
     * @return array{connection: string, queue: string}
     */
    public static function assertObservedWorkerTarget(
        string $expectedConnection,
        string $expectedQueue,
        mixed $observedConnection,
        mixed $observedQueue
    ): array {
        $observedConnection = is_string($observedConnection) ? trim($observedConnection) : '';
        $observedQueue = is_string($observedQueue) ? trim($observedQueue) : '';

        if ($observedConnection === ''
            || $observedQueue === ''
            || ! self::sameString($observedConnection, $expectedConnection)
            || ! self::queuesMatch($expectedConnection, $expectedQueue, $observedQueue)) {
            throw new LogicException('queue_canary_worker_target_mismatch');
        }

        return [
            'connection' => $observedConnection,
            'queue' => $observedQueue,
        ];
    }

    public static function acknowledgementKey(string $runId, string $canaryId): string
    {
        self::assertIdentifier($runId);
        self::assertIdentifier($canaryId);

        return self::prefix().':ack:'.hash('sha256', $runId.'|'.$canaryId);
    }

    public static function ttlSeconds(): int
    {
        return self::normalizeTtl((int) config('async.canary.ack_ttl_seconds', 600));
    }

    public static function defaultTimeoutSeconds(): int
    {
        return max(1, min(
            self::maximumTimeoutSeconds(),
            (int) config('async.canary.timeout_seconds', 60)
        ));
    }

    public static function maximumTimeoutSeconds(): int
    {
        return max(1, min(3600, (int) config('async.canary.max_timeout_seconds', 600)));
    }

    public static function timestamp(): string
    {
        return now()->utc()->format('Y-m-d\TH:i:s.u\Z');
    }

    public static function monotonicNow(): int
    {
        return hrtime(true);
    }

    public static function elapsedMilliseconds(int $startedAt): float
    {
        return round(max(0, self::monotonicNow() - $startedAt) / 1_000_000, 3);
    }

    /**
     * Execute a process-local queue tick exclusively for the internal asynchronous test harness.
     */
    public static function runTestingTick(): void
    {
        if (! app()->runningUnitTests() || ! app()->bound(self::TEST_TICK_BINDING)) {
            return;
        }

        $tick = app()->make(self::TEST_TICK_BINDING);
        if (! is_callable($tick)) {
            throw new LogicException('queue_canary_testing_tick_invalid');
        }

        app()->call($tick);
    }

    private static function storeName(?string $requestedStore): string
    {
        $requestedStore = trim((string) $requestedStore);
        if ($requestedStore !== '') {
            return $requestedStore;
        }

        $configured = trim((string) config('async.canary.store', config('cache.default', 'database')));

        return $configured !== '' ? $configured : (string) config('cache.default', 'database');
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private static function databaseStoreIsShared(array $definition): bool
    {
        $connection = trim((string) ($definition['connection'] ?? ''));
        if ($connection === '') {
            $connection = trim((string) config('database.default'));
        }

        $driver = strtolower(trim((string) config("database.connections.{$connection}.driver")));

        return in_array($driver, ['mariadb', 'mysql', 'pgsql', 'sqlsrv'], true);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<int, string>
     */
    private static function databaseQueueErrors(array $definition): array
    {
        $databaseConnection = trim((string) ($definition['connection'] ?? config('database.default')));
        $databaseDriver = strtolower(trim((string) config("database.connections.{$databaseConnection}.driver")));

        if ($databaseDriver === '') {
            return ['queue_database_connection_not_configured'];
        }

        if ($databaseDriver === 'sqlite' && ! self::internalTestQueueHarnessActive()) {
            return ['queue_database_not_shared'];
        }

        if (! in_array($databaseDriver, ['mariadb', 'mysql', 'pgsql', 'sqlite', 'sqlsrv'], true)) {
            return ['queue_database_driver_not_supported'];
        }

        return [];
    }

    private static function assertWorkerIdentity(string $appEnvironment, string $release, string $commit): void
    {
        $current = self::executionIdentity();

        if (! $current['valid']
            || ! self::sameString($current['app_env'], $appEnvironment)
            || ! self::sameString($current['release'], $release)
            || ! self::sameString($current['commit'], $commit)) {
            throw new LogicException('queue_canary_worker_identity_mismatch');
        }
    }

    private static function assertWorkerMode(string $mode): void
    {
        if (! in_array($mode, [self::MODE_OPERATIONAL, self::MODE_INTERNAL_TEST], true)
            || ! hash_equals(self::mode(), $mode)) {
            throw new LogicException('queue_canary_worker_mode_mismatch');
        }
    }

    private static function expectedTransportClass(string $driver): string
    {
        return match ($driver) {
            'database' => DatabaseQueue::class,
            'redis' => RedisQueue::class,
            'sqs' => SqsQueue::class,
            'beanstalkd' => BeanstalkdQueue::class,
            default => throw new LogicException('queue_driver_not_supported'),
        };
    }

    /**
     * @param  array<int, string>  $errors
     * @return array{connection: string, driver: string, transport_class: string|null, persistent: bool, errors: array<int, string>}
     */
    private static function queueStatus(
        string $connection,
        string $driver,
        ?string $transportClass,
        array $errors
    ): array {
        return [
            'connection' => $connection,
            'driver' => $driver,
            'transport_class' => $transportClass,
            'persistent' => $errors === [],
            'errors' => $errors,
        ];
    }

    private static function queuesMatch(string $connection, string $expectedQueue, string $observedQueue): bool
    {
        $expectedQueue = trim($expectedQueue);
        $observedQueue = trim($observedQueue);
        if ($expectedQueue === '' || $observedQueue === '') {
            return false;
        }
        if (hash_equals($expectedQueue, $observedQueue)) {
            return true;
        }
        if (strtolower(trim((string) config("queue.connections.{$connection}.driver"))) !== 'sqs') {
            return false;
        }

        try {
            $transport = app('queue')->connection($connection);
        } catch (Throwable) {
            return false;
        }

        return $transport instanceof SqsQueue
            && hash_equals(rtrim($transport->getQueue($expectedQueue), '/'), rtrim($observedQueue, '/'));
    }

    /**
     * @return array{app_env?: mixed, release?: mixed, commit?: mixed}|null
     */
    private static function testingIdentity(): ?array
    {
        if (! app()->runningUnitTests() || ! app()->bound(self::TEST_IDENTITY_BINDING)) {
            return null;
        }

        $identity = app()->make(self::TEST_IDENTITY_BINDING);

        return is_array($identity) ? $identity : null;
    }

    private static function internalTestQueueHarnessActive(): bool
    {
        return app()->runningUnitTests() && app()->bound(self::TEST_TICK_BINDING);
    }

    private static function prefix(): string
    {
        $configured = trim((string) config('async.canary.prefix', 'async-queue-canary'));
        $normalized = preg_replace('/[^A-Za-z0-9:_-]+/', '-', $configured) ?? '';
        $normalized = trim($normalized, ':-');

        return substr($normalized !== '' ? $normalized : 'async-queue-canary', 0, 80);
    }

    private static function normalizeTtl(int $ttlSeconds): int
    {
        return max(60, min(3600, $ttlSeconds));
    }

    private static function assertIdentifier(string $identifier): void
    {
        if (! Str::isUuid($identifier)) {
            throw new LogicException('queue_canary_identifier_invalid');
        }
    }

    private static function sameString(mixed $actual, string $expected): bool
    {
        return is_string($actual) && hash_equals($expected, $actual);
    }
}
