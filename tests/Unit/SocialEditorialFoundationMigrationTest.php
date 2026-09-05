<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

it('keeps the editorial revision foundation additive and reversible across an up down up cycle', function () {
    $migrationPath = database_path(
        'migrations/2026_08_28_223000_add_editorial_revision_foundation_to_social_delivery.php'
    );
    $baseMigrationPaths = collect(File::glob(database_path('migrations/*.php')))
        ->filter(fn (string $path): bool => strcmp(
            basename($path),
            basename($migrationPath),
        ) < 0)
        ->values()
        ->all();
    $postColumns = [
        'editorial_status',
        'delivery_status',
        'sync_status',
        'current_editorial_revision',
        'scheduled_timezone',
        'scheduled_local_time',
        'payload_hash',
        'delivery_aggregated_at',
        'editorial_status_source',
        'delivery_status_source',
        'sync_status_source',
        'approved_revision_id',
    ];
    $approvalColumns = ['social_post_revision_id'];
    $targetColumns = [
        'current_editorial_revision',
        'delivery_status',
        'sync_status',
        'payload_hash',
        'current_revision_id',
        'last_submitted_revision_id',
    ];
    $providerSpecificTargetColumns = [
        'provider_post_id',
        'provider_status',
        'submitted_at',
        'remote_scheduled_for',
        'last_synced_at',
        'provider_error_code',
        'provider_error_message',
    ];
    $revisionColumns = [
        'id',
        'user_id',
        'social_post_id',
        'revision_number',
        'base_content',
        'source_snapshot',
        'media_snapshot',
        'scheduled_for',
        'scheduled_timezone',
        'scheduled_local_time',
        'payload_hash',
        'created_by_user_id',
        'approved_by_user_id',
        'approved_at',
        'origin',
        'approval_provenance',
        'created_at',
        'updated_at',
    ];
    $columnListing = static fn (string $table): array => Schema::getColumnListing($table);
    $foreignKeys = static function (string $table): array {
        return collect(Schema::getForeignKeys($table))
            ->mapWithKeys(function (array $foreignKey): array {
                $columns = array_map('strtolower', (array) $foreignKey['columns']);

                return [implode(',', $columns) => [
                    'columns' => $columns,
                    'foreign_table' => strtolower((string) $foreignKey['foreign_table']),
                    'foreign_columns' => array_map(
                        'strtolower',
                        (array) $foreignKey['foreign_columns']
                    ),
                    'on_delete' => strtolower((string) $foreignKey['on_delete']),
                ]];
            })
            ->sortKeys()
            ->all();
    };
    $indexes = static function (string $table): array {
        return collect(Schema::getIndexes($table))
            ->mapWithKeys(function (array $index): array {
                $name = strtolower((string) $index['name']);

                return [$name => [
                    'columns' => array_map('strtolower', (array) $index['columns']),
                    'unique' => (bool) $index['unique'],
                    'primary' => (bool) $index['primary'],
                ]];
            })
            ->sortKeys()
            ->all();
    };
    $explicitIndexDelta = static function (array $current, array $baseline): array {
        return collect(array_diff_key($current, $baseline))
            ->reject(fn (array $index, string $name): bool => $index['primary']
                || str_ends_with($name, '_foreign')
                || str_starts_with($name, 'sqlite_autoindex_'))
            ->all();
    };
    $addedColumns = static fn (array $current, array $baseline): array => array_values(
        array_diff($current, $baseline)
    );

    try {
        expect(Artisan::call('migrate:fresh', [
            '--force' => true,
            '--no-interaction' => true,
            '--path' => $baseMigrationPaths,
            '--realpath' => true,
        ]))->toBe(0);

        $owner = User::factory()->create();
        $createdAt = '2026-08-28 16:00:00';
        $connectionId = DB::table('social_account_connections')->insertGetId([
            'user_id' => $owner->id,
            'platform' => 'facebook',
            'label' => 'Legacy Facebook page',
            'external_account_id' => 'legacy-facebook-page',
            'status' => 'connected',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
        $postId = DB::table('social_posts')->insertGetId([
            'user_id' => $owner->id,
            'created_by_user_id' => $owner->id,
            'updated_by_user_id' => $owner->id,
            'source_type' => 'promotion',
            'source_id' => 42,
            'content_payload' => json_encode(
                ['text' => 'Legacy editorial content'],
                JSON_THROW_ON_ERROR
            ),
            'status' => 'pending_approval',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
        $targetId = DB::table('social_post_targets')->insertGetId([
            'social_post_id' => $postId,
            'social_account_connection_id' => $connectionId,
            'status' => 'pending',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
        $approvalId = DB::table('social_approval_requests')->insertGetId([
            'social_post_id' => $postId,
            'requested_by_user_id' => $owner->id,
            'status' => 'pending',
            'note' => 'Legacy approval request',
            'requested_at' => $createdAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
        $tables = [
            'social_account_connections',
            'social_posts',
            'social_post_targets',
            'social_approval_requests',
        ];
        $baselineColumns = collect($tables)
            ->mapWithKeys(fn (string $table): array => [$table => $columnListing($table)])
            ->all();
        $baselineForeignKeys = collect($tables)
            ->mapWithKeys(fn (string $table): array => [$table => $foreignKeys($table)])
            ->all();
        $baselineIndexes = collect($tables)
            ->mapWithKeys(fn (string $table): array => [$table => $indexes($table)])
            ->all();
        $assertLegacyRows = static function () use (
            $approvalId,
            $connectionId,
            $postId,
            $targetId,
        ): void {
            expect(DB::table('social_account_connections')->where('id', $connectionId)->value('label'))
                ->toBe('Legacy Facebook page');
            expect(DB::table('social_posts')->where('id', $postId)->value('status'))
                ->toBe('pending_approval');
            expect(DB::table('social_post_targets')->where('id', $targetId)->value('status'))
                ->toBe('pending');
            expect(DB::table('social_approval_requests')->where('id', $approvalId)->value('note'))
                ->toBe('Legacy approval request');
        };
        $assertLegacyAdditiveValuesAreNull = static function () use (
            $approvalColumns,
            $approvalId,
            $postColumns,
            $postId,
            $targetColumns,
            $targetId,
        ): void {
            foreach ([
                ['social_posts', $postId, $postColumns],
                ['social_post_targets', $targetId, $targetColumns],
                ['social_approval_requests', $approvalId, $approvalColumns],
            ] as [$table, $id, $columns]) {
                $values = (array) DB::table($table)->select($columns)->find($id);

                expect(array_filter($values, fn (mixed $value): bool => $value !== null))->toBe([]);
            }
        };
        $assertFoundationSchema = static function () use (
            $addedColumns,
            $approvalColumns,
            $baselineColumns,
            $baselineForeignKeys,
            $baselineIndexes,
            $columnListing,
            $explicitIndexDelta,
            $foreignKeys,
            $indexes,
            $postColumns,
            $providerSpecificTargetColumns,
            $revisionColumns,
            $targetColumns,
        ): void {
            expect(Schema::hasTable('social_post_revisions'))->toBeTrue();
            expect($columnListing('social_post_revisions'))->toBe($revisionColumns);
            expect($addedColumns(
                $columnListing('social_posts'),
                $baselineColumns['social_posts']
            ))->toBe($postColumns);
            expect($addedColumns(
                $columnListing('social_approval_requests'),
                $baselineColumns['social_approval_requests']
            ))->toBe($approvalColumns);
            expect($addedColumns(
                $columnListing('social_post_targets'),
                $baselineColumns['social_post_targets']
            ))->toBe($targetColumns);
            expect(array_values(array_intersect(
                $columnListing('social_post_targets'),
                $providerSpecificTargetColumns
            )))->toBe([]);

            expect($foreignKeys('social_post_revisions'))->toBe([
                'approved_by_user_id' => [
                    'columns' => ['approved_by_user_id'],
                    'foreign_table' => 'users',
                    'foreign_columns' => ['id'],
                    'on_delete' => 'set null',
                ],
                'created_by_user_id' => [
                    'columns' => ['created_by_user_id'],
                    'foreign_table' => 'users',
                    'foreign_columns' => ['id'],
                    'on_delete' => 'set null',
                ],
                'social_post_id' => [
                    'columns' => ['social_post_id'],
                    'foreign_table' => 'social_posts',
                    'foreign_columns' => ['id'],
                    'on_delete' => 'cascade',
                ],
                'user_id' => [
                    'columns' => ['user_id'],
                    'foreign_table' => 'users',
                    'foreign_columns' => ['id'],
                    'on_delete' => 'cascade',
                ],
            ]);
            expect(array_diff_key(
                $foreignKeys('social_posts'),
                $baselineForeignKeys['social_posts']
            ))->toBe([
                'approved_revision_id' => [
                    'columns' => ['approved_revision_id'],
                    'foreign_table' => 'social_post_revisions',
                    'foreign_columns' => ['id'],
                    'on_delete' => 'set null',
                ],
            ]);
            expect(array_diff_key(
                $foreignKeys('social_approval_requests'),
                $baselineForeignKeys['social_approval_requests']
            ))->toBe([
                'social_post_revision_id' => [
                    'columns' => ['social_post_revision_id'],
                    'foreign_table' => 'social_post_revisions',
                    'foreign_columns' => ['id'],
                    'on_delete' => 'set null',
                ],
            ]);
            expect(array_diff_key(
                $foreignKeys('social_post_targets'),
                $baselineForeignKeys['social_post_targets']
            ))->toBe([
                'current_revision_id' => [
                    'columns' => ['current_revision_id'],
                    'foreign_table' => 'social_post_revisions',
                    'foreign_columns' => ['id'],
                    'on_delete' => 'set null',
                ],
                'last_submitted_revision_id' => [
                    'columns' => ['last_submitted_revision_id'],
                    'foreign_table' => 'social_post_revisions',
                    'foreign_columns' => ['id'],
                    'on_delete' => 'set null',
                ],
            ]);

            expect($explicitIndexDelta($indexes('social_post_revisions'), []))->toBe([
                'social_post_revisions_post_approved_idx' => [
                    'columns' => ['social_post_id', 'approved_at'],
                    'unique' => false,
                    'primary' => false,
                ],
                'social_post_revisions_post_number_uq' => [
                    'columns' => ['social_post_id', 'revision_number'],
                    'unique' => true,
                    'primary' => false,
                ],
                'social_post_revisions_user_created_idx' => [
                    'columns' => ['user_id', 'created_at'],
                    'unique' => false,
                    'primary' => false,
                ],
            ]);
            expect($explicitIndexDelta(
                $indexes('social_posts'),
                $baselineIndexes['social_posts']
            ))->toBe([
                'social_posts_user_delivery_idx' => [
                    'columns' => ['user_id', 'delivery_status'],
                    'unique' => false,
                    'primary' => false,
                ],
                'social_posts_user_editorial_idx' => [
                    'columns' => ['user_id', 'editorial_status'],
                    'unique' => false,
                    'primary' => false,
                ],
            ]);
            expect($explicitIndexDelta(
                $indexes('social_account_connections'),
                $baselineIndexes['social_account_connections']
            ))->toBe([
                'sac_transport_destination_uq' => [
                    'columns' => [
                        'user_id',
                        'delivery_provider',
                        'transport_generation',
                        'logical_destination_key',
                    ],
                    'unique' => true,
                    'primary' => false,
                ],
            ]);
            expect($explicitIndexDelta(
                $indexes('social_post_targets'),
                $baselineIndexes['social_post_targets']
            ))->toBe([
                'social_post_targets_post_delivery_idx' => [
                    'columns' => ['social_post_id', 'delivery_status'],
                    'unique' => false,
                    'primary' => false,
                ],
                'spt_post_destination_uq' => [
                    'columns' => ['social_post_id', 'logical_destination_key'],
                    'unique' => true,
                    'primary' => false,
                ],
            ]);
        };
        $assertBaselineSchema = static function () use (
            $baselineColumns,
            $baselineForeignKeys,
            $baselineIndexes,
            $columnListing,
            $foreignKeys,
            $indexes,
            $tables,
        ): void {
            expect(Schema::hasTable('social_post_revisions'))->toBeFalse();

            foreach ($tables as $table) {
                expect($columnListing($table))->toBe($baselineColumns[$table]);
                expect($foreignKeys($table))->toBe($baselineForeignKeys[$table]);
                expect($indexes($table))->toBe($baselineIndexes[$table]);
            }
        };

        /** @var Migration $migration */
        $migration = require $migrationPath;

        $migration->up();
        $assertFoundationSchema();
        $assertLegacyRows();
        $assertLegacyAdditiveValuesAreNull();

        $revisionId = DB::table('social_post_revisions')->insertGetId([
            'user_id' => $owner->id,
            'social_post_id' => $postId,
            'revision_number' => 1,
            'base_content' => json_encode(
                ['content_payload' => ['text' => 'Legacy editorial content']],
                JSON_THROW_ON_ERROR
            ),
            'scheduled_timezone' => 'America/Toronto',
            'payload_hash' => str_repeat('a', 64),
            'created_by_user_id' => $owner->id,
            'origin' => 'legacy_backfill',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
        DB::table('social_posts')->where('id', $postId)->update([
            'approved_revision_id' => $revisionId,
        ]);
        DB::table('social_approval_requests')->where('id', $approvalId)->update([
            'social_post_revision_id' => $revisionId,
        ]);
        DB::table('social_post_targets')->where('id', $targetId)->update([
            'current_revision_id' => $revisionId,
            'last_submitted_revision_id' => $revisionId,
        ]);

        foreach ([
            ['social_posts', 'approved_revision_id'],
            ['social_approval_requests', 'social_post_revision_id'],
            ['social_post_targets', 'current_revision_id'],
            ['social_post_targets', 'last_submitted_revision_id'],
        ] as [$tableName, $column]) {
            Schema::table($tableName, function (Blueprint $table) use ($column): void {
                $table->dropForeign([$column]);
            });

            expect(collect(Schema::getForeignKeys($tableName))
                ->contains(fn (array $foreignKey): bool => $foreignKey['columns'] === [$column]))
                ->toBeFalse();
        }

        $migration->down();
        $assertBaselineSchema();
        $assertLegacyRows();

        $migration->up();
        $assertFoundationSchema();
        $assertLegacyRows();
        $assertLegacyAdditiveValuesAreNull();
        expect(DB::table('social_post_revisions')->count())->toBe(0);
    } finally {
        Artisan::call('migrate:fresh', [
            '--force' => true,
            '--no-interaction' => true,
        ]);
    }
});
