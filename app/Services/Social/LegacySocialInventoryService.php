<?php

namespace App\Services\Social;

use App\Jobs\GenerateSocialPostCandidateJob;
use App\Jobs\ProcessSocialDeliveryOutboxJob;
use App\Models\SocialAccountConnection;
use App\Models\SocialPostTarget;
use App\Support\QueueWorkload;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;

class LegacySocialInventoryService
{
    private const CONNECTION_IDENTITY_CHUNK_SIZE = 500;

    private const PULSE_JOB_CLASSES = [
        'social_automation' => GenerateSocialPostCandidateJob::class,
        'social_publish' => ProcessSocialDeliveryOutboxJob::class,
    ];

    private const HISTORICAL_PULSE_JOB_CLASSES = [
        'social_publish' => [
            'App\\Jobs\\PublishSocialPostTargetJob',
        ],
    ];

    private const MAX_QUEUE_SCOPES = 32;

    private const MAX_QUEUE_NAME_LENGTH = 255;

    private const REFERENCE_CHUNK_SIZE = 500;

    private const SOURCE_CONTEXTS = [
        'approved-environment',
        'local',
        'representative-clone',
        'unspecified',
    ];

    public function __construct(
        private readonly SocialLogicalDestinationKeyService $logicalDestinationKeys,
    ) {}

    /**
     * @param  list<string>  $declaredQueueScopes
     * @return array{
     *     schema_version: string,
     *     scope: string,
     *     read_only: bool,
     *     sensitive_fields: string,
     *     capture: array{
     *         started_at: string,
     *         completed_at: string,
     *         operator_declared_source_context: string,
     *         domain: string,
     *         queue_scopes: string,
     *         failed_publications: string,
     *         failed_pulse_jobs: string,
     *         cross_source_atomic: bool
     *     },
     *     connections: array<string, mixed>,
     *     targets: array<string, mixed>,
     *     references: array<string, mixed>,
     *     configured_pulse_topology: array{
     *         evidence_scope: string,
     *         deployed_runtime_proven: bool,
     *         requires_external_attestation: bool,
     *         workload_count: int,
     *         configured_workload_count: int,
     *         exactly_one_production_worker_profile_count: int,
     *         workloads: array<string, array{
     *             configured: bool,
     *             queue_fingerprint: string|null,
     *             production_worker_profile_count: int
     *         }>
     *     },
     *     failed_publications: array<string, mixed>,
     *     failed_pulse_jobs: array<string, mixed>,
     *     queue_scope_manifest: array{
     *         operator_attested_complete_scope_list: bool,
     *         recognized_job_workloads: list<string>,
     *         scope_count: int,
     *         measurable_scope_count: int,
     *         unmeasurable_scope_count: int,
     *         requires_job_policy: bool|null,
     *         scopes: list<array<string, mixed>>
     *     }
     * }
     */
    public function inventory(
        array $declaredQueueScopes = [],
        bool $completeQueueScopeListAttested = false,
        string $sourceContext = 'unspecified',
    ): array {
        $sourceContext = trim($sourceContext);
        if (! in_array($sourceContext, self::SOURCE_CONTEXTS, true)) {
            throw new InvalidArgumentException(
                'Inventory source context must be approved-environment, local, representative-clone, or unspecified.'
            );
        }

        $queueScopes = $this->resolveQueueScopes(
            $declaredQueueScopes,
            $completeQueueScopeListAttested,
        );
        $captureStartedAt = now('UTC')->toIso8601String();

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

        $queueInventories = array_map(
            fn (array $queueScope): array => $this->queuedSocialJobInventory($queueScope),
            $queueScopes,
        );
        $measurableScopeCount = count(array_filter(
            $queueInventories,
            static fn (array $queueInventory): bool => $queueInventory['measurable'],
        ));
        $failedPulseJobInventory = $this->failedPulseJobInventory();
        $captureCompletedAt = now('UTC')->toIso8601String();
        $queuedJobsObserved = count(array_filter(
            $queueInventories,
            static fn (array $queueInventory): bool => $queueInventory['measurable']
                && count(array_filter(
                    $queueInventory['jobs_by_workload'],
                    static fn (array $workloadInventory): bool => $workloadInventory['total'] > 0
                        || $workloadInventory['unparseable_candidates'] > 0,
                )) > 0,
        )) > 0;
        $queueEvidenceComplete = $completeQueueScopeListAttested
            && $measurableScopeCount === count($queueInventories);
        $queuedJobsRequirePolicy = $queuedJobsObserved
            ? true
            : ($queueEvidenceComplete ? false : null);

        return [
            'schema_version' => 'pulse_legacy_inventory_v2',
            'scope' => 'all_tenants_aggregate',
            'read_only' => true,
            'sensitive_fields' => 'excluded',
            'capture' => [
                'started_at' => $captureStartedAt,
                'completed_at' => $captureCompletedAt,
                'operator_declared_source_context' => $sourceContext,
                'domain' => 'transactional',
                'queue_scopes' => 'sequential_independent_passes',
                'failed_publications' => 'independent_single_pass',
                'failed_pulse_jobs' => 'independent_single_pass',
                'cross_source_atomic' => false,
            ],
            ...$domainInventory,
            'configured_pulse_topology' => $this->configuredPulseTopology(),
            'failed_publications' => $this->failedPublicationProjection($failedPulseJobInventory),
            'failed_pulse_jobs' => $failedPulseJobInventory,
            'queue_scope_manifest' => [
                'operator_attested_complete_scope_list' => $completeQueueScopeListAttested,
                'recognized_job_workloads' => array_keys(self::PULSE_JOB_CLASSES),
                'scope_count' => count($queueInventories),
                'measurable_scope_count' => $measurableScopeCount,
                'unmeasurable_scope_count' => count($queueInventories) - $measurableScopeCount,
                'requires_job_policy' => $queuedJobsRequirePolicy,
                'scopes' => $queueInventories,
            ],
        ];
    }

    /**
     * @param  list<string>  $declaredQueueScopes
     * @return list<array{queue_connection: string, driver: string, queue: string, queue_label: string}>
     */
    private function resolveQueueScopes(
        array $declaredQueueScopes,
        bool $completeQueueScopeListAttested,
    ): array {
        if ($completeQueueScopeListAttested && $declaredQueueScopes === []) {
            throw new InvalidArgumentException(
                'A complete queue scope attestation requires at least one explicit --queue-scope value.'
            );
        }

        if (count($declaredQueueScopes) > self::MAX_QUEUE_SCOPES) {
            throw new InvalidArgumentException(
                'No more than '.self::MAX_QUEUE_SCOPES.' queue scopes may be declared.'
            );
        }

        if ($declaredQueueScopes === []) {
            $defaultQueueConnection = (string) config('queue.default');
            $currentQueueScopes = [];

            foreach (array_keys(self::PULSE_JOB_CLASSES) as $workload) {
                $currentQueueScopes[] = $this->resolveQueueScope(
                    $defaultQueueConnection,
                    QueueWorkload::queue($workload),
                );
            }

            return $this->normalizeQueueScopes($currentQueueScopes, false);
        }

        $queueScopes = [];

        foreach ($declaredQueueScopes as $declaredQueueScope) {
            if (! is_string($declaredQueueScope)) {
                throw new InvalidArgumentException('Queue scopes must be strings.');
            }

            $scopeParts = explode(':', $declaredQueueScope, 2);
            if (count($scopeParts) !== 2) {
                throw new InvalidArgumentException(
                    'Queue scopes must use the connection:queue format.'
                );
            }

            $queueScopes[] = $this->resolveQueueScope($scopeParts[0], $scopeParts[1]);
        }

        $queueScopes = $this->normalizeQueueScopes($queueScopes, true);

        if ($completeQueueScopeListAttested) {
            $this->assertCurrentPulseQueuesDeclared($queueScopes);
        }

        return $queueScopes;
    }

    /**
     * @param  list<array{queue_connection: string, driver: string, queue: string, queue_label: string}>  $queueScopes
     * @return list<array{queue_connection: string, driver: string, queue: string, queue_label: string}>
     */
    private function normalizeQueueScopes(array $queueScopes, bool $rejectDuplicates): array
    {
        usort($queueScopes, static function (array $left, array $right): int {
            return [$left['queue_connection'], $left['queue']]
                <=> [$right['queue_connection'], $right['queue']];
        });

        $uniqueScopes = [];
        $uniqueDatabaseScopes = [];
        $normalizedScopes = [];
        foreach ($queueScopes as $queueScope) {
            $scopeKey = $queueScope['queue_connection']."\0".$queueScope['queue'];
            if (array_key_exists($scopeKey, $uniqueScopes)) {
                if ($rejectDuplicates) {
                    throw new InvalidArgumentException('Each queue scope must be declared only once.');
                }

                continue;
            }

            $databaseScopeKey = $this->databaseQueueScopeKey($queueScope);
            if ($databaseScopeKey !== null && array_key_exists($databaseScopeKey, $uniqueDatabaseScopes)) {
                if ($rejectDuplicates) {
                    throw new InvalidArgumentException(
                        'Database queue scopes must not alias the same connection, table, and queue.'
                    );
                }

                continue;
            }

            $uniqueScopes[$scopeKey] = true;
            if ($databaseScopeKey !== null) {
                $uniqueDatabaseScopes[$databaseScopeKey] = true;
            }
            $normalizedScopes[] = $queueScope;
        }

        return $normalizedScopes;
    }

    /**
     * @param  list<array{queue_connection: string, driver: string, queue: string, queue_label: string}>  $queueScopes
     */
    private function assertCurrentPulseQueuesDeclared(array $queueScopes): void
    {
        $defaultQueueConnection = (string) config('queue.default');

        foreach (array_keys(self::PULSE_JOB_CLASSES) as $workload) {
            $expectedScope = $this->resolveQueueScope(
                $defaultQueueConnection,
                QueueWorkload::queue($workload),
            );
            $expectedDatabaseScopeKey = $this->databaseQueueScopeKey($expectedScope);
            $isDeclared = count(array_filter(
                $queueScopes,
                function (array $queueScope) use ($expectedScope, $expectedDatabaseScopeKey): bool {
                    $databaseScopeKey = $this->databaseQueueScopeKey($queueScope);

                    if ($expectedDatabaseScopeKey !== null && $databaseScopeKey !== null) {
                        return hash_equals($expectedDatabaseScopeKey, $databaseScopeKey);
                    }

                    return $queueScope['queue_connection'] === $expectedScope['queue_connection']
                        && $queueScope['queue'] === $expectedScope['queue'];
                },
            )) > 0;

            if (! $isDeclared) {
                throw new InvalidArgumentException(
                    "Complete queue scope attestation is missing current Pulse workload [{$workload}]."
                );
            }
        }
    }

    /**
     * @param  array{queue_connection: string, driver: string, queue: string, queue_label: string}  $queueScope
     */
    private function databaseQueueScopeKey(array $queueScope): ?string
    {
        if ($queueScope['driver'] !== 'database') {
            return null;
        }

        $definition = config("queue.connections.{$queueScope['queue_connection']}");
        if (! is_array($definition)) {
            return null;
        }

        $databaseConnection = trim((string) ($definition['connection'] ?? config('database.default')));
        $table = trim((string) ($definition['table'] ?? 'jobs'));

        return $databaseConnection."\0".$table."\0".Str::lower($queueScope['queue']);
    }

    /**
     * @return array{queue_connection: string, driver: string, queue: string, queue_label: string}
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
        if ($resolvedQueue === ''
            || Str::length($resolvedQueue) > self::MAX_QUEUE_NAME_LENGTH
            || preg_match('/[\x00-\x1F\x7F]/', $resolvedQueue) === 1) {
            throw new InvalidArgumentException(
                'Queue name must be non-empty, at most 255 characters, and cannot contain control characters.'
            );
        }

        return [
            'queue_connection' => $resolvedQueueConnection,
            'driver' => $driver,
            'queue' => $resolvedQueue,
            'queue_label' => $this->queueEvidenceLabel($resolvedQueue),
        ];
    }

    private function queueEvidenceLabel(string $queue): string
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', $queue) === 1) {
            return $queue;
        }

        return 'sha256:'.hash('sha256', $queue);
    }

    /**
     * @return array{
     *     evidence_scope: string,
     *     deployed_runtime_proven: bool,
     *     requires_external_attestation: bool,
     *     workload_count: int,
     *     configured_workload_count: int,
     *     exactly_one_production_worker_profile_count: int,
     *     workloads: array<string, array{
     *         configured: bool,
     *         queue_fingerprint: string|null,
     *         production_worker_profile_count: int
     *     }>
     * }
     */
    private function configuredPulseTopology(): array
    {
        $asyncTopology = QueueWorkload::inventory();
        $workloads = [];
        $configuredWorkloadCount = 0;
        $exactlyOneProductionWorkerProfileCount = 0;

        foreach (array_keys(self::PULSE_JOB_CLASSES) as $workload) {
            $configuredQueue = trim($asyncTopology['workloads'][$workload] ?? '');
            $productionWorkerProfileCount = count(array_filter(
                $asyncTopology['workers'],
                static fn (array $worker): bool => $worker['environment'] === 'production'
                    && in_array($workload, $worker['workloads'], true),
            ));

            if ($configuredQueue !== '') {
                $configuredWorkloadCount++;
            }

            if ($configuredQueue !== '' && $productionWorkerProfileCount === 1) {
                $exactlyOneProductionWorkerProfileCount++;
            }

            $workloads[$workload] = [
                'configured' => $configuredQueue !== '',
                'queue_fingerprint' => $configuredQueue === ''
                    ? null
                    : 'sha256:'.hash('sha256', $configuredQueue),
                'production_worker_profile_count' => $productionWorkerProfileCount,
            ];
        }

        return [
            'evidence_scope' => 'effective_application_configuration_only',
            'deployed_runtime_proven' => false,
            'requires_external_attestation' => true,
            'workload_count' => count($workloads),
            'configured_workload_count' => $configuredWorkloadCount,
            'exactly_one_production_worker_profile_count' => $exactlyOneProductionWorkerProfileCount,
            'workloads' => $workloads,
        ];
    }

    /**
     * @return array{
     *     total: int,
     *     active: int,
     *     connected: int,
     *     by_platform: array<string, int>,
     *     by_status: array<string, int>,
     *     logical_destination_key_readiness: array{
     *         evaluated: int,
     *         derivable: int,
     *         derivation_failures: int,
     *         duplicate_or_collision_groups: int
     *     }
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
            'logical_destination_key_readiness' => [
                'evaluated' => 0,
                'derivable' => 0,
                'derivation_failures' => 0,
                'duplicate_or_collision_groups' => 0,
            ],
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

        $seenLogicalDestinationKeys = [];
        $duplicateOrCollisionKeys = [];

        DB::table('social_account_connections')
            ->select(['id', 'user_id', 'platform', 'external_account_id'])
            ->chunkById(
                self::CONNECTION_IDENTITY_CHUNK_SIZE,
                function (Collection $connections) use (
                    &$inventory,
                    &$seenLogicalDestinationKeys,
                    &$duplicateOrCollisionKeys,
                ): void {
                    foreach ($connections as $connection) {
                        $inventory['logical_destination_key_readiness']['evaluated']++;

                        try {
                            $logicalDestinationKey = $this->logicalDestinationKeys
                                ->deriveForLegacyConnection(
                                    tenantId: (string) $connection->user_id,
                                    platform: (string) $connection->platform,
                                    externalAccountId: (string) $connection->external_account_id,
                                );
                        } catch (InvalidArgumentException) {
                            $inventory['logical_destination_key_readiness']['derivation_failures']++;

                            continue;
                        }

                        $inventory['logical_destination_key_readiness']['derivable']++;

                        if (array_key_exists($logicalDestinationKey, $seenLogicalDestinationKeys)) {
                            $duplicateOrCollisionKeys[$logicalDestinationKey] = true;
                        }

                        $seenLogicalDestinationKeys[$logicalDestinationKey] = true;
                    }
                },
                'id'
            );

        $inventory['logical_destination_key_readiness']['duplicate_or_collision_groups'] =
            count($duplicateOrCollisionKeys);

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
     * @param  array{queue_connection: string, driver: string, queue: string, queue_label: string}  $queueScope
     * @return array{
     *     measurable: bool,
     *     queue_connection: string,
     *     driver: string,
     *     queue_label: string,
     *     reason: string|null,
     *     total: int|null,
     *     ready: int|null,
     *     delayed: int|null,
     *     active_reserved: int|null,
     *     expired_reserved: int|null,
     *     unparseable_candidates: int|null,
     *     jobs_by_workload: array<string, array<string, int|null>>
     * }
     */
    private function queuedSocialJobInventory(array $queueScope): array
    {
        $queueConnection = $queueScope['queue_connection'];
        $driver = $queueScope['driver'];
        $queue = $queueScope['queue'];
        $queueLabel = $queueScope['queue_label'];

        if ($driver !== 'database') {
            return $this->unmeasurableQueueInventory(
                $queueConnection,
                $driver,
                $queueLabel,
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
                $queueLabel,
                'queue_table_unavailable'
            );
        }

        return $this->databaseQueueInventory(
            $connection,
            $table,
            $queueConnection,
            $driver,
            $queue,
            $queueLabel,
        );
    }

    /**
     * @return array{
     *     measurable: true,
     *     queue_connection: string,
     *     driver: string,
     *     queue_label: string,
     *     reason: null,
     *     total: int,
     *     ready: int,
     *     delayed: int,
     *     active_reserved: int,
     *     expired_reserved: int,
     *     unparseable_candidates: int,
     *     jobs_by_workload: array<string, array<string, int>>
     * }
     */
    private function databaseQueueInventory(
        Connection $connection,
        string $table,
        string $queueConnection,
        string $driver,
        string $queue,
        string $queueLabel,
    ): array {
        $jobsByWorkload = $this->emptyQueuedWorkloadInventories();
        $nowTimestamp = now()->timestamp;
        $retryAfter = max(1, (int) config("queue.connections.{$queueConnection}.retry_after", 90));
        $expiredBefore = $nowTimestamp - $retryAfter;
        $candidates = $connection->table($table)
            ->select(['id', 'payload', 'available_at', 'reserved_at'])
            ->where('queue', $queue)
            ->where(function (Builder $query): void {
                foreach ($this->recognizedJobClasses() as $index => $jobClass) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $query->{$method}('payload', 'like', '%'.class_basename($jobClass).'%');
                }
            })
            ->orderBy('id')
            ->cursor();

        foreach ($candidates as $candidate) {
            $classification = $this->classifySocialJobPayload($candidate->payload);
            if (! $classification['parseable']) {
                foreach ($this->candidateWorkloads($candidate->payload) as $workload) {
                    $jobsByWorkload[$workload]['unparseable_candidates']++;
                }

                continue;
            }

            $workload = $classification['workload'];
            if ($workload === null) {
                continue;
            }

            $jobsByWorkload[$workload]['total']++;
            $reservedAt = $candidate->reserved_at;

            if ($reservedAt !== null) {
                if ((int) $reservedAt <= $expiredBefore) {
                    $jobsByWorkload[$workload]['ready']++;
                    $jobsByWorkload[$workload]['expired_reserved']++;
                } else {
                    $jobsByWorkload[$workload]['active_reserved']++;
                }

                continue;
            }

            if ((int) $candidate->available_at > $nowTimestamp) {
                $jobsByWorkload[$workload]['delayed']++;
            } else {
                $jobsByWorkload[$workload]['ready']++;
            }
        }

        return [
            'measurable' => true,
            'queue_connection' => $queueConnection,
            'driver' => $driver,
            'queue_label' => $queueLabel,
            'reason' => null,
            ...$jobsByWorkload['social_publish'],
            'jobs_by_workload' => $jobsByWorkload,
        ];
    }

    /**
     * @return array{
     *     measurable: bool,
     *     driver: string,
     *     reason: string|null,
     *     total: int|null,
     *     unparseable_candidates: int|null,
     *     requires_job_policy: bool|null,
     *     by_workload: array<string, array{total: int|null, unparseable_candidates: int|null}>
     * }
     */
    private function failedPulseJobInventory(): array
    {
        $definition = config('queue.failed');
        if (! is_array($definition)) {
            return $this->unmeasurableFailedSocialJobInventory(
                'unknown',
                'failed_queue_configuration_invalid',
            );
        }

        $driver = trim((string) ($definition['driver'] ?? ''));
        if (! in_array($driver, ['database', 'database-uuids'], true)) {
            return $this->unmeasurableFailedSocialJobInventory(
                $driver !== '' ? $driver : 'unknown',
                'failed_queue_driver_not_database',
            );
        }

        $databaseConnection = trim((string) ($definition['database'] ?? config('database.default')));
        $table = trim((string) ($definition['table'] ?? 'failed_jobs'));
        $connection = DB::connection($databaseConnection !== '' ? $databaseConnection : null);

        if ($table === '' || ! $connection->getSchemaBuilder()->hasTable($table)) {
            return $this->unmeasurableFailedSocialJobInventory(
                $driver,
                'failed_queue_table_unavailable',
            );
        }

        $inventory = [
            'measurable' => true,
            'driver' => $driver,
            'reason' => null,
            'total' => 0,
            'unparseable_candidates' => 0,
            'requires_job_policy' => false,
            'by_workload' => $this->emptyFailedWorkloadInventories(),
        ];
        $candidates = $connection->table($table)
            ->select(['id', 'payload'])
            ->where(function (Builder $query): void {
                foreach ($this->recognizedJobClasses() as $index => $jobClass) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $query->{$method}('payload', 'like', '%'.class_basename($jobClass).'%');
                }
            })
            ->orderBy('id')
            ->cursor();

        foreach ($candidates as $candidate) {
            $classification = $this->classifySocialJobPayload($candidate->payload);
            if (! $classification['parseable']) {
                $inventory['unparseable_candidates']++;
                foreach ($this->candidateWorkloads($candidate->payload) as $workload) {
                    $inventory['by_workload'][$workload]['unparseable_candidates']++;
                }

                continue;
            }

            $workload = $classification['workload'];
            if ($workload === null) {
                continue;
            }

            $inventory['total']++;
            $inventory['by_workload'][$workload]['total']++;
        }

        $inventory['requires_job_policy'] = $inventory['total'] > 0
            || $inventory['unparseable_candidates'] > 0;

        return $inventory;
    }

    /**
     * @return array{parseable: bool, workload: string|null}
     */
    private function classifySocialJobPayload(mixed $rawPayload): array
    {
        try {
            $payload = json_decode((string) $rawPayload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['parseable' => false, 'workload' => null];
        }

        if (! is_array($payload)) {
            return ['parseable' => true, 'workload' => null];
        }

        $displayName = $payload['displayName'] ?? null;
        $workload = is_string($displayName)
            ? $this->workloadForJobClass($displayName)
            : null;

        return [
            'parseable' => true,
            'workload' => $workload,
        ];
    }

    /**
     * @return list<string>
     */
    private function candidateWorkloads(mixed $rawPayload): array
    {
        $payload = (string) $rawPayload;
        $workloads = [];

        foreach ($this->recognizedJobClassesByWorkload() as $workload => $jobClasses) {
            foreach ($jobClasses as $jobClass) {
                if (Str::contains($payload, class_basename($jobClass), true)) {
                    $workloads[] = $workload;

                    break;
                }
            }
        }

        return $workloads;
    }

    /**
     * @return list<string>
     */
    private function recognizedJobClasses(): array
    {
        return array_values(array_unique(array_merge(
            ...array_values($this->recognizedJobClassesByWorkload()),
        )));
    }

    /**
     * @return array<string, list<string>>
     */
    private function recognizedJobClassesByWorkload(): array
    {
        $classes = [];

        foreach (self::PULSE_JOB_CLASSES as $workload => $jobClass) {
            $classes[$workload] = [
                $jobClass,
                ...(self::HISTORICAL_PULSE_JOB_CLASSES[$workload] ?? []),
            ];
        }

        return $classes;
    }

    private function workloadForJobClass(string $jobClass): ?string
    {
        foreach ($this->recognizedJobClassesByWorkload() as $workload => $jobClasses) {
            if (in_array($jobClass, $jobClasses, true)) {
                return $workload;
            }
        }

        return null;
    }

    /**
     * @return array<string, array{
     *     total: int,
     *     ready: int,
     *     delayed: int,
     *     active_reserved: int,
     *     expired_reserved: int,
     *     unparseable_candidates: int
     * }>
     */
    private function emptyQueuedWorkloadInventories(): array
    {
        return array_fill_keys(array_keys(self::PULSE_JOB_CLASSES), [
            'total' => 0,
            'ready' => 0,
            'delayed' => 0,
            'active_reserved' => 0,
            'expired_reserved' => 0,
            'unparseable_candidates' => 0,
        ]);
    }

    /**
     * @return array<string, array{total: int, unparseable_candidates: int}>
     */
    private function emptyFailedWorkloadInventories(): array
    {
        return array_fill_keys(array_keys(self::PULSE_JOB_CLASSES), [
            'total' => 0,
            'unparseable_candidates' => 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $failedPulseJobs
     * @return array{
     *     measurable: bool,
     *     driver: string,
     *     reason: string|null,
     *     total: int|null,
     *     unparseable_candidates: int|null
     * }
     */
    private function failedPublicationProjection(array $failedPulseJobs): array
    {
        return [
            'measurable' => $failedPulseJobs['measurable'],
            'driver' => $failedPulseJobs['driver'],
            'reason' => $failedPulseJobs['reason'],
            'total' => $failedPulseJobs['by_workload']['social_publish']['total'],
            'unparseable_candidates' => $failedPulseJobs['by_workload']['social_publish']['unparseable_candidates'],
        ];
    }

    /**
     * @return array{
     *     measurable: false,
     *     driver: string,
     *     reason: string,
     *     total: null,
     *     unparseable_candidates: null,
     *     requires_job_policy: null,
     *     by_workload: array<string, array{total: null, unparseable_candidates: null}>
     * }
     */
    private function unmeasurableFailedSocialJobInventory(string $driver, string $reason): array
    {
        return [
            'measurable' => false,
            'driver' => $driver,
            'reason' => $reason,
            'total' => null,
            'unparseable_candidates' => null,
            'requires_job_policy' => null,
            'by_workload' => array_fill_keys(array_keys(self::PULSE_JOB_CLASSES), [
                'total' => null,
                'unparseable_candidates' => null,
            ]),
        ];
    }

    /**
     * @return array{
     *     measurable: false,
     *     queue_connection: string,
     *     driver: string,
     *     queue_label: string,
     *     reason: string,
     *     total: null,
     *     ready: null,
     *     delayed: null,
     *     active_reserved: null,
     *     expired_reserved: null,
     *     unparseable_candidates: null,
     *     jobs_by_workload: array<string, array<string, null>>
     * }
     */
    private function unmeasurableQueueInventory(
        string $queueConnection,
        string $driver,
        string $queueLabel,
        string $reason,
    ): array {
        return [
            'measurable' => false,
            'queue_connection' => $queueConnection,
            'driver' => $driver,
            'queue_label' => $queueLabel,
            'reason' => $reason,
            'total' => null,
            'ready' => null,
            'delayed' => null,
            'active_reserved' => null,
            'expired_reserved' => null,
            'unparseable_candidates' => null,
            'jobs_by_workload' => array_fill_keys(array_keys(self::PULSE_JOB_CLASSES), [
                'total' => null,
                'ready' => null,
                'delayed' => null,
                'active_reserved' => null,
                'expired_reserved' => null,
                'unparseable_candidates' => null,
            ]),
        ];
    }
}
