<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dateTime('auto_started_at')->nullable()->after('end_time');
            $table->dateTime('auto_completed_at')->nullable()->after('auto_started_at');
            $table->dateTime('start_alerted_at')->nullable()->after('auto_completed_at');
            $table->dateTime('end_alerted_at')->nullable()->after('start_alerted_at');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn([
                'auto_started_at',
                'auto_completed_at',
                'start_alerted_at',
                'end_alerted_at',
            ]);
        });
    }
};
