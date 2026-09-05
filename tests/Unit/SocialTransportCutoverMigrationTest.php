<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

it('keeps the Pulse cutover control plane additive reversible and audit protected', function () {
    $migrationPath = database_path(
        'migrations/2026_08_29_045009_create_social_transport_cutover_control_plane.php',
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

        /** @var Migration $migration */
        $migration = require $migrationPath;

        Schema::create('social_transport_cutovers', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('social_transport_cutover_mappings', function (Blueprint $table): void {
            $table->id();
        });
        DB::table('social_transport_cutover_mappings')->insert(['id' => 1]);

        expect(fn () => $migration->up())->toThrow(
            LogicException::class,
            'a previous failed migration left tables behind',
        )->and(Schema::hasTable('social_transport_cutover_mappings'))->toBeTrue()
            ->and(DB::table('social_transport_cutover_mappings')->count())->toBe(1)
            ->and(Schema::hasTable('social_transport_cutover_events'))->toBeFalse()
            ->and(Schema::hasIndex(
                'social_account_connections',
                'social_account_connections_id_user_uq',
            ))->toBeFalse();

        expect(fn () => $migration->down())->toThrow(
            LogicException::class,
            'must be explicitly archived',
        )->and(DB::table('social_transport_cutover_mappings')->count())->toBe(1);

        DB::table('social_transport_cutover_mappings')->delete();
        $migration->down();

        expect(Schema::hasTable('social_transport_cutovers'))->toBeFalse()
            ->and(Schema::hasTable('social_transport_cutover_mappings'))->toBeFalse();

        $migration->up();

        expect(Schema::getColumnListing('social_transport_cutovers'))->toContain(
            'user_id',
            'state',
            'active_transport_generation',
            'h2_evidence_hash',
            'canary_contract_hash',
            'mapping_manifest_hash',
            'h3_evidence_hash',
            'lock_version',
        )->and(Schema::getColumnListing('social_transport_cutover_mappings'))->toContain(
            'legacy_connection_id',
            'replacement_connection_id',
            'logical_destination_key',
            'owner_evidence_hash',
            'shadow_evidence_hash',
        )->and(Schema::getColumnListing('social_transport_cutover_events'))->toContain(
            'sequence',
            'from_state',
            'to_state',
            'actor_user_id',
            'evidence_hash',
        );

        $cutoverIndexes = collect(Schema::getIndexes('social_transport_cutovers'))->keyBy('name');
        $mappingIndexes = collect(Schema::getIndexes('social_transport_cutover_mappings'))->keyBy('name');
        $eventIndexes = collect(Schema::getIndexes('social_transport_cutover_events'))->keyBy('name');
        $connectionIndexes = collect(Schema::getIndexes('social_account_connections'))->keyBy('name');

        $expectedIndexes = [
            'social_account_connections' => [
                'social_account_connections_id_user_uq' => [['id', 'user_id'], true],
            ],
            'social_transport_cutovers' => [
                'social_transport_cutovers_tenant_uq' => [['user_id'], true],
                'social_transport_cutovers_id_tenant_uq' => [['id', 'user_id'], true],
                'social_transport_cutovers_state_transport_idx' => [[
                    'state',
                    'active_transport_generation',
                ], false],
                'social_transport_cutovers_drain_rollback_idx' => [[
                    'legacy_drain_status',
                    'rollback_status',
                ], false],
            ],
            'social_transport_cutover_mappings' => [
                'social_cutover_mappings_legacy_uq' => [[
                    'social_transport_cutover_id',
                    'legacy_connection_id',
                ], true],
                'social_cutover_mappings_replacement_uq' => [[
                    'social_transport_cutover_id',
                    'replacement_connection_id',
                ], true],
                'social_cutover_mappings_tenant_destination_uq' => [[
                    'user_id',
                    'logical_destination_key',
                ], true],
                'social_cutover_mappings_tenant_shadow_idx' => [[
                    'user_id',
                    'shadow_validated_at',
                ], false],
            ],
            'social_transport_cutover_events' => [
                'social_cutover_events_sequence_uq' => [[
                    'social_transport_cutover_id',
                    'sequence',
                ], true],
                'social_cutover_events_tenant_created_idx' => [[
                    'user_id',
                    'created_at',
                ], false],
            ],
        ];
        $indexesByTable = [
            'social_account_connections' => $connectionIndexes,
            'social_transport_cutovers' => $cutoverIndexes,
            'social_transport_cutover_mappings' => $mappingIndexes,
            'social_transport_cutover_events' => $eventIndexes,
        ];

        foreach ($expectedIndexes as $table => $indexes) {
            foreach ($indexes as $indexName => [$expectedColumns, $expectedUnique]) {
                $actualIndex = $indexesByTable[$table]->get($indexName);

                expect($actualIndex)->not->toBeNull();
                expect($actualIndex['columns'])->toBe($expectedColumns);
                expect($actualIndex['unique'])->toBe($expectedUnique);
            }
        }

        expect($cutoverIndexes->get('social_transport_cutovers_tenant_uq')['unique'])->toBeTrue()
            ->and($mappingIndexes->get('social_cutover_mappings_legacy_uq')['unique'])->toBeTrue()
            ->and($mappingIndexes->get('social_cutover_mappings_replacement_uq')['unique'])->toBeTrue()
            ->and($mappingIndexes->get('social_cutover_mappings_tenant_destination_uq')['unique'])->toBeTrue()
            ->and($eventIndexes->get('social_cutover_events_sequence_uq')['unique'])->toBeTrue();

        $cutoverForeignKeys = collect(Schema::getForeignKeys('social_transport_cutovers'));
        $mappingForeignKeys = collect(Schema::getForeignKeys('social_transport_cutover_mappings'));
        $eventForeignKeys = collect(Schema::getForeignKeys('social_transport_cutover_events'));
        $controlPlaneForeignKeys = collect([
            ...$cutoverForeignKeys,
            ...$mappingForeignKeys,
            ...$eventForeignKeys,
        ]);
        $migrationSource = File::get($migrationPath);
        $foreignKeyNames = [
            'stc_tenant_fk',
            'stc_h2_actor_fk',
            'stc_h3_actor_fk',
            'stc_last_actor_fk',
            'stcm_cutover_tenant_fk',
            'stcm_legacy_conn_tenant_fk',
            'stcm_replacement_conn_tenant_fk',
            'stcm_owner_fk',
            'stce_cutover_tenant_fk',
            'stce_actor_fk',
        ];
        $indexNames = [
            'social_account_connections_id_user_uq',
            'social_transport_cutovers_tenant_uq',
            'social_transport_cutovers_id_tenant_uq',
            'social_transport_cutovers_state_transport_idx',
            'social_transport_cutovers_drain_rollback_idx',
            'social_cutover_mappings_legacy_uq',
            'social_cutover_mappings_replacement_uq',
            'social_cutover_mappings_tenant_destination_uq',
            'social_cutover_mappings_tenant_shadow_idx',
            'social_cutover_events_sequence_uq',
            'social_cutover_events_tenant_created_idx',
        ];
        $mysqlIdentifierNames = [...$foreignKeyNames, ...$indexNames];

        expect(collect($mysqlIdentifierNames)->every(
            fn (string $identifierName): bool => mb_strlen($identifierName) <= 64
                && str_contains($migrationSource, "'{$identifierName}'"),
        ))->toBeTrue()->and(collect($mysqlIdentifierNames)->duplicates())->toBeEmpty()
            ->and($controlPlaneForeignKeys)->toHaveCount(10)
            ->and(Schema::hasIndex(
                'social_account_connections',
                'social_account_connections_id_user_uq',
            ))->toBeTrue();

        $actualIdentifierNames = collect([
            ...$connectionIndexes->pluck('name'),
            ...$cutoverIndexes->pluck('name'),
            ...$mappingIndexes->pluck('name'),
            ...$eventIndexes->pluck('name'),
            ...$controlPlaneForeignKeys->pluck('name'),
        ])->filter(fn (mixed $name): bool => is_string($name));

        expect($actualIdentifierNames->every(
            fn (string $identifierName): bool => mb_strlen($identifierName) <= 64,
        ))->toBeTrue();

        if (DB::connection()->getDriverName() === 'mysql') {
            expect($controlPlaneForeignKeys->pluck('name')->sort()->values()->all())->toBe(
                collect($foreignKeyNames)->sort()->values()->all(),
            );
        }

        expect($mappingForeignKeys->contains(
            fn (array $foreignKey): bool => $foreignKey['columns'] === [
                'legacy_connection_id',
                'user_id',
            ]
                && $foreignKey['foreign_table'] === 'social_account_connections',
        ))->toBeTrue()->and($mappingForeignKeys->contains(
            fn (array $foreignKey): bool => $foreignKey['columns'] === [
                'replacement_connection_id',
                'user_id',
            ]
                && $foreignKey['foreign_table'] === 'social_account_connections',
        ))->toBeTrue()->and($eventForeignKeys->contains(
            fn (array $foreignKey): bool => $foreignKey['columns'] === [
                'social_transport_cutover_id',
                'user_id',
            ]
                && $foreignKey['foreign_table'] === 'social_transport_cutovers',
        ))->toBeTrue();

        /** @param Collection<int, array<string, mixed>> $foreignKeys */
        $deleteAction = static function (
            Collection $foreignKeys,
            array $columns,
            string $foreignTable,
        ): string {
            $foreignKey = $foreignKeys->first(
                fn (array $candidate): bool => $candidate['columns'] === $columns
                    && $candidate['foreign_table'] === $foreignTable,
            );

            return strtolower((string) ($foreignKey['on_delete'] ?? ''));
        };
        expect($deleteAction($cutoverForeignKeys, ['user_id'], 'users'))
            ->toBeIn(['restrict', 'no action'])
            ->and($deleteAction($cutoverForeignKeys, ['h2_approved_by_user_id'], 'users'))
            ->toBeIn(['restrict', 'no action'])
            ->and($deleteAction($cutoverForeignKeys, ['h3_approved_by_user_id'], 'users'))
            ->toBeIn(['restrict', 'no action'])
            ->and($deleteAction($cutoverForeignKeys, ['last_transition_by_user_id'], 'users'))
            ->toBeIn(['restrict', 'no action'])
            ->and($deleteAction(
                $mappingForeignKeys,
                ['social_transport_cutover_id', 'user_id'],
                'social_transport_cutovers',
            ))->toBeIn(['restrict', 'no action'])
            ->and($deleteAction(
                $mappingForeignKeys,
                ['legacy_connection_id', 'user_id'],
                'social_account_connections',
            ))->toBeIn(['restrict', 'no action'])
            ->and($deleteAction(
                $mappingForeignKeys,
                ['replacement_connection_id', 'user_id'],
                'social_account_connections',
            ))->toBeIn(['restrict', 'no action'])
            ->and($deleteAction($mappingForeignKeys, ['owner_validated_by_user_id'], 'users'))
            ->toBeIn(['restrict', 'no action'])
            ->and($deleteAction(
                $eventForeignKeys,
                ['social_transport_cutover_id', 'user_id'],
                'social_transport_cutovers',
            ))->toBeIn(['restrict', 'no action'])
            ->and($deleteAction($eventForeignKeys, ['actor_user_id'], 'users'))
            ->toBeIn(['restrict', 'no action']);

        $owner = User::factory()->create();
        DB::table('social_transport_cutovers')->insert([
            'user_id' => $owner->id,
            'state' => 'legacy_only',
            'active_transport_generation' => 'direct_v1',
            'pilot_status' => 'not_started',
            'legacy_drain_status' => 'pending',
            'rollback_status' => 'unavailable',
            'last_transition_by_user_id' => $owner->id,
            'last_evidence_hash' => str_repeat('a', 64),
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $cutoverId = (int) DB::table('social_transport_cutovers')
            ->where('user_id', $owner->id)
            ->value('id');
        $foreignOwner = User::factory()->create();

        expect(fn () => DB::table('social_transport_cutover_events')->insert([
            'social_transport_cutover_id' => $cutoverId,
            'user_id' => $foreignOwner->id,
            'sequence' => 1,
            'from_state' => 'legacy_only',
            'to_state' => 'legacy_only',
            'actor_user_id' => $foreignOwner->id,
            'reason' => 'cross_tenant_probe',
            'evidence_hash' => str_repeat('b', 64),
            'created_at' => now(),
        ]))->toThrow(QueryException::class);

        expect(fn () => DB::table('social_transport_cutover_events')->insert([
            'social_transport_cutover_id' => $cutoverId,
            'user_id' => $owner->id,
            'sequence' => 1,
            'from_state' => 'legacy_only',
            'to_state' => 'legacy_only',
            'actor_user_id' => null,
            'reason' => 'missing_actor_probe',
            'evidence_hash' => str_repeat('c', 64),
            'created_at' => now(),
        ]))->toThrow(QueryException::class);

        expect(fn () => $migration->down())
            ->toThrow(LogicException::class, 'must be explicitly archived');

        DB::table('social_transport_cutovers')->delete();
        $migration->down();
        $migration->down();

        expect(Schema::hasTable('social_transport_cutover_events'))->toBeFalse()
            ->and(Schema::hasTable('social_transport_cutover_mappings'))->toBeFalse()
            ->and(Schema::hasTable('social_transport_cutovers'))->toBeFalse()
            ->and(Schema::hasIndex(
                'social_account_connections',
                'social_account_connections_id_user_uq',
            ))->toBeFalse();

        $migration->up();
        expect(Schema::hasTable('social_transport_cutovers'))->toBeTrue();
    } finally {
        Artisan::call('migrate:fresh', [
            '--force' => true,
            '--no-interaction' => true,
        ]);
    }
});
