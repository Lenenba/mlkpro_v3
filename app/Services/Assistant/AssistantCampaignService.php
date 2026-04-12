<?php

namespace App\Services\Assistant;

use App\Enums\CampaignAudienceSourceLogic;
use App\Models\Campaign;
use App\Models\Product;
use App\Models\User;
use App\Services\Campaigns\CampaignService;
use App\Services\Campaigns\TemplateRenderer;
use App\Support\CampaignTemplateLanguage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AssistantCampaignService
{
    public function __construct(
        private readonly CampaignAssistantContextService $campaignContextService,
        private readonly CampaignDraftScoringService $campaignDraftScoringService,
        private readonly CampaignService $campaignService,
        private readonly TemplateRenderer $templateRenderer,
    ) {}

    /**
     * @param  array<string, mixed>  $interpretation
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function handle(array $interpretation, User $user, array $context = []): array
    {
        $campaignContext = is_array($context['campaign_context'] ?? null)
            ? $context['campaign_context']
            : $this->campaignContextService->build($user);

        if (! (bool) ($campaignContext['campaigns_enabled'] ?? false)) {
            return [
                'status' => 'not_allowed',
                'message' => 'Le module campagnes n est pas disponible pour ce compte.',
            ];
        }

        if (! (bool) ($campaignContext['can_view'] ?? false)) {
            return [
                'status' => 'not_allowed',
                'message' => 'Vous n avez pas acces au module campagnes.',
            ];
        }

        if (! (bool) ($campaignContext['can_manage'] ?? false)) {
            return [
                'status' => 'not_allowed',
                'message' => 'Vous pouvez voir les campagnes, mais pas creer ou modifier un brouillon.',
            ];
        }

        $accountOwner = $this->resolveAccountOwner($user, $campaignContext);
        if (! $accountOwner) {
            return [
                'status' => 'not_allowed',
                'message' => 'Impossible de resoudre le compte principal pour les campagnes.',
            ];
        }

        $existingCampaign = $this->resolveExistingDraft($accountOwner, $context['campaign_draft_id'] ?? null);
        $draft = $this->mergeDraft(
            is_array($context['campaign'] ?? null) ? $context['campaign'] : [],
            is_array($interpretation['campaign'] ?? null) ? $interpretation['campaign'] : []
        );

        $resolved = $this->resolveDraft($draft, $campaignContext, $existingCampaign);
        if ($resolved['questions'] !== []) {
            return [
                'status' => 'needs_input',
                'message' => 'J ai besoin d une information bloquante pour preparer le brouillon campagne.',
                'questions' => $resolved['questions'],
                'context' => [
                    'intent' => 'draft_campaign',
                    'campaign' => $this->buildContextDraft($resolved),
                    'campaign_draft_id' => $existingCampaign?->id,
                ],
            ];
        }

        try {
            $campaign = $this->campaignService->saveCampaign(
                $accountOwner,
                $user,
                $this->buildPayload($resolved, $campaignContext, $existingCampaign, $accountOwner),
                $existingCampaign
            );
        } catch (ValidationException $exception) {
            return $this->validationNeedsInput($exception, $resolved, $existingCampaign);
        }

        $review = $this->finalizePreparedCampaign(
            $campaign,
            $accountOwner,
            $user,
            $resolved,
            $campaignContext
        );

        if (($review['status'] ?? null) === 'needs_input') {
            return [
                'status' => 'needs_input',
                'message' => (string) ($review['message'] ?? 'Le brouillon campagne demande encore un ajustement.'),
                'questions' => $review['questions'] ?? [],
                'context' => [
                    'intent' => 'draft_campaign',
                    'campaign' => $this->buildContextDraft($review['resolved'] ?? $resolved),
                    'campaign_draft_id' => ($review['campaign'] ?? $campaign)?->id,
                ],
            ];
        }

        $campaign = $review['campaign'] ?? $campaign;
        $resolved = $review['resolved'] ?? $resolved;
        $estimatedCounts = $review['estimated_counts'] ?? [];
        $previewSummary = $review['preview_summary'] ?? [];

        $status = $existingCampaign ? 'updated' : 'created';

        return [
            'status' => $status,
            'message' => $this->buildSuccessMessage($campaign, $resolved, $status, $estimatedCounts, $previewSummary),
            'campaign_review' => $this->buildCampaignReview($campaign, $resolved, $status, $estimatedCounts, $previewSummary),
            'action' => [
                'type' => 'campaign_draft_ready',
                'campaign_id' => $campaign->id,
            ],
            'context' => [
                'intent' => 'draft_campaign',
                'campaign' => $this->buildContextDraft($resolved),
                'campaign_draft_id' => $campaign->id,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $updates
     * @return array<string, mixed>
     */
    private function mergeDraft(array $base, array $updates): array
    {
        $keys = [
            'objective',
            'campaign_type',
            'offer_hint',
            'offer_mode_hint',
            'audience_hint',
            'timing_hint',
            'kpi_hint',
            'language_hint',
            'name_hint',
            'notes',
        ];

        $merged = [];
        foreach ($keys as $key) {
            $incoming = trim((string) ($updates[$key] ?? ''));
            $merged[$key] = $incoming !== '' ? $incoming : trim((string) ($base[$key] ?? ''));
        }

        $merged['channel_hints'] = collect($base['channel_hints'] ?? [])
            ->concat($updates['channel_hints'] ?? [])
            ->map(fn ($value) => strtoupper(trim((string) $value)))
            ->filter(fn (string $value) => in_array($value, [
                Campaign::CHANNEL_EMAIL,
                Campaign::CHANNEL_SMS,
                Campaign::CHANNEL_IN_APP,
            ], true))
            ->unique()
            ->values()
            ->all();

        return $merged;
    }

    private function resolveAccountOwner(User $user, array $campaignContext): ?User
    {
        $ownerId = (int) ($campaignContext['tenant']['owner_id'] ?? $user->accountOwnerId() ?? $user->id);

        return $ownerId > 0
            ? User::query()->find($ownerId)
            : $user;
    }

    private function resolveExistingDraft(User $accountOwner, mixed $draftId): ?Campaign
    {
        if (! is_numeric($draftId)) {
            return null;
        }

        return Campaign::query()
            ->where('user_id', $accountOwner->id)
            ->whereKey((int) $draftId)
            ->with([
                'offers.offer:id,name,item_type',
                'channels:id,campaign_id,channel,is_enabled,message_template_id',
                'audience',
                'audienceSegment:id,name',
            ])
            ->first();
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $campaignContext
     * @return array<string, mixed>
     */
    private function resolveDraft(array $draft, array $campaignContext, ?Campaign $existingCampaign): array
    {
        $brief = [
            'objective' => trim((string) ($draft['objective'] ?? '')),
            'campaign_type' => trim((string) ($draft['campaign_type'] ?? '')),
            'offer_hint' => trim((string) ($draft['offer_hint'] ?? '')),
            'offer_mode_hint' => trim((string) ($draft['offer_mode_hint'] ?? '')),
            'audience_hint' => trim((string) ($draft['audience_hint'] ?? '')),
            'timing_hint' => trim((string) ($draft['timing_hint'] ?? '')),
            'channel_hints' => is_array($draft['channel_hints'] ?? null) ? array_values($draft['channel_hints']) : [],
            'kpi_hint' => trim((string) ($draft['kpi_hint'] ?? '')),
            'language_hint' => trim((string) ($draft['language_hint'] ?? '')),
            'name_hint' => trim((string) ($draft['name_hint'] ?? '')),
            'notes' => trim((string) ($draft['notes'] ?? '')),
        ];

        $campaignType = $this->resolveCampaignType($brief);
        $channelSelection = $this->resolveChannels($brief, $campaignContext, $campaignType, $existingCampaign);
        $offerSelection = $this->resolveOffer($brief, $campaignContext, $campaignType, $existingCampaign);
        $channels = $channelSelection['channels'];
        $offer = $offerSelection['offer'];
        $audience = $this->resolveAudience($brief, $campaignContext, $campaignType, $existingCampaign);
        $languageMode = $this->resolveLanguageMode($brief, $campaignContext);
        $locale = $this->resolveLocale($languageMode, $campaignContext);
        $schedule = $this->resolveSchedule($brief, $campaignContext, $existingCampaign);
        $name = $this->resolveCampaignName($brief, $campaignType, $offer, $schedule, $existingCampaign);
        $kpiFocus = $this->resolveKpiFocus($brief, $campaignType);

        $questions = [];
        if ($channels === []) {
            $questions[] = 'Aucun canal compatible n est active. Activez EMAIL, SMS ou IN_APP pour preparer cette campagne.';
        }

        if ($offer === null) {
            $questions[] = 'Quel produit ou service faut-il mettre en avant dans cette campagne ?';
        }

        return [
            'brief' => $brief,
            'campaign_type' => $campaignType,
            'channels' => $channels,
            'offer' => $offer,
            'scoring' => [
                'channels' => $channelSelection['scoring'],
                'offers' => $offerSelection['scoring'],
            ],
            'offer_mode' => $this->resolveOfferMode($brief, $offer, $campaignContext, $existingCampaign),
            'audience' => $audience,
            'language_mode' => $languageMode,
            'locale' => $locale,
            'schedule' => $schedule,
            'name' => $name,
            'kpi_focus' => $kpiFocus,
            'questions' => array_values(array_unique($questions)),
        ];
    }

    /**
     * @param  array<string, mixed>  $brief
     */
    private function resolveCampaignType(array $brief): string
    {
        $explicit = strtoupper($brief['campaign_type']);
        if (in_array($explicit, Campaign::allowedTypes(), true)) {
            return $explicit;
        }

        $haystack = Str::lower(Str::ascii(implode(' ', [
            $brief['objective'],
            $brief['audience_hint'],
            $brief['timing_hint'],
            $brief['notes'],
            $brief['name_hint'],
        ])));

        if ($this->containsAny($haystack, ['back in stock', 'retour en stock', 'retour disponible', 'de nouveau disponible'])) {
            return Campaign::TYPE_BACK_AVAILABLE;
        }

        if ($this->containsAny($haystack, ['cross sell', 'cross-sell', 'upsell', 'complement', 'offre complementaire'])) {
            return Campaign::TYPE_CROSS_SELL;
        }

        if ($this->containsAny($haystack, ['winback', 'reactiver', 'reactivation', 'relancer', 'anciens clients', 'old clients', 'inactive', 'inactifs'])) {
            return Campaign::TYPE_WINBACK;
        }

        if ($this->containsAny($haystack, ['nouveau service', 'nouveau produit', 'new service', 'new product', 'launch', 'lancement'])) {
            return Campaign::TYPE_NEW_OFFER;
        }

        if ($this->containsAny($haystack, ['annonce', 'announcement', 'annoncer', 'news', 'nouvelle'])) {
            return Campaign::TYPE_ANNOUNCEMENT;
        }

        return Campaign::TYPE_PROMOTION;
    }

    /**
     * @param  array<string, mixed>  $brief
     * @param  array<string, mixed>  $campaignContext
     * @return array{channels: array<int, string>, scoring: array<int, array<string, mixed>>}
     */
    private function resolveChannels(array $brief, array $campaignContext, string $campaignType, ?Campaign $existingCampaign): array
    {
        $enabledChannels = collect($campaignContext['marketing']['enabled_channels'] ?? [])
            ->map(fn ($value) => strtoupper(trim((string) $value)))
            ->filter(fn (string $value) => in_array($value, [
                Campaign::CHANNEL_EMAIL,
                Campaign::CHANNEL_SMS,
                Campaign::CHANNEL_IN_APP,
            ], true))
            ->unique()
            ->values();

        if ($enabledChannels->isEmpty()) {
            return [
                'channels' => [],
                'scoring' => [],
            ];
        }

        $requested = collect($brief['channel_hints'])
            ->map(fn ($value) => strtoupper(trim((string) $value)))
            ->filter(fn (string $value) => $enabledChannels->contains($value))
            ->unique()
            ->values();

        if ($requested->isNotEmpty()) {
            return [
                'channels' => $requested->all(),
                'scoring' => collect($requested)
                    ->map(fn (string $channel): array => [
                        'channel' => $channel,
                        'score' => 100.0,
                        'reasons' => ['explicit_channel_request'],
                    ])
                    ->values()
                    ->all(),
            ];
        }

        if ($existingCampaign && $existingCampaign->relationLoaded('channels')) {
            $existing = $existingCampaign->channels
                ->where('is_enabled', true)
                ->pluck('channel')
                ->map(fn ($value) => strtoupper(trim((string) $value)))
                ->filter(fn (string $value) => $enabledChannels->contains($value))
                ->unique()
                ->values();

            if ($existing->isNotEmpty()) {
                return [
                    'channels' => $existing->all(),
                    'scoring' => collect($existing)
                        ->map(fn (string $channel): array => [
                            'channel' => $channel,
                            'score' => 95.0,
                            'reasons' => ['existing_draft_channels'],
                        ])
                        ->values()
                        ->all(),
                ];
            }
        }

        $ranked = $this->campaignDraftScoringService->rankChannels(
            $enabledChannels->all(),
            $brief,
            $campaignType,
            is_array($campaignContext['performance'] ?? null) ? $campaignContext['performance'] : []
        );

        return [
            'channels' => collect($ranked)->pluck('channel')->take(2)->values()->all(),
            'scoring' => array_values($ranked),
        ];
    }

    /**
     * @param  array<string, mixed>  $brief
     * @param  array<string, mixed>  $campaignContext
     * @return array{offer: array<string, mixed>|null, scoring: array<int, array<string, mixed>>}
     */
    private function resolveOffer(array $brief, array $campaignContext, string $campaignType, ?Campaign $existingCampaign): array
    {
        $offers = $this->filterOffersByMode(
            is_array($campaignContext['offers'] ?? null) ? $campaignContext['offers'] : [],
            $brief['offer_mode_hint'],
            is_array($campaignContext['marketing']['allowed_offer_modes'] ?? null)
                ? $campaignContext['marketing']['allowed_offer_modes']
                : []
        );

        $offerHint = Str::lower(Str::ascii($brief['offer_hint']));
        if ($offerHint !== '') {
            $best = null;
            $bestScore = 0.0;

            foreach ($offers as $offer) {
                $score = $this->scoreOfferCandidate($offerHint, $offer);
                if ($score > $bestScore) {
                    $best = $offer;
                    $bestScore = $score;
                }
            }

            if ($best && $bestScore >= 0.72) {
                return [
                    'offer' => array_merge($best, [
                        'assistant_reason' => 'offer_hint_match',
                    ]),
                    'scoring' => [
                        array_merge($best, [
                            'score' => round($bestScore * 100, 2),
                            'score_reasons' => ['offer_hint_match'],
                        ]),
                    ],
                ];
            }

            $databaseMatch = $this->searchOfferByHintFromDatabase(
                $offerHint,
                (int) ($campaignContext['tenant']['owner_id'] ?? 0),
                $brief['offer_mode_hint'],
                is_array($campaignContext['marketing']['allowed_offer_modes'] ?? null)
                    ? $campaignContext['marketing']['allowed_offer_modes']
                    : []
            );

            if ($databaseMatch) {
                return [
                    'offer' => array_merge($databaseMatch, [
                        'assistant_reason' => 'offer_hint_database_match',
                    ]),
                    'scoring' => [
                        array_merge($databaseMatch, [
                            'score' => 93.0,
                            'score_reasons' => ['offer_hint_database_match'],
                        ]),
                    ],
                ];
            }
        }

        if ($existingCampaign && $existingCampaign->relationLoaded('offers') && $existingCampaign->offers->isNotEmpty()) {
            $existingOffer = $existingCampaign->offers
                ->first(fn ($item) => $item->offer !== null);

            if ($existingOffer?->offer) {
                return [
                    'offer' => [
                        'id' => (int) $existingOffer->offer->id,
                        'name' => (string) $existingOffer->offer->name,
                        'offer_type' => strtolower((string) ($existingOffer->offer->item_type ?: $existingOffer->offer_type)),
                        'assistant_reason' => 'existing_draft_offer',
                    ],
                    'scoring' => [[
                        'id' => (int) $existingOffer->offer->id,
                        'name' => (string) $existingOffer->offer->name,
                        'offer_type' => strtolower((string) ($existingOffer->offer->item_type ?: $existingOffer->offer_type)),
                        'score' => 95.0,
                        'score_reasons' => ['existing_draft_offer'],
                    ]],
                ];
            }
        }

        if ($offers === []) {
            return [
                'offer' => null,
                'scoring' => [],
            ];
        }

        $ranked = $this->campaignDraftScoringService->rankOffers(
            $offers,
            $brief,
            $campaignType,
            is_array($campaignContext['performance'] ?? null) ? $campaignContext['performance'] : []
        );

        $selected = $ranked[0] ?? null;
        if ($selected) {
            $selected['assistant_reason'] = match (true) {
                collect($selected['score_reasons'] ?? [])->contains('historical_offer_performance') => 'historical_offer_performance',
                collect($selected['score_reasons'] ?? [])->contains('historical_offer_performance_global') => 'historical_offer_performance_global',
                default => 'latest_active_offer',
            };
        }

        return [
            'offer' => $selected,
            'scoring' => array_values($ranked),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $offers
     * @param  array<int, string>  $allowedModes
     * @return array<int, array<string, mixed>>
     */
    private function filterOffersByMode(array $offers, string $requestedMode, array $allowedModes): array
    {
        $requestedMode = strtoupper(trim($requestedMode));
        $allowedModes = collect($allowedModes)
            ->map(fn ($value) => strtoupper(trim((string) $value)))
            ->filter(fn (string $value) => in_array($value, Campaign::allowedOfferModes(), true))
            ->unique()
            ->values();

        $normalized = collect($offers)
            ->filter(fn ($offer) => is_array($offer))
            ->map(function (array $offer): array {
                return [
                    'id' => (int) ($offer['id'] ?? 0),
                    'name' => trim((string) ($offer['name'] ?? '')),
                    'offer_type' => strtolower(trim((string) ($offer['offer_type'] ?? 'product'))),
                    'category_name' => trim((string) ($offer['category_name'] ?? '')),
                    'tags' => is_array($offer['tags'] ?? null) ? array_values($offer['tags']) : [],
                    'promo_discount_percent' => $offer['promo_discount_percent'] ?? null,
                ];
            })
            ->filter(fn (array $offer) => $offer['id'] > 0 && $offer['name'] !== '')
            ->values();

        if ($allowedModes->isNotEmpty()) {
            $normalized = $normalized->filter(function (array $offer) use ($allowedModes): bool {
                if ($allowedModes->contains(Campaign::OFFER_MODE_MIXED)) {
                    return true;
                }

                if ($offer['offer_type'] === 'service') {
                    return $allowedModes->contains(Campaign::OFFER_MODE_SERVICES);
                }

                return $allowedModes->contains(Campaign::OFFER_MODE_PRODUCTS);
            })->values();
        }

        if ($requestedMode === Campaign::OFFER_MODE_PRODUCTS) {
            return $normalized->where('offer_type', 'product')->values()->all();
        }

        if ($requestedMode === Campaign::OFFER_MODE_SERVICES) {
            return $normalized->where('offer_type', 'service')->values()->all();
        }

        return $normalized->all();
    }

    /**
     * @param  array<string, mixed>  $offer
     */
    private function scoreOfferCandidate(string $offerHint, array $offer): float
    {
        $parts = [
            Str::lower(Str::ascii((string) ($offer['name'] ?? ''))),
            Str::lower(Str::ascii((string) ($offer['category_name'] ?? ''))),
            ...collect($offer['tags'] ?? [])->map(fn ($value) => Str::lower(Str::ascii((string) $value)))->all(),
        ];

        $best = 0.0;
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            $score = $this->stringSimilarity($offerHint, $part);
            if (str_contains($part, $offerHint) || str_contains($offerHint, $part)) {
                $score = max($score, 0.9);
            }

            $best = max($best, $score);
        }

        return $best;
    }

    /**
     * @param  array<int, string>  $allowedModes
     * @return array<string, mixed>|null
     */
    private function searchOfferByHintFromDatabase(
        string $offerHint,
        int $ownerId,
        string $requestedMode,
        array $allowedModes
    ): ?array {
        if ($ownerId <= 0 || trim($offerHint) === '') {
            return null;
        }

        $normalizedHint = trim(Str::lower(Str::ascii($offerHint)));
        $offers = Product::query()
            ->where('user_id', $ownerId)
            ->where('is_active', true)
            ->with('category:id,name')
            ->get([
                'id',
                'category_id',
                'item_type',
                'name',
                'price',
                'tags',
                'promo_discount_percent',
            ])
            ->map(fn (Product $offer): array => [
                'id' => (int) $offer->id,
                'name' => (string) $offer->name,
                'offer_type' => (string) $offer->item_type,
                'price' => (float) $offer->price,
                'category_name' => (string) ($offer->category?->name ?? ''),
                'tags' => array_values((array) ($offer->tags ?? [])),
                'promo_discount_percent' => $offer->promo_discount_percent !== null
                    ? (float) $offer->promo_discount_percent
                    : null,
            ])
            ->all();

        $offers = $this->filterOffersByMode($offers, $requestedMode, $allowedModes);
        if ($offers === []) {
            return null;
        }

        $best = null;
        $bestScore = 0.0;

        foreach ($offers as $offer) {
            $score = $this->scoreOfferCandidate($normalizedHint, $offer);
            if ($score > $bestScore) {
                $best = $offer;
                $bestScore = $score;
            }
        }

        return $best && $bestScore >= 0.72 ? $best : null;
    }

    /**
     * @param  array<string, mixed>  $brief
     * @param  array<string, mixed>|null  $offer
     * @param  array<string, mixed>  $campaignContext
     */
    private function resolveOfferMode(array $brief, ?array $offer, array $campaignContext, ?Campaign $existingCampaign): string
    {
        $allowedModes = collect($campaignContext['marketing']['allowed_offer_modes'] ?? [])
            ->map(fn ($value) => strtoupper(trim((string) $value)))
            ->filter(fn (string $value) => in_array($value, Campaign::allowedOfferModes(), true))
            ->unique()
            ->values();

        if ($offer) {
            $candidate = $offer['offer_type'] === 'service'
                ? Campaign::OFFER_MODE_SERVICES
                : Campaign::OFFER_MODE_PRODUCTS;

            if ($allowedModes->isEmpty() || $allowedModes->contains($candidate) || $allowedModes->contains(Campaign::OFFER_MODE_MIXED)) {
                return $candidate;
            }
        }

        $requested = strtoupper(trim($brief['offer_mode_hint']));
        if ($requested !== '' && in_array($requested, Campaign::allowedOfferModes(), true)) {
            return $requested;
        }

        if ($existingCampaign && in_array((string) $existingCampaign->offer_mode, Campaign::allowedOfferModes(), true)) {
            return (string) $existingCampaign->offer_mode;
        }

        $companyType = Str::lower(trim((string) ($campaignContext['tenant']['company_type'] ?? '')));

        return $companyType === 'services'
            ? Campaign::OFFER_MODE_SERVICES
            : Campaign::OFFER_MODE_PRODUCTS;
    }

    /**
     * @param  array<string, mixed>  $brief
     * @param  array<string, mixed>  $campaignContext
     * @return array<string, mixed>
     */
    private function resolveAudience(array $brief, array $campaignContext, string $campaignType, ?Campaign $existingCampaign): array
    {
        $audienceHint = trim(implode(' ', array_filter([
            $brief['audience_hint'],
            $brief['objective'],
            $brief['notes'],
        ])));
        $audienceNeedle = Str::lower(Str::ascii($audienceHint));

        $segments = is_array($campaignContext['segments'] ?? null) ? $campaignContext['segments'] : [];
        $segment = $this->matchSegment($segments, $audienceNeedle, $campaignType);
        if ($segment) {
            return [
                'segment_id' => (int) $segment['id'],
                'smart_filters' => null,
                'exclusion_filters' => null,
                'include_mailing_list_ids' => [],
                'exclude_mailing_list_ids' => [],
                'source_logic' => 'UNION',
                'summary' => (string) $segment['name'],
                'strategy' => 'segment_reuse',
            ];
        }

        $mailingList = $this->matchMailingList(
            is_array($campaignContext['mailing_lists'] ?? null) ? $campaignContext['mailing_lists'] : [],
            $audienceNeedle
        );

        $filters = $this->buildAudienceFilters(
            $audienceNeedle,
            $campaignType,
            is_array($campaignContext['vip_tiers'] ?? null) ? $campaignContext['vip_tiers'] : []
        );

        if ($existingCampaign && $audienceNeedle === '') {
            $existingAudience = $existingCampaign->relationLoaded('audience') ? $existingCampaign->audience : null;

            if ($existingCampaign->audienceSegment) {
                return [
                    'segment_id' => (int) $existingCampaign->audienceSegment->id,
                    'smart_filters' => null,
                    'exclusion_filters' => null,
                    'include_mailing_list_ids' => [],
                    'exclude_mailing_list_ids' => [],
                    'source_logic' => 'UNION',
                    'summary' => (string) $existingCampaign->audienceSegment->name,
                    'strategy' => 'existing_draft_segment',
                ];
            }

            if ($existingAudience) {
                return [
                    'segment_id' => null,
                    'smart_filters' => is_array($existingAudience->smart_filters) ? $existingAudience->smart_filters : null,
                    'exclusion_filters' => is_array($existingAudience->exclusion_filters) ? $existingAudience->exclusion_filters : null,
                    'include_mailing_list_ids' => is_array($existingAudience->include_mailing_list_ids) ? $existingAudience->include_mailing_list_ids : [],
                    'exclude_mailing_list_ids' => is_array($existingAudience->exclude_mailing_list_ids) ? $existingAudience->exclude_mailing_list_ids : [],
                    'source_logic' => (string) ($existingAudience->source_logic ?: 'UNION'),
                    'summary' => (string) (($existingAudience->source_summary['assistant_label'] ?? '') ?: 'Audience existante'),
                    'strategy' => 'existing_draft_audience',
                ];
            }
        }

        $summary = 'Tous les clients';
        if ($mailingList) {
            $summary = (string) $mailingList['name'];
        } elseif ($filters['summary'] !== '') {
            $summary = $filters['summary'];
        }

        return [
            'segment_id' => null,
            'smart_filters' => $filters['smart_filters'],
            'exclusion_filters' => null,
            'include_mailing_list_ids' => $mailingList ? [(int) $mailingList['id']] : [],
            'exclude_mailing_list_ids' => [],
            'source_logic' => CampaignAudienceSourceLogic::UNION->value,
            'summary' => $summary,
            'strategy' => $mailingList ? 'mailing_list_reuse' : ($filters['smart_filters'] ? 'assistant_filters' : 'all_customers'),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $segments
     * @return array<string, mixed>|null
     */
    private function matchSegment(array $segments, string $audienceNeedle, string $campaignType): ?array
    {
        $keywords = match ($campaignType) {
            Campaign::TYPE_WINBACK => ['inactive', 'inactif', 'winback', 'lost', 'ancien'],
            Campaign::TYPE_NEW_OFFER => ['new', 'nouveau', 'launch'],
            Campaign::TYPE_CROSS_SELL => ['cross', 'upsell', 'bundle'],
            default => ['promo', 'promotion', 'vip'],
        };

        if ($audienceNeedle !== '') {
            $keywords[] = $audienceNeedle;
        }

        $best = null;
        $bestScore = 0.0;

        foreach ($segments as $segment) {
            if (! is_array($segment)) {
                continue;
            }

            $segmentText = Str::lower(Str::ascii(implode(' ', array_filter([
                (string) ($segment['name'] ?? ''),
                ...collect($segment['tags'] ?? [])->map(fn ($value) => (string) $value)->all(),
            ]))));

            if ($segmentText === '') {
                continue;
            }

            $score = 0.0;
            foreach ($keywords as $keyword) {
                $keyword = Str::lower(Str::ascii(trim((string) $keyword)));
                if ($keyword === '') {
                    continue;
                }

                if (str_contains($segmentText, $keyword)) {
                    $score = max($score, 1.0);

                    continue;
                }

                $score = max($score, $this->stringSimilarity($segmentText, $keyword));
            }

            if ($score > $bestScore) {
                $best = $segment;
                $bestScore = $score;
            }
        }

        return $bestScore >= 0.9 ? $best : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $mailingLists
     * @return array<string, mixed>|null
     */
    private function matchMailingList(array $mailingLists, string $audienceNeedle): ?array
    {
        if ($audienceNeedle === '') {
            return null;
        }

        foreach ($mailingLists as $list) {
            if (! is_array($list)) {
                continue;
            }

            $listText = Str::lower(Str::ascii(implode(' ', array_filter([
                (string) ($list['name'] ?? ''),
                ...collect($list['tags'] ?? [])->map(fn ($value) => (string) $value)->all(),
            ]))));

            if ($listText !== '' && (str_contains($listText, $audienceNeedle) || str_contains($audienceNeedle, $listText))) {
                return $list;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $vipTiers
     * @return array{smart_filters: ?array<string, mixed>, summary: string}
     */
    private function buildAudienceFilters(string $audienceNeedle, string $campaignType, array $vipTiers): array
    {
        if ($audienceNeedle === '' && $campaignType === Campaign::TYPE_WINBACK) {
            return [
                'smart_filters' => [
                    'operator' => 'AND',
                    'rules' => [
                        [
                            'field' => 'segment_status',
                            'operator' => 'equals',
                            'value' => ['inactive'],
                        ],
                    ],
                ],
                'summary' => 'Clients inactifs',
            ];
        }

        if ($this->containsAny($audienceNeedle, ['vip'])) {
            $matchedTier = collect($vipTiers)->first(function ($tier) use ($audienceNeedle) {
                $code = Str::lower(Str::ascii((string) ($tier['code'] ?? '')));
                $name = Str::lower(Str::ascii((string) ($tier['name'] ?? '')));

                return ($code !== '' && str_contains($audienceNeedle, $code))
                    || ($name !== '' && str_contains($audienceNeedle, $name));
            });

            if (is_array($matchedTier) && ! empty($matchedTier['id'])) {
                return [
                    'smart_filters' => [
                        'operator' => 'AND',
                        'rules' => [
                            [
                                'field' => 'vip_tier_id',
                                'operator' => 'equals',
                                'value' => [(int) $matchedTier['id']],
                            ],
                        ],
                    ],
                    'summary' => 'VIP '.((string) ($matchedTier['name'] ?? '')),
                ];
            }

            return [
                'smart_filters' => [
                    'operator' => 'AND',
                    'rules' => [
                        [
                            'field' => 'is_vip',
                            'operator' => 'equals',
                            'value' => true,
                        ],
                    ],
                ],
                'summary' => 'Clients VIP',
            ];
        }

        if ($this->containsAny($audienceNeedle, ['lost', 'perdu', 'perdus'])) {
            return [
                'smart_filters' => [
                    'operator' => 'AND',
                    'rules' => [
                        [
                            'field' => 'segment_status',
                            'operator' => 'equals',
                            'value' => ['lost'],
                        ],
                    ],
                ],
                'summary' => 'Clients perdus',
            ];
        }

        if ($this->containsAny($audienceNeedle, ['inactive', 'inactif', 'anciens', 'ancien', 'old clients'])) {
            return [
                'smart_filters' => [
                    'operator' => 'AND',
                    'rules' => [
                        [
                            'field' => 'segment_status',
                            'operator' => 'equals',
                            'value' => ['inactive'],
                        ],
                    ],
                ],
                'summary' => 'Clients inactifs',
            ];
        }

        if ($this->containsAny($audienceNeedle, ['new clients', 'nouveaux clients', 'recent', 'recents', 'nouveau'])) {
            return [
                'smart_filters' => [
                    'operator' => 'AND',
                    'rules' => [
                        [
                            'field' => 'segment_status',
                            'operator' => 'equals',
                            'value' => ['new'],
                        ],
                    ],
                ],
                'summary' => 'Nouveaux clients',
            ];
        }

        return [
            'smart_filters' => null,
            'summary' => '',
        ];
    }

    /**
     * @param  array<string, mixed>  $brief
     * @param  array<string, mixed>  $campaignContext
     */
    private function resolveLanguageMode(array $brief, array $campaignContext): string
    {
        $hint = strtoupper(trim($brief['language_hint']));
        if (in_array($hint, Campaign::allowedLanguageModes(), true)) {
            return $hint;
        }

        return CampaignTemplateLanguage::defaultModeForLocale(
            (string) ($campaignContext['tenant']['locale'] ?? 'fr')
        );
    }

    private function resolveLocale(string $languageMode, array $campaignContext): string
    {
        if ($languageMode === Campaign::LANGUAGE_MODE_BOTH || $languageMode === Campaign::LANGUAGE_MODE_PREFERRED) {
            return CampaignTemplateLanguage::fromLocale(
                (string) ($campaignContext['tenant']['locale'] ?? 'fr')
            );
        }

        return CampaignTemplateLanguage::normalize($languageMode);
    }

    /**
     * @param  array<string, mixed>  $brief
     * @param  array<string, mixed>  $campaignContext
     * @return array<string, mixed>
     */
    private function resolveSchedule(array $brief, array $campaignContext, ?Campaign $existingCampaign): array
    {
        $timingHint = trim($brief['timing_hint']);
        $timezone = (string) ($campaignContext['tenant']['timezone'] ?? config('app.timezone', 'UTC'));

        if ($timingHint === '' && $existingCampaign) {
            return [
                'schedule_type' => (string) ($existingCampaign->schedule_type ?: Campaign::SCHEDULE_MANUAL),
                'scheduled_at' => $existingCampaign->scheduled_at?->toISOString(),
                'label' => $existingCampaign->scheduled_at
                    ? $existingCampaign->scheduled_at->copy()->timezone($timezone)->format('Y-m-d H:i')
                    : 'Brouillon manuel',
            ];
        }

        if ($timingHint === '') {
            return [
                'schedule_type' => Campaign::SCHEDULE_MANUAL,
                'scheduled_at' => null,
                'label' => 'Brouillon manuel',
            ];
        }

        $normalized = Str::lower(Str::ascii($timingHint));
        $scheduledAt = null;

        if (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $timingHint, $matches) === 1) {
            $scheduledAt = Carbon::createFromFormat('Y-m-d H:i', $matches[1].' 10:00', $timezone);
        } elseif ($this->containsAny($normalized, ['weekend', 'week end', 'week-end'])) {
            $scheduledAt = $this->nextSaturdayAt($timezone, 10);
        } elseif ($this->containsAny($normalized, ['tomorrow', 'demain'])) {
            $scheduledAt = Carbon::now($timezone)->addDay()->startOfDay()->setHour(10);
        } elseif ($this->containsAny($normalized, ['today', 'aujourd'])) {
            $scheduledAt = $this->nextTodaySlot($timezone);
        }

        if (! $scheduledAt) {
            return [
                'schedule_type' => Campaign::SCHEDULE_MANUAL,
                'scheduled_at' => null,
                'label' => $timingHint,
            ];
        }

        return [
            'schedule_type' => Campaign::SCHEDULE_SCHEDULED,
            'scheduled_at' => $scheduledAt->copy()->utc()->toISOString(),
            'label' => $scheduledAt->format('Y-m-d H:i'),
        ];
    }

    private function nextSaturdayAt(string $timezone, int $hour): Carbon
    {
        $date = Carbon::now($timezone)->next(Carbon::SATURDAY)->startOfDay()->setHour($hour);

        if ($date->isPast()) {
            $date = $date->addWeek();
        }

        return $date;
    }

    private function nextTodaySlot(string $timezone): Carbon
    {
        $now = Carbon::now($timezone);

        if ((int) $now->format('H') >= 20) {
            return $now->copy()->addDay()->startOfDay()->setHour(10);
        }

        $slot = $now->copy()->addHour()->minute(0)->second(0);

        return (int) $slot->format('H') < 8
            ? $slot->startOfDay()->setHour(10)
            : $slot;
    }

    /**
     * @param  array<string, mixed>  $brief
     * @param  array<string, mixed>|null  $offer
     * @param  array<string, mixed>  $schedule
     */
    private function resolveCampaignName(array $brief, string $campaignType, ?array $offer, array $schedule, ?Campaign $existingCampaign): string
    {
        if ($brief['name_hint'] !== '') {
            return $brief['name_hint'];
        }

        if ($existingCampaign && trim((string) $existingCampaign->name) !== '') {
            return (string) $existingCampaign->name;
        }

        $label = match ($campaignType) {
            Campaign::TYPE_WINBACK => 'Relance clients',
            Campaign::TYPE_NEW_OFFER => 'Lancement',
            Campaign::TYPE_BACK_AVAILABLE => 'Retour disponible',
            Campaign::TYPE_CROSS_SELL => 'Cross-sell',
            Campaign::TYPE_ANNOUNCEMENT => 'Annonce',
            default => 'Promotion',
        };

        $parts = [$label];
        if ($offer && trim((string) ($offer['name'] ?? '')) !== '') {
            $parts[] = trim((string) $offer['name']);
        }

        if (($schedule['schedule_type'] ?? Campaign::SCHEDULE_MANUAL) === Campaign::SCHEDULE_SCHEDULED
            && trim((string) ($schedule['label'] ?? '')) !== '') {
            $parts[] = trim((string) $schedule['label']);
        }

        return implode(' - ', $parts);
    }

    /**
     * @param  array<string, mixed>  $brief
     */
    private function resolveKpiFocus(array $brief, string $campaignType): string
    {
        $text = Str::lower(Str::ascii(implode(' ', [
            $brief['objective'],
            $brief['kpi_hint'],
            $brief['notes'],
        ])));

        if ($this->containsAny($text, ['reservation', 'booking', 'bookings'])) {
            return 'reservations';
        }

        if ($this->containsAny($text, ['devis', 'quote'])) {
            return 'quotes';
        }

        if ($this->containsAny($text, ['invoice', 'facture', 'vente', 'revenue', 'sales'])) {
            return 'revenue';
        }

        return $campaignType === Campaign::TYPE_WINBACK
            ? 'reactivated_customers'
            : 'click_rate';
    }

    /**
     * @param  array<string, mixed>  $resolved
     * @param  array<string, mixed>  $campaignContext
     * @return array<string, mixed>
     */
    private function buildPayload(
        array $resolved,
        array $campaignContext,
        ?Campaign $existingCampaign,
        User $accountOwner
    ): array {
        $channelBuild = $this->buildChannelPayloads($resolved, $campaignContext, $existingCampaign, $accountOwner);
        $channels = $channelBuild['channels'];

        $offer = $resolved['offer'];
        $assistantSettings = is_array($existingCampaign?->settings) ? $existingCampaign->settings : [];
        $assistantSettings['assistant'] = [
            'source' => 'assistant',
            'objective' => (string) $resolved['brief']['objective'],
            'campaign_type' => (string) $resolved['campaign_type'],
            'kpi_focus' => (string) $resolved['kpi_focus'],
            'audience_strategy' => (string) ($resolved['audience']['strategy'] ?? 'all_customers'),
            'audience_label' => (string) ($resolved['audience']['summary'] ?? ''),
            'schedule_label' => (string) ($resolved['schedule']['label'] ?? ''),
            'requested_timing' => (string) ($resolved['brief']['timing_hint'] ?? ''),
            'confidence' => 'phase_5',
            'generated_copy_channels' => array_values($resolved['generated_copy_channels'] ?? []),
            'history_used' => $this->historyWasUsed($resolved, $channelBuild['template_scoring'] ?? []),
            'scoring' => [
                'offers' => $this->compactScoringEntries($resolved['scoring']['offers'] ?? [], 'id'),
                'channels' => $this->compactScoringEntries($resolved['scoring']['channels'] ?? [], 'channel'),
                'templates' => $this->compactTemplateScoring($channelBuild['template_scoring'] ?? []),
            ],
        ];

        return [
            'name' => $resolved['name'],
            'campaign_type' => $resolved['campaign_type'],
            'offer_mode' => $resolved['offer_mode'],
            'language_mode' => $resolved['language_mode'],
            'schedule_type' => $resolved['schedule']['schedule_type'],
            'scheduled_at' => $resolved['schedule']['scheduled_at'],
            'locale' => $resolved['locale'],
            'cta_url' => $this->resolveCtaUrl($accountOwner, $offer, $existingCampaign),
            'audience_segment_id' => $resolved['audience']['segment_id'],
            'channels' => $channels,
            'offers' => $offer ? [[
                'offer_type' => (string) $offer['offer_type'],
                'offer_id' => (int) $offer['id'],
            ]] : [],
            'audience' => [
                'smart_filters' => $resolved['audience']['smart_filters'],
                'exclusion_filters' => $resolved['audience']['exclusion_filters'],
                'manual_customer_ids' => [],
                'include_mailing_list_ids' => $resolved['audience']['include_mailing_list_ids'],
                'exclude_mailing_list_ids' => $resolved['audience']['exclude_mailing_list_ids'],
                'source_logic' => $resolved['audience']['source_logic'],
                'source_summary' => [
                    'assistant_generated' => true,
                    'assistant_label' => (string) ($resolved['audience']['summary'] ?? ''),
                    'strategy' => (string) ($resolved['audience']['strategy'] ?? 'all_customers'),
                ],
            ],
            'settings' => $assistantSettings,
        ];
    }

    /**
     * @param  array<string, mixed>  $resolved
     * @param  array<string, mixed>  $campaignContext
     * @return array{channels: array<int, array<string, mixed>>, template_scoring: array<string, array<int, array<string, mixed>>>}
     */
    private function buildChannelPayloads(
        array $resolved,
        array $campaignContext,
        ?Campaign $existingCampaign,
        User $accountOwner
    ): array {
        $forcedGeneratedChannels = collect($resolved['generated_copy_channels'] ?? [])
            ->map(fn ($value) => strtoupper(trim((string) $value)))
            ->filter()
            ->unique()
            ->values();
        $templateScoring = [];

        $channels = collect($resolved['channels'])
            ->map(function (string $channel) use ($resolved, $campaignContext, $existingCampaign, $accountOwner, $forcedGeneratedChannels, &$templateScoring): array {
                $templateSelection = $this->resolveTemplateSelection(
                    $campaignContext,
                    $channel,
                    (string) $resolved['campaign_type'],
                    (string) $resolved['locale']
                );
                $templateId = $templateSelection['template_id'];
                $templateScoring[$channel] = array_values($templateSelection['scoring']);

                $existingChannel = $existingCampaign?->relationLoaded('channels')
                    ? $existingCampaign->channels->firstWhere('channel', $channel)
                    : null;

                $shouldGenerate = $forcedGeneratedChannels->contains($channel) || ! $templateId;
                $generated = $shouldGenerate
                    ? $this->buildGeneratedChannelTemplates($channel, $resolved, $accountOwner)
                    : [];

                $metadata = is_array($generated['metadata'] ?? null) ? $generated['metadata'] : [];
                if ($templateSelection['selected'] !== null) {
                    $metadata['assistant_template_selection'] = [
                        'template_id' => (int) ($templateSelection['selected']['id'] ?? 0),
                        'score' => (float) ($templateSelection['selected']['score'] ?? 0),
                        'reasons' => array_values($templateSelection['selected']['score_reasons'] ?? []),
                    ];
                }

                return array_filter([
                    'channel' => $channel,
                    'is_enabled' => true,
                    'message_template_id' => $existingChannel?->message_template_id ?: $templateId,
                    'subject_template' => $generated['subject_template'] ?? $existingChannel?->subject_template,
                    'title_template' => $generated['title_template'] ?? $existingChannel?->title_template,
                    'body_template' => $generated['body_template'] ?? $existingChannel?->body_template,
                    'metadata' => $metadata !== [] ? $metadata : null,
                ], fn ($value) => $value !== null);
            })
            ->values()
            ->all();

        return [
            'channels' => $channels,
            'template_scoring' => $templateScoring,
        ];
    }

    /**
     * @param  array<string, mixed>  $resolved
     * @return array<string, mixed>
     */
    private function buildGeneratedChannelTemplates(string $channel, array $resolved, User $accountOwner): array
    {
        $ctaUrl = $this->resolveCtaUrl($accountOwner, $resolved['offer'] ?? null, null) ?? '{ctaUrl}';
        $offerName = '{offerName}';
        $promoPercent = (string) (($resolved['offer']['promo_discount_percent'] ?? null) ?? '');
        $hasPromo = $promoPercent !== '' && $promoPercent !== '0';

        $lead = match ($resolved['campaign_type']) {
            Campaign::TYPE_WINBACK => "nous aimerions vous revoir. {$offerName} est pret pour vous.",
            Campaign::TYPE_NEW_OFFER => "decouvrez notre nouveaute {$offerName}.",
            Campaign::TYPE_BACK_AVAILABLE => "{$offerName} est de retour et disponible maintenant.",
            Campaign::TYPE_CROSS_SELL => "{$offerName} complete parfaitement votre prochaine visite.",
            Campaign::TYPE_ANNOUNCEMENT => "nous avons une annonce importante autour de {$offerName}.",
            default => $hasPromo
                ? "profitez de {$promoPercent}% sur {$offerName}."
                : "profitez de {$offerName} des maintenant.",
        };

        if ($channel === Campaign::CHANNEL_EMAIL) {
            return [
                'subject_template' => match ($resolved['campaign_type']) {
                    Campaign::TYPE_WINBACK => '{firstName}, on vous reserve quelque chose',
                    Campaign::TYPE_NEW_OFFER => '{firstName}, decouvrez {offerName}',
                    Campaign::TYPE_BACK_AVAILABLE => '{offerName} est de retour',
                    Campaign::TYPE_CROSS_SELL => '{firstName}, une idee pour completer votre experience',
                    Campaign::TYPE_ANNOUNCEMENT => 'Nouvelle annonce: {offerName}',
                    default => $hasPromo
                        ? '{firstName}, profitez de {promoPercent}% sur {offerName}'
                        : '{firstName}, offre speciale sur {offerName}',
                },
                'body_template' => implode('', [
                    '<p>Bonjour {firstName},</p>',
                    '<p>'.e(ucfirst($lead)).'</p>',
                    '<p>Voir l offre: <a href="'.e($ctaUrl).'">'.e($ctaUrl).'</a></p>',
                    '<p>A bientot.</p>',
                ]),
                'metadata' => [
                    'preview_text' => 'Campagne preparee par l assistant',
                ],
            ];
        }

        if ($channel === Campaign::CHANNEL_SMS) {
            $body = match ($resolved['campaign_type']) {
                Campaign::TYPE_WINBACK => 'Bonjour {firstName}, on aimerait vous revoir. {offerName} vous attend: '.$ctaUrl,
                Campaign::TYPE_BACK_AVAILABLE => '{offerName} est de retour. Voir: '.$ctaUrl,
                default => $hasPromo
                    ? '{offerName}: '.$promoPercent.'% maintenant. '.$ctaUrl
                    : '{offerName} est disponible. '.$ctaUrl,
            };

            return [
                'body_template' => $this->shortenSmsTemplate($body),
                'metadata' => [
                    'shortener' => true,
                ],
            ];
        }

        return [
            'title_template' => match ($resolved['campaign_type']) {
                Campaign::TYPE_WINBACK => 'On vous attend',
                Campaign::TYPE_NEW_OFFER => 'Nouveau: {offerName}',
                Campaign::TYPE_BACK_AVAILABLE => '{offerName} est de retour',
                default => 'Offre du moment',
            },
            'body_template' => ucfirst($lead).' Voir: '.$ctaUrl,
            'metadata' => [
                'deep_link' => $ctaUrl,
            ],
        ];
    }

    private function shortenSmsTemplate(string $body): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($body)) ?: '';

        return mb_strlen($normalized) > 155
            ? mb_substr($normalized, 0, 152).'...'
            : $normalized;
    }

    private function resolveCtaUrl(User $accountOwner, ?array $offer, ?Campaign $existingCampaign): ?string
    {
        if ($existingCampaign && trim((string) $existingCampaign->cta_url) !== '') {
            return (string) $existingCampaign->cta_url;
        }

        if (! $offer) {
            return null;
        }

        $slug = trim((string) ($accountOwner->company_slug ?: $accountOwner->id));

        return ($offer['offer_type'] ?? 'product') === 'service'
            ? route('public.showcase.show', ['slug' => $slug])
            : route('public.store.show', ['slug' => $slug]);
    }

    private function resolveTemplateSelection(
        array $campaignContext,
        string $channel,
        string $campaignType,
        string $locale
    ): array {
        $templates = collect($campaignContext['default_templates'] ?? [])
            ->filter(fn ($template) => is_array($template))
            ->map(fn (array $template): array => [
                'id' => (int) ($template['id'] ?? 0),
                'channel' => strtoupper(trim((string) ($template['channel'] ?? ''))),
                'campaign_type' => $template['campaign_type']
                    ? strtoupper(trim((string) $template['campaign_type']))
                    : null,
                'language' => $template['language']
                    ? strtoupper(trim((string) $template['language']))
                    : null,
                'updated_at' => $template['updated_at'] ?? null,
            ])
            ->filter(fn (array $template) => $template['id'] > 0 && $template['channel'] === $channel)
            ->values();
        if ($templates->isEmpty()) {
            return [
                'template_id' => null,
                'selected' => null,
                'scoring' => [],
            ];
        }

        $ranked = $this->campaignDraftScoringService->rankTemplates(
            $templates->all(),
            $channel,
            $campaignType,
            $locale,
            is_array($campaignContext['performance'] ?? null) ? $campaignContext['performance'] : []
        );

        return [
            'template_id' => isset($ranked[0]['id']) ? (int) $ranked[0]['id'] : null,
            'selected' => $ranked[0] ?? null,
            'scoring' => array_values($ranked),
        ];
    }

    /**
     * @param  array<string, mixed>  $resolved
     * @param  array<string, mixed>  $campaignContext
     * @return array<string, mixed>
     */
    private function finalizePreparedCampaign(
        Campaign $campaign,
        User $accountOwner,
        User $actor,
        array $resolved,
        array $campaignContext
    ): array {
        $estimatedCounts = $this->campaignService->estimateAudience($campaign);

        if (($estimatedCounts['total_eligible'] ?? 0) === 0
            && ($resolved['audience']['strategy'] ?? '') !== 'all_customers_fallback') {
            $resolved = $this->applyAudienceFallback($resolved);
            $campaign = $this->campaignService->saveCampaign(
                $accountOwner,
                $actor,
                $this->buildPayload($resolved, $campaignContext, $campaign, $accountOwner),
                $campaign
            );
            $estimatedCounts = $this->campaignService->estimateAudience($campaign);
        }

        if (($estimatedCounts['total_eligible'] ?? 0) === 0) {
            $feedback = $this->buildZeroAudienceFeedback($campaign, $resolved, $estimatedCounts);

            return [
                'status' => 'needs_input',
                'message' => $feedback['message'],
                'questions' => $feedback['questions'],
                'campaign' => $campaign,
                'resolved' => $resolved,
                'estimated_counts' => $estimatedCounts,
            ];
        }

        $previewSummary = $this->previewCampaign($campaign);
        if (($previewSummary['channels_to_regenerate'] ?? []) !== []) {
            $resolved['generated_copy_channels'] = array_values(array_unique(array_merge(
                $resolved['generated_copy_channels'] ?? [],
                $previewSummary['channels_to_regenerate']
            )));

            $campaign = $this->campaignService->saveCampaign(
                $accountOwner,
                $actor,
                $this->buildPayload($resolved, $campaignContext, $campaign, $accountOwner),
                $campaign
            );
            $previewSummary = $this->previewCampaign($campaign);
        }

        if (($previewSummary['invalid_channels'] ?? []) !== []) {
            return [
                'status' => 'needs_input',
                'message' => 'Le brouillon a ete cree, mais certains messages doivent encore etre verifies.',
                'questions' => ['Ouvrez le wizard campagne pour ajuster les messages signales.'],
                'campaign' => $campaign,
                'resolved' => $resolved,
                'estimated_counts' => $estimatedCounts,
                'preview_summary' => $previewSummary,
            ];
        }

        $this->persistAssistantDiagnostics($campaign, $estimatedCounts, $previewSummary);

        return [
            'status' => 'ready',
            'campaign' => $campaign->fresh(['offers.offer:id,name,item_type', 'channels', 'audience']),
            'resolved' => $resolved,
            'estimated_counts' => $estimatedCounts,
            'preview_summary' => $previewSummary,
        ];
    }

    /**
     * @param  array<string, mixed>  $resolved
     * @param  array<string, mixed>  $estimatedCounts
     * @return array{message: string, questions: array<int, string>}
     */
    private function buildZeroAudienceFeedback(Campaign $campaign, array $resolved, array $estimatedCounts): array
    {
        $enabledChannels = $campaign->channels()
            ->where('is_enabled', true)
            ->pluck('channel')
            ->map(fn ($channel) => $this->channelLabel((string) $channel))
            ->values()
            ->all();

        $channelSummary = match (count($enabledChannels)) {
            0 => 'le canal selectionne',
            1 => $enabledChannels[0],
            default => implode(', ', $enabledChannels),
        };

        $audienceTargetsAllCustomers = ($resolved['audience']['strategy'] ?? '') === 'all_customers_fallback';
        $message = $audienceTargetsAllCustomers
            ? sprintf('Le brouillon a ete cree, mais aucun client actuel n est eligible pour %s.', $channelSummary)
            : sprintf('Le brouillon a ete cree, mais aucun contact de l audience ciblee n est eligible pour %s.', $channelSummary);

        $blockedByReason = collect($estimatedCounts['blocked_by_reason'] ?? [])
            ->filter(fn ($count) => (int) $count > 0)
            ->sortDesc();

        if ($blockedByReason->isEmpty()) {
            return [
                'message' => $message,
                'questions' => ['Elargissez l audience ou choisissez un autre segment pour obtenir des destinataires eligibles.'],
            ];
        }

        $primaryReason = (string) $blockedByReason->keys()->first();
        $primaryCount = (int) $blockedByReason->first();

        $questions = match ($primaryReason) {
            'consent_missing' => [
                $audienceTargetsAllCustomers
                    ? sprintf(
                        'Vous ciblez deja tous les clients actuels, mais %d contacts sont bloques car aucun consentement marketing explicite n est enregistre pour %s.',
                        $primaryCount,
                        $channelSummary
                    )
                    : sprintf(
                        '%d contacts de cette audience sont bloques car aucun consentement marketing explicite n est enregistre pour %s.',
                        $primaryCount,
                        $channelSummary
                    ),
                sprintf('Accordez ou importez les consentements marketing pour %s, ou choisissez un autre canal deja autorise.', $channelSummary),
            ],
            'consent_revoked' => [
                sprintf('%d contacts ont retire leur consentement marketing pour %s.', $primaryCount, $channelSummary),
                sprintf('Choisissez un autre canal ou excluez ces contacts deja desabonnes de %s.', $channelSummary),
            ],
            'missing_destination' => [
                sprintf('%d contacts n ont pas de coordonnee utilisable pour %s.', $primaryCount, $channelSummary),
                sprintf('Completez les adresses ou numeros manquants, ou choisissez un canal different de %s.', $channelSummary),
            ],
            'missing_app_account' => [
                sprintf('%d contacts n ont pas de compte portail actif pour recevoir du In-app.', $primaryCount),
                'Activez un acces portail pour ces clients ou utilisez Email/SMS.',
            ],
            'quiet_hours' => [
                $audienceTargetsAllCustomers
                    ? sprintf(
                        'Vous ciblez deja tous les clients actuels, mais le creneau d envoi est dans les heures calmes pour %s.',
                        $channelSummary
                    )
                    : sprintf(
                        'Le creneau d envoi actuel est dans les heures calmes pour %s.',
                        $channelSummary
                    ),
                'Planifiez la campagne en dehors des quiet hours ou ajustez les heures calmes dans les parametres marketing.',
            ],
            'opted_out' => [
                sprintf('%d contacts se sont deja desabonnes du canal %s.', $primaryCount, $channelSummary),
                sprintf('Choisissez un autre canal ou acceptez que %s reste a zero pour ces contacts.', $channelSummary),
            ],
            default => [
                'Elargissez l audience ou choisissez un autre segment pour obtenir des destinataires eligibles.',
            ],
        };

        return [
            'message' => $message,
            'questions' => $questions,
        ];
    }

    /**
     * @param  array<string, mixed>  $resolved
     * @return array<string, mixed>
     */
    private function applyAudienceFallback(array $resolved): array
    {
        $resolved['audience'] = [
            'segment_id' => null,
            'smart_filters' => null,
            'exclusion_filters' => null,
            'include_mailing_list_ids' => [],
            'exclude_mailing_list_ids' => [],
            'source_logic' => CampaignAudienceSourceLogic::UNION->value,
            'summary' => 'Tous les clients eligibles',
            'strategy' => 'all_customers_fallback',
        ];

        return $resolved;
    }

    /**
     * @return array<string, mixed>
     */
    private function previewCampaign(Campaign $campaign): array
    {
        $campaign->loadMissing(['channels', 'offers.offer', 'products', 'user']);
        $product = $campaign->offers->first()?->offer ?: $campaign->products->first();
        $context = $this->templateRenderer->buildContext($campaign, null, $product);

        $invalidChannels = [];
        $channelsToRegenerate = [];

        foreach ($campaign->channels->where('is_enabled', true) as $channelModel) {
            $rendered = $this->templateRenderer->renderChannel($channelModel, $context);
            $channel = strtoupper((string) $channelModel->channel);
            $hasInvalidTokens = ($rendered['invalid_tokens'] ?? []) !== [];
            $smsTooLong = (bool) ($rendered['sms_too_long'] ?? false);
            $missingSubject = $channel === Campaign::CHANNEL_EMAIL
                && trim((string) ($rendered['subject'] ?? '')) === '';
            $missingBody = trim((string) ($rendered['body'] ?? '')) === '';

            if ($hasInvalidTokens || $smsTooLong || $missingSubject || $missingBody) {
                $invalidChannels[] = $channel;
            }

            if ($hasInvalidTokens || $smsTooLong || $missingSubject || $missingBody) {
                $channelsToRegenerate[] = $channel;
            }
        }

        return [
            'invalid_channels' => array_values(array_unique($invalidChannels)),
            'channels_to_regenerate' => array_values(array_unique($channelsToRegenerate)),
            'validated' => $invalidChannels === [],
        ];
    }

    /**
     * @param  array<string, mixed>  $estimatedCounts
     * @param  array<string, mixed>  $previewSummary
     */
    private function persistAssistantDiagnostics(
        Campaign $campaign,
        array $estimatedCounts,
        array $previewSummary
    ): void {
        $settings = is_array($campaign->settings) ? $campaign->settings : [];
        $assistant = is_array($settings['assistant'] ?? null) ? $settings['assistant'] : [];
        $assistant['estimated_counts'] = $estimatedCounts;
        $assistant['preview_validation'] = [
            'validated' => (bool) ($previewSummary['validated'] ?? false),
            'invalid_channels' => array_values($previewSummary['invalid_channels'] ?? []),
        ];
        $settings['assistant'] = $assistant;

        $campaign->forceFill(['settings' => $settings])->save();
    }

    /**
     * @param  array<string, mixed>  $resolved
     */
    private function buildContextDraft(array $resolved): array
    {
        return [
            'objective' => (string) ($resolved['brief']['objective'] ?? ''),
            'campaign_type' => (string) ($resolved['campaign_type'] ?? ''),
            'offer_hint' => (string) ($resolved['offer']['name'] ?? $resolved['brief']['offer_hint'] ?? ''),
            'offer_mode_hint' => (string) ($resolved['offer_mode'] ?? ''),
            'audience_hint' => (string) ($resolved['audience']['summary'] ?? $resolved['brief']['audience_hint'] ?? ''),
            'timing_hint' => (string) ($resolved['schedule']['label'] ?? $resolved['brief']['timing_hint'] ?? ''),
            'channel_hints' => array_values($resolved['channels'] ?? []),
            'kpi_hint' => (string) ($resolved['kpi_focus'] ?? ''),
            'language_hint' => (string) ($resolved['language_mode'] ?? ''),
            'name_hint' => (string) ($resolved['name'] ?? ''),
            'notes' => (string) ($resolved['brief']['notes'] ?? ''),
        ];
    }

    private function buildSuccessMessage(
        Campaign $campaign,
        array $resolved,
        string $status,
        array $estimatedCounts = [],
        array $previewSummary = []
    ): string {
        $eligible = (int) ($estimatedCounts['total_eligible'] ?? 0);
        $verb = $status === 'updated' ? 'mis a jour' : 'cree';
        $previewLabel = (bool) ($previewSummary['validated'] ?? false) ? 'valides' : 'a verifier';

        return sprintf(
            'Brouillon campagne %s. %d contacts eligibles estimes et messages %s. Verifiez la synthese puis ouvrez le brouillon si besoin.',
            $verb,
            $eligible,
            $previewLabel
        );
    }

    /**
     * @param  array<string, mixed>  $resolved
     * @param  array<string, mixed>  $estimatedCounts
     * @param  array<string, mixed>  $previewSummary
     * @return array<string, mixed>
     */
    private function buildCampaignReview(
        Campaign $campaign,
        array $resolved,
        string $status,
        array $estimatedCounts = [],
        array $previewSummary = []
    ): array {
        $campaign->loadMissing(['audienceSegment:id,name']);
        $assistantSettings = is_array(data_get($campaign->settings, 'assistant')) ? data_get($campaign->settings, 'assistant') : [];

        $channels = collect($resolved['channels'] ?? [])
            ->map(fn ($channel) => $this->channelLabel((string) $channel))
            ->filter()
            ->values()
            ->all();
        $generatedCopyChannels = collect($resolved['generated_copy_channels'] ?? [])
            ->map(fn ($channel) => $this->channelLabel((string) $channel))
            ->filter()
            ->values()
            ->all();
        $eligible = (int) ($estimatedCounts['total_eligible'] ?? 0);
        $offerName = trim((string) ($resolved['offer']['name'] ?? ''));
        $audienceSummary = trim((string) ($resolved['audience']['summary'] ?? 'Tous les clients eligibles'));
        $scheduleLabel = trim((string) ($resolved['schedule']['label'] ?? 'Brouillon manuel'));
        $brief = is_array($resolved['brief'] ?? null) ? $resolved['brief'] : [];

        $deduced = [];
        if (! $this->hasExplicitText($brief['campaign_type'] ?? '')) {
            $deduced[] = [
                'label' => 'Type de campagne',
                'value' => $this->campaignTypeLabel((string) ($resolved['campaign_type'] ?? '')),
                'reason' => 'Deduit automatiquement depuis l objectif marketing.',
            ];
        }
        if (($brief['channel_hints'] ?? []) === []) {
            $deduced[] = [
                'label' => 'Canaux recommandes',
                'value' => $channels !== [] ? implode(', ', $channels) : 'A completer',
                'reason' => $this->channelReviewReason($resolved, $assistantSettings),
            ];
        }
        if (! $this->hasExplicitText($brief['offer_mode_hint'] ?? '')) {
            $deduced[] = [
                'label' => 'Mode d offre',
                'value' => $this->offerModeLabel((string) ($resolved['offer_mode'] ?? '')),
                'reason' => 'Deduit depuis l offre retenue et le contexte du compte.',
            ];
        }
        if (! $this->hasExplicitText($brief['kpi_hint'] ?? '')) {
            $deduced[] = [
                'label' => 'KPI principal',
                'value' => $this->kpiLabel((string) ($resolved['kpi_focus'] ?? '')),
                'reason' => 'Aligne sur le resultat attendu pour cette demande.',
            ];
        }

        $proposed = [
            [
                'label' => 'Nom de campagne',
                'value' => (string) $campaign->name,
                'reason' => $this->hasExplicitText($brief['name_hint'] ?? '')
                    ? 'Repris depuis votre consigne.'
                    : 'Genere automatiquement pour rester coherent avec l objectif, l offre et le planning.',
            ],
            [
                'label' => 'Offre retenue',
                'value' => $offerName !== '' ? $offerName : 'A completer',
                'reason' => $this->offerReviewReason($resolved),
            ],
            [
                'label' => 'Audience',
                'value' => $eligible > 0 ? sprintf('%s (%d eligibles)', $audienceSummary, $eligible) : $audienceSummary,
                'reason' => $this->audienceReviewReason($resolved, $campaign),
            ],
            [
                'label' => 'Planning',
                'value' => $scheduleLabel,
                'reason' => $this->scheduleReviewReason($resolved),
            ],
            [
                'label' => 'Messages',
                'value' => (bool) ($previewSummary['validated'] ?? false)
                    ? 'Messages verifies'
                    : 'Messages a verifier',
                'reason' => $this->messageReviewReason($generatedCopyChannels, $previewSummary, $assistantSettings),
            ],
        ];

        $needsConfirmation = [];
        if (! $this->hasExplicitText($brief['offer_hint'] ?? '') && $offerName !== '') {
            $needsConfirmation[] = [
                'label' => 'Offre selectionnee',
                'value' => $offerName,
                'reason' => 'Confirmez que cette offre est bien celle a pousser avant l envoi.',
            ];
        }
        if (($resolved['audience']['strategy'] ?? '') === 'all_customers_fallback') {
            $needsConfirmation[] = [
                'label' => 'Audience elargie',
                'value' => $audienceSummary,
                'reason' => 'Le ciblage initial etait vide, le brouillon a ete elargi a tous les clients eligibles.',
            ];
        }
        if ($this->hasExplicitText($brief['timing_hint'] ?? '')
            && (string) ($resolved['schedule']['schedule_type'] ?? Campaign::SCHEDULE_MANUAL) === Campaign::SCHEDULE_SCHEDULED) {
            $needsConfirmation[] = [
                'label' => 'Date de diffusion',
                'value' => $scheduleLabel,
                'reason' => 'La date a ete deduite a partir de votre expression temporelle.',
            ];
        }
        if ($generatedCopyChannels !== []) {
            $needsConfirmation[] = [
                'label' => 'Messages generes',
                'value' => implode(', ', $generatedCopyChannels),
                'reason' => 'Le contenu a ete genere automatiquement. Une verification rapide est recommandee.',
            ];
        }

        return [
            'type' => 'campaign_review',
            'campaign_id' => $campaign->id,
            'title' => (string) $campaign->name,
            'subtitle' => $status === 'updated'
                ? 'Brouillon campagne mis a jour et pret a etre ajuste.'
                : 'Brouillon campagne prepare et directement modifiable.',
            'status' => $status,
            'status_label' => $status === 'updated' ? 'Mis a jour' : 'Pret',
            'summary' => [
                [
                    'label' => 'Type',
                    'value' => $this->campaignTypeLabel((string) $campaign->campaign_type),
                ],
                [
                    'label' => 'Canaux',
                    'value' => $channels !== [] ? implode(', ', $channels) : 'A completer',
                ],
                [
                    'label' => 'Audience estimee',
                    'value' => $eligible > 0 ? $eligible.' contacts' : 'A confirmer',
                ],
                [
                    'label' => 'KPI principal',
                    'value' => $this->kpiLabel((string) ($resolved['kpi_focus'] ?? '')),
                ],
            ],
            'deduced' => $deduced,
            'proposed' => $proposed,
            'needs_confirmation' => $needsConfirmation,
            'next_steps' => [
                [
                    'type' => 'open_campaign_draft',
                    'label' => 'Ouvrir le brouillon',
                    'campaign_id' => $campaign->id,
                ],
                [
                    'type' => 'preview_campaign',
                    'label' => 'Voir un apercu',
                    'campaign_id' => $campaign->id,
                    'channels' => array_values($resolved['channels'] ?? []),
                ],
                [
                    'type' => 'test_send_campaign',
                    'label' => 'Envoyer un test',
                    'campaign_id' => $campaign->id,
                    'channels' => array_values($resolved['channels'] ?? []),
                ],
                [
                    'type' => 'view_campaign',
                    'label' => 'Voir la fiche',
                    'campaign_id' => $campaign->id,
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $resolved
     */
    private function audienceReviewReason(array $resolved, Campaign $campaign): string
    {
        if (($resolved['audience']['strategy'] ?? '') === 'segment') {
            $segmentName = trim((string) $campaign->audienceSegment?->name);

            return $segmentName !== ''
                ? 'Segment existant reutilise automatiquement: '.$segmentName.'.'
                : 'Segment existant reutilise automatiquement.';
        }

        if (($resolved['audience']['strategy'] ?? '') === 'all_customers_fallback') {
            return 'Le ciblage a ete elargi automatiquement pour eviter une audience vide.';
        }

        return 'Filtres intelligents prepares a partir de l intention marketing.';
    }

    /**
     * @param  array<string, mixed>  $resolved
     * @param  array<string, mixed>  $assistantSettings
     */
    private function channelReviewReason(array $resolved, array $assistantSettings): string
    {
        $channelScoring = collect(data_get($assistantSettings, 'scoring.channels', []));
        $usedHistory = $channelScoring->contains(function ($entry): bool {
            return collect($entry['reasons'] ?? [])->contains(fn ($reason) => str_contains((string) $reason, 'historical_'));
        });

        if ($usedHistory) {
            return 'Selectionnes selon les canaux actifs et les meilleures performances observees sur des campagnes similaires.';
        }

        return 'Selectionnes selon les canaux actifs et la nature de la campagne.';
    }

    /**
     * @param  array<string, mixed>  $resolved
     */
    private function offerReviewReason(array $resolved): string
    {
        if ($this->hasExplicitText($resolved['brief']['offer_hint'] ?? '')) {
            return 'Offre reprise depuis votre demande.';
        }

        $assistantReason = (string) ($resolved['offer']['assistant_reason'] ?? '');
        if (in_array($assistantReason, ['historical_offer_performance', 'historical_offer_performance_global'], true)) {
            return 'Offre retenue car elle a mieux performe sur des campagnes similaires.';
        }

        return 'Offre active la plus pertinente retenue automatiquement.';
    }

    /**
     * @param  array<string, mixed>  $resolved
     */
    private function scheduleReviewReason(array $resolved): string
    {
        if ((string) ($resolved['schedule']['schedule_type'] ?? Campaign::SCHEDULE_MANUAL) === Campaign::SCHEDULE_SCHEDULED) {
            $timingHint = trim((string) ($resolved['brief']['timing_hint'] ?? ''));

            return $timingHint !== ''
                ? 'Creneau deduit depuis "'.$timingHint.'".'
                : 'Creneau programme automatiquement.';
        }

        return 'Le brouillon reste manuel tant que vous ne lancez pas la diffusion.';
    }

    /**
     * @param  array<int, string>  $generatedCopyChannels
     * @param  array<string, mixed>  $previewSummary
     */
    private function messageReviewReason(array $generatedCopyChannels, array $previewSummary, array $assistantSettings = []): string
    {
        if ($generatedCopyChannels !== []) {
            return 'Contenu genere automatiquement pour '.implode(', ', $generatedCopyChannels).' puis valide en preview.';
        }

        $templateHistoryUsed = collect(data_get($assistantSettings, 'scoring.templates', []))
            ->flatten(1)
            ->contains(function ($entry): bool {
                return collect($entry['reasons'] ?? [])->contains(fn ($reason) => str_contains((string) $reason, 'historical_template_'));
            });

        if ($templateHistoryUsed) {
            return 'Templates existants appliques en priorisant ceux qui ont le mieux performe historiquement.';
        }

        return (bool) ($previewSummary['validated'] ?? false)
            ? 'Templates existants appliques et verifies automatiquement.'
            : 'Une verification manuelle reste conseillee.';
    }

    private function campaignTypeLabel(string $campaignType): string
    {
        return match (strtoupper($campaignType)) {
            Campaign::TYPE_WINBACK => 'Winback',
            Campaign::TYPE_NEW_OFFER => 'Nouveau service ou produit',
            Campaign::TYPE_BACK_AVAILABLE => 'Retour disponible',
            Campaign::TYPE_CROSS_SELL => 'Cross-sell',
            Campaign::TYPE_ANNOUNCEMENT => 'Annonce',
            Campaign::TYPE_PROMOTION => 'Promotion',
            default => $campaignType !== '' ? $campaignType : 'A completer',
        };
    }

    private function offerModeLabel(string $offerMode): string
    {
        return match (strtoupper($offerMode)) {
            Campaign::OFFER_MODE_PRODUCTS => 'Produits',
            Campaign::OFFER_MODE_SERVICES => 'Services',
            Campaign::OFFER_MODE_MIXED => 'Mixte',
            default => $offerMode !== '' ? $offerMode : 'A completer',
        };
    }

    private function kpiLabel(string $kpiFocus): string
    {
        return match (strtolower($kpiFocus)) {
            'reservations' => 'Reservations',
            'quotes' => 'Devis convertis',
            'revenue' => 'Revenus',
            'reactivated_customers' => 'Clients reactives',
            'click_rate' => 'Taux de clic',
            default => $kpiFocus !== '' ? $kpiFocus : 'A definir',
        };
    }

    private function channelLabel(string $channel): string
    {
        return match (strtoupper($channel)) {
            Campaign::CHANNEL_EMAIL => 'Email',
            Campaign::CHANNEL_SMS => 'SMS',
            Campaign::CHANNEL_IN_APP => 'In-app',
            default => $channel,
        };
    }

    private function hasExplicitText(mixed $value): bool
    {
        return trim((string) $value) !== '';
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<int, array<string, mixed>>
     */
    private function compactScoringEntries(array $entries, string $primaryKey): array
    {
        return collect($entries)
            ->filter(fn ($entry) => is_array($entry))
            ->take(4)
            ->map(function (array $entry) use ($primaryKey): array {
                return array_filter([
                    $primaryKey => $entry[$primaryKey] ?? null,
                    'name' => $entry['name'] ?? null,
                    'score' => isset($entry['score']) ? round((float) $entry['score'], 2) : null,
                    'reasons' => array_values($entry['score_reasons'] ?? $entry['reasons'] ?? []),
                ], fn ($value) => $value !== null && $value !== []);
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $templateScoring
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function compactTemplateScoring(array $templateScoring): array
    {
        return collect($templateScoring)
            ->map(fn (array $entries) => $this->compactScoringEntries($entries, 'id'))
            ->filter(fn (array $entries) => $entries !== [])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $resolved
     * @param  array<string, array<int, array<string, mixed>>>  $templateScoring
     */
    private function historyWasUsed(array $resolved, array $templateScoring): bool
    {
        $offerHistory = collect($resolved['scoring']['offers'] ?? [])
            ->contains(fn ($entry) => collect($entry['score_reasons'] ?? [])->contains('historical_offer_performance'));
        $channelHistory = collect($resolved['scoring']['channels'] ?? [])
            ->contains(fn ($entry) => collect($entry['reasons'] ?? [])->contains(fn ($reason) => str_contains((string) $reason, 'historical_')));
        $templateHistory = collect($templateScoring)
            ->flatten(1)
            ->contains(fn ($entry) => collect($entry['score_reasons'] ?? [])->contains(fn ($reason) => str_contains((string) $reason, 'historical_template_')));

        return $offerHistory || $channelHistory || $templateHistory;
    }

    /**
     * @param  array<string, mixed>  $resolved
     * @return array<string, mixed>
     */
    private function validationNeedsInput(
        ValidationException $exception,
        array $resolved,
        ?Campaign $existingCampaign
    ): array {
        $questions = collect($exception->errors())
            ->flatten()
            ->map(fn ($message) => trim((string) $message))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($questions === []) {
            $questions = ['Je ne peux pas finaliser ce brouillon. Merci de preciser l offre, l audience ou les canaux.'];
        }

        return [
            'status' => 'needs_input',
            'message' => 'Le brouillon campagne ne peut pas etre finalise avec les informations actuelles.',
            'questions' => $questions,
            'context' => [
                'intent' => 'draft_campaign',
                'campaign' => $this->buildContextDraft($resolved),
                'campaign_draft_id' => $existingCampaign?->id,
            ],
        ];
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function stringSimilarity(string $left, string $right): float
    {
        if ($left === '' || $right === '') {
            return 0.0;
        }

        if ($left === $right) {
            return 1.0;
        }

        $maxLength = max(strlen($left), strlen($right));
        if ($maxLength === 0) {
            return 0.0;
        }

        $distance = levenshtein($left, $right);
        $score = 1 - ($distance / $maxLength);

        return max(0.0, min(1.0, $score));
    }
}
