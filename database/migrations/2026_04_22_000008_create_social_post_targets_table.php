<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('social_post_targets')) {
            return;
        }

        if (! Schema::hasTable('social_posts') || ! Schema::hasTable('social_account_connections')) {
            return;
        }

        Schema::create('social_post_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('social_post_id')->constrained('social_posts')->cascadeOnDelete();
            $table->foreignId('social_account_connection_id')->nullable()
                ->constrained('social_account_connections')
                ->nullOnDelete();
            $table->string('status', 30)->default('pending');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['social_post_id', 'social_account_connection_id'],
                'social_post_targets_post_connection_unique'
            );
            $table->index(['social_post_id', 'status'], 'social_post_targets_post_status_idx');
            $table->index(
                ['social_account_connection_id', 'status'],
                'social_post_targets_connection_status_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_post_targets');
    }
};
