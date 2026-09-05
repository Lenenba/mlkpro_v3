<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('social_video_projects', function (Blueprint $table) {
            $table->string('intelligence_status', 24)->default('idle');
            $table->string('intelligence_error_code')->nullable();
            $table->uuid('intelligence_run_id')->nullable();
            $table->json('intelligence')->nullable();
        });
        Schema::table('social_video_clips', function (Blueprint $table) {
            $table->json('publication_ids')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('social_video_projects', function (Blueprint $table) {
            $table->dropColumn(['intelligence_status', 'intelligence_error_code', 'intelligence_run_id', 'intelligence']);
        });
        Schema::table('social_video_clips', function (Blueprint $table) {
            $table->dropColumn('publication_ids');
        });
    }
};
