<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\CampaignChannel;
use App\Models\CampaignEvent;
use App\Models\CampaignMessage;
use App\Models\CampaignProspect;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Services\Campaigns\CampaignProspectingOutreachService;
use App\Services\Campaigns\CampaignRunProgressService;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class SendCampaignRecipientJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public int $timeout = 90;

    public function __construct(
        public int $campaignRecipientId
    ) {
        $this->onQueue(QueueWorkload::queue('campaigns_send'));
    }

    public function backoff(): array
    {
        return QueueWorkload::backoff('campaigns_send', [30, 120, 300, 600]);
    }

    public function failed(?Throwable $exception): void
    {
        $runId = (int) CampaignRecipient::query()->whereKey($this->campaignRecipientId)->value('campaign_run_id');
        if ($runId <= 0) {
            return;
        }

        DB::transaction(function () use ($runId): void {
            $recipient = $this->lockedRecipient($runId);
            if (! $recipient || ! $recipient->run || $recipient->status !== CampaignRecipient::STATUS_QUEUED) {
                return;
            }

            $state = data_get($recipient->metadata, 'delivery_attempt.state');
            if (in_array($state, ['accepted', 'rejected', 'unknown'], true)) {
                if (in_array($recipient->run->status, [CampaignRun::STATUS_PENDING, CampaignRun::STATUS_RUNNING], true)) {
                    $recipient->run->forceFill(['status' => CampaignRun::STATUS_FAILED, 'error_message' => 'delivery_tracking_failed'])->save();
                    $recipient->campaign()->where('status', Campaign::STATUS_RUNNING)->update(['status' => Campaign::STATUS_FAILED]);
                }

                return;
            }

            $reason = $state === 'submitting' ? 'provider_result_unknown' : 'delivery_preparation_failed';
            if ($state === 'submitting') {
                $this->storeResult($recipient, ['ok' => false, 'delivery_outcome' => 'unknown', 'reason' => $reason]);
            }
            app(CampaignTrackingService::class)->markFailed($recipient, $reason);
            app(CampaignRunProgressService::class)->refresh($recipient->run);
        }, 3);
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
        $runId = (int) CampaignRecipient::query()->whereKey($this->campaignRecipientId)->value('campaign_run_id');
        if ($runId <= 0) {
            return;
        }

        $prepared = DB::transaction(function () use ($runId, $renderer, $trackingService, $progressService, $consentService, $prospectingOutreachService): ?array {
            $recipient = $this->lockedRecipient($runId);
            if (! $recipient || ! $recipient->campaign || ! $recipient->run) {
                return null;
            }

            $attempt = data_get($recipient->metadata, 'delivery_attempt', []);
            if ($recipient->status !== CampaignRecipient::STATUS_QUEUED) {
                return $recipient->status === CampaignRecipient::STATUS_FAILED
                    && ($attempt['state'] ?? null) === 'rejected'
                    && in_array($recipient->run->status, [CampaignRun::STATUS_PENDING, CampaignRun::STATUS_RUNNING], true)
                    ? ['dispatch_fallbacks' => true]
                    : null;
            }

            if (in_array($attempt['state'] ?? null, ['accepted', 'rejected', 'unknown'], true)) {
                return ['recipient' => $recipient, 'result' => $attempt['result']];
            }

            if (($attempt['state'] ?? null) === 'submitting') {
                $retryAt = Carbon::parse($attempt['started_at'])->addSeconds($this->timeout + 30);
                if ($retryAt->lessThanOrEqualTo(now())) {
                    $result = ['ok' => false, 'delivery_outcome' => 'unknown', 'reason' => 'provider_result_unknown'];
                    $this->storeResult($recipient, $result);

                    return ['recipient' => $recipient, 'result' => $result];
                }

                return ['retry_after' => max(1, (int) ceil(now()->diffInSeconds($retryAt)))];
            }

            $message = $this->prepareMessage($recipient, $renderer, $trackingService, $progressService, $prospectingOutreachService);
            if (! $message) {
                return null;
            }

            $reason = $this->deliveryBlockedReason($recipient, $consentService);
            if ($reason !== null) {
                $recipient->forceFill([
                    'status' => CampaignRecipient::STATUS_SKIPPED,
                    'failure_reason' => $reason,
                ])->save();
                $trackingService->recordEvent($recipient, CampaignEvent::EVENT_SKIPPED, ['reason' => $reason]);
                $progressService->refresh($recipient->run);

                return null;
            }

            $recipient->forceFill(['metadata' => array_merge($recipient->metadata ?? [], [
                'delivery_attempt' => [
                    'id' => (string) Str::uuid(),
                    'state' => 'submitting',
                    'started_at' => now()->toIso8601String(),
                ],
            ])])->save();

            return ['recipient' => $recipient, 'message' => $message];
        }, 3);

        if (! $prepared) {
            return;
        }

        if (isset($prepared['retry_after'])) {
            $this->release($prepared['retry_after']);

            return;
        }

        if (isset($prepared['dispatch_fallbacks'])) {
            CampaignRecipient::query()->where('campaign_run_id', $runId)
                ->where('status', CampaignRecipient::STATUS_QUEUED)
                ->where('metadata->fallback->parent_recipient_id', $this->campaignRecipientId)
                ->each(fn (CampaignRecipient $recipient) => self::dispatch($recipient->id)->afterCommit());

            return;
        }

        if (! isset($prepared['result'])) {
            try {
                $result = $providerManager->send($prepared['recipient'], $prepared['message']);
            } catch (Throwable $exception) {
                report($exception);
                $result = ['ok' => false, 'delivery_outcome' => 'unknown', 'reason' => 'provider_result_unknown'];
            }

            DB::transaction(function () use ($runId, $result): void {
                $recipient = $this->lockedRecipient($runId);
                if ($recipient) {
                    $this->storeResult($recipient, $result);
                }
            }, 3);
        }

        DB::transaction(function () use ($runId, $trackingService, $progressService, $consentService, $fatigueLimiter, $prospectingOutreachService): void {
            $recipient = $this->lockedRecipient($runId);
            if (! $recipient || ! $recipient->run || $recipient->status !== CampaignRecipient::STATUS_QUEUED) {
                return;
            }

            $result = data_get($recipient->metadata, 'delivery_attempt.result', []);
            if (! ($result['ok'] ?? false)) {
                $unknown = ($result['delivery_outcome'] ?? null) === 'unknown';
                $reason = $unknown ? 'provider_result_unknown' : (string) ($result['reason'] ?? 'provider_error');
                $fallback = $unknown
                    ? ['queued' => false, 'reason' => 'provider_result_unknown']
                    : $this->queueFallbackForFailure($recipient, $reason, $trackingService, $consentService, $fatigueLimiter, $prospectingOutreachService);
                $trackingService->markFailed($recipient, $reason, [
                    'provider' => $result['provider'] ?? null,
                    'delivery_outcome' => $unknown ? 'unknown' : 'rejected',
                    'fallback' => $fallback,
                ]);
            } else {
                $trackingService->markSent($recipient, $result['provider'] ?? null, $result['provider_message_id'] ?? null);
                if (strtoupper((string) $recipient->channel) === Campaign::CHANNEL_IN_APP) {
                    $trackingService->markDelivered($recipient);
                }
            }

            $progressService->refresh($recipient->run);
        }, 3);
    }

    private function lockedRecipient(int $runId): ?CampaignRecipient
    {
        $run = CampaignRun::query()->lockForUpdate()->find($runId);
        if (! $run) {
            return null;
        }

        $recipient = CampaignRecipient::query()->with([
            'campaign' => fn ($query) => $query->with(['channels', 'offers.offer', 'products', 'user']),
            'customer' => fn ($query) => $query->with(['defaultProperty', 'portalUser']),
            'message',
        ])->lockForUpdate()->find($this->campaignRecipientId);

        return $recipient?->setRelation('run', $run);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function storeResult(CampaignRecipient $recipient, array $result): void
    {
        $state = ($result['ok'] ?? false) ? 'accepted' : (($result['delivery_outcome'] ?? null) === 'unknown' ? 'unknown' : 'rejected');
        $attempt = data_get($recipient->metadata, 'delivery_attempt', []);
        $attempt['state'] = $state;
        $attempt['resolved_at'] = now()->toIso8601String();
        $attempt['result'] = array_intersect_key($result, array_flip(['ok', 'provider', 'provider_message_id', 'reason', 'delivery_outcome', 'status']));
        $updates = ['metadata' => array_merge($recipient->metadata ?? [], ['delivery_attempt' => $attempt])];
        if ($state === 'accepted') {
            $updates['provider'] = $result['provider'] ?? null;
            $updates['provider_message_id'] = $result['provider_message_id'] ?? null;
        }
        $recipient->forceFill($updates)->save();
    }

    private function deliveryBlockedReason(CampaignRecipient $recipient, ConsentService $consentService): ?string
    {
        if (! $recipient->campaign?->user || ! $recipient->run
            || ! in_array($recipient->run->status, [CampaignRun::STATUS_PENDING, CampaignRun::STATUS_RUNNING], true)
            || $recipient->campaign->status === Campaign::STATUS_CANCELED) {
            return 'campaign_not_running';
        }

        $channel = $recipient->campaign->channels->firstWhere('channel', $recipient->channel);
        if (! $channel?->is_enabled) {
            return 'channel_disabled';
        }

        $prospectId = (int) data_get($recipient->metadata, 'prospect_id', 0);
        if ($prospectId > 0) {
            $prospect = CampaignProspect::query()->where('campaign_id', $recipient->campaign_id)
                ->where('user_id', $recipient->user_id)->find($prospectId);
            if (! $prospect || $prospect->do_not_contact || $prospect->status === CampaignProspect::STATUS_DO_NOT_CONTACT) {
                return 'do_not_contact';
            }
        }

        $decision = $consentService->canReceive($recipient->campaign->user, $recipient->customer, $recipient->channel, $recipient->destination);

        return ($decision['allowed'] ?? false) ? null : (string) ($decision['reason'] ?? 'consent_denied');
    }

    private function prepareMessage(
        CampaignRecipient $recipient,
        TemplateRenderer $renderer,
        CampaignTrackingService $trackingService,
        CampaignRunProgressService $progressService,
        CampaignProspectingOutreachService $prospectingOutreachService,
    ): ?CampaignMessage {
        $channelModel = $recipient->campaign->channels
            ->first(fn ($channel) => strtoupper((string) $channel->channel) === strtoupper((string) $recipient->channel));
        if (! $channelModel) {
            $trackingService->markFailed($recipient, 'missing_channel_template');
            $progressService->refresh($recipient->run);

            return null;
        }

        $resolvedChannel = $this->resolveChannelForRecipient($channelModel, $recipient);
        $channelForRender = $resolvedChannel['channel'];
        $abVariant = $resolvedChannel['variant'];

        $product = $recipient->campaign->offers->first()?->offer ?: $recipient->campaign->products->first();
        $trackedUrl = $recipient->campaign->cta_url
            ? $trackingService->trackedUrl($recipient)
            : null;
        $unsubscribeUrl = strtoupper((string) $recipient->channel) === Campaign::CHANNEL_EMAIL
            ? $trackingService->unsubscribeUrl($recipient)
            : null;
        $context = $renderer->buildContext(
            $recipient->campaign,
            $recipient->customer,
            $product,
            array_merge(
                $prospectingOutreachService->buildContextExtrasFromRecipient($recipient),
                [
                    'ctaUrl' => (string) ($trackedUrl ?: $recipient->campaign->cta_url),
                    'trackedCtaUrl' => (string) ($trackedUrl ?: $recipient->campaign->cta_url),
                    'unsubscribeUrl' => (string) ($unsubscribeUrl ?? ''),
                ]
            )
        );
        $rendered = $renderer->renderChannel($channelForRender, $context);

        if (($rendered['invalid_tokens'] ?? []) !== []) {
            $trackingService->markFailed(
                $recipient,
                'invalid_template_tokens',
                ['invalid_tokens' => $rendered['invalid_tokens']]
            );
            $progressService->refresh($recipient->run);

            return null;
        }

        if (strtoupper((string) $recipient->channel) === Campaign::CHANNEL_SMS && ($rendered['sms_too_long'] ?? false)) {
            $trackingService->markFailed($recipient, 'sms_too_long', [
                'segments' => $rendered['sms_segments'] ?? null,
            ]);
            $progressService->refresh($recipient->run);

            return null;
        }

        return CampaignMessage::query()->updateOrCreate(
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
            unset($nextMetadata['delivery_attempt']);
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

            SendCampaignRecipientJob::dispatch((int) $fallbackRecipient->id)->afterCommit();

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
