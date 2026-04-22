<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('social_account_connections')) {
            return;
        }

        Schema::table('social_account_connections', function (Blueprint $table): void {
            if (! Schema::hasColumn('social_account_connections', 'oauth_state')) {
                $table->string('oauth_state', 120)->nullable()->after('token_expires_at');
            }

            if (! Schema::hasColumn('social_account_connections', 'oauth_state_expires_at')) {
                $table->timestamp('oauth_state_expires_at')->nullable()->after('oauth_state');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('social_account_connections')) {
            return;
        }

        Schema::table('social_account_connections', function (Blueprint $table): void {
            if (Schema::hasColumn('social_account_connections', 'oauth_state_expires_at')) {
                $table->dropColumn('oauth_state_expires_at');
            }

            if (Schema::hasColumn('social_account_connections', 'oauth_state')) {
                $table->dropColumn('oauth_state');
            }
        });
    }
};
