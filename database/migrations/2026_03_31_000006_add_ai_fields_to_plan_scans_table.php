<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_scans', function (Blueprint $table) {
            $table->string('ai_status')->nullable()->after('confidence_score');
            $table->string('ai_model')->nullable()->after('ai_status');
            $table->json('ai_usage')->nullable()->after('ai_model');
            $table->json('ai_extraction_raw')->nullable()->after('ai_usage');
            $table->json('ai_extraction_normalized')->nullable()->after('ai_extraction_raw');
            $table->boolean('ai_review_required')->default(false)->after('ai_extraction_normalized');
            $table->timestamp('ai_failed_at')->nullable()->after('ai_review_required');
            $table->text('ai_error_message')->nullable()->after('ai_failed_at');
        });
    }

    public function down(): void
    {
        Schema::table('plan_scans', function (Blueprint $table) {
            $table->dropColumn([
                'ai_status',
                'ai_model',
                'ai_usage',
                'ai_extraction_raw',
                'ai_extraction_normalized',
                'ai_review_required',
                'ai_failed_at',
                'ai_error_message',
            ]);
        });
    }
};
