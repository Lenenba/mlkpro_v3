<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

it('keeps the Pulse backfill ledger additive reversible and protected while a batch is active', function () {
    $migrationPath = database_path(
        'migrations/2026_08_28_224000_create_social_backfill_batch_ledgers.php'
    );
    $baseMigrationPaths = collect(File::glob(database_path('migrations/*.php')))
        ->reject(fn (string $path): bool => $path === $migrationPath)
        ->values()
        ->all();

    try {
        expect(Artisan::call('migrate:fresh', [
            '--force' => true,
            '--no-interaction' => true,
            '--path' => $baseMigrationPaths,
            '--realpath' => true,
        ]))->toBe(0);
        expect(Schema::hasTable('social_backfill_batches'))->toBeFalse()
            ->and(Schema::hasTable('social_backfill_batch_entries'))->toBeFalse();

        /** @var Migration $migration */
        $migration = require $migrationPath;
        $migration->up();
        $migration->up();

        expect(Schema::getColumnListing('social_backfill_batches'))->toBe([
            'id',
            'operation',
            'state',
            'row_count',
            'manifest_hash',
            'applied_at',
            'rolled_back_at',
            'created_at',
            'updated_at',
        ])->and(Schema::getColumnListing('social_backfill_batch_entries'))->toBe([
            'id',
            'social_backfill_batch_id',
            'workspace_id',
            'entity_type',
            'entity_id',
            'mutation',
            'before_fingerprint',
            'after_fingerprint',
            'created_at',
        ]);

        $batchIndexes = collect(Schema::getIndexes('social_backfill_batches'))->keyBy('name');
        $entryIndexes = collect(Schema::getIndexes('social_backfill_batch_entries'))->keyBy('name');
        expect($batchIndexes->get('social_backfill_batches_operation_state_idx')['columns'])
            ->toBe(['operation', 'state', 'id'])
            ->and($entryIndexes->get('social_backfill_entries_batch_entity_uq')['columns'])
            ->toBe(['social_backfill_batch_id', 'entity_type', 'entity_id'])
            ->and($entryIndexes->get('social_backfill_entries_batch_entity_uq')['unique'])
            ->toBeTrue()
            ->and($entryIndexes->get('social_backfill_entries_entity_idx')['columns'])
            ->toBe(['entity_type', 'entity_id'])
            ->and($entryIndexes->get('social_backfill_entries_workspace_batch_idx')['columns'])
            ->toBe(['workspace_id', 'social_backfill_batch_id']);

        $batchForeignKey = collect(Schema::getForeignKeys('social_backfill_batch_entries'))
            ->first(fn (array $foreignKey): bool => $foreignKey['columns'] === ['social_backfill_batch_id']);
        expect($batchForeignKey)->not->toBeNull()
            ->and($batchForeignKey['foreign_table'])->toBe('social_backfill_batches')
            ->and($batchForeignKey['foreign_columns'])->toBe(['id'])
            ->and(strtolower((string) $batchForeignKey['on_delete']))->toBeIn(['restrict', 'no action'])
            ->and(collect(Schema::getForeignKeys('social_backfill_batch_entries'))
                ->contains(fn (array $foreignKey): bool => $foreignKey['columns'] === ['workspace_id']))
            ->toBeFalse();

        $owner = User::factory()->create();
        $timestamp = '2026-08-28 22:40:00';
        $batchId = DB::table('social_backfill_batches')->insertGetId([
            'operation' => 'legacy_transport_v1',
            'state' => 'applied',
            'row_count' => 1,
            'manifest_hash' => str_repeat('a', 64),
            'applied_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        DB::table('social_backfill_batch_entries')->insert([
            'social_backfill_batch_id' => $batchId,
            'workspace_id' => $owner->id,
            'entity_type' => 'social_account_connection',
            'entity_id' => 42,
            'mutation' => 'update',
            'before_fingerprint' => str_repeat('b', 64),
            'after_fingerprint' => str_repeat('c', 64),
            'created_at' => $timestamp,
        ]);

        expect(fn () => $migration->down())
            ->toThrow(LogicException::class, 'Active Pulse backfill batches');
        expect(DB::table('social_backfill_batch_entries')->count())->toBe(1);

        DB::table('social_backfill_batches')->where('id', $batchId)->update([
            'state' => 'rolled_back',
            'rolled_back_at' => $timestamp,
        ]);
        expect(fn () => DB::table('social_backfill_batches')->where('id', $batchId)->delete())
            ->toThrow(QueryException::class);
        $migration->down();
        $migration->down();

        expect(Schema::hasTable('social_backfill_batch_entries'))->toBeFalse()
            ->and(Schema::hasTable('social_backfill_batches'))->toBeFalse();

        $migration->up();
        expect(Schema::hasTable('social_backfill_batches'))->toBeTrue()
            ->and(Schema::hasTable('social_backfill_batch_entries'))->toBeTrue();
    } finally {
        Artisan::call('migrate:fresh', [
            '--force' => true,
            '--no-interaction' => true,
        ]);
    }
});
