<?php

namespace App\Services\Campaigns\Providers;

use App\Models\Campaign;
use App\Models\CampaignMessage;
use App\Models\CampaignRecipient;
use Illuminate\Support\Facades\Mail;

class EmailCampaignProvider implements CampaignChannelProvider
{
    public function channel(): string
    {
        return Campaign::CHANNEL_EMAIL;
    }

    public function send(CampaignRecipient $recipient, CampaignMessage $message): array
    {
        $to = trim((string) $recipient->destination);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return [
                'ok' => false,
                'provider' => 'mail',
                'reason' => 'invalid_email',
            ];
        }

        $subject = trim((string) $message->subject_rendered);
        if ($subject === '') {
            $subject = trim((string) $recipient->campaign?->name) ?: 'Campaign update';
        }

        try {
            Mail::html((string) $message->body_rendered, function ($mail) use ($to, $subject): void {
                $mail->to($to)->subject($subject);
            });
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'provider' => 'mail',
                'reason' => 'mail_exception',
                'error' => $exception->getMessage(),
            ];
        }

        return [
            'ok' => true,
            'provider' => 'mail',
            'provider_message_id' => null,
        ];
    }
}
