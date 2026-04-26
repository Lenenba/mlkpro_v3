<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('social_automation_rules')) {
            return;
        }

        Schema::create('social_automation_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('frequency_type', 40)->default('daily');
            $table->unsignedInteger('frequency_interval')->default(1);
            $table->string('scheduled_time', 5)->nullable();
            $table->string('timezone', 80)->nullable();
            $table->string('approval_mode', 30)->default('required');
            $table->string('language', 10)->nullable();
            $table->json('content_sources')->nullable();
            $table->json('target_connection_ids')->nullable();
            $table->unsignedInteger('max_posts_per_day')->default(1);
            $table->unsignedInteger('min_hours_between_similar_posts')->default(24);
            $table->timestamp('last_generated_at')->nullable();
            $table->timestamp('next_generation_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_active', 'next_generation_at'], 'social_automation_rules_due_idx');
            $table->index(['user_id', 'frequency_type'], 'social_automation_rules_frequency_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_automation_rules');
    }
};
