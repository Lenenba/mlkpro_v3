<?php

namespace App\Services\Social;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use LogicException;

final class SocialBackfillBatchLedgerService
{
    public const MUTATION_INSERT = 'insert';

    public const MUTATION_UPDATE = 'update';

    public const OPERATION_EDITORIAL_FOUNDATION = 'editorial_foundation_v1';

    public const OPERATION_LEGACY_TRANSPORT = 'legacy_transport_v1';

    private const STATE_APPLIED = 'applied';

    private const STATE_ROLLED_BACK = 'rolled_back';

    /**
     * @param  array<int, array{workspace_id:int,entity_type:string,entity_id:int,mutation:string,before_fingerprint:string|null,after_fingerprint:string}>  $entries
     */
    public function record(string $operation, array $entries): ?int
    {
        $this->assertSchemaReady();

        if ($entries === []) {
            return null;
        }

        $entries = $this->normalizeEntries($operation, $entries);
        $recordedAt = now();

        return DB::transaction(function () use ($entries, $operation, $recordedAt): int {
            $batchId = DB::table('social_backfill_batches')->insertGetId([
                'operation' => $operation,
                'state' => self::STATE_APPLIED,
                'row_count' => count($entries),
                'manifest_hash' => $this->manifestHash($operation, $entries),
                'applied_at' => $recordedAt,
                'rolled_back_at' => null,
                'created_at' => $recordedAt,
                'updated_at' => $recordedAt,
            ]);

            DB::table('social_backfill_batch_entries')->insert(array_map(
                fn (array $entry): array => [
                    'social_backfill_batch_id' => $batchId,
                    ...$entry,
                    'created_at' => $recordedAt,
                ],
                $entries,
            ));

            return $batchId;
        });
    }

    public function latestApplied(string $operation, bool $lockForUpdate = false): ?object
    {
        $this->assertOperation($operation);
        $this->assertSchemaReady();
        $query = DB::table('social_backfill_batches')
            ->where('operation', $operation)
            ->where('state', self::STATE_APPLIED)
            ->orderByDesc('id');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * @return Collection<int, object>
     */
    public function entries(int $batchId, bool $lockForUpdate = false): Collection
    {
        $query = DB::table('social_backfill_batch_entries')
            ->where('social_backfill_batch_id', $batchId)
            ->orderBy('entity_type')
            ->orderBy('entity_id');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    /**
     * @param  Collection<int, object>  $entries
     */
    public function assertManifest(object $batch, Collection $entries): void
    {
        $normalizedEntries = $entries->map(fn (object $entry): array => [
            'workspace_id' => (int) $entry->workspace_id,
            'entity_type' => (string) $entry->entity_type,
            'entity_id' => (int) $entry->entity_id,
            'mutation' => (string) $entry->mutation,
            'before_fingerprint' => $entry->before_fingerprint === null
                ? null
                : (string) $entry->before_fingerprint,
            'after_fingerprint' => (string) $entry->after_fingerprint,
        ])->all();

        if ((int) $batch->row_count !== count($normalizedEntries)
            || ! hash_equals(
                (string) $batch->manifest_hash,
                $this->manifestHash((string) $batch->operation, $normalizedEntries),
            )) {
            throw new LogicException('The Pulse backfill batch ledger manifest is inconsistent.');
        }
    }

    public function markRolledBack(int $batchId): void
    {
        $updated = DB::table('social_backfill_batches')
            ->where('id', $batchId)
            ->where('state', self::STATE_APPLIED)
            ->whereNull('rolled_back_at')
            ->update([
                'state' => self::STATE_ROLLED_BACK,
                'rolled_back_at' => now(),
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new LogicException('The Pulse backfill batch state changed during rollback.');
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function fingerprint(array $attributes): string
    {
        $encoded = json_encode(
            $this->canonicalize($attributes),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return hash('sha256', $encoded);
    }

    public function assertSchemaReady(): void
    {
        $required = [
            'social_backfill_batches' => [
                'operation',
                'state',
                'row_count',
                'manifest_hash',
                'applied_at',
                'rolled_back_at',
            ],
            'social_backfill_batch_entries' => [
                'social_backfill_batch_id',
                'workspace_id',
                'entity_type',
                'entity_id',
                'mutation',
                'before_fingerprint',
                'after_fingerprint',
            ],
        ];

        foreach ($required as $table => $columns) {
            if (! Schema::hasTable($table) || ! Schema::hasColumns($table, $columns)) {
                throw new LogicException('The additive Pulse backfill batch ledger schema is not installed.');
            }
        }
    }

    /**
     * @param  array<int, array{workspace_id:int,entity_type:string,entity_id:int,mutation:string,before_fingerprint:string|null,after_fingerprint:string}>  $entries
     * @return array<int, array{workspace_id:int,entity_type:string,entity_id:int,mutation:string,before_fingerprint:string|null,after_fingerprint:string}>
     */
    private function normalizeEntries(string $operation, array $entries): array
    {
        $this->assertOperation($operation);

        foreach ($entries as $entry) {
            if ($entry['workspace_id'] <= 0
                || $entry['entity_id'] <= 0
                || preg_match('/\A[a-z][a-z0-9_]{1,47}\z/', $entry['entity_type']) !== 1
                || ! in_array($entry['mutation'], [self::MUTATION_INSERT, self::MUTATION_UPDATE], true)
                || ($entry['mutation'] === self::MUTATION_INSERT && $entry['before_fingerprint'] !== null)
                || ($entry['mutation'] === self::MUTATION_UPDATE
                    && preg_match('/\A[0-9a-f]{64}\z/', (string) $entry['before_fingerprint']) !== 1)
                || preg_match('/\A[0-9a-f]{64}\z/', $entry['after_fingerprint']) !== 1) {
                throw new InvalidArgumentException('The Pulse backfill batch entry is invalid.');
            }
        }

        usort($entries, fn (array $left, array $right): int => [
            $left['entity_type'],
            $left['entity_id'],
        ] <=> [
            $right['entity_type'],
            $right['entity_id'],
        ]);

        $uniqueKeys = collect($entries)
            ->map(fn (array $entry): string => $entry['entity_type'].':'.$entry['entity_id'])
            ->unique();

        if ($uniqueKeys->count() !== count($entries)) {
            throw new InvalidArgumentException('The Pulse backfill batch contains duplicate entries.');
        }

        return $entries;
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     */
    private function manifestHash(string $operation, array $entries): string
    {
        return $this->fingerprint([
            'operation' => $operation,
            'entries' => $entries,
        ]);
    }

    private function assertOperation(string $operation): void
    {
        if (! in_array($operation, [
            self::OPERATION_EDITORIAL_FOUNDATION,
            self::OPERATION_LEGACY_TRANSPORT,
        ], true)) {
            throw new InvalidArgumentException('The Pulse backfill batch operation is invalid.');
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
