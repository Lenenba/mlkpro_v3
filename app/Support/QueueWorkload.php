<?php

namespace App\Support;

use InvalidArgumentException;
use LogicException;

class QueueWorkload
{
    public static function queue(string $workload): string
    {
        $definition = self::workloadDefinition($workload);
        $queue = trim((string) ($definition['queue'] ?? ''));

        if ($queue === '') {
            throw new LogicException("Async workload [{$workload}] must define a non-empty queue.");
        }

        return $queue;
    }

    /**
     * @param  array<int, int>  $fallback
     * @return array<int, int>
     */
    public static function backoff(string $workload, array $fallback = []): array
    {
        $configured = self::workloadDefinition($workload)['backoff'] ?? $fallback;
        if (! is_array($configured)) {
            return $fallback;
        }

        $normalized = array_values(array_filter(
            array_map(static fn ($value): int => max(0, (int) $value), $configured),
            static fn (int $value): bool => $value >= 0
        ));

        return $normalized !== [] ? $normalized : $fallback;
    }

    public static function timeout(string $workload, int $fallback = 60): int
    {
        $configured = self::workloadDefinition($workload)['timeout'] ?? $fallback;

        return max(1, (int) $configured);
    }

    /**
     * @return array<int, string>
     */
    public static function workerQueues(string $worker, ?string $connection = null): array
    {
        $definition = self::workerDefinition($worker);
        $queues = [];

        foreach (self::workerWorkloads($worker, $definition) as $workload) {
            $queues[] = self::queue($workload);
        }

        if ((bool) ($definition['include_default'] ?? false)) {
            $queues[] = self::defaultQueue($connection);
        }

        $queues = array_values(array_unique(array_filter(
            array_map(static fn ($queue): string => trim((string) $queue), $queues),
            static fn (string $queue): bool => $queue !== ''
        )));

        if ($queues === []) {
            throw new LogicException("Async worker [{$worker}] does not resolve any queue.");
        }

        return $queues;
    }

    public static function workerTries(string $worker): int
    {
        return max(1, (int) (self::workerDefinition($worker)['tries'] ?? 1));
    }

    public static function workerTimeout(string $worker): int
    {
        $definition = self::workerDefinition($worker);
        $timeout = max(1, (int) ($definition['timeout'] ?? 60));

        foreach (self::workerWorkloads($worker, $definition) as $workload) {
            $timeout = max($timeout, self::timeout($workload));
        }

        return $timeout;
    }

    /**
     * @return array{
     *     workloads: array<string, string>,
     *     workers: array<string, array{environment: string, workloads: array<int, string>, queues: array<int, string>, timeout: int, tries: int}>,
     *     unassigned_workloads: array<int, string>,
     *     unassigned_production_workloads: array<int, string>,
     *     external_checks: array<int, string>,
     *     errors: array<int, string>
     * }
     */
    public static function inventory(): array
    {
        $configuredWorkloads = config('async.workloads', []);
        $configuredWorkers = config('async.workers', []);
        $workloads = [];
        $workers = [];
        $assignments = [];
        $productionAssignments = [];
        $productionQueueAssignments = [];
        $externalChecks = [];
        $errors = [];

        if (! is_array($configuredWorkloads)) {
            $configuredWorkloads = [];
            $errors[] = 'async.workloads must be an array.';
        }

        foreach ($configuredWorkloads as $name => $definition) {
            $name = (string) $name;
            $assignments[$name] = [];
            $productionAssignments[$name] = [];

            if (! is_array($definition)) {
                $errors[] = "Async workload [{$name}] must be an array.";

                continue;
            }

            $queue = trim((string) ($definition['queue'] ?? ''));
            if ($queue === '') {
                $errors[] = "Async workload [{$name}] must define a non-empty queue.";

                continue;
            }

            $workloads[$name] = $queue;
        }

        if (! is_array($configuredWorkers)) {
            $configuredWorkers = [];
            $errors[] = 'async.workers must be an array.';
        }

        foreach ($configuredWorkers as $name => $definition) {
            $name = (string) $name;
            if (! is_array($definition)) {
                $errors[] = "Async worker [{$name}] must be an array.";

                continue;
            }

            $environment = trim((string) ($definition['environment'] ?? 'production')) ?: 'production';
            $workerWorkloads = self::normalizeWorkloadNames($definition['workloads'] ?? []);

            foreach ($workerWorkloads as $workload) {
                if (! array_key_exists($workload, $assignments)) {
                    $errors[] = "Async worker [{$name}] references unknown workload [{$workload}].";

                    continue;
                }

                $assignments[$workload][] = $name;
                if ($environment === 'production') {
                    $productionAssignments[$workload][] = $name;
                }
            }

            try {
                $queues = self::workerQueues($name);
                $timeout = self::workerTimeout($name);
                $tries = self::workerTries($name);
            } catch (LogicException $exception) {
                $errors[] = $exception->getMessage();
                $queues = [];
                $timeout = max(1, (int) ($definition['timeout'] ?? 60));
                $tries = max(1, (int) ($definition['tries'] ?? 1));
            }

            if ($environment === 'production') {
                foreach ($queues as $queue) {
                    $productionQueueAssignments[$queue][] = $name;
                }
            }

            $workers[$name] = [
                'environment' => $environment,
                'workloads' => $workerWorkloads,
                'queues' => $queues,
                'timeout' => $timeout,
                'tries' => $tries,
            ];
        }

        $unassigned = array_keys(array_filter(
            $assignments,
            static fn (array $workerNames): bool => $workerNames === []
        ));
        $unassignedProduction = array_keys(array_filter(
            $productionAssignments,
            static fn (array $workerNames): bool => $workerNames === []
        ));

        foreach ($unassigned as $workload) {
            $errors[] = "Async workload [{$workload}] is not assigned to any worker.";
        }

        foreach ($unassignedProduction as $workload) {
            $errors[] = "Async workload [{$workload}] is not assigned to a production worker.";
        }

        foreach ($productionQueueAssignments as $queue => $workerNames) {
            $workerNames = array_values(array_unique($workerNames));

            if (count($workerNames) > 1) {
                $errors[] = sprintf(
                    'Async queue [%s] is consumed by multiple production workers [%s].',
                    $queue,
                    implode(', ', $workerNames)
                );
            }
        }

        $maximumTimeout = max(0, ...array_map(
            static fn (array $worker): int => $worker['timeout'],
            array_values($workers)
        ));
        $connections = config('queue.connections', []);

        if (is_array($connections)) {
            foreach ($connections as $connection => $definition) {
                if (! is_array($definition)) {
                    continue;
                }

                if (($definition['driver'] ?? null) === 'sqs') {
                    $externalChecks[] = sprintf(
                        'Queue connection [%s] requires an externally managed visibility timeout greater than %d seconds.',
                        (string) $connection,
                        $maximumTimeout
                    );
                }

                if (! isset($definition['retry_after'])) {
                    continue;
                }

                $retryAfter = (int) $definition['retry_after'];
                if ($retryAfter <= $maximumTimeout) {
                    $errors[] = sprintf(
                        'Queue connection [%s] retry_after (%d) must exceed the maximum worker timeout (%d).',
                        (string) $connection,
                        $retryAfter,
                        $maximumTimeout
                    );
                }
            }
        }

        sort($unassigned);
        sort($unassignedProduction);

        return [
            'workloads' => $workloads,
            'workers' => $workers,
            'unassigned_workloads' => $unassigned,
            'unassigned_production_workloads' => $unassignedProduction,
            'external_checks' => array_values(array_unique($externalChecks)),
            'errors' => array_values(array_unique($errors)),
        ];
    }

    public static function validateWorkerConnection(string $connection): void
    {
        self::workerConnectionDefinition($connection);
    }

    private static function defaultQueue(?string $connection = null): string
    {
        $connection = trim($connection ?: (string) config('queue.default', 'database'));
        $definition = self::workerConnectionDefinition($connection);
        $queue = trim((string) ($definition['queue'] ?? 'default'));

        return $queue !== '' ? $queue : 'default';
    }

    /**
     * @return array<string, mixed>
     */
    private static function workerConnectionDefinition(string $connection): array
    {
        $connection = trim($connection);
        $definition = config("queue.connections.{$connection}");

        if ($connection === '' || ! is_array($definition)) {
            throw new InvalidArgumentException("Queue connection [{$connection}] is not configured.");
        }

        $driver = trim((string) ($definition['driver'] ?? ''));
        if ($driver === '') {
            throw new LogicException("Queue connection [{$connection}] must define a driver.");
        }

        if (in_array($driver, ['sync', 'null'], true)) {
            throw new LogicException("Queue connection [{$connection}] uses driver [{$driver}], which cannot run a persistent worker.");
        }

        if ($driver === 'sqs' && ! class_exists(\Aws\Sqs\SqsClient::class)) {
            throw new LogicException("Queue connection [{$connection}] requires the AWS SDK for PHP.");
        }

        if ($driver === 'beanstalkd' && ! class_exists(\Pheanstalk\Pheanstalk::class)) {
            throw new LogicException("Queue connection [{$connection}] requires Pheanstalk.");
        }

        if ($driver === 'redis') {
            self::validateRedisClient($connection);
        }

        return $definition;
    }

    private static function validateRedisClient(string $connection): void
    {
        $client = (string) config('database.redis.client', 'phpredis');
        $available = match ($client) {
            'phpredis' => class_exists(\Redis::class),
            'predis' => class_exists(\Predis\Client::class),
            default => true,
        };

        if (! $available) {
            throw new LogicException("Queue connection [{$connection}] requires the configured Redis client [{$client}].");
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function workloadDefinition(string $workload): array
    {
        $definition = config("async.workloads.{$workload}");

        if (! is_array($definition)) {
            throw new LogicException("Async workload [{$workload}] is not configured.");
        }

        return $definition;
    }

    /**
     * @return array<string, mixed>
     */
    private static function workerDefinition(string $worker): array
    {
        $definition = config("async.workers.{$worker}");

        if (! is_array($definition)) {
            throw new InvalidArgumentException("Async worker [{$worker}] is not configured.");
        }

        return $definition;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<int, string>
     */
    private static function workerWorkloads(string $worker, array $definition): array
    {
        $workloads = $definition['workloads'] ?? [];

        if (! is_array($workloads)) {
            throw new LogicException("Async worker [{$worker}] workloads must be an array.");
        }

        return self::normalizeWorkloadNames($workloads);
    }

    /**
     * @return array<int, string>
     */
    private static function normalizeWorkloadNames(mixed $workloads): array
    {
        if (! is_array($workloads)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn ($workload): string => trim((string) $workload), $workloads),
            static fn (string $workload): bool => $workload !== ''
        )));
    }
}
