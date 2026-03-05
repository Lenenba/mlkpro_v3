<?php

namespace App\Services\Campaigns\Providers;

use App\Models\Campaign;
use App\Models\CampaignMessage;
use App\Models\CampaignRecipient;
use App\Services\SmsNotificationService;

class SmsCampaignProvider implements CampaignChannelProvider
{
    public function __construct(
        private readonly SmsNotificationService $smsService,
    ) {
    }

    public function channel(): string
    {
        return Campaign::CHANNEL_SMS;
    }

    public function send(CampaignRecipient $recipient, CampaignMessage $message): array
    {
        $destination = (string) $recipient->destination;
        $body = trim((string) $message->body_rendered);
        if ($body === '') {
            return [
                'ok' => false,
                'provider' => 'twilio',
                'reason' => 'empty_body',
            ];
        }

        $result = $this->smsService->sendWithResult($destination, $body);
        if (!($result['ok'] ?? false)) {
            return [
                'ok' => false,
                'provider' => 'twilio',
                'reason' => (string) ($result['reason'] ?? 'sms_error'),
                'status' => $result['status'] ?? null,
                'error' => (string) ($result['message'] ?? $result['error'] ?? ''),
            ];
        }

        return [
            'ok' => true,
            'provider' => 'twilio',
            'provider_message_id' => $result['sid'] ?? null,
        ];
    }
}
