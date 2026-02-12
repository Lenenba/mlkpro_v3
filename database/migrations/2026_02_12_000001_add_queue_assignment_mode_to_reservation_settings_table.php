<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservation_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('reservation_settings', 'queue_assignment_mode')) {
                $table->string('queue_assignment_mode', 30)
                    ->default('per_staff')
                    ->after('queue_mode_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('reservation_settings', function (Blueprint $table) {
            if (Schema::hasColumn('reservation_settings', 'queue_assignment_mode')) {
                $table->dropColumn('queue_assignment_mode');
            }
        });
    }
};
