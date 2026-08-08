<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('customers', 'birth_date')) {
            Schema::table('customers', function (Blueprint $table): void {
                $table->date('birth_date')->nullable()->after('last_name');
            });
        }

        if (! Schema::hasIndex('reservations', 'reservations_account_client_status_start_idx')) {
            Schema::table('reservations', function (Blueprint $table): void {
                $table->index(
                    ['account_id', 'client_id', 'status', 'starts_at'],
                    'reservations_account_client_status_start_idx'
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('reservations', 'reservations_account_client_status_start_idx')) {
            Schema::table('reservations', function (Blueprint $table): void {
                $table->dropIndex('reservations_account_client_status_start_idx');
            });
        }

        if (Schema::hasColumn('customers', 'birth_date')) {
            Schema::table('customers', function (Blueprint $table): void {
                $table->dropColumn('birth_date');
            });
        }
    }
};
