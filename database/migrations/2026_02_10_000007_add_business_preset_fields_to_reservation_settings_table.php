<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('reservation_settings')) {
            return;
        }

        Schema::table('reservation_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('reservation_settings', 'business_preset')) {
                $table->string('business_preset', 40)->nullable()->after('team_member_id');
            }
            if (!Schema::hasColumn('reservation_settings', 'late_release_minutes')) {
                $table->unsignedInteger('late_release_minutes')->nullable()->after('allow_client_reschedule');
            }
            if (!Schema::hasColumn('reservation_settings', 'waitlist_enabled')) {
                $table->boolean('waitlist_enabled')->default(false)->after('late_release_minutes');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('reservation_settings')) {
            return;
        }

        Schema::table('reservation_settings', function (Blueprint $table) {
            if (Schema::hasColumn('reservation_settings', 'waitlist_enabled')) {
                $table->dropColumn('waitlist_enabled');
            }
            if (Schema::hasColumn('reservation_settings', 'late_release_minutes')) {
                $table->dropColumn('late_release_minutes');
            }
            if (Schema::hasColumn('reservation_settings', 'business_preset')) {
                $table->dropColumn('business_preset');
            }
        });
    }
};

