<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('social_account_connections')) {
            return;
        }

        Schema::create('social_account_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 40);
            $table->string('label', 120);
            $table->string('display_name', 191)->nullable();
            $table->string('account_handle', 191)->nullable();
            $table->string('external_account_id', 191)->nullable();
            $table->string('auth_method', 30)->default('oauth');
            $table->longText('credentials')->nullable();
            $table->json('permissions')->nullable();
            $table->string('status', 30)->default('draft');
            $table->boolean('is_active')->default(true);
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['user_id', 'platform', 'external_account_id'],
                'social_account_connections_owner_platform_external_unique'
            );
            $table->index(['user_id', 'platform'], 'social_account_connections_user_platform_idx');
            $table->index(['user_id', 'status'], 'social_account_connections_user_status_idx');
            $table->index(['user_id', 'is_active'], 'social_account_connections_user_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_account_connections');
    }
};
