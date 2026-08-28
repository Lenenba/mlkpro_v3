<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            ! Schema::hasColumn('reservation_settings', 'account_default_marker')
            || Schema::hasIndex('reservation_settings', 'rs_account_default_unique')
        ) {
            return;
        }

        Schema::table('reservation_settings', function (Blueprint $table): void {
            $table->unique(['account_id', 'account_default_marker'], 'rs_account_default_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasIndex('reservation_settings', 'rs_account_default_unique')) {
            return;
        }

        Schema::table('reservation_settings', function (Blueprint $table): void {
            $table->dropUnique('rs_account_default_unique');
        });
    }
};
