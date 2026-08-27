<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('social_account_connections')
            || Schema::hasColumn('social_account_connections', 'oauth_code_verifier')) {
            return;
        }

        Schema::table('social_account_connections', function (Blueprint $table): void {
            $table->text('oauth_code_verifier')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('social_account_connections')
            || ! Schema::hasColumn('social_account_connections', 'oauth_code_verifier')) {
            return;
        }

        Schema::table('social_account_connections', function (Blueprint $table): void {
            $table->dropColumn('oauth_code_verifier');
        });
    }
};
