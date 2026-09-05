<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const CONTROL_PLANE_TABLES = [
        'social_transport_cutovers',
        'social_transport_cutover_mappings',
        'social_transport_cutover_events',
    ];

    public function up(): void
    {
        $missingDependencies = collect([
            'users',
            'social_account_connections',
        ])->reject(fn (string $table): bool => Schema::hasTable($table));

        if ($missingDependencies->isNotEmpty()) {
            throw new LogicException(sprintf(
                'Cannot create the Pulse cutover control plane because required tables are missing: %s.',
                $missingDependencies->implode(', '),
            ));
        }

        $existingControlPlaneTables = collect(self::CONTROL_PLANE_TABLES)
            ->filter(fn (string $table): bool => Schema::hasTable($table))
            ->values();

        if ($existingControlPlaneTables->isNotEmpty()) {
            throw new LogicException(sprintf(
                'Cannot create the Pulse cutover control plane because a previous failed migration left tables behind: %s. After verifying that every listed table is empty, invoke this pending migration down directly; migrate:rollback cannot recover an unrecorded migration.',
                $existingControlPlaneTables->implode(', '),
            ));
        }

        if (! Schema::hasIndex(
            'social_account_connections',
            'social_account_connections_id_user_uq',
        )) {
            Schema::table('social_account_connections', function (Blueprint $table): void {
                $table->unique(
                    ['id', 'user_id'],
                    'social_account_connections_id_user_uq',
                );
            });
        }

        Schema::create('social_transport_cutovers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->string('state', 32)->default('legacy_only');
            $table->string('active_transport_generation', 32)->default('direct_v1');
            $table->string('pilot_status', 24)->default('not_started');
            $table->string('legacy_drain_status', 24)->default('pending');
            $table->string('rollback_status', 24)->default('unavailable');
            $table->char('h2_evidence_hash', 64)->nullable();
            $table->char('canary_contract_hash', 64)->nullable();
            $table->char('mapping_manifest_hash', 64)->nullable();
            $table->unsignedInteger('canary_minimum_deliveries')->nullable();
            $table->unsignedInteger('canary_minimum_hours')->nullable();
            $table->unsignedInteger('canary_maximum_unknown')->nullable();
            $table->unsignedInteger('rollback_rto_seconds')->nullable();
            $table->foreignId('h2_approved_by_user_id')->nullable();
            $table->timestamp('h2_approved_at')->nullable();
            $table->timestamp('cutover_at')->nullable();
            $table->timestamp('canary_started_at')->nullable();
            $table->timestamp('canary_completed_at')->nullable();
            $table->timestamp('legacy_drain_completed_at')->nullable();
            $table->timestamp('rollback_window_ends_at')->nullable();
            $table->foreignId('h3_approved_by_user_id')->nullable();
            $table->char('h3_evidence_hash', 64)->nullable();
            $table->timestamp('h3_go_general_at')->nullable();
            $table->timestamp('h3_direct_removal_authorized_at')->nullable();
            $table->timestamp('direct_retired_at')->nullable();
            $table->foreignId('last_transition_by_user_id')->nullable();
            $table->char('last_evidence_hash', 64);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->unique('user_id', 'social_transport_cutovers_tenant_uq');
            $table->unique(
                ['id', 'user_id'],
                'social_transport_cutovers_id_tenant_uq',
            );
            $table->index(
                ['state', 'active_transport_generation'],
                'social_transport_cutovers_state_transport_idx',
            );
            $table->index(
                ['legacy_drain_status', 'rollback_status'],
                'social_transport_cutovers_drain_rollback_idx',
            );
            $table->foreign('user_id', 'stc_tenant_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
            $table->foreign('h2_approved_by_user_id', 'stc_h2_actor_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
            $table->foreign('h3_approved_by_user_id', 'stc_h3_actor_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
            $table->foreign('last_transition_by_user_id', 'stc_last_actor_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });

        Schema::create('social_transport_cutover_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('social_transport_cutover_id');
            $table->foreignId('user_id');
            $table->foreignId('legacy_connection_id');
            $table->foreignId('replacement_connection_id');
            $table->string('logical_destination_key', 71);
            $table->foreignId('owner_validated_by_user_id');
            $table->timestamp('owner_validated_at');
            $table->char('owner_evidence_hash', 64);
            $table->timestamp('shadow_validated_at')->nullable();
            $table->char('shadow_evidence_hash', 64)->nullable();
            $table->timestamps();

            $table->unique(
                ['social_transport_cutover_id', 'legacy_connection_id'],
                'social_cutover_mappings_legacy_uq',
            );
            $table->unique(
                ['social_transport_cutover_id', 'replacement_connection_id'],
                'social_cutover_mappings_replacement_uq',
            );
            $table->unique(
                ['user_id', 'logical_destination_key'],
                'social_cutover_mappings_tenant_destination_uq',
            );
            $table->index(
                ['user_id', 'shadow_validated_at'],
                'social_cutover_mappings_tenant_shadow_idx',
            );
            $table->foreign(
                ['social_transport_cutover_id', 'user_id'],
                'stcm_cutover_tenant_fk',
            )
                ->references(['id', 'user_id'])
                ->on('social_transport_cutovers')
                ->restrictOnDelete();
            $table->foreign(
                ['legacy_connection_id', 'user_id'],
                'stcm_legacy_conn_tenant_fk',
            )
                ->references(['id', 'user_id'])
                ->on('social_account_connections')
                ->restrictOnDelete();
            $table->foreign(
                ['replacement_connection_id', 'user_id'],
                'stcm_replacement_conn_tenant_fk',
            )
                ->references(['id', 'user_id'])
                ->on('social_account_connections')
                ->restrictOnDelete();
            $table->foreign('owner_validated_by_user_id', 'stcm_owner_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });

        Schema::create('social_transport_cutover_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('social_transport_cutover_id');
            $table->foreignId('user_id');
            $table->unsignedInteger('sequence');
            $table->string('from_state', 32);
            $table->string('to_state', 32);
            $table->foreignId('actor_user_id');
            $table->string('reason', 64);
            $table->char('evidence_hash', 64);
            $table->timestamp('created_at');

            $table->unique(
                ['social_transport_cutover_id', 'sequence'],
                'social_cutover_events_sequence_uq',
            );
            $table->index(
                ['user_id', 'created_at'],
                'social_cutover_events_tenant_created_idx',
            );
            $table->foreign(
                ['social_transport_cutover_id', 'user_id'],
                'stce_cutover_tenant_fk',
            )
                ->references(['id', 'user_id'])
                ->on('social_transport_cutovers')
                ->restrictOnDelete();
            $table->foreign('actor_user_id', 'stce_actor_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $populatedControlPlaneTables = collect(self::CONTROL_PLANE_TABLES)
            ->filter(fn (string $table): bool => Schema::hasTable($table)
                && DB::table($table)->exists())
            ->values();

        if ($populatedControlPlaneTables->isNotEmpty()) {
            throw new LogicException(sprintf(
                'Pulse cutover records must be explicitly archived before removing the control plane: %s.',
                $populatedControlPlaneTables->implode(', '),
            ));
        }

        Schema::dropIfExists('social_transport_cutover_events');
        Schema::dropIfExists('social_transport_cutover_mappings');
        Schema::dropIfExists('social_transport_cutovers');

        if (Schema::hasIndex(
            'social_account_connections',
            'social_account_connections_id_user_uq',
        )) {
            Schema::table('social_account_connections', function (Blueprint $table): void {
                $table->dropUnique('social_account_connections_id_user_uq');
            });
        }
    }
};
