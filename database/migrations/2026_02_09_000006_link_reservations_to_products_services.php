<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('reservations') || !Schema::hasColumn('reservations', 'service_id')) {
            return;
        }

        if (!Schema::hasTable('reservation_services')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            return;
        }

        DB::table('reservations')
            ->whereNotNull('service_id')
            ->update(['service_id' => null]);

        Schema::table('reservations', function (Blueprint $table) {
            try {
                $table->dropForeign(['service_id']);
            } catch (\Throwable) {
                // Already switched or foreign key name differs across environments.
            }
        });

        Schema::table('reservations', function (Blueprint $table) {
            try {
                $table->foreign('service_id')
                    ->references('id')
                    ->on('products')
                    ->nullOnDelete();
            } catch (\Throwable) {
                // Foreign key already points to products.
            }
        });

        if (Schema::hasTable('reservation_services')) {
            Schema::drop('reservation_services');
        }
    }

    public function down(): void
    {
        // No rollback to deprecated reservation_services table.
    }
};
