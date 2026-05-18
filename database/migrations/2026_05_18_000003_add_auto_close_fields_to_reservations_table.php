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

        Schema::table('reservations', function (Blueprint $table) {
            if (! Schema::hasColumn('reservations', 'auto_closed_at')) {
                $table->dateTime('auto_closed_at')->nullable()->after('cancel_reason');
            }

            if (! Schema::hasColumn('reservations', 'auto_closed_reason')) {
                $table->string('auto_closed_reason', 255)->nullable()->after('auto_closed_at');
            }

            $table->index(['account_id', 'status', 'auto_closed_at'], 'reservations_account_status_auto_closed_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('reservations')) {
            return;
        }

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropIndex('reservations_account_status_auto_closed_idx');

            if (Schema::hasColumn('reservations', 'auto_closed_reason')) {
                $table->dropColumn('auto_closed_reason');
            }

            if (Schema::hasColumn('reservations', 'auto_closed_at')) {
                $table->dropColumn('auto_closed_at');
            }
        });
    }
};
