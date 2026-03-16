<?php

namespace App\Services\Campaigns;

use App\Enums\CampaignAudienceSourceLogic;
use App\Enums\OfferType;
use App\Jobs\DispatchCampaignRunJob;
use App\Models\ActivityLog;
use App\Models\Campaign;
use App\Models\CampaignAudience;
use App\Models\CampaignChannel;
use App\Models\CampaignOffer;
use App\Models\CampaignRun;
use App\Models\Customer;
use App\Models\MailingList;
use App\Models\MessageTemplate;
use App\Models\Product;
use App\Models\User;
use App\Notifications\CampaignInAppNotification;
use App\Services\SmsNotificationService;
use App\Support\NotificationDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class CampaignService
{
    public function __construct(
        private readonly AudienceResolver $audienceResolver,
        private readonly TemplateRenderer $templateRenderer,
        private readonly SmsNotificationService $smsService,
        private readonly TemplateLibraryService $templateLibraryService,
        private readonly CampaignProspectingOutreachService $prospectingOutreachService,
    ) {
    }

    public function saveCampaign(
        User $accountOwner,
        User $actor,
        array $payload,
        ?Campaign $campaign = null
    ): Campaign {
        return DB::transaction(function () use ($accountOwner, $actor, $payload, $campaign): Campaign {
            $isCreate = !$campaign;
            if (!$campaign) {
                $campaign = new Campaign();
                $campaign->user_id = $accountOwner->id;
                $campaign->created_by_user_id = $actor->id;
                $campaign->status = Campaign::STATUS_DRAFT;
            } elseif ((int) $campaign->user_id !== (int) $accountOwner->id) {
                throw ValidationException::withMessages([
                    'campaign' => 'Campaign not found for this tenant.',
                ]);
            }

            $channelsPayload = $this->normalizeChannels($payload['channels'] ?? []);
            if ($channelsPayload === []) {
                throw ValidationException::withMessages([
                    'channels' => 'At least one channel is required.',
                ]);
            }

            $campaignType = strtoupper((string) ($payload['campaign_type'] ?? $payload['type'] ?? Campaign::TYPE_PROMOTION));
            if (!in_array($campaignType, Campaign::allowedTypes(), true)) {
                throw ValidationException::withMessages([
                    'campaign_type' => 'Invalid campaign type.',
                ]);
            }

            $languageMode = strtoupper((string) ($payload['language_mode'] ?? Campaign::LANGUAGE_MODE_PREFERRED));
            if (!in_array($languageMode, Campaign::allowedLanguageModes(), true)) {
                throw ValidationException::withMessages([
                    'language_mode' => 'Invalid language mode.',
                ]);
            }

            $prospectingEnabled = (bool) ($payload['prospecting_enabled'] ?? false);
            $campaignDirection = strtolower((string) (
                $payload['campaign_direction']
                ?? ($prospectingEnabled
                    ? Campaign::DIRECTION_PROSPECTING_OUTBOUND
                    : Campaign::DIRECTION_CUSTOMER_MARKETING)
            ));

            if (!in_array($campaignDirection, Campaign::allowedDirections(), true)) {
                throw ValidationException::withMessages([
                    'campaign_direction' => 'Invalid campaign direction.',
                ]);
            }

            if (!$prospectingEnabled) {
                $campaignDirection = Campaign::DIRECTION_CUSTOMER_MARKETING;
            } elseif ($campaignDirection === Campaign::DIRECTION_CUSTOMER_MARKETING) {
                $campaignDirection = Campaign::DIRECTION_PROSPECTING_OUTBOUND;
            }

            $offers = $this->normalizedOffers($payload, $accountOwner);
            if ($offers->isEmpty()) {
                throw ValidationException::withMessages([
                    'offers' => 'At least one offer is required.',
                ]);
            }

            $offerMode = $this->resolveOfferMode(
                (string) ($payload['offer_mode'] ?? ''),
                $offers
            );

            $campaign->fill([
                'updated_by_user_id' => $actor->id,
                'audience_segment_id' => $payload['audience_segment_id'] ?? null,
                'name' => trim((string) ($payload['name'] ?? '')),
                'campaign_type' => $campaignType,
                'campaign_direction' => $campaignDirection,
                'prospecting_enabled' => $prospectingEnabled,
                'offer_mode' => $offerMode,
                'language_mode' => $languageMode,
                'type' => $campaignType, // Legacy mirror column kept for backward compatibility.
                'schedule_type' => (string) ($payload['schedule_type'] ?? Campaign::SCHEDULE_MANUAL),
                'scheduled_at' => $payload['scheduled_at'] ?? null,
                'locale' => $payload['locale'] ?? null,
                'cta_url' => $payload['cta_url'] ?? null,
                'is_marketing' => true,
                'settings' => $this->normalizeCampaignSettings(
                    is_array($payload['settings'] ?? null) ? $payload['settings'] : []
                ),
            ]);

            if ($campaign->status === Campaign::STATUS_COMPLETED) {
                $campaign->status = Campaign::STATUS_DRAFT;
            }

            if ($campaign->schedule_type === Campaign::SCHEDULE_SCHEDULED && $campaign->scheduled_at) {
                $campaign->status = Campaign::STATUS_SCHEDULED;
            }

            $campaign->save();

            $this->syncCampaignOffers($campaign, $offers);

            $existingChannels = $campaign->channels()
                ->get()
                ->keyBy(fn (CampaignChannel $channel) => strtoupper((string) $channel->channel));

            $templateIds = collect($channelsPayload)
                ->pluck('message_template_id')
                ->map(fn ($value) => is_numeric($value) ? (int) $value : null)
                ->filter()
                ->unique()
                ->values();

            $templates = $templateIds->isEmpty()
                ? collect()
                : MessageTemplate::query()
                    ->where('user_id', $accountOwner->id)
                    ->whereIn('id', $templateIds->all())
                    ->get()
                    ->keyBy('id');

            if ($templateIds->count() !== $templates->count()) {
                throw ValidationException::withMessages([
                    'channels' => 'One or more selected templates are invalid for this tenant.',
                ]);
            }

            $keptChannels = [];
            foreach ($channelsPayload as $channelData) {
                $channelName = strtoupper((string) $channelData['channel']);
                $channel = $existingChannels->get($channelName) ?: new CampaignChannel([
                    'campaign_id' => $campaign->id,
                    'channel' => $channelName,
                ]);

                $template = null;
                if (!empty($channelData['message_template_id'])) {
                    $template = $templates->get((int) $channelData['message_template_id']);
                    if (!$template || strtoupper((string) $template->channel) !== $channelName) {
                        throw ValidationException::withMessages([
                            'channels' => 'Template channel mismatch.',
                        ]);
                    }
                } else {
                    $template = $this->templateLibraryService->resolveDefault(
                        $accountOwner,
                        $channelName,
                        $campaignType,
                        $campaign->locale
                    );
                }

                $templatePayload = $template
                    ? $this->templateLibraryService->extractChannelTemplates($template)
                    : [
                        'subject_template' => null,
                        'title_template' => null,
                        'body_template' => null,
                        'metadata' => null,
                    ];

                $metadata = is_array($templatePayload['metadata'] ?? null)
                    ? $templatePayload['metadata']
                    : [];
                $channelMetadata = is_array($channelData['metadata'] ?? null) ? $channelData['metadata'] : [];
                $contentOverride = is_array($channelData['content_override'] ?? null)
                    ? $channelData['content_override']
                    : null;
                if ($contentOverride !== null) {
                    $metadata['content_override'] = $contentOverride;
                }

                $channel->fill([
                    'is_enabled' => (bool) ($channelData['is_enabled'] ?? true),
                    'message_template_id' => $template?->id,
                    'subject_template' => $this->firstNonEmpty(
                        $channelData['subject_template'] ?? null,
                        $templatePayload['subject_template'] ?? null
                    ),
                    'title_template' => $this->firstNonEmpty(
                        $channelData['title_template'] ?? null,
                        $templatePayload['title_template'] ?? null
                    ),
                    'body_template' => $this->firstNonEmpty(
                        $channelData['body_template'] ?? null,
                        $templatePayload['body_template'] ?? null
                    ),
                    'content_override' => $contentOverride,
                    'metadata' => array_merge($metadata, $channelMetadata),
                ]);

                $channel->save();
                $keptChannels[] = $channelName;
            }

            if ($keptChannels !== []) {
                $campaign->channels()
                    ->whereNotIn('channel', $keptChannels)
                    ->delete();
            }

            $audience = is_array($payload['audience'] ?? null) ? $payload['audience'] : [];
            $manualCustomerIds = $this->normalizeIdList($audience['manual_customer_ids'] ?? []);
            $includeMailingListIds = $this->normalizeIdList($audience['include_mailing_list_ids'] ?? []);
            $excludeMailingListIds = $this->normalizeIdList($audience['exclude_mailing_list_ids'] ?? []);
            $sourceLogic = CampaignAudienceSourceLogic::normalize(
                (string) ($audience['source_logic'] ?? null)
            )->value;

            $this->assertMailingListsBelongToTenant(
                $accountOwner,
                $includeMailingListIds,
                $excludeMailingListIds
            );

            $sourceSummary = is_array($audience['source_summary'] ?? null)
                ? $audience['source_summary']
                : [];
            $sourceSummary = array_merge([
                'logic' => $sourceLogic,
                'dynamic_enabled' => (bool) (
                    !empty($audience['smart_filters'])
                    || !empty($campaign->audience_segment_id)
                ),
                'include_mailing_lists_count' => count($includeMailingListIds),
                'exclude_mailing_lists_count' => count($excludeMailingListIds),
                'manual_customer_ids_count' => count($manualCustomerIds),
                'manual_contacts_count' => is_array($audience['manual_contacts'] ?? null)
                    ? count($audience['manual_contacts'])
                    : 0,
            ], $sourceSummary);

            $campaign->audience()->updateOrCreate(
                ['campaign_id' => $campaign->id],
                [
                    'smart_filters' => is_array($audience['smart_filters'] ?? null) ? $audience['smart_filters'] : null,
                    'exclusion_filters' => is_array($audience['exclusion_filters'] ?? null) ? $audience['exclusion_filters'] : null,
                    'manual_customer_ids' => $manualCustomerIds !== [] ? $manualCustomerIds : null,
                    'include_mailing_list_ids' => $includeMailingListIds !== [] ? $includeMailingListIds : null,
                    'exclude_mailing_list_ids' => $excludeMailingListIds !== [] ? $excludeMailingListIds : null,
                    'source_logic' => $sourceLogic,
                    'source_summary' => $sourceSummary,
                    'manual_contacts' => $audience['manual_contacts'] ?? null,
                    'estimated_counts' => is_array($audience['estimated_counts'] ?? null) ? $audience['estimated_counts'] : null,
                ]
            );

            ActivityLog::record(
                $actor,
                $campaign,
                $isCreate ? 'campaign_created' : 'campaign_updated',
                [
                    'campaign_id' => $campaign->id,
                    'campaign_type' => $campaignType,
                    'campaign_direction' => $campaignDirection,
                    'prospecting_enabled' => $prospectingEnabled,
                    'offer_mode' => $offerMode,
                    'channels' => $keptChannels,
                    'offers' => $offers->map(fn (array $offer) => [
                        'offer_type' => $offer['offer_type'],
                        'offer_id' => $offer['offer_id'],
                    ])->values()->all(),
                ]
            );

            return $campaign->fresh([
                'offers.offer:id,name,price,stock,image,item_type,sku,number',
                'products:id,name,price,stock,image',
                'channels.template:id,name,channel,campaign_type,language',
                'audience',
                'audienceSegment',
            ]);
        });
    }

    public function estimateAudience(Campaign $campaign): array
    {
        $result = $this->audienceResolver->resolveForCampaign($campaign);

        CampaignAudience::query()
            ->where('campaign_id', $campaign->id)
            ->update([
                'estimated_counts' => $result['counts'],
                'resolved_at' => now(),
            ]);

        return $result['counts'];
    }

    public function queueRun(
        Campaign $campaign,
        User $actor,
        string $triggerType = CampaignRun::TRIGGER_MANUAL,
        ?Carbon $scheduledFor = null,
        ?string $idempotencyKey = null
    ): CampaignRun {
        $campaign->loadMissing('channels');
        $enabledChannels = $campaign->channels->where('is_enabled', true)->count();
        if ($enabledChannels === 0) {
            throw ValidationException::withMessages([
                'channels' => 'No enabled channels configured for this campaign.',
            ]);
        }

        $this->prospectingOutreachService->assertCanQueueRun($campaign->loadMissing('user'));

        $effectiveIdempotencyKey = $idempotencyKey;
        if (!$effectiveIdempotencyKey) {
            $timePart = ($scheduledFor ?: now())->copy()->seconds(0)->format('Y-m-d H:i');
            $effectiveIdempotencyKey = hash('sha256', implode('|', [
                $campaign->id,
                $campaign->user_id,
                strtoupper($triggerType),
                $timePart,
            ]));
        }

        $existing = CampaignRun::query()
            ->where('idempotency_key', $effectiveIdempotencyKey)
            ->first();
        if ($existing) {
            return $existing;
        }

        $run = CampaignRun::query()->create([
            'campaign_id' => $campaign->id,
            'user_id' => $campaign->user_id,
            'triggered_by_user_id' => $actor->id,
            'trigger_type' => $triggerType,
            'status' => CampaignRun::STATUS_PENDING,
            'idempotency_key' => $effectiveIdempotencyKey,
            'scheduled_for' => $scheduledFor,
        ]);

        $campaign->forceFill([
            'status' => $scheduledFor && $scheduledFor->isFuture()
                ? Campaign::STATUS_SCHEDULED
                : Campaign::STATUS_RUNNING,
            'started_at' => $scheduledFor && $scheduledFor->isFuture() ? null : now(),
        ])->save();

        $dispatch = DispatchCampaignRunJob::dispatch($run->id)
            ->onQueue((string) config('campaigns.queues.dispatch', 'campaigns-dispatch'));
        if ($scheduledFor && $scheduledFor->isFuture()) {
            $dispatch->delay($scheduledFor);
        }

        ActivityLog::record($actor, $campaign, 'campaign_run_queued', [
            'campaign_id' => $campaign->id,
            'campaign_run_id' => $run->id,
            'trigger_type' => $triggerType,
            'scheduled_for' => $scheduledFor?->toIso8601String(),
        ]);

        return $run;
    }

    public function sendTest(Campaign $campaign, User $actor, array $channels): array
    {
        $campaign->loadMissing(['channels', 'offers.offer', 'products', 'user']);
        $sampleCustomer = Customer::query()
            ->where('user_id', $campaign->user_id)
            ->inRandomOrder()
            ->first();
        $sampleProspect = $sampleCustomer
            ? null
            : $this->prospectingOutreachService->sampleProspects($campaign, 1)->first();
        $product = $campaign->offers->first()?->offer ?: $campaign->products->first();
        $context = $this->templateRenderer->buildContext(
            $campaign,
            $sampleCustomer,
            $product,
            $sampleProspect
                ? $this->prospectingOutreachService->contextExtrasFromProspect($sampleProspect, $campaign)
                : []
        );

        $results = [];
        foreach ($campaign->channels->where('is_enabled', true) as $channelModel) {
            $channel = strtoupper((string) $channelModel->channel);
            if ($channels !== [] && !in_array($channel, $channels, true)) {
                continue;
            }

            $rendered = $this->templateRenderer->renderChannel($channelModel, $context);
            if (($rendered['invalid_tokens'] ?? []) !== []) {
                $results[] = [
                    'channel' => $channel,
                    'ok' => false,
                    'reason' => 'invalid_tokens',
                    'invalid_tokens' => $rendered['invalid_tokens'],
                ];
                continue;
            }

            if ($channel === Campaign::CHANNEL_SMS && ($rendered['sms_too_long'] ?? false)) {
                $results[] = [
                    'channel' => $channel,
                    'ok' => false,
                    'reason' => 'sms_too_long',
                    'segments' => $rendered['sms_segments'],
                ];
                continue;
            }

            $results[] = match ($channel) {
                Campaign::CHANNEL_EMAIL => $this->sendTestEmail($actor, $rendered),
                Campaign::CHANNEL_SMS => $this->sendTestSms($actor, $rendered),
                Campaign::CHANNEL_IN_APP => $this->sendTestInApp($actor, $campaign, $rendered),
                default => [
                    'channel' => $channel,
                    'ok' => false,
                    'reason' => 'unsupported_channel',
                ],
            };
        }

        return $results;
    }

    private function sendTestEmail(User $actor, array $rendered): array
    {
        $email = trim((string) $actor->email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'channel' => Campaign::CHANNEL_EMAIL,
                'ok' => false,
                'reason' => 'missing_actor_email',
            ];
        }

        try {
            Mail::html((string) ($rendered['body'] ?? ''), function ($mail) use ($email, $rendered): void {
                $mail->to($email)->subject((string) ($rendered['subject'] ?? 'Campaign test'));
            });
        } catch (\Throwable $exception) {
            return [
                'channel' => Campaign::CHANNEL_EMAIL,
                'ok' => false,
                'reason' => 'mail_exception',
                'error' => $exception->getMessage(),
            ];
        }

        return [
            'channel' => Campaign::CHANNEL_EMAIL,
            'ok' => true,
        ];
    }

    private function sendTestSms(User $actor, array $rendered): array
    {
        $phone = trim((string) $actor->phone_number);
        if ($phone === '') {
            return [
                'channel' => Campaign::CHANNEL_SMS,
                'ok' => false,
                'reason' => 'missing_actor_phone',
            ];
        }

        $result = $this->smsService->sendWithResult($phone, (string) ($rendered['body'] ?? ''));
        if (!($result['ok'] ?? false)) {
            return [
                'channel' => Campaign::CHANNEL_SMS,
                'ok' => false,
                'reason' => (string) ($result['reason'] ?? 'sms_error'),
            ];
        }

        return [
            'channel' => Campaign::CHANNEL_SMS,
            'ok' => true,
            'provider_message_id' => $result['sid'] ?? null,
        ];
    }

    private function sendTestInApp(User $actor, Campaign $campaign, array $rendered): array
    {
        $queued = NotificationDispatcher::send($actor, new CampaignInAppNotification([
            'title' => (string) ($rendered['title'] ?? $campaign->name),
            'message' => (string) ($rendered['body'] ?? ''),
            'action_url' => $campaign->cta_url,
            'campaign_id' => $campaign->id,
            'campaign_run_id' => null,
            'campaign_recipient_id' => null,
        ]));

        return [
            'channel' => Campaign::CHANNEL_IN_APP,
            'ok' => $queued,
            'reason' => $queued ? null : 'notification_dispatch_failed',
        ];
    }

    private function normalizeChannels(array $channels): array
    {
        return collect($channels)
            ->filter(fn ($channel) => is_array($channel))
            ->map(function (array $channel): array {
                $metadata = is_array($channel['metadata'] ?? null) ? $channel['metadata'] : [];
                $abTesting = is_array($channel['ab_testing'] ?? null)
                    ? $channel['ab_testing']
                    : (is_array($metadata['ab_testing'] ?? null) ? $metadata['ab_testing'] : null);
                if ($abTesting !== null) {
                    $metadata['ab_testing'] = $this->normalizeAbTesting($abTesting);
                }

                return [
                    'channel' => strtoupper((string) ($channel['channel'] ?? '')),
                    'is_enabled' => array_key_exists('is_enabled', $channel) ? (bool) $channel['is_enabled'] : true,
                    'subject_template' => $channel['subject_template'] ?? null,
                    'title_template' => $channel['title_template'] ?? null,
                    'body_template' => $channel['body_template'] ?? null,
                    'message_template_id' => $channel['message_template_id'] ?? null,
                    'content_override' => is_array($channel['content_override'] ?? null) ? $channel['content_override'] : null,
                    'metadata' => $metadata !== [] ? $metadata : null,
                ];
            })
            ->filter(fn (array $channel) => in_array($channel['channel'], Campaign::allowedChannels(), true))
            ->unique('channel')
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function normalizeCampaignSettings(array $settings): array
    {
        $normalized = $settings;
        $holdout = is_array($settings['holdout'] ?? null) ? $settings['holdout'] : [];
        $normalized['holdout'] = [
            'enabled' => (bool) ($holdout['enabled'] ?? false),
            'percent' => max(0, min(100, (int) ($holdout['percent'] ?? 0))),
        ];

        $fallback = is_array($settings['channel_fallback'] ?? null) ? $settings['channel_fallback'] : [];
        $fallbackMapInput = is_array($fallback['map'] ?? null) ? $fallback['map'] : [];
        $fallbackMap = [];

        foreach (Campaign::allowedChannels() as $sourceChannel) {
            $source = strtoupper((string) $sourceChannel);
            $targets = collect($fallbackMapInput[$source] ?? [])
                ->map(fn ($value) => strtoupper((string) $value))
                ->filter(fn (string $value) => in_array($value, Campaign::allowedChannels(), true))
                ->reject(fn (string $value) => $value === $source)
                ->unique()
                ->values()
                ->all();

            if ($targets !== []) {
                $fallbackMap[$source] = $targets;
            }
        }

        $normalized['channel_fallback'] = [
            'enabled' => (bool) ($fallback['enabled'] ?? false),
            'max_depth' => max(1, min(3, (int) ($fallback['max_depth'] ?? 1))),
            'map' => $fallbackMap,
        ];

        $sequence = is_array($settings['prospecting_sequence'] ?? null) ? $settings['prospecting_sequence'] : [];
        $delays = collect($sequence['follow_up_delays_hours'] ?? [72, 168])
            ->map(fn ($value) => max(1, min(24 * 30, (int) $value)))
            ->filter()
            ->values()
            ->take(2)
            ->all();

        $maxSteps = max(1, min(3, (int) ($sequence['max_steps'] ?? (1 + count($delays)))));
        $normalized['prospecting_sequence'] = [
            'enabled' => (bool) ($sequence['enabled'] ?? true),
            'max_steps' => $maxSteps,
            'follow_up_delays_hours' => array_slice($delays, 0, max(0, $maxSteps - 1)),
        ];

        return $normalized;
    }

    /**
     * @param array<string, mixed> $abTesting
     * @return array<string, mixed>
     */
    private function normalizeAbTesting(array $abTesting): array
    {
        $variant = static function (mixed $value): array {
            $source = is_array($value) ? $value : [];
            return [
                'subject_template' => $source['subject_template'] ?? null,
                'title_template' => $source['title_template'] ?? null,
                'body_template' => $source['body_template'] ?? null,
            ];
        };

        return [
            'enabled' => (bool) ($abTesting['enabled'] ?? false),
            'split_a_percent' => max(1, min(99, (int) ($abTesting['split_a_percent'] ?? 50))),
            'variant_a' => $variant($abTesting['variant_a'] ?? []),
            'variant_b' => $variant($abTesting['variant_b'] ?? []),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return Collection<int, array{offer_type: string, offer_id: int, metadata: array<string, mixed>|null}>
     */
    private function normalizedOffers(array $payload, User $accountOwner): Collection
    {
        $offers = collect($payload['offers'] ?? [])
            ->filter(fn ($item) => is_array($item))
            ->map(function (array $item): array {
                return [
                    'offer_type' => strtolower((string) ($item['offer_type'] ?? '')),
                    'offer_id' => is_numeric($item['offer_id'] ?? null) ? (int) $item['offer_id'] : 0,
                    'metadata' => is_array($item['metadata'] ?? null) ? $item['metadata'] : null,
                ];
            })
            ->filter(fn (array $item) => in_array($item['offer_type'], OfferType::values(), true) && $item['offer_id'] > 0);

        $legacyProducts = collect($payload['product_ids'] ?? [])
            ->map(fn ($value) => is_numeric($value) ? (int) $value : null)
            ->filter()
            ->map(fn (int $productId): array => [
                'offer_type' => OfferType::PRODUCT->value,
                'offer_id' => $productId,
                'metadata' => null,
            ]);

        $fromSelectors = $this->offersFromSelectors(
            is_array($payload['offer_selectors'] ?? null) ? $payload['offer_selectors'] : [],
            (string) ($payload['offer_mode'] ?? ''),
            $accountOwner
        );

        $merged = $offers
            ->concat($legacyProducts)
            ->concat($fromSelectors)
            ->unique(fn (array $item) => $item['offer_type'] . ':' . $item['offer_id'])
            ->values();

        if ($merged->isEmpty()) {
            return $merged;
        }

        $productIds = $merged->pluck('offer_id')->unique()->values();
        $products = Product::query()
            ->where('user_id', $accountOwner->id)
            ->whereIn('id', $productIds->all())
            ->get(['id', 'item_type'])
            ->keyBy('id');

        if ($products->count() !== $productIds->count()) {
            throw ValidationException::withMessages([
                'offers' => 'Some offers do not belong to this tenant.',
            ]);
        }

        return $merged->map(function (array $item) use ($products): array {
            $product = $products->get($item['offer_id']);
            $resolvedType = strtolower((string) ($product?->item_type ?? OfferType::PRODUCT->value));
            if (!in_array($resolvedType, OfferType::values(), true)) {
                $resolvedType = OfferType::PRODUCT->value;
            }

            if ($item['offer_type'] !== '' && $item['offer_type'] !== $resolvedType) {
                throw ValidationException::withMessages([
                    'offers' => 'Offer type mismatch for selected offer.',
                ]);
            }

            return [
                'offer_type' => $resolvedType,
                'offer_id' => (int) $item['offer_id'],
                'metadata' => $item['metadata'],
            ];
        })->values();
    }

    /**
     * @param array<string, mixed> $selectors
     * @return Collection<int, array{offer_type: string, offer_id: int, metadata: array<string, mixed>|null}>
     */
    private function offersFromSelectors(array $selectors, string $offerMode, User $accountOwner): Collection
    {
        $categoryIds = collect($selectors['category_ids'] ?? [])
            ->map(fn ($value) => is_numeric($value) ? (int) $value : null)
            ->filter()
            ->unique()
            ->values();

        $tags = collect($selectors['tags'] ?? [])
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->values();

        if ($categoryIds->isEmpty() && $tags->isEmpty()) {
            return collect();
        }

        $query = Product::query()
            ->where('user_id', $accountOwner->id)
            ->where('is_active', true)
            ->when($categoryIds->isNotEmpty(), function ($builder) use ($categoryIds): void {
                $builder->whereIn('category_id', $categoryIds->all());
            });

        if ($tags->isNotEmpty()) {
            $query->where(function ($builder) use ($tags): void {
                foreach ($tags as $index => $tag) {
                    if ($index === 0) {
                        $builder->whereJsonContains('tags', $tag);
                        continue;
                    }
                    $builder->orWhereJsonContains('tags', $tag);
                }
            });
        }

        $normalizedMode = strtoupper(trim($offerMode));
        if ($normalizedMode === Campaign::OFFER_MODE_PRODUCTS) {
            $query->where('item_type', OfferType::PRODUCT->value);
        } elseif ($normalizedMode === Campaign::OFFER_MODE_SERVICES) {
            $query->where('item_type', OfferType::SERVICE->value);
        }

        return $query
            ->get(['id', 'item_type'])
            ->map(fn (Product $offer): array => [
                'offer_type' => strtolower((string) $offer->item_type),
                'offer_id' => (int) $offer->id,
                'metadata' => [
                    'source' => 'selector_snapshot',
                ],
            ])
            ->values();
    }

    /**
     * @param Collection<int, array{offer_type: string, offer_id: int, metadata: array<string, mixed>|null}> $offers
     */
    private function resolveOfferMode(string $requestedMode, Collection $offers): string
    {
        $types = $offers
            ->pluck('offer_type')
            ->map(fn ($type) => strtolower((string) $type))
            ->unique()
            ->values();

        $inferred = Campaign::OFFER_MODE_PRODUCTS;
        if ($types->count() > 1) {
            $inferred = Campaign::OFFER_MODE_MIXED;
        } elseif ($types->first() === OfferType::SERVICE->value) {
            $inferred = Campaign::OFFER_MODE_SERVICES;
        }

        $requested = strtoupper(trim($requestedMode));
        if ($requested === '') {
            return $inferred;
        }

        if (!in_array($requested, Campaign::allowedOfferModes(), true)) {
            throw ValidationException::withMessages([
                'offer_mode' => 'Invalid offer mode.',
            ]);
        }

        if ($requested === Campaign::OFFER_MODE_PRODUCTS && $types->contains(OfferType::SERVICE->value)) {
            throw ValidationException::withMessages([
                'offer_mode' => 'Services are not allowed in PRODUCTS mode.',
            ]);
        }

        if ($requested === Campaign::OFFER_MODE_SERVICES && $types->contains(OfferType::PRODUCT->value)) {
            throw ValidationException::withMessages([
                'offer_mode' => 'Products are not allowed in SERVICES mode.',
            ]);
        }

        return $requested;
    }

    /**
     * @param Collection<int, array{offer_type: string, offer_id: int, metadata: array<string, mixed>|null}> $offers
     */
    private function syncCampaignOffers(Campaign $campaign, Collection $offers): void
    {
        $campaign->offers()->delete();

        $payload = $offers->map(fn (array $offer): array => [
            'campaign_id' => $campaign->id,
            'offer_type' => $offer['offer_type'],
            'offer_id' => $offer['offer_id'],
            'metadata' => $offer['metadata'],
            'created_at' => now(),
            'updated_at' => now(),
        ])->values()->all();

        if ($payload !== []) {
            CampaignOffer::query()->insert($payload);
        }

        $legacyProductIds = $offers
            ->filter(fn (array $offer) => $offer['offer_type'] === OfferType::PRODUCT->value)
            ->pluck('offer_id')
            ->unique()
            ->values()
            ->all();
        $campaign->products()->sync($legacyProductIds);
    }

    /**
     * @param mixed $values
     * @return array<int, int>
     */
    private function normalizeIdList(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }

        return collect($values)
            ->map(fn ($value) => is_numeric($value) ? (int) $value : null)
            ->filter(fn ($value) => is_int($value) && $value > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param array<int, int> $includeIds
     * @param array<int, int> $excludeIds
     */
    private function assertMailingListsBelongToTenant(User $accountOwner, array $includeIds, array $excludeIds): void
    {
        $allIds = collect($includeIds)
            ->concat($excludeIds)
            ->unique()
            ->values();

        if ($allIds->isEmpty()) {
            return;
        }

        $existingCount = MailingList::query()
            ->where('user_id', $accountOwner->id)
            ->whereIn('id', $allIds->all())
            ->count();

        if ($existingCount !== $allIds->count()) {
            throw ValidationException::withMessages([
                'audience.include_mailing_list_ids' => 'One or more mailing lists are invalid for this tenant.',
            ]);
        }
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
