<?php

namespace App\Services\Campaigns\Providers;

use App\Models\CampaignMessage;
use App\Models\CampaignRecipient;
use Illuminate\Support\Collection;

class CampaignProviderManager
{
    /**
     * @var array<string, CampaignChannelProvider>
     */
    private array $providers;

    public function __construct(
        EmailCampaignProvider $emailProvider,
        SmsCampaignProvider $smsProvider,
        InAppCampaignProvider $inAppProvider,
    ) {
        $collection = Collection::make([
            $emailProvider,
            $smsProvider,
            $inAppProvider,
        ]);

        $this->providers = $collection
            ->mapWithKeys(fn (CampaignChannelProvider $provider) => [strtoupper($provider->channel()) => $provider])
            ->all();
    }

    public function send(CampaignRecipient $recipient, CampaignMessage $message): array
    {
        $channel = strtoupper((string) $recipient->channel);
        $provider = $this->providers[$channel] ?? null;
        if (!$provider) {
            return [
                'ok' => false,
                'reason' => 'unsupported_channel',
                'provider' => null,
            ];
        }

        return $provider->send($recipient, $message);
    }
}
