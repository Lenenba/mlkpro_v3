<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_scans', function (Blueprint $table) {
            $table->string('plan_file_sha256', 64)->nullable()->after('plan_file_name');
            $table->string('ai_cache_key')->nullable()->after('ai_model');
            $table->boolean('ai_cache_hit')->default(false)->after('ai_cache_key');
            $table->string('ai_cache_source')->nullable()->after('ai_cache_hit');
            $table->json('ai_attempts')->nullable()->after('ai_usage');
            $table->decimal('ai_estimated_cost_usd', 12, 6)->nullable()->after('ai_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('plan_scans', function (Blueprint $table) {
            $table->dropColumn([
                'plan_file_sha256',
                'ai_cache_key',
                'ai_cache_hit',
                'ai_cache_source',
                'ai_attempts',
                'ai_estimated_cost_usd',
            ]);
        });
    }
};
