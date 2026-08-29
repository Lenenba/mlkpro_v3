<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'sdo_active_ambiguity_idx';

    private const TABLE = 'social_delivery_outbox';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $columns = [
            'reconciliation_resolved_at' => ! Schema::hasColumn(self::TABLE, 'reconciliation_resolved_at'),
            'reconciliation_observed_at' => ! Schema::hasColumn(self::TABLE, 'reconciliation_observed_at'),
            'reconciliation_resolution' => ! Schema::hasColumn(self::TABLE, 'reconciliation_resolution'),
            'reconciliation_resolution_source' => ! Schema::hasColumn(self::TABLE, 'reconciliation_resolution_source'),
        ];

        if (in_array(true, $columns, true)) {
            Schema::table(self::TABLE, function (Blueprint $table) use ($columns): void {
                if ($columns['reconciliation_resolved_at']) {
                    $table->timestamp('reconciliation_resolved_at')->nullable();
                }

                if ($columns['reconciliation_observed_at']) {
                    $table->timestamp('reconciliation_observed_at')->nullable();
                }

                if ($columns['reconciliation_resolution']) {
                    $table->string('reconciliation_resolution', 16)->nullable();
                }

                if ($columns['reconciliation_resolution_source']) {
                    $table->string('reconciliation_resolution_source', 32)->nullable();
                }
            });
        }

        if (Schema::hasColumn(self::TABLE, 'status')
            && Schema::hasColumn(self::TABLE, 'reconciliation_resolved_at')
            && ! Schema::hasIndex(self::TABLE, self::INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(
                    ['status', 'reconciliation_resolved_at'],
                    self::INDEX,
                );
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (Schema::hasIndex(self::TABLE, self::INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropIndex(self::INDEX);
            });
        }

        $columns = collect([
            'reconciliation_resolved_at',
            'reconciliation_observed_at',
            'reconciliation_resolution',
            'reconciliation_resolution_source',
        ])->filter(fn (string $column): bool => Schema::hasColumn(self::TABLE, $column));

        if ($columns->isNotEmpty()) {
            Schema::table(self::TABLE, function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns->all());
            });
        }
    }
};
