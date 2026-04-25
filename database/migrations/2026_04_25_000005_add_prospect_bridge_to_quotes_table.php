<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            if (! Schema::hasColumn('quotes', 'prospect_id')) {
                $table->foreignId('prospect_id')
                    ->nullable()
                    ->after('request_id')
                    ->constrained('requests')
                    ->nullOnDelete();

                $table->index(['user_id', 'prospect_id'], 'quotes_user_prospect_id_index');
            }
        });

        DB::table('quotes')
            ->whereNull('prospect_id')
            ->whereNotNull('request_id')
            ->update([
                'prospect_id' => DB::raw('request_id'),
            ]);

        $this->setNullable('quotes', 'customer_id');
    }

    public function down(): void
    {
        $this->setNotNullable('quotes', 'customer_id');

        Schema::table('quotes', function (Blueprint $table) {
            if (Schema::hasColumn('quotes', 'prospect_id')) {
                $table->dropIndex('quotes_user_prospect_id_index');
                $table->dropConstrainedForeignId('prospect_id');
            }
        });
    }

    private function setNullable(string $table, string $column): void
    {
        if (! Schema::hasColumn($table, $column)) {
            return;
        }

        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE {$table} MODIFY {$column} BIGINT UNSIGNED NULL");
        } elseif ($driver === 'pgsql') {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} DROP NOT NULL");
        } elseif ($driver === 'sqlite') {
            Schema::table($table, function (Blueprint $table) use ($column) {
                $table->unsignedBigInteger($column)->nullable()->change();
            });
        }
    }

    private function setNotNullable(string $table, string $column): void
    {
        if (! Schema::hasColumn($table, $column)) {
            return;
        }

        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE {$table} MODIFY {$column} BIGINT UNSIGNED NOT NULL");
        } elseif ($driver === 'pgsql') {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} SET NOT NULL");
        } elseif ($driver === 'sqlite') {
            Schema::table($table, function (Blueprint $table) use ($column) {
                $table->unsignedBigInteger($column)->nullable(false)->change();
            });
        }
    }
};
