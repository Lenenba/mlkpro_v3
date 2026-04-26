<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('social_automation_runs')) {
            return;
        }

        Schema::create('social_automation_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('social_automation_rule_id')->constrained('social_automation_rules')->cascadeOnDelete();
            $table->foreignId('social_post_id')->nullable()->constrained('social_posts')->nullOnDelete();
            $table->string('status', 30);
            $table->string('outcome_code', 80)->nullable();
            $table->text('message')->nullable();
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'started_at'], 'social_automation_runs_user_started_idx');
            $table->index(['social_automation_rule_id', 'started_at'], 'social_automation_runs_rule_started_idx');
            $table->index(['status', 'started_at'], 'social_automation_runs_status_started_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_automation_runs');
    }
};
