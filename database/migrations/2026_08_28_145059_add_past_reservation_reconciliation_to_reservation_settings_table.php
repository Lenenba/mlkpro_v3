<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('reservation_settings')) {
            return;
        }

        if (! Schema::hasColumn('reservation_settings', 'past_reservation_reconciliation_enabled')) {
            Schema::table('reservation_settings', function (Blueprint $table): void {
                $table->boolean('past_reservation_reconciliation_enabled')->default(false);
            });
        }

        if (! Schema::hasColumn('reservation_settings', 'past_reservation_reconciliation_mode')) {
            Schema::table('reservation_settings', function (Blueprint $table): void {
                $table->string('past_reservation_reconciliation_mode', 30)->default('signal_only');
            });
        }

        if (! Schema::hasColumn('reservation_settings', 'past_reservation_grace_minutes')) {
            Schema::table('reservation_settings', function (Blueprint $table): void {
                $table->unsignedInteger('past_reservation_grace_minutes')->default(120);
            });
        }

        if (! Schema::hasColumn('reservation_settings', 'past_reservation_max_catchup_days')) {
            Schema::table('reservation_settings', function (Blueprint $table): void {
                $table->unsignedInteger('past_reservation_max_catchup_days')->default(7);
            });
        }

        if (! Schema::hasIndex('reservation_settings', 'rs_past_reconciliation_dispatch_idx')) {
            Schema::table('reservation_settings', function (Blueprint $table): void {
                $table->index(
                    ['team_member_id', 'past_reservation_reconciliation_enabled', 'account_id'],
                    'rs_past_reconciliation_dispatch_idx'
                );
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('reservation_settings')) {
            return;
        }

        if (Schema::hasIndex('reservation_settings', 'rs_past_reconciliation_dispatch_idx')) {
            Schema::table('reservation_settings', function (Blueprint $table): void {
                $table->dropIndex('rs_past_reconciliation_dispatch_idx');
            });
        }

        $columns = array_values(array_filter([
            'past_reservation_reconciliation_enabled',
            'past_reservation_reconciliation_mode',
            'past_reservation_grace_minutes',
            'past_reservation_max_catchup_days',
        ], fn (string $column): bool => Schema::hasColumn('reservation_settings', $column)));

        if ($columns !== []) {
            Schema::table('reservation_settings', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
