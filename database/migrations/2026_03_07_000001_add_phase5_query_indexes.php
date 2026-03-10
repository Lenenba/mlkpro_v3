<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->index(
                ['user_id', 'customer_id'],
                'payments_user_customer_idx'
            );
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->index(
                ['account_id', 'service_id', 'starts_at'],
                'reservations_account_service_start_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_user_customer_idx');
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropIndex('reservations_account_service_start_idx');
        });
    }
};
