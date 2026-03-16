<?php

namespace App\Services\Campaigns\Providers;

use App\Models\CampaignProspectProviderConnection;

class LushaProspectProviderAdapter extends AbstractApiKeyProspectProviderAdapter
{
    public function key(): string
    {
        return CampaignProspectProviderConnection::PROVIDER_LUSHA;
    }

    public function label(): string
    {
        return 'Lusha';
    }
}
