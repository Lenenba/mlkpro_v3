<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('team_member_attendances')) {
            return;
        }

        Schema::table('team_member_attendances', function (Blueprint $table) {
            if (! Schema::hasColumn('team_member_attendances', 'current_status')) {
                $table->string('current_status', 20)->default('available')->after('clock_out_method');
                $table->index(['account_id', 'team_member_id', 'current_status'], 'attendances_account_member_status_idx');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('team_member_attendances')) {
            return;
        }

        Schema::table('team_member_attendances', function (Blueprint $table) {
            if (Schema::hasColumn('team_member_attendances', 'current_status')) {
                $table->dropIndex('attendances_account_member_status_idx');
                $table->dropColumn('current_status');
            }
        });
    }
};
