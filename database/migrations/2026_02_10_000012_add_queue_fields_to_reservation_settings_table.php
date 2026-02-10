<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservation_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('reservation_settings', 'queue_mode_enabled')) {
                $table->boolean('queue_mode_enabled')->default(false)->after('waitlist_enabled');
            }
            if (!Schema::hasColumn('reservation_settings', 'queue_dispatch_mode')) {
                $table->string('queue_dispatch_mode', 40)->default('fifo_with_appointment_priority')->after('queue_mode_enabled');
            }
            if (!Schema::hasColumn('reservation_settings', 'queue_grace_minutes')) {
                $table->unsignedInteger('queue_grace_minutes')->default(5)->after('queue_dispatch_mode');
            }
            if (!Schema::hasColumn('reservation_settings', 'queue_pre_call_threshold')) {
                $table->unsignedInteger('queue_pre_call_threshold')->default(2)->after('queue_grace_minutes');
            }
            if (!Schema::hasColumn('reservation_settings', 'queue_no_show_on_grace_expiry')) {
                $table->boolean('queue_no_show_on_grace_expiry')->default(false)->after('queue_pre_call_threshold');
            }
        });
    }

    public function down(): void
    {
        Schema::table('reservation_settings', function (Blueprint $table) {
            foreach ([
                'queue_no_show_on_grace_expiry',
                'queue_pre_call_threshold',
                'queue_grace_minutes',
                'queue_dispatch_mode',
                'queue_mode_enabled',
            ] as $column) {
                if (Schema::hasColumn('reservation_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

