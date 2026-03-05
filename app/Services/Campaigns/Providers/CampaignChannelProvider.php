<?php

namespace App\Services\Campaigns\Providers;

use App\Models\CampaignMessage;
use App\Models\CampaignRecipient;

interface CampaignChannelProvider
{
    public function channel(): string;

    public function send(CampaignRecipient $recipient, CampaignMessage $message): array;
}
