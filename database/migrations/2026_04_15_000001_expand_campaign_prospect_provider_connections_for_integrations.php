<?php

use App\Models\CampaignProspectProviderConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('campaign_prospect_provider_connections')) {
            return;
        }

        Schema::table('campaign_prospect_provider_connections', function (Blueprint $table): void {
            $table->string('auth_method', 30)->nullable()->after('label');
            $table->timestamp('connected_at')->nullable()->after('last_validated_at');
            $table->timestamp('last_refreshed_at')->nullable()->after('connected_at');
            $table->timestamp('token_expires_at')->nullable()->after('last_refreshed_at');
            $table->string('oauth_state', 120)->nullable()->after('last_error');
            $table->timestamp('oauth_state_expires_at')->nullable()->after('oauth_state');
            $table->string('external_account_id', 191)->nullable()->after('oauth_state_expires_at');
            $table->string('external_account_label', 191)->nullable()->after('external_account_id');

            $table->index('oauth_state', 'campaign_provider_connections_oauth_state_idx');
        });

        DB::table('campaign_prospect_provider_connections')
            ->whereNull('auth_method')
            ->update([
                'auth_method' => CampaignProspectProviderConnection::AUTH_METHOD_API_KEY,
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('campaign_prospect_provider_connections')) {
            return;
        }

        Schema::table('campaign_prospect_provider_connections', function (Blueprint $table): void {
            $table->dropIndex('campaign_provider_connections_oauth_state_idx');
            $table->dropColumn([
                'auth_method',
                'connected_at',
                'last_refreshed_at',
                'token_expires_at',
                'oauth_state',
                'oauth_state_expires_at',
                'external_account_id',
                'external_account_label',
            ]);
        });
    }
};
