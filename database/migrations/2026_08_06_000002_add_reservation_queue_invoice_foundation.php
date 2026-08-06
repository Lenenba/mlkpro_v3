<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            if (! Schema::hasColumn('invoices', 'reservation_queue_item_id')) {
                $table->foreignId('reservation_queue_item_id')
                    ->nullable()
                    ->after('work_id')
                    ->constrained('reservation_queue_items')
                    ->nullOnDelete()
                    ->unique();
            }

            if (! Schema::hasColumn('invoices', 'source')) {
                $table->string('source', 50)->nullable()->after('reservation_queue_item_id');
            }

            if (! Schema::hasColumn('invoices', 'customer_snapshot')) {
                $table->json('customer_snapshot')->nullable()->after('source');
            }

            if (! Schema::hasColumn('invoices', 'receipt_delivery')) {
                $table->string('receipt_delivery', 20)->nullable()->after('customer_snapshot');
            }

            if (! Schema::hasColumn('invoices', 'receipt_delivered_at')) {
                $table->timestamp('receipt_delivered_at')->nullable()->after('receipt_delivery');
            }
        });

        $this->setNullable('invoices', 'customer_id');
        $this->setNullable('invoices', 'work_id');
    }

    public function down(): void
    {
        $this->ensureRequiredLinksCanBeRestored();

        Schema::table('invoices', function (Blueprint $table): void {
            if (Schema::hasColumn('invoices', 'reservation_queue_item_id')) {
                $table->dropUnique(['reservation_queue_item_id']);
                $table->dropConstrainedForeignId('reservation_queue_item_id');
            }

            $columns = array_values(array_filter([
                Schema::hasColumn('invoices', 'source') ? 'source' : null,
                Schema::hasColumn('invoices', 'customer_snapshot') ? 'customer_snapshot' : null,
                Schema::hasColumn('invoices', 'receipt_delivery') ? 'receipt_delivery' : null,
                Schema::hasColumn('invoices', 'receipt_delivered_at') ? 'receipt_delivered_at' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        $this->setNotNullable('invoices', 'customer_id');
        $this->setNotNullable('invoices', 'work_id');
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
            Schema::table($table, function (Blueprint $table) use ($column): void {
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
            Schema::table($table, function (Blueprint $table) use ($column): void {
                $table->unsignedBigInteger($column)->nullable(false)->change();
            });
        }
    }

    private function ensureRequiredLinksCanBeRestored(): void
    {
        $hasOptionalLinks = DB::table('invoices')
            ->whereNull('customer_id')
            ->orWhereNull('work_id')
            ->exists();

        if ($hasOptionalLinks) {
            throw new \RuntimeException(
                'Cannot restore required invoice customer/work links while invoices without these links exist.'
            );
        }
    }
};
