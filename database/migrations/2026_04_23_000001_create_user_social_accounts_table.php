<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_social_accounts')) {
            return;
        }

        Schema::create('user_social_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 40);
            $table->string('provider_user_id', 191);
            $table->string('provider_email', 191)->nullable();
            $table->timestamp('provider_email_verified_at')->nullable();
            $table->string('provider_name', 191)->nullable();
            $table->text('provider_avatar_url')->nullable();
            $table->longText('access_token')->nullable();
            $table->longText('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['provider', 'provider_user_id'],
                'user_social_accounts_provider_provider_user_unique'
            );
            $table->unique(['user_id', 'provider'], 'user_social_accounts_user_provider_unique');
            $table->index(['user_id', 'last_login_at'], 'user_social_accounts_user_login_idx');
            $table->index(['provider', 'provider_email'], 'user_social_accounts_provider_email_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_social_accounts');
    }
};
