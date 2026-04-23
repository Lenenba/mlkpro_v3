<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('social_approval_requests')) {
            return;
        }

        Schema::create('social_approval_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('social_post_id')->constrained('social_posts')->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 30)->default('pending');
            $table->text('note')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['social_post_id', 'status'], 'social_approvals_post_status_idx');
            $table->index(['status', 'requested_at'], 'social_approvals_status_requested_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_approval_requests');
    }
};
