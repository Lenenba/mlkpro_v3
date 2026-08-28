<?php

namespace App\Services\Social;

use App\Jobs\PublishSocialPostTargetJob;
use App\Models\SocialAccountConnection;
use App\Models\SocialPostTarget;
use App\Support\QueueWorkload;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;

class LegacySocialInventoryService
{
    private const REFERENCE_CHUNK_SIZE = 500;

    /**
     * @return array{
     *     schema_version: string,
     *     scope: string,
     *     read_only: bool,
     *     sensitive_fields: string,
     *     capture: array{domain: string, queued_publications: string, cross_source_atomic: bool},
     *     connections: array<string, mixed>,
     *     targets: array<string, mixed>,
     *     references: array<string, mixed>,
     *     queued_publications: array<string, mixed>
     * }
     */
    public function inventory(?string $queueConnection = null, ?string $queue = null): array
    {
        $queueScope = $this->resolveQueueScope($queueConnection, $queue);

        $domainInventory = DB::connection()->transaction(function (): array {
            return [
                'connections' => $this->connectionInventory(),
                'targets' => $this->targetInventory(),
                'references' => [
                    'automation_rules' => $this->referenceInventory(
                        table: 'social_automation_rules',
                        jsonColumn: 'target_connection_ids',
                    ),
                    'post_templates' => $this->referenceInventory(
                        table: 'social_post_templates',
                        jsonColumn: 'metadata',
                        path: 'selected_target_connection_ids',
                    ),
                ],
            ];
        });

        return [
            'schema_version' => 'pulse_legacy_inventory_v1',
            'scope' => 'all_tenants_aggregate',
            'read_only' => true,
            'sensitive_fields' => 'excluded',
            'capture' => [
                'domain' => 'transactional',
                'queued_publications' => 'independent_single_pass',
                'cross_source_atomic' => false,
            ],
            ...$domainInventory,
            'queued_publications' => $this->queuedPublicationInventory($queueScope),
        ];
    }

    /**
     * @return array{queue_connection: string, driver: string, queue: string}
     */
    private function resolveQueueScope(?string $queueConnection, ?string $queue): array
    {
        $resolvedQueueConnection = trim($queueConnection ?? (string) config('queue.default'));
        if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,190}\z/', $resolvedQueueConnection)) {
            throw new InvalidArgumentException('Queue connection must use only letters, numbers, underscores, or hyphens.');
        }

        $definition = config("queue.connections.{$resolvedQueueConnection}");
        if (! is_array($definition)) {
            throw new InvalidArgumentException("Queue connection [{$resolvedQueueConnection}] is not configured.");
        }

        $driver = trim((string) ($definition['driver'] ?? ''));
        if ($driver === '') {
            throw new InvalidArgumentException("Queue connection [{$resolvedQueueConnection}] does not define a driver.");
        }

        $resolvedQueue = trim($queue ?? QueueWorkload::queue('social_publish'));
        if ($resolvedQueue === '' || preg_match('/[\x00-\x1F\x7F]/', $resolvedQueue) === 1) {
            throw new InvalidArgumentException(
                'Queue name must be non-empty and cannot contain control characters.'
            );
        }

        return [
            'queue_connection' => $resolvedQueueConnection,
            'driver' => $driver,
            'queue' => $resolvedQueue,
        ];
    }

    /**
     * @return array{
     *     total: int,
     *     active: int,
     *     connected: int,
     *     by_platform: array<string, int>,
     *     by_status: array<string, int>
     * }
     */
    private function connectionInventory(): array
    {
        $inventory = [
            'total' => 0,
            'active' => 0,
            'connected' => 0,
            'by_platform' => [],
            'by_status' => [],
        ];

        $connections = DB::table('social_account_connections')
            ->select(['platform', 'status', 'is_active'])
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('platform', 'status', 'is_active')
            ->orderBy('platform')
            ->orderBy('status')
            ->get();

        foreach ($connections as $connection) {
            $platform = (string) $connection->platform;
            $status = (string) $connection->status;
            $count = (int) $connection->aggregate;

            $inventory['total'] += $count;
            $inventory['active'] += (bool) $connection->is_active ? $count : 0;
            $inventory['connected'] += $status === SocialAccountConnection::STATUS_CONNECTED ? $count : 0;
            $inventory['by_platform'][$platform] = ($inventory['by_platform'][$platform] ?? 0) + $count;
            $inventory['by_status'][$status] = ($inventory['by_status'][$status] ?? 0) + $count;
        }

        ksort($inventory['by_platform']);
        ksort($inventory['by_status']);

        return $inventory;
    }

    /**
     * @return array{
     *     total: int,
     *     with_connection: int,
     *     without_connection: int,
     *     cross_tenant: int,
     *     future_scheduled: int,
     *     by_status: array<string, int>
     * }
     */
    private function targetInventory(): array
    {
        $inventory = [
            'total' => 0,
            'with_connection' => 0,
            'without_connection' => 0,
            'cross_tenant' => 0,
            'future_scheduled' => 0,
            'by_status' => [],
        ];

        $targets = DB::table('social_post_targets as targets')
            ->join('social_posts as posts', 'posts.id', '=', 'targets.social_post_id')
            ->leftJoin(
                'social_account_connections as connections',
                'connections.id',
                '=',
                'targets.social_account_connection_id'
            )
            ->select('targets.status as target_status')
            ->selectRaw('COUNT(*) as aggregate')
            ->selectRaw('SUM(CASE WHEN connections.id IS NOT NULL THEN 1 ELSE 0 END) as with_connection')
            ->selectRaw('SUM(CASE WHEN connections.id IS NULL THEN 1 ELSE 0 END) as without_connection')
            ->selectRaw(
                'SUM(CASE WHEN connections.id IS NOT NULL AND posts.user_id <> connections.user_id THEN 1 ELSE 0 END) as cross_tenant'
            )
            ->selectRaw(
                'SUM(CASE WHEN targets.status = ? AND posts.scheduled_for > ? THEN 1 ELSE 0 END) as future_scheduled',
                [SocialPostTarget::STATUS_SCHEDULED, now()]
            )
            ->groupBy('targets.status')
            ->orderBy('targets.status')
            ->get();

        foreach ($targets as $target) {
            $status = (string) $target->target_status;
            $count = (int) $target->aggregate;

            $inventory['total'] += $count;
            $inventory['with_connection'] += (int) $target->with_connection;
            $inventory['without_connection'] += (int) $target->without_connection;
            $inventory['cross_tenant'] += (int) $target->cross_tenant;
            $inventory['future_scheduled'] += (int) $target->future_scheduled;
            $inventory['by_status'][$status] = $count;
        }

        ksort($inventory['by_status']);

        return $inventory;
    }

    /**
     * @return array{
     *     records: int,
     *     records_with_references: int,
     *     references: int,
     *     missing_references: int,
     *     cross_tenant_references: int,
     *     malformed_records: int,
     *     invalid_references: int,
     *     duplicate_references: int
     * }
     */
    private function referenceInventory(string $table, string $jsonColumn, ?string $path = null): array
    {
        $inventory = [
            'records' => 0,
            'records_with_references' => 0,
            'references' => 0,
            'missing_references' => 0,
            'cross_tenant_references' => 0,
            'malformed_records' => 0,
            'invalid_references' => 0,
            'duplicate_references' => 0,
        ];

        DB::table($table)
            ->select(['id', 'user_id', $jsonColumn])
            ->chunkById(
                self::REFERENCE_CHUNK_SIZE,
                function (Collection $records) use (&$inventory, $jsonColumn, $path): void {
                    $parsedRecords = [];
                    $referencedConnectionIds = [];

                    foreach ($records as $record) {
                        $inventory['records']++;
                        $parsed = $this->parseConnectionReferences($record->{$jsonColumn}, $path);
                        $inventory['malformed_records'] += $parsed['malformed'] ? 1 : 0;
                        $inventory['invalid_references'] += $parsed['invalid'];
                        $inventory['duplicate_references'] += $parsed['duplicates'];

                        if ($parsed['ids'] !== []) {
                            $inventory['records_with_references']++;
                        }

                        $parsedRecords[] = [
                            'user_id' => (int) $record->user_id,
                            'connection_ids' => $parsed['ids'],
                        ];
                        array_push($referencedConnectionIds, ...$parsed['ids']);
                    }

                    $connectionOwners = DB::table('social_account_connections')
                        ->whereIn('id', array_values(array_unique($referencedConnectionIds)))
                        ->pluck('user_id', 'id')
                        ->mapWithKeys(fn (mixed $userId, mixed $connectionId): array => [
                            (int) $connectionId => (int) $userId,
                        ])
                        ->all();

                    foreach ($parsedRecords as $parsedRecord) {
                        foreach ($parsedRecord['connection_ids'] as $connectionId) {
                            $inventory['references']++;

                            if (! array_key_exists($connectionId, $connectionOwners)) {
                                $inventory['missing_references']++;

                                continue;
                            }

                            if ($connectionOwners[$connectionId] !== $parsedRecord['user_id']) {
                                $inventory['cross_tenant_references']++;
                            }
                        }
                    }
                },
                'id'
            );

        return $inventory;
    }

    /**
     * @return array{ids: list<int>, malformed: bool, invalid: int, duplicates: int}
     */
    private function parseConnectionReferences(mixed $rawValue, ?string $path): array
    {
        if ($rawValue === null || $rawValue === '') {
            return ['ids' => [], 'malformed' => false, 'invalid' => 0, 'duplicates' => 0];
        }

        try {
            $decoded = is_string($rawValue)
                ? json_decode($rawValue, true, 512, JSON_THROW_ON_ERROR)
                : $rawValue;
        } catch (JsonException) {
            return ['ids' => [], 'malformed' => true, 'invalid' => 1, 'duplicates' => 0];
        }

        if ($path !== null) {
            if (! is_array($decoded)) {
                return ['ids' => [], 'malformed' => true, 'invalid' => 1, 'duplicates' => 0];
            }

            $decoded = data_get($decoded, $path);
        }

        if ($decoded === null) {
            return ['ids' => [], 'malformed' => false, 'invalid' => 0, 'duplicates' => 0];
        }

        if (! is_array($decoded)) {
            return ['ids' => [], 'malformed' => true, 'invalid' => 1, 'duplicates' => 0];
        }

        if (! array_is_list($decoded)) {
            return ['ids' => [], 'malformed' => true, 'invalid' => 1, 'duplicates' => 0];
        }

        $ids = [];
        $seen = [];
        $invalid = 0;
        $duplicates = 0;

        foreach ($decoded as $connectionId) {
            if (! is_int($connectionId)
                && ! (is_string($connectionId) && ctype_digit($connectionId))) {
                $invalid++;

                continue;
            }

            $normalizedConnectionId = (int) $connectionId;
            if ($normalizedConnectionId <= 0) {
                $invalid++;

                continue;
            }

            if (array_key_exists($normalizedConnectionId, $seen)) {
                $duplicates++;

                continue;
            }

            $seen[$normalizedConnectionId] = true;
            $ids[] = $normalizedConnectionId;
        }

        return [
            'ids' => $ids,
            'malformed' => false,
            'invalid' => $invalid,
            'duplicates' => $duplicates,
        ];
    }

    /**
     * @param  array{queue_connection: string, driver: string, queue: string}  $queueScope
     * @return array{
     *     measurable: bool,
     *     queue_connection: string,
     *     driver: string,
     *     queue: string,
     *     reason: string|null,
     *     total: int|null,
     *     ready: int|null,
     *     delayed: int|null,
     *     active_reserved: int|null,
     *     expired_reserved: int|null,
     *     unparseable_candidates: int|null
     * }
     */
    private function queuedPublicationInventory(array $queueScope): array
    {
        $queueConnection = $queueScope['queue_connection'];
        $driver = $queueScope['driver'];
        $queue = $queueScope['queue'];

        if ($driver !== 'database') {
            return $this->unmeasurableQueueInventory(
                $queueConnection,
                $driver,
                $queue,
                'queue_driver_not_database'
            );
        }

        $table = trim((string) config("queue.connections.{$queueConnection}.table", 'jobs'));
        $databaseConnection = config("queue.connections.{$queueConnection}.connection");
        $connection = DB::connection(is_string($databaseConnection) ? $databaseConnection : null);

        if ($table === '' || ! $connection->getSchemaBuilder()->hasTable($table)) {
            return $this->unmeasurableQueueInventory(
                $queueConnection,
                $driver,
                $queue,
                'queue_table_unavailable'
            );
        }

        return $this->databaseQueueInventory($connection, $table, $queueConnection, $driver, $queue);
    }

    /**
     * @return array{
     *     measurable: true,
     *     queue_connection: string,
     *     driver: string,
     *     queue: string,
     *     reason: null,
     *     total: int,
     *     ready: int,
     *     delayed: int,
     *     active_reserved: int,
     *     expired_reserved: int,
     *     unparseable_candidates: int
     * }
     */
    private function databaseQueueInventory(
        Connection $connection,
        string $table,
        string $queueConnection,
        string $driver,
        string $queue,
    ): array {
        $inventory = [
            'measurable' => true,
            'queue_connection' => $queueConnection,
            'driver' => $driver,
            'queue' => $queue,
            'reason' => null,
            'total' => 0,
            'ready' => 0,
            'delayed' => 0,
            'active_reserved' => 0,
            'expired_reserved' => 0,
            'unparseable_candidates' => 0,
        ];
        $nowTimestamp = now()->timestamp;
        $retryAfter = max(1, (int) config("queue.connections.{$queueConnection}.retry_after", 90));
        $expiredBefore = $nowTimestamp - $retryAfter;
        $candidates = $connection->table($table)
            ->select(['id', 'payload', 'available_at', 'reserved_at'])
            ->where('queue', $queue)
            ->where('payload', 'like', '%'.class_basename(PublishSocialPostTargetJob::class).'%')
            ->orderBy('id')
            ->cursor();

        foreach ($candidates as $candidate) {
            try {
                $payload = json_decode((string) $candidate->payload, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $inventory['unparseable_candidates']++;

                continue;
            }

            if (! is_array($payload)
                || ($payload['displayName'] ?? null) !== PublishSocialPostTargetJob::class) {
                continue;
            }

            $inventory['total']++;
            $reservedAt = $candidate->reserved_at;

            if ($reservedAt !== null) {
                if ((int) $reservedAt <= $expiredBefore) {
                    $inventory['ready']++;
                    $inventory['expired_reserved']++;
                } else {
                    $inventory['active_reserved']++;
                }

                continue;
            }

            if ((int) $candidate->available_at > $nowTimestamp) {
                $inventory['delayed']++;
            } else {
                $inventory['ready']++;
            }
        }

        return $inventory;
    }

    /**
     * @return array{
     *     measurable: false,
     *     queue_connection: string,
     *     driver: string,
     *     queue: string,
     *     reason: string,
     *     total: null,
     *     ready: null,
     *     delayed: null,
     *     active_reserved: null,
     *     expired_reserved: null,
     *     unparseable_candidates: null
     * }
     */
    private function unmeasurableQueueInventory(
        string $queueConnection,
        string $driver,
        string $queue,
        string $reason,
    ): array {
        return [
            'measurable' => false,
            'queue_connection' => $queueConnection,
            'driver' => $driver,
            'queue' => $queue,
            'reason' => $reason,
            'total' => null,
            'ready' => null,
            'delayed' => null,
            'active_reserved' => null,
            'expired_reserved' => null,
            'unparseable_candidates' => null,
        ];
    }
}
