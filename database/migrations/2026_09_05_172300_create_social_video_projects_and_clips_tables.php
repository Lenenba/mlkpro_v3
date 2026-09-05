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
        Schema::create('social_video_projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('source_path');
            $table->string('preview_path')->nullable();
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('status', 24)->default('pending');
            $table->string('error_code')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('social_video_clips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_video_project_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->unsignedInteger('start_ms');
            $table->unsignedInteger('end_ms');
            $table->string('format', 16);
            $table->string('framing', 16);
            $table->unsignedTinyInteger('focal_x')->default(50);
            $table->unsignedTinyInteger('focal_y')->default(50);
            $table->string('status', 24)->default('pending');
            $table->string('path')->nullable();
            $table->string('error_code')->nullable();
            $table->timestamps();
            $table->unique(['social_video_project_id', 'position'], 'social_video_clip_position_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_video_clips');
        Schema::dropIfExists('social_video_projects');
    }
};
