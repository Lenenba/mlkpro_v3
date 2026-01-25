<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'sale_id')) {
                $table->foreignId('sale_id')->nullable()->after('invoice_id')
                    ->constrained('sales')->nullOnDelete();
                $table->index(['sale_id', 'status']);
            }
        });

        $this->setNullable('payments', 'invoice_id');
        $this->setNullable('payments', 'customer_id');
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'sale_id')) {
                $table->dropForeign(['sale_id']);
                $table->dropIndex(['sale_id', 'status']);
                $table->dropColumn('sale_id');
            }
        });

        $this->setNotNullable('payments', 'invoice_id');
        $this->setNotNullable('payments', 'customer_id');
    }

    private function setNullable(string $table, string $column): void
    {
        if (!Schema::hasColumn($table, $column)) {
            return;
        }

        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE {$table} MODIFY {$column} BIGINT UNSIGNED NULL");
        } elseif ($driver === 'pgsql') {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} DROP NOT NULL");
        }
    }

    private function setNotNullable(string $table, string $column): void
    {
        if (!Schema::hasColumn($table, $column)) {
            return;
        }

        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE {$table} MODIFY {$column} BIGINT UNSIGNED NOT NULL");
        } elseif ($driver === 'pgsql') {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} SET NOT NULL");
        }
    }
};
