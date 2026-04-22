<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('social_posts')) {
            return;
        }

        Schema::create('social_posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_type', 80)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->json('content_payload')->nullable();
            $table->json('media_payload')->nullable();
            $table->text('link_url')->nullable();
            $table->string('status', 30)->default('draft');
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status'], 'social_posts_user_status_idx');
            $table->index(['user_id', 'scheduled_for'], 'social_posts_user_scheduled_idx');
            $table->index(['user_id', 'created_at'], 'social_posts_user_created_idx');
            $table->index(['user_id', 'source_type', 'source_id'], 'social_posts_user_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_posts');
    }
};
