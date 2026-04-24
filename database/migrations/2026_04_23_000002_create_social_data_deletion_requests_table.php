<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('social_data_deletion_requests')) {
            return;
        }

        Schema::create('social_data_deletion_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 40);
            $table->string('confirmation_code', 191)->unique();
            $table->string('provider_user_id', 191)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 30)->default('pending');
            $table->boolean('delete_local_account')->default(false);
            $table->text('failure_reason')->nullable();
            $table->json('summary')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['provider', 'provider_user_id'], 'social_data_deletion_requests_provider_user_idx');
            $table->index(['provider', 'status'], 'social_data_deletion_requests_provider_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_data_deletion_requests');
    }
};
