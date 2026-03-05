<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('marketing_settings')) {
            return;
        }

        Schema::table('marketing_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('marketing_settings', 'vip')) {
                $table->json('vip')->nullable()->after('offers');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('marketing_settings')) {
            return;
        }

        Schema::table('marketing_settings', function (Blueprint $table) {
            if (Schema::hasColumn('marketing_settings', 'vip')) {
                $table->dropColumn('vip');
            }
        });
    }
};
