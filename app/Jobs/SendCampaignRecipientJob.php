<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\CampaignChannel;
use App\Models\CampaignMessage;
use App\Models\CampaignRecipient;
use App\Services\Campaigns\CampaignRunProgressService;
use App\Services\Campaigns\CampaignProspectingOutreachService;
use App\Services\Campaigns\CampaignTrackingService;
use App\Services\Campaigns\ConsentService;
use App\Services\Campaigns\FatigueLimiter;
use App\Services\Campaigns\Providers\CampaignProviderManager;
use App\Services\Campaigns\TemplateRenderer;
use App\Support\QueueWorkload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendCampaignRecipientJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public function __construct(
        public int $campaignRecipientId
    ) {
        $this->onQueue(QueueWorkload::queue('campaigns_send'));
    }

    public function backoff(): array
    {
        return QueueWorkload::backoff('campaigns_send', [30, 120, 300, 600]);
    }

    public function handle(
        TemplateRenderer $renderer,
        CampaignTrackingService $trackingService,
        CampaignProviderManager $providerManager,
        CampaignRunProgressService $progressService,
        ConsentService $consentService,
        FatigueLimiter $fatigueLimiter,
        CampaignProspectingOutreachService $prospectingOutreachService,
    ): void {
        $recipient = CampaignRecipient::query()
            ->with([
                'run',
                'campaign' => fn ($query) => $query->with(['channels', 'offers.offer', 'products', 'user']),
                'customer' => fn ($query) => $query->with(['defaultProperty', 'portalUser']),
                'message',
            ])
            ->find($this->campaignRecipientId);

        if (! $recipient || ! $recipient->campaign || ! $recipient->run) {
            return;
        }

        if ($recipient->status !== CampaignRecipient::STATUS_QUEUED) {
            return;
        }

        $channelModel = $recipient->campaign->channels
            ->first(fn ($channel) => strtoupper((string) $channel->channel) === strtoupper((string) $recipient->channel));
        if (! $channelModel) {
            $trackingService->markFailed($recipient, 'missing_channel_template');
            $progressService->refresh($recipient->run);

            return;
        }

        $resolvedChannel = $this->resolveChannelForRecipient($channelModel, $recipient);
        $channelForRender = $resolvedChannel['channel'];
        $abVariant = $resolvedChannel['variant'];

        $product = $recipient->campaign->offers->first()?->offer ?: $recipient->campaign->products->first();
        $context = $renderer->buildContext(
            $recipient->campaign,
            $recipient->customer,
            $product,
            $prospectingOutreachService->buildContextExtrasFromRecipient($recipient)
        );
        $rendered = $renderer->renderChannel($channelForRender, $context);

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
                        'ab_variant' => $abVariant,
                        'message_template_id' => $channelModel->message_template_id,
                        'content_override' => $channelModel->content_override,
                        'subject_template' => $channelForRender->subject_template,
                        'title_template' => $channelForRender->title_template,
                        'body_template' => $channelForRender->body_template,
                        'base_template' => [
                            'subject_template' => $channelModel->subject_template,
                            'title_template' => $channelModel->title_template,
                            'body_template' => $channelModel->body_template,
                        ],
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
        if (! ($result['ok'] ?? false)) {
            $reason = (string) ($result['reason'] ?? 'provider_error');
            $fallback = $this->queueFallbackForFailure(
                $recipient,
                $reason,
                $trackingService,
                $consentService,
                $fatigueLimiter,
                $prospectingOutreachService
            );

            $trackingService->markFailed(
                $recipient,
                $reason,
                [
                    'provider' => $result['provider'] ?? null,
                    'fallback' => $fallback,
                ]
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

    /**
     * @return array{channel: CampaignChannel, variant: string|null}
     */
    private function resolveChannelForRecipient(CampaignChannel $channelModel, CampaignRecipient $recipient): array
    {
        $recipientMetadata = is_array($recipient->metadata) ? $recipient->metadata : [];
        $assignment = is_array($recipientMetadata['ab_test'] ?? null) ? $recipientMetadata['ab_test'] : [];
        $variant = strtoupper((string) ($assignment['variant'] ?? ''));
        if (! in_array($variant, ['A', 'B'], true)) {
            return ['channel' => $channelModel, 'variant' => null];
        }

        $channelMetadata = is_array($channelModel->metadata) ? $channelModel->metadata : [];
        $abTesting = is_array($channelMetadata['ab_testing'] ?? null) ? $channelMetadata['ab_testing'] : [];
        if (! ($abTesting['enabled'] ?? false)) {
            return ['channel' => $channelModel, 'variant' => null];
        }

        $variantPayload = $variant === 'A'
            ? (is_array($abTesting['variant_a'] ?? null) ? $abTesting['variant_a'] : [])
            : (is_array($abTesting['variant_b'] ?? null) ? $abTesting['variant_b'] : []);

        $resolved = clone $channelModel;
        $resolved->subject_template = $this->firstNonEmpty(
            $variantPayload['subject_template'] ?? null,
            $channelModel->subject_template
        );
        $resolved->title_template = $this->firstNonEmpty(
            $variantPayload['title_template'] ?? null,
            $channelModel->title_template
        );
        $resolved->body_template = $this->firstNonEmpty(
            $variantPayload['body_template'] ?? null,
            $channelModel->body_template
        );

        return ['channel' => $resolved, 'variant' => $variant];
    }

    /**
     * @return array<string, mixed>
     */
    private function queueFallbackForFailure(
        CampaignRecipient $recipient,
        string $failureReason,
        CampaignTrackingService $trackingService,
        ConsentService $consentService,
        FatigueLimiter $fatigueLimiter,
        CampaignProspectingOutreachService $prospectingOutreachService
    ): array {
        $campaign = $recipient->campaign;
        $accountOwner = $campaign?->user;
        if (! $campaign || ! $accountOwner) {
            return ['queued' => false, 'reason' => 'missing_campaign_owner'];
        }

        $config = $this->fallbackConfig(is_array($campaign->settings) ? $campaign->settings : []);
        if (! ($config['enabled'] ?? false)) {
            return ['queued' => false, 'reason' => 'fallback_disabled'];
        }

        $fromChannel = strtoupper((string) $recipient->channel);
        $targets = is_array($config['map'][$fromChannel] ?? null) ? $config['map'][$fromChannel] : [];
        if ($targets === []) {
            return ['queued' => false, 'reason' => 'no_fallback_targets'];
        }

        $recipientMetadata = is_array($recipient->metadata) ? $recipient->metadata : [];
        $fallbackMetadata = is_array($recipientMetadata['fallback'] ?? null) ? $recipientMetadata['fallback'] : [];
        $history = collect($fallbackMetadata['history'] ?? [])
            ->map(fn ($value) => strtoupper((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();
        if (! in_array($fromChannel, $history, true)) {
            $history[] = $fromChannel;
        }

        $currentDepth = (int) ($fallbackMetadata['depth'] ?? max(0, count($history) - 1));
        $maxDepth = max(1, min(3, (int) ($config['max_depth'] ?? 1)));
        if ($currentDepth >= $maxDepth) {
            return ['queued' => false, 'reason' => 'fallback_depth_reached', 'depth' => $currentDepth];
        }

        $enabledChannels = $campaign->channels
            ->where('is_enabled', true)
            ->pluck('channel')
            ->map(fn ($channel) => strtoupper((string) $channel))
            ->unique()
            ->values()
            ->all();

        $attempts = [];
        foreach ($targets as $targetChannel) {
            $target = strtoupper((string) $targetChannel);
            if (in_array($target, $history, true)) {
                $attempts[] = ['channel' => $target, 'reason' => 'channel_already_in_history'];

                continue;
            }

            if (! in_array($target, $enabledChannels, true)) {
                $attempts[] = ['channel' => $target, 'reason' => 'channel_not_enabled'];

                continue;
            }

            $destinationCandidate = $this->destinationForChannel($recipient, $target);
            if (! $destinationCandidate) {
                $destinationCandidate = $prospectingOutreachService->destinationForFallback($recipient, $target);
            }
            $consentDecision = $consentService->canReceive(
                $accountOwner,
                $recipient->customer,
                $target,
                $destinationCandidate
            );
            if (! ($consentDecision['allowed'] ?? false)) {
                $attempts[] = [
                    'channel' => $target,
                    'reason' => (string) ($consentDecision['reason'] ?? 'consent_denied'),
                ];

                continue;
            }

            if ($recipient->customer) {
                $fatigueDecision = $fatigueLimiter->canSend(
                    $accountOwner,
                    $recipient->customer,
                    $target,
                    $campaign
                );
                if (! ($fatigueDecision['allowed'] ?? false)) {
                    $attempts[] = [
                        'channel' => $target,
                        'reason' => (string) ($fatigueDecision['reason'] ?? 'fatigue_denied'),
                    ];

                    continue;
                }
            }

            $destination = (string) ($consentDecision['destination'] ?? '');
            if ($destination === '') {
                $attempts[] = ['channel' => $target, 'reason' => 'missing_destination'];

                continue;
            }

            $destinationHash = CampaignRecipient::destinationHash($destination)
                ?: hash('sha256', $target.':'.strtolower($destination));
            $nextHistory = collect(array_merge($history, [$target]))
                ->map(fn ($value) => strtoupper((string) $value))
                ->unique()
                ->values()
                ->all();

            $nextMetadata = $recipientMetadata;
            $nextMetadata['fallback'] = [
                'root_recipient_id' => (int) ($fallbackMetadata['root_recipient_id'] ?? $recipient->id),
                'parent_recipient_id' => $recipient->id,
                'from_channel' => $fromChannel,
                'to_channel' => $target,
                'depth' => $currentDepth + 1,
                'history' => $nextHistory,
                'last_reason' => $failureReason,
            ];

            $fallbackRecipient = CampaignRecipient::query()->firstOrCreate(
                [
                    'campaign_run_id' => $recipient->campaign_run_id,
                    'channel' => $target,
                    'destination_hash' => $destinationHash,
                ],
                [
                    'campaign_id' => $recipient->campaign_id,
                    'user_id' => $recipient->user_id,
                    'customer_id' => $recipient->customer_id,
                    'destination' => $destination,
                    'dedupe_key' => $target.':'.$destinationHash,
                    'status' => CampaignRecipient::STATUS_QUEUED,
                    'queued_at' => now(),
                    'metadata' => $nextMetadata,
                ]
            );

            if (! $fallbackRecipient->wasRecentlyCreated) {
                $attempts[] = ['channel' => $target, 'reason' => 'duplicate_destination'];

                continue;
            }

            $trackingService->ensureTokens($fallbackRecipient);

            SendCampaignRecipientJob::dispatch((int) $fallbackRecipient->id)
                ->onQueue((string) config('campaigns.queues.send', 'campaigns-send'));

            return [
                'queued' => true,
                'channel' => $target,
                'recipient_id' => (int) $fallbackRecipient->id,
                'attempts' => $attempts,
            ];
        }

        return [
            'queued' => false,
            'reason' => 'no_eligible_fallback_target',
            'attempts' => $attempts,
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{enabled: bool, max_depth: int, map: array<string, array<int, string>>}
     */
    private function fallbackConfig(array $settings): array
    {
        $fallback = is_array($settings['channel_fallback'] ?? null) ? $settings['channel_fallback'] : [];
        $mapInput = is_array($fallback['map'] ?? null) ? $fallback['map'] : [];
        $map = [];

        foreach (Campaign::allowedChannels() as $source) {
            $normalizedSource = strtoupper((string) $source);
            $targets = collect($mapInput[$normalizedSource] ?? [])
                ->map(fn ($value) => strtoupper((string) $value))
                ->filter(fn (string $value) => in_array($value, Campaign::allowedChannels(), true))
                ->reject(fn (string $value) => $value === $normalizedSource)
                ->unique()
                ->values()
                ->all();

            if ($targets !== []) {
                $map[$normalizedSource] = $targets;
            }
        }

        return [
            'enabled' => (bool) ($fallback['enabled'] ?? false),
            'max_depth' => max(1, min(3, (int) ($fallback['max_depth'] ?? 1))),
            'map' => $map,
        ];
    }

    private function destinationForChannel(CampaignRecipient $recipient, string $channel): ?string
    {
        $target = strtoupper(trim($channel));
        $customer = $recipient->customer;
        if ($customer) {
            return match ($target) {
                Campaign::CHANNEL_EMAIL => $customer->email,
                Campaign::CHANNEL_SMS => $customer->phone,
                Campaign::CHANNEL_IN_APP => $customer->portal_user_id ? (string) $customer->portal_user_id : null,
                default => null,
            };
        }

        return strtoupper((string) $recipient->channel) === $target
            ? (string) $recipient->destination
            : null;
    }

    private function firstNonEmpty(mixed $primary, mixed $fallback): ?string
    {
        $value = trim((string) $primary);
        if ($value !== '') {
            return $value;
        }

        $fallbackValue = trim((string) $fallback);

        return $fallbackValue !== '' ? $fallbackValue : null;
    }
}
