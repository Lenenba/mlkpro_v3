<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_scans', function (Blueprint $table) {
            $table->unsignedInteger('ai_retry_count')->default(0)->after('ai_review_required');
            $table->timestamp('ai_last_requested_at')->nullable()->after('ai_retry_count');
            $table->timestamp('ai_escalated_at')->nullable()->after('ai_last_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('plan_scans', function (Blueprint $table) {
            $table->dropColumn([
                'ai_retry_count',
                'ai_last_requested_at',
                'ai_escalated_at',
            ]);
        });
    }
};
