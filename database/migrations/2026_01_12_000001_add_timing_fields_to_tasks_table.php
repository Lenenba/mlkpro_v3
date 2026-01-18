<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('completion_reason')->nullable()->after('completed_at');
            $table->string('delay_reason')->nullable()->after('completion_reason');
            $table->dateTime('delay_started_at')->nullable()->after('delay_reason');
            $table->dateTime('client_notified_at')->nullable()->after('delay_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn([
                'completion_reason',
                'delay_reason',
                'delay_started_at',
                'client_notified_at',
            ]);
        });
    }
};
