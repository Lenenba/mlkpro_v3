<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\CampaignMessage;
use App\Models\CampaignRecipient;
use App\Services\Campaigns\CampaignRunProgressService;
use App\Services\Campaigns\CampaignTrackingService;
use App\Services\Campaigns\Providers\CampaignProviderManager;
use App\Services\Campaigns\TemplateRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendCampaignRecipientJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public array $backoff = [30, 120, 300, 600];

    public function __construct(
        public int $campaignRecipientId
    ) {
    }

    public function handle(
        TemplateRenderer $renderer,
        CampaignTrackingService $trackingService,
        CampaignProviderManager $providerManager,
        CampaignRunProgressService $progressService,
    ): void {
        $recipient = CampaignRecipient::query()
            ->with([
                'run',
                'campaign' => fn ($query) => $query->with(['channels', 'offers.offer', 'products', 'user']),
                'customer' => fn ($query) => $query->with(['defaultProperty', 'portalUser']),
                'message',
            ])
            ->find($this->campaignRecipientId);

        if (!$recipient || !$recipient->campaign || !$recipient->run) {
            return;
        }

        if ($recipient->status !== CampaignRecipient::STATUS_QUEUED) {
            return;
        }

        $channelModel = $recipient->campaign->channels
            ->first(fn ($channel) => strtoupper((string) $channel->channel) === strtoupper((string) $recipient->channel));
        if (!$channelModel) {
            $trackingService->markFailed($recipient, 'missing_channel_template');
            $progressService->refresh($recipient->run);
            return;
        }

        $product = $recipient->campaign->offers->first()?->offer ?: $recipient->campaign->products->first();
        $context = $renderer->buildContext(
            $recipient->campaign,
            $recipient->customer,
            $product
        );
        $rendered = $renderer->renderChannel($channelModel, $context);

        if (($rendered['invalid_tokens'] ?? []) !== []) {
            $trackingService->markFailed(
                $recipient,
                'invalid_template_tokens',
                ['invalid_tokens' => $rendered['invalid_tokens']]
            );
            $progressService->refresh($recipient->run);
            return;
        }

        if (strtoupper((string) $recipient->channel) === Campaign::CHANNEL_SMS && ($rendered['sms_too_long'] ?? false)) {
            $trackingService->markFailed($recipient, 'sms_too_long', [
                'segments' => $rendered['sms_segments'] ?? null,
            ]);
            $progressService->refresh($recipient->run);
            return;
        }

        $trackedUrl = $recipient->campaign->cta_url
            ? $trackingService->trackedUrl($recipient)
            : null;

        $message = CampaignMessage::query()->updateOrCreate(
            ['campaign_recipient_id' => $recipient->id],
            [
                'campaign_run_id' => $recipient->campaign_run_id,
                'channel' => $recipient->channel,
                'subject_rendered' => $rendered['subject'] ?? null,
                'title_rendered' => $rendered['title'] ?? null,
                'body_rendered' => $rendered['body'] ?? null,
                'cta_url' => $recipient->campaign->cta_url,
                'tracked_cta_url' => $trackedUrl,
                'payload' => [
                    'character_count' => $rendered['character_count'] ?? null,
                    'sms_segments' => $rendered['sms_segments'] ?? null,
                    'template_snapshot' => [
                        'message_template_id' => $channelModel->message_template_id,
                        'content_override' => $channelModel->content_override,
                        'subject_template' => $channelModel->subject_template,
                        'title_template' => $channelModel->title_template,
                        'body_template' => $channelModel->body_template,
                    ],
                    'offer_snapshot' => $product ? [
                        'id' => (int) $product->id,
                        'type' => (string) $product->item_type,
                        'name' => (string) $product->name,
                        'price' => (float) $product->price,
                    ] : null,
                ],
            ]
        );

        $result = $providerManager->send($recipient, $message);
        if (!($result['ok'] ?? false)) {
            $trackingService->markFailed(
                $recipient,
                (string) ($result['reason'] ?? 'provider_error'),
                ['provider' => $result['provider'] ?? null]
            );
            $progressService->refresh($recipient->run);
            return;
        }

        $trackingService->markSent(
            $recipient,
            $result['provider'] ?? null,
            $result['provider_message_id'] ?? null
        );

        if (strtoupper((string) $recipient->channel) === Campaign::CHANNEL_IN_APP) {
            $trackingService->markDelivered($recipient);
        }

        $progressService->refresh($recipient->run);
    }
}
