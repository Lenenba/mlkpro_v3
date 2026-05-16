<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('reservation_settings')) {
            return;
        }

        Schema::table('reservation_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('reservation_settings', 'kiosk_image_path')) {
                $table->string('kiosk_image_path')->nullable()->after('queue_no_show_on_grace_expiry');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('reservation_settings')) {
            return;
        }

        Schema::table('reservation_settings', function (Blueprint $table) {
            if (Schema::hasColumn('reservation_settings', 'kiosk_image_path')) {
                $table->dropColumn('kiosk_image_path');
            }
        });
    }
};
