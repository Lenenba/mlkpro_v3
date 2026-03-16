<?php

namespace App\Services\Campaigns\Providers;

use App\Models\CampaignProspectProviderConnection;

class ApolloProspectProviderAdapter extends AbstractApiKeyProspectProviderAdapter
{
    public function key(): string
    {
        return CampaignProspectProviderConnection::PROVIDER_APOLLO;
    }

    public function label(): string
    {
        return 'Apollo';
    }
}
