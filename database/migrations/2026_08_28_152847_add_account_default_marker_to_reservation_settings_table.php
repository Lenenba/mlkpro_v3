<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('reservation_settings') || Schema::hasColumn('reservation_settings', 'account_default_marker')) {
            return;
        }

        Schema::table('reservation_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('account_default_marker')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('reservation_settings') || ! Schema::hasColumn('reservation_settings', 'account_default_marker')) {
            return;
        }

        Schema::table('reservation_settings', function (Blueprint $table): void {
            $table->dropColumn('account_default_marker');
        });
    }
};
