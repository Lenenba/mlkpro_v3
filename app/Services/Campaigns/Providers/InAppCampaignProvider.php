<?php

namespace App\Services\Campaigns\Providers;

use App\Models\Campaign;
use App\Models\CampaignMessage;
use App\Models\CampaignRecipient;
use App\Models\User;
use App\Notifications\CampaignInAppNotification;
use App\Support\NotificationDispatcher;

class InAppCampaignProvider implements CampaignChannelProvider
{
    public function channel(): string
    {
        return Campaign::CHANNEL_IN_APP;
    }

    public function send(CampaignRecipient $recipient, CampaignMessage $message): array
    {
        $customer = $recipient->relationLoaded('customer')
            ? $recipient->customer
            : $recipient->customer()->first();

        $notifiable = $customer?->portalUser;
        if (!$notifiable && !empty($recipient->destination) && is_numeric($recipient->destination)) {
            $notifiable = User::query()->find((int) $recipient->destination);
        }

        if (!$notifiable) {
            return [
                'ok' => false,
                'provider' => 'in_app',
                'reason' => 'missing_notifiable_user',
            ];
        }

        $queued = NotificationDispatcher::send(
            $notifiable,
            new CampaignInAppNotification([
                'title' => $message->title_rendered ?: ($recipient->campaign?->name ?: 'Campaign'),
                'message' => (string) $message->body_rendered,
                'action_url' => $message->tracked_cta_url ?: $message->cta_url,
                'campaign_id' => $recipient->campaign_id,
                'campaign_run_id' => $recipient->campaign_run_id,
                'campaign_recipient_id' => $recipient->id,
            ]),
            [
                'campaign_recipient_id' => $recipient->id,
            ]
        );

        if (!$queued) {
            return [
                'ok' => false,
                'provider' => 'in_app',
                'reason' => 'notification_dispatch_failed',
            ];
        }

        return [
            'ok' => true,
            'provider' => 'in_app',
            'provider_message_id' => null,
        ];
    }
}
