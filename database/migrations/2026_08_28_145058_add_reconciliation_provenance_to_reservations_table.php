<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('reservations')) {
            return;
        }

        if (! Schema::hasColumn('reservations', 'status_version')) {
            Schema::table('reservations', function (Blueprint $table): void {
                $table->unsignedBigInteger('status_version')->default(0);
            });
        }

        if (! Schema::hasColumn('reservations', 'schedule_version')) {
            Schema::table('reservations', function (Blueprint $table): void {
                $table->unsignedBigInteger('schedule_version')->default(0);
            });
        }

        if (! Schema::hasColumn('reservations', 'mutation_version')) {
            Schema::table('reservations', function (Blueprint $table): void {
                $table->unsignedBigInteger('mutation_version')->default(0);
            });
        }

        if (! Schema::hasColumn('reservations', 'status_changed_at')) {
            Schema::table('reservations', function (Blueprint $table): void {
                $table->dateTime('status_changed_at')->nullable();
            });
        }

        if (! Schema::hasColumn('reservations', 'status_changed_by_user_id')) {
            Schema::table('reservations', function (Blueprint $table): void {
                $table->foreignId('status_changed_by_user_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('reservations', 'status_change_source')) {
            Schema::table('reservations', function (Blueprint $table): void {
                $table->string('status_change_source', 40)->default('legacy_unknown');
            });
        }

        if (! Schema::hasColumn('reservations', 'outcome_review_required_at')) {
            Schema::table('reservations', function (Blueprint $table): void {
                $table->dateTime('outcome_review_required_at')->nullable();
            });
        }

        if (! Schema::hasColumn('reservations', 'outcome_review_reason_code')) {
            Schema::table('reservations', function (Blueprint $table): void {
                $table->string('outcome_review_reason_code', 80)->nullable();
            });
        }

        if (! Schema::hasIndex('reservations', 'reservations_reconciliation_candidates_idx')) {
            Schema::table('reservations', function (Blueprint $table): void {
                $table->index(
                    ['account_id', 'status', 'outcome_review_required_at', 'ends_at', 'id'],
                    'reservations_reconciliation_candidates_idx'
                );
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('reservations')) {
            return;
        }

        if (Schema::hasIndex('reservations', 'reservations_reconciliation_candidates_idx')) {
            Schema::table('reservations', function (Blueprint $table): void {
                $table->dropIndex('reservations_reconciliation_candidates_idx');
            });
        }

        if (Schema::hasColumn('reservations', 'status_changed_by_user_id')) {
            Schema::table('reservations', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('status_changed_by_user_id');
            });
        }

        $columns = array_values(array_filter([
            'status_version',
            'schedule_version',
            'mutation_version',
            'status_changed_at',
            'status_change_source',
            'outcome_review_required_at',
            'outcome_review_reason_code',
        ], fn (string $column): bool => Schema::hasColumn('reservations', $column)));

        if ($columns !== []) {
            Schema::table('reservations', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
