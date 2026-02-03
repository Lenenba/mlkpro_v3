<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_member_shifts', function (Blueprint $table) {
            $table->timestamp('reminder_sent_at')->nullable()->after('break_minutes');
            $table->timestamp('late_alerted_at')->nullable()->after('reminder_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('team_member_shifts', function (Blueprint $table) {
            $table->dropColumn(['reminder_sent_at', 'late_alerted_at']);
        });
    }
};
