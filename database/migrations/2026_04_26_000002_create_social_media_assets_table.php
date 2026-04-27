<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('social_media_assets')) {
            return;
        }

        Schema::create('social_media_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('media_type', 32)->default('image');
            $table->string('source', 32)->default('upload');
            $table->string('context', 64)->default('library');
            $table->string('name')->nullable();
            $table->text('url');
            $table->string('disk', 64)->nullable();
            $table->string('path')->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('origin_type')->nullable();
            $table->unsignedBigInteger('origin_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'source', 'created_at'], 'social_media_assets_user_source_idx');
            $table->index(['user_id', 'context', 'created_at'], 'social_media_assets_user_context_idx');
            $table->index(['origin_type', 'origin_id'], 'social_media_assets_origin_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_media_assets');
    }
};
