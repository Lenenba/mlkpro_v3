<?php

use App\Models\SocialAccountConnection;
use App\Models\SocialTransportCutover;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

it('keeps operational cutover proofs additive idempotent reversible and audit protected', function () {
    $migrationPath = database_path(
        'migrations/2026_08_29_061232_add_operational_proofs_to_social_transport_cutovers_table.php',
    );
    $columns = [
        'rollback_resume_state',
        'h2_approval_authority',
        'h3_approval_authority',
        'canary_evidence_hash',
        'canary_observed_deliveries',
        'canary_observed_unknown',
        'canary_observed_rollback_rto_seconds',
        'direct_writer_barrier_at',
        'legacy_drain_observation_started_at',
        'legacy_drain_evidence_hash',
    ];

    try {
        expect(Artisan::call('migrate:fresh', [
            '--force' => true,
            '--no-interaction' => true,
        ]))->toBe(0);

        /** @var Migration $migration */
        $migration = require $migrationPath;
        $migration->up();
        $migration->up();

        expect(Schema::hasColumns('social_transport_cutovers', $columns))->toBeTrue();

        $owner = User::factory()->create();
        $cutoverId = DB::table('social_transport_cutovers')->insertGetId([
            'user_id' => $owner->id,
            'state' => SocialTransportCutover::STATE_LEGACY_ONLY,
            'active_transport_generation' => SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1,
            'pilot_status' => SocialTransportCutover::PILOT_NOT_STARTED,
            'legacy_drain_status' => SocialTransportCutover::DRAIN_PENDING,
            'rollback_status' => SocialTransportCutover::ROLLBACK_UNAVAILABLE,
            'last_transition_by_user_id' => $owner->id,
            'last_evidence_hash' => hash('sha256', 'operational proof migration'),
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('social_transport_cutovers')->where('id', $cutoverId)->update([
            'rollback_resume_state' => SocialTransportCutover::STATE_LEGACY_ONLY,
        ]);

        expect(fn () => $migration->down())
            ->toThrow(LogicException::class, 'must be archived');

        DB::table('social_transport_cutovers')->where('id', $cutoverId)->update([
            'rollback_resume_state' => null,
        ]);
        $migration->down();
        $migration->down();

        expect(Schema::hasTable('social_transport_cutovers'))->toBeTrue()
            ->and(Schema::hasColumn('social_transport_cutovers', 'rollback_resume_state'))
            ->toBeFalse()
            ->and(DB::table('social_transport_cutovers')->where('id', $cutoverId)->exists())
            ->toBeTrue();

        $migration->up();
        $migration->up();

        expect(Schema::hasColumns('social_transport_cutovers', $columns))->toBeTrue();

        $migration->down();
        DB::table('social_transport_cutovers')->where('id', $cutoverId)->update([
            'state' => SocialTransportCutover::STATE_ROLLBACK_HOLD,
            'rollback_status' => SocialTransportCutover::ROLLBACK_REQUESTED,
        ]);
        $migration->up();

        expect(DB::table('social_transport_cutovers')
            ->where('id', $cutoverId)
            ->value('rollback_resume_state'))->toBe(SocialTransportCutover::STATE_LEGACY_ONLY);

        DB::table('social_transport_cutovers')->where('id', $cutoverId)->update([
            'state' => SocialTransportCutover::STATE_LEGACY_ONLY,
            'rollback_status' => SocialTransportCutover::ROLLBACK_UNAVAILABLE,
            'rollback_resume_state' => null,
        ]);
    } finally {
        Artisan::call('migrate:fresh', [
            '--force' => true,
            '--no-interaction' => true,
        ]);
    }
});
