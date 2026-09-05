<?php

namespace App\Services\Social;

use App\Models\SocialAccountConnection;
use App\Models\SocialPostTarget;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use LogicException;

final class SocialLegacyTransportBackfillService
{
    private const ENTITY_CONNECTION = 'social_account_connection';

    private const ENTITY_TARGET = 'social_post_target';

    private const LOCK_KEY = 'pulse:legacy-direct-transport-backfill';

    private const LOCK_SECONDS = 300;

    public function __construct(
        private readonly SocialLogicalDestinationKeyService $logicalDestinationKeys,
        private readonly SocialBackfillBatchLedgerService $ledger,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function preview(): array
    {
        $this->assertSchemaReady();

        $snapshot = $this->loadSnapshot(false);
        $analysis = $this->analyzeSnapshot(...$snapshot);

        return $this->publicReport($analysis, 'preflight');
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        return $this->withExclusiveLock(function (): array {
            return DB::transaction(function (): array {
                $this->assertSchemaReady();

                $snapshot = $this->loadSnapshot(true);
                $analysis = $this->analyzeSnapshot(...$snapshot);
                $this->assertReady($analysis);
                $connectionOwners = $snapshot[0]->keyBy('id');
                $postOwners = $snapshot[1]->keyBy('id');
                $targetsById = $snapshot[2]->keyBy('id');
                $ledgerEntries = [];

                foreach ($analysis['connection_updates'] as $update) {
                    $before = $this->rowAttributes(self::ENTITY_CONNECTION, $update['id']);
                    $updated = DB::table('social_account_connections')
                        ->where('id', $update['id'])
                        ->whereNull('delivery_provider')
                        ->whereNull('transport_generation')
                        ->whereNull('logical_destination_key')
                        ->update([
                            'delivery_provider' => SocialAccountConnection::DELIVERY_PROVIDER_DIRECT,
                            'transport_generation' => SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1,
                            'logical_destination_key' => $update['logical_destination_key'],
                        ]);

                    if ($updated !== 1) {
                        throw new LogicException(
                            'The legacy social connection set changed while its transport identity was being backfilled.'
                        );
                    }

                    $ledgerEntries[] = $this->updateLedgerEntry(
                        self::ENTITY_CONNECTION,
                        $update['id'],
                        (int) $connectionOwners->get($update['id'])->user_id,
                        $before,
                    );
                }

                foreach ($analysis['target_updates'] as $update) {
                    $before = $this->rowAttributes(self::ENTITY_TARGET, $update['id']);
                    $updated = DB::table('social_post_targets')
                        ->where('id', $update['id'])
                        ->whereNull('delivery_provider')
                        ->whereNull('transport_generation')
                        ->whereNull('logical_destination_key')
                        ->update([
                            'delivery_provider' => SocialAccountConnection::DELIVERY_PROVIDER_DIRECT,
                            'transport_generation' => SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1,
                            'logical_destination_key' => $update['logical_destination_key'],
                        ]);

                    if ($updated !== 1) {
                        throw new LogicException(
                            'The legacy social target set changed while its transport identity was being backfilled.'
                        );
                    }

                    $target = $targetsById->get($update['id']);
                    $ledgerEntries[] = $this->updateLedgerEntry(
                        self::ENTITY_TARGET,
                        $update['id'],
                        (int) $postOwners->get((int) $target->social_post_id)->user_id,
                        $before,
                    );
                }

                $verification = $this->analyzeSnapshot(...$this->loadSnapshot(true));
                $this->assertReady($verification);

                if ($verification['report']['connections']['backfillable'] !== 0
                    || $verification['report']['targets']['backfillable'] !== 0) {
                    throw new LogicException('The legacy transport backfill verification did not converge.');
                }

                $batchId = $this->ledger->record(
                    SocialBackfillBatchLedgerService::OPERATION_LEGACY_TRANSPORT,
                    $ledgerEntries,
                );

                $report = $this->publicReport($analysis, 'apply');
                $report['batch_id'] = $batchId;
                $report['connections']['updated'] = count($analysis['connection_updates']);
                $report['targets']['updated'] = count($analysis['target_updates']);

                return $report;
            });
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function rollback(): array
    {
        return $this->withExclusiveLock(function (): array {
            return DB::transaction(function (): array {
                $this->assertSchemaReady();

                $analysis = $this->analyzeSnapshot(...$this->loadSnapshot(true));
                $this->assertReady($analysis);
                $batch = $this->ledger->latestApplied(
                    SocialBackfillBatchLedgerService::OPERATION_LEGACY_TRANSPORT,
                    true,
                );

                if (! $batch) {
                    $report = $this->publicReport($analysis, 'rollback');
                    $report['batch_id'] = null;
                    $report['connections']['cleared'] = 0;
                    $report['targets']['cleared'] = 0;

                    return $report;
                }

                $entries = $this->ledger->entries((int) $batch->id, true);
                $this->ledger->assertManifest($batch, $entries);
                $this->assertTransportRollbackEntries($entries);
                $this->assertLedgerRowsUnchanged($entries);
                $this->assertNoNewTransportConsumers($entries);
                $entriesByType = $entries->groupBy('entity_type');

                foreach ($entriesByType->get(self::ENTITY_TARGET, collect()) as $entry) {
                    $this->restoreUpdatedLedgerEntry($entry);
                }

                foreach ($entriesByType->get(self::ENTITY_CONNECTION, collect()) as $entry) {
                    $this->restoreUpdatedLedgerEntry($entry);
                }

                $this->ledger->markRolledBack((int) $batch->id);

                $verification = $this->analyzeSnapshot(...$this->loadSnapshot(true));
                $this->assertReady($verification);

                $report = $this->publicReport($analysis, 'rollback');
                $report['batch_id'] = (int) $batch->id;
                $report['connections']['cleared'] = $entriesByType
                    ->get(self::ENTITY_CONNECTION, collect())
                    ->count();
                $report['targets']['cleared'] = $entriesByType
                    ->get(self::ENTITY_TARGET, collect())
                    ->count();

                return $report;
            });
        });
    }

    /**
     * @return array{0:Collection<int,object>,1:Collection<int,object>,2:Collection<int,object>}
     */
    private function loadSnapshot(bool $lockForUpdate): array
    {
        $connectionsQuery = DB::table('social_account_connections')->orderBy('id');
        $postsQuery = DB::table('social_posts')->orderBy('id');
        $targetsQuery = DB::table('social_post_targets')->orderBy('id');

        if ($lockForUpdate) {
            $connectionsQuery->lockForUpdate();
            $postsQuery->lockForUpdate();
            $targetsQuery->lockForUpdate();
        }

        return [
            $connectionsQuery->get([
                'id',
                'user_id',
                'platform',
                'external_account_id',
                'delivery_provider',
                'transport_generation',
                'logical_destination_key',
            ]),
            $postsQuery->get(['id', 'user_id']),
            $targetsQuery->get([
                'id',
                'social_post_id',
                'social_account_connection_id',
                'status',
                'delivery_provider',
                'transport_generation',
                'logical_destination_key',
            ]),
        ];
    }

    /**
     * @param  Collection<int, object>  $connections
     * @param  Collection<int, object>  $posts
     * @param  Collection<int, object>  $targets
     * @return array<string, mixed>
     */
    private function analyzeSnapshot(Collection $connections, Collection $posts, Collection $targets): array
    {
        $report = [
            'contract' => 'pulse_legacy_transport_backfill_v1',
            'ready' => true,
            'connections' => [
                'total' => $connections->count(),
                'backfillable' => 0,
                'already_canonical' => 0,
            ],
            'targets' => [
                'total' => $targets->count(),
                'backfillable' => 0,
                'already_canonical' => 0,
                'terminal_orphans_ignored' => 0,
            ],
            'anomalies' => [
                'total' => 0,
                'by_reason' => [],
            ],
        ];
        $connectionUpdates = [];
        $canonicalConnections = [];
        $connectionIdentities = [];
        $connectionIdentityValid = [];
        $logicalDestinationGroups = [];

        foreach ($connections as $connection) {
            try {
                $logicalDestinationKey = $this->logicalDestinationKeys->deriveForLegacyConnection(
                    (string) $connection->user_id,
                    (string) $connection->platform,
                    (string) $connection->external_account_id,
                );
            } catch (InvalidArgumentException) {
                $this->recordAnomaly($report, 'connection_identity_not_derivable');
                $connectionIdentityValid[(int) $connection->id] = false;

                continue;
            }

            $connectionId = (int) $connection->id;
            $connectionIdentities[$connectionId] = [
                'user_id' => (int) $connection->user_id,
                'logical_destination_key' => $logicalDestinationKey,
            ];
            $logicalDestinationGroups[$logicalDestinationKey] =
                (int) ($logicalDestinationGroups[$logicalDestinationKey] ?? 0) + 1;

            $state = $this->transportIdentityState($connection, $logicalDestinationKey);
            $connectionIdentityValid[$connectionId] = in_array($state, ['empty', 'canonical'], true);

            if ($state === 'empty') {
                $report['connections']['backfillable']++;
                $connectionUpdates[] = [
                    'id' => $connectionId,
                    'logical_destination_key' => $logicalDestinationKey,
                ];

                continue;
            }

            if ($state === 'canonical') {
                $report['connections']['already_canonical']++;
                $canonicalConnections[] = [
                    'id' => $connectionId,
                    'logical_destination_key' => $logicalDestinationKey,
                ];

                continue;
            }

            $this->recordAnomaly(
                $report,
                $state === 'partial'
                    ? 'connection_transport_identity_partial'
                    : 'connection_transport_identity_conflict'
            );
        }

        foreach ($logicalDestinationGroups as $count) {
            if ($count > 1) {
                $this->recordAnomaly($report, 'connection_logical_destination_duplicate_or_collision');
            }
        }

        $postsById = $posts->keyBy(fn (object $post): int => (int) $post->id);
        $connectionsById = $connections->keyBy(fn (object $connection): int => (int) $connection->id);
        $targetUpdates = [];
        $canonicalTargets = [];
        $destinationGroups = [];

        foreach ($targets as $target) {
            $status = (string) $target->status;
            if (! in_array($status, SocialPostTarget::allowedStatuses(), true)) {
                $this->recordAnomaly($report, 'target_status_unknown');

                continue;
            }

            $post = $postsById->get((int) $target->social_post_id);
            if (! $post) {
                $this->recordAnomaly($report, 'target_post_missing');

                continue;
            }

            if ($target->logical_destination_key !== null) {
                $group = (int) $target->social_post_id.'|'.(string) $target->logical_destination_key;
                $destinationGroups[$group] = (int) ($destinationGroups[$group] ?? 0) + 1;
            }

            $connection = $connectionsById->get((int) $target->social_account_connection_id);
            if (! $connection) {
                if ($this->isTerminalTargetStatus($status)) {
                    $terminalOrphanState = $this->terminalOrphanIdentityState($target);

                    if (in_array($terminalOrphanState, ['empty', 'canonical'], true)) {
                        $report['targets']['terminal_orphans_ignored']++;

                        continue;
                    }

                    $this->recordAnomaly(
                        $report,
                        $terminalOrphanState === 'partial'
                            ? 'target_transport_identity_partial'
                            : 'target_transport_identity_conflict'
                    );

                    continue;
                }

                $this->recordAnomaly($report, 'target_connection_missing_and_replayable');

                continue;
            }

            $connectionId = (int) $connection->id;
            if ((int) $post->user_id !== (int) $connection->user_id) {
                $this->recordAnomaly($report, 'target_cross_tenant');

                continue;
            }

            if (($connectionIdentityValid[$connectionId] ?? false) !== true) {
                $this->recordAnomaly($report, 'target_connection_identity_invalid');

                continue;
            }

            $logicalDestinationKey = (string) $connectionIdentities[$connectionId]['logical_destination_key'];
            $state = $this->transportIdentityState($target, $logicalDestinationKey);

            if ($state === 'empty') {
                $report['targets']['backfillable']++;
                $targetUpdates[] = [
                    'id' => (int) $target->id,
                    'logical_destination_key' => $logicalDestinationKey,
                ];
                $group = (int) $target->social_post_id.'|'.$logicalDestinationKey;
                $destinationGroups[$group] = (int) ($destinationGroups[$group] ?? 0) + 1;
            } elseif ($state === 'canonical') {
                $report['targets']['already_canonical']++;
                $canonicalTargets[] = [
                    'id' => (int) $target->id,
                    'logical_destination_key' => $logicalDestinationKey,
                ];
            } else {
                $this->recordAnomaly(
                    $report,
                    $state === 'partial'
                        ? 'target_transport_identity_partial'
                        : 'target_transport_identity_conflict'
                );

                continue;
            }
        }

        foreach ($destinationGroups as $count) {
            if ($count > 1) {
                $this->recordAnomaly($report, 'target_logical_destination_duplicate_or_collision');
            }
        }

        return [
            'report' => $report,
            'connection_updates' => $connectionUpdates,
            'target_updates' => $targetUpdates,
            'canonical_connections' => $canonicalConnections,
            'canonical_targets' => $canonicalTargets,
        ];
    }

    private function transportIdentityState(object $row, ?string $expectedLogicalDestinationKey): string
    {
        $provider = $row->delivery_provider;
        $generation = $row->transport_generation;
        $logicalDestinationKey = $row->logical_destination_key;
        $nullCount = collect([$provider, $generation, $logicalDestinationKey])
            ->filter(fn (mixed $value): bool => $value === null)
            ->count();

        if ($nullCount === 3) {
            return 'empty';
        }

        if ($nullCount > 0) {
            return 'partial';
        }

        if ((string) $provider === SocialAccountConnection::DELIVERY_PROVIDER_DIRECT
            && (string) $generation === SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1
            && $expectedLogicalDestinationKey !== null
            && hash_equals($expectedLogicalDestinationKey, (string) $logicalDestinationKey)) {
            return 'canonical';
        }

        return 'conflict';
    }

    private function terminalOrphanIdentityState(object $target): string
    {
        $provider = $target->delivery_provider;
        $generation = $target->transport_generation;
        $logicalDestinationKey = $target->logical_destination_key;
        $nullCount = collect([$provider, $generation, $logicalDestinationKey])
            ->filter(fn (mixed $value): bool => $value === null)
            ->count();

        if ($nullCount === 3) {
            return 'empty';
        }

        if ($nullCount > 0) {
            return 'partial';
        }

        if ((string) $provider === SocialAccountConnection::DELIVERY_PROVIDER_DIRECT
            && (string) $generation === SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1
            && preg_match('/\Aldk:v1:[0-9a-f]{64}\z/', (string) $logicalDestinationKey) === 1) {
            return 'canonical';
        }

        return 'conflict';
    }

    private function isTerminalTargetStatus(string $status): bool
    {
        return in_array($status, [
            SocialPostTarget::STATUS_PUBLISHED,
            SocialPostTarget::STATUS_CANCELED,
        ], true);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function recordAnomaly(array &$report, string $reason): void
    {
        $report['ready'] = false;
        $report['anomalies']['total']++;
        $report['anomalies']['by_reason'][$reason] =
            (int) ($report['anomalies']['by_reason'][$reason] ?? 0) + 1;
        ksort($report['anomalies']['by_reason']);
    }

    /**
     * @param  array<string, mixed>  $analysis
     */
    private function assertReady(array $analysis): void
    {
        if (($analysis['report']['ready'] ?? false) === true) {
            return;
        }

        $reasonCounts = collect($analysis['report']['anomalies']['by_reason'] ?? [])
            ->map(fn (mixed $count, mixed $reason): string => (string) $reason.'='.(int) $count)
            ->implode(', ');

        throw new LogicException(
            'Legacy Pulse transport backfill blocked by aggregate anomalies: '.$reasonCounts.'.'
        );
    }

    private function assertSchemaReady(): void
    {
        $this->ledger->assertSchemaReady();

        $columns = [
            'delivery_provider',
            'transport_generation',
            'logical_destination_key',
        ];

        if (! Schema::hasTable('social_account_connections')
            || ! Schema::hasColumns('social_account_connections', $columns)
            || ! Schema::hasTable('social_posts')
            || ! Schema::hasTable('social_post_targets')
            || ! Schema::hasColumns('social_post_targets', $columns)) {
            throw new LogicException('The additive Pulse transport identity schema is not installed.');
        }
    }

    /**
     * @param  array<string, mixed>  $before
     * @return array{workspace_id:int,entity_type:string,entity_id:int,mutation:string,before_fingerprint:string,after_fingerprint:string}
     */
    private function updateLedgerEntry(
        string $entityType,
        int $entityId,
        int $workspaceId,
        array $before,
    ): array {
        return [
            'workspace_id' => $workspaceId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'mutation' => SocialBackfillBatchLedgerService::MUTATION_UPDATE,
            'before_fingerprint' => $this->ledger->fingerprint($before),
            'after_fingerprint' => $this->ledger->fingerprint(
                $this->rowAttributes($entityType, $entityId)
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rowAttributes(string $entityType, int $entityId): array
    {
        [$table, $columns] = match ($entityType) {
            self::ENTITY_CONNECTION => ['social_account_connections', [
                'id',
                'user_id',
                'platform',
                'external_account_id',
                'delivery_provider',
                'transport_generation',
                'logical_destination_key',
                'updated_at',
            ]],
            self::ENTITY_TARGET => ['social_post_targets', [
                'id',
                'social_post_id',
                'social_account_connection_id',
                'status',
                'delivery_provider',
                'transport_generation',
                'logical_destination_key',
                'updated_at',
            ]],
            default => throw new LogicException('The legacy Pulse transport ledger entity is invalid.'),
        };
        $row = DB::table($table)->select($columns)->find($entityId);

        if (! $row) {
            throw new LogicException('A legacy Pulse transport ledger row is missing.');
        }

        return (array) $row;
    }

    private function entityTable(string $entityType): string
    {
        return match ($entityType) {
            self::ENTITY_CONNECTION => 'social_account_connections',
            self::ENTITY_TARGET => 'social_post_targets',
            default => throw new LogicException('The legacy Pulse transport ledger entity is invalid.'),
        };
    }

    /**
     * @param  Collection<int, object>  $entries
     */
    private function assertTransportRollbackEntries(Collection $entries): void
    {
        if ($entries->isEmpty()) {
            throw new LogicException('The legacy Pulse transport batch ledger is empty.');
        }

        foreach ($entries as $entry) {
            if (! in_array((string) $entry->entity_type, [
                self::ENTITY_CONNECTION,
                self::ENTITY_TARGET,
            ], true) || (string) $entry->mutation !== SocialBackfillBatchLedgerService::MUTATION_UPDATE) {
                throw new LogicException('The legacy Pulse transport batch ledger contains an invalid entity.');
            }
        }
    }

    /**
     * @param  Collection<int, object>  $entries
     */
    private function assertLedgerRowsUnchanged(Collection $entries): void
    {
        foreach ($entries as $entry) {
            $entityType = (string) $entry->entity_type;
            $entityId = (int) $entry->entity_id;

            if ($this->workspaceIdForRow($entityType, $entityId) !== (int) $entry->workspace_id) {
                throw new LogicException(
                    'A legacy Pulse transport ledger entry does not match the row tenant.'
                );
            }

            $currentFingerprint = $this->ledger->fingerprint(
                $this->rowAttributes($entityType, $entityId)
            );

            if (! hash_equals((string) $entry->after_fingerprint, $currentFingerprint)) {
                throw new LogicException('A legacy Pulse transport row changed after its batch was applied.');
            }
        }
    }

    private function workspaceIdForRow(string $entityType, int $entityId): int
    {
        $workspaceId = match ($entityType) {
            self::ENTITY_CONNECTION => DB::table('social_account_connections')
                ->where('id', $entityId)
                ->value('user_id'),
            self::ENTITY_TARGET => DB::table('social_post_targets')
                ->join('social_posts', 'social_posts.id', '=', 'social_post_targets.social_post_id')
                ->where('social_post_targets.id', $entityId)
                ->value('social_posts.user_id'),
            default => throw new LogicException('The legacy Pulse transport ledger entity is invalid.'),
        };

        if ($workspaceId === null) {
            throw new LogicException('A legacy Pulse transport ledger tenant cannot be resolved.');
        }

        return (int) $workspaceId;
    }

    /**
     * @param  Collection<int, object>  $entries
     */
    private function assertNoNewTransportConsumers(Collection $entries): void
    {
        $connectionIds = $entries->where('entity_type', self::ENTITY_CONNECTION)
            ->pluck('entity_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if ($connectionIds === []) {
            return;
        }

        $targetIds = $entries->where('entity_type', self::ENTITY_TARGET)
            ->pluck('entity_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $hasNewConsumer = DB::table('social_post_targets')
            ->whereIn('social_account_connection_id', $connectionIds)
            ->whereNotIn('id', $targetIds)
            ->exists();

        if ($hasNewConsumer) {
            throw new LogicException(
                'The legacy Pulse transport backfill cannot be rolled back after new consumers exist.'
            );
        }
    }

    private function restoreUpdatedLedgerEntry(object $entry): void
    {
        $cleared = DB::table($this->entityTable((string) $entry->entity_type))
            ->where('id', $entry->entity_id)
            ->update([
                'delivery_provider' => null,
                'transport_generation' => null,
                'logical_destination_key' => null,
            ]);

        if ($cleared !== 1) {
            throw new LogicException('A legacy Pulse transport row changed during rollback.');
        }

        $restoredFingerprint = $this->ledger->fingerprint(
            $this->rowAttributes((string) $entry->entity_type, (int) $entry->entity_id)
        );

        if (! hash_equals((string) $entry->before_fingerprint, $restoredFingerprint)) {
            throw new LogicException('A legacy Pulse transport row was not restored exactly.');
        }
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return array<string, mixed>
     */
    private function publicReport(array $analysis, string $mode): array
    {
        return [
            ...$analysis['report'],
            'mode' => $mode,
        ];
    }

    /**
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    private function withExclusiveLock(callable $callback): mixed
    {
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);

        if (! $lock->get()) {
            throw new LogicException('Another legacy Pulse transport operation is already running.');
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }
}
