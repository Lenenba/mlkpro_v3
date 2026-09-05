<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'social_transport_cutovers';

    /** @var list<string> */
    private const COLUMNS = [
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

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $missing = collect(self::COLUMNS)
            ->reject(fn (string $column): bool => Schema::hasColumn(self::TABLE, $column))
            ->flip();

        if ($missing->isNotEmpty()) {
            Schema::table(self::TABLE, function (Blueprint $table) use ($missing): void {
                if ($missing->has('rollback_resume_state')) {
                    $table->string('rollback_resume_state', 32)->nullable();
                }

                if ($missing->has('h2_approval_authority')) {
                    $table->string('h2_approval_authority', 32)->nullable();
                }

                if ($missing->has('h3_approval_authority')) {
                    $table->string('h3_approval_authority', 32)->nullable();
                }

                if ($missing->has('canary_evidence_hash')) {
                    $table->char('canary_evidence_hash', 64)->nullable();
                }

                if ($missing->has('canary_observed_deliveries')) {
                    $table->unsignedInteger('canary_observed_deliveries')->nullable();
                }

                if ($missing->has('canary_observed_unknown')) {
                    $table->unsignedInteger('canary_observed_unknown')->nullable();
                }

                if ($missing->has('canary_observed_rollback_rto_seconds')) {
                    $table->unsignedInteger('canary_observed_rollback_rto_seconds')->nullable();
                }

                if ($missing->has('direct_writer_barrier_at')) {
                    $table->timestamp('direct_writer_barrier_at')->nullable();
                }

                if ($missing->has('legacy_drain_observation_started_at')) {
                    $table->timestamp('legacy_drain_observation_started_at')->nullable();
                }

                if ($missing->has('legacy_drain_evidence_hash')) {
                    $table->char('legacy_drain_evidence_hash', 64)->nullable();
                }
            });
        }

        DB::table(self::TABLE)
            ->where('state', 'rollback_hold')
            ->whereNull('rollback_resume_state')
            ->update(['rollback_resume_state' => 'legacy_only']);
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $existing = collect(self::COLUMNS)
            ->filter(fn (string $column): bool => Schema::hasColumn(self::TABLE, $column))
            ->values();

        if ($existing->isEmpty()) {
            return;
        }

        $hasOperationalEvidence = DB::table(self::TABLE)
            ->where(function ($query) use ($existing): void {
                foreach ($existing as $column) {
                    $query->orWhereNotNull($column);
                }
            })
            ->exists();

        if ($hasOperationalEvidence) {
            throw new LogicException(
                'Pulse operational cutover proofs must be archived before removing their columns.',
            );
        }

        Schema::table(self::TABLE, function (Blueprint $table) use ($existing): void {
            $table->dropColumn($existing->all());
        });
    }
};
