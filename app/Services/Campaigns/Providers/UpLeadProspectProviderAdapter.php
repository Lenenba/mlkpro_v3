<?php

namespace App\Services\Campaigns\Providers;

use App\Models\CampaignProspectProviderConnection;

class UpLeadProspectProviderAdapter extends AbstractApiKeyProspectProviderAdapter
{
    public function key(): string
    {
        return CampaignProspectProviderConnection::PROVIDER_UPLEAD;
    }

    public function label(): string
    {
        return 'UpLead';
    }
}
