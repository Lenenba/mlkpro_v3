<?php

namespace App\Http\Requests\Campaigns;

use App\Enums\CampaignAudienceSourceLogic;
use App\Models\Campaign;
use App\Models\MessageTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'campaign_type' => ['nullable', Rule::in(Campaign::allowedTypes())],
            'type' => ['nullable', Rule::in(Campaign::allowedTypes())],
            'offer_mode' => ['nullable', Rule::in(Campaign::allowedOfferModes())],
            'language_mode' => ['nullable', Rule::in(Campaign::allowedLanguageModes())],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer'],
            'offers' => ['nullable', 'array', 'min:1'],
            'offers.*.offer_type' => ['required_with:offers', Rule::in(['product', 'service'])],
            'offers.*.offer_id' => ['required_with:offers', 'integer'],
            'offers.*.metadata' => ['nullable', 'array'],
            'offer_selectors' => ['nullable', 'array'],
            'offer_selectors.category_ids' => ['nullable', 'array'],
            'offer_selectors.category_ids.*' => ['integer'],
            'offer_selectors.tags' => ['nullable', 'array'],
            'offer_selectors.tags.*' => ['string', 'max:60'],
            'schedule_type' => ['required', Rule::in([
                Campaign::SCHEDULE_MANUAL,
                Campaign::SCHEDULE_SCHEDULED,
                Campaign::SCHEDULE_AUTOMATION,
            ])],
            'scheduled_at' => ['nullable', 'date'],
            'locale' => ['nullable', 'string', 'max:10'],
            'cta_url' => ['nullable', 'url', 'max:1024'],
            'audience_segment_id' => ['nullable', 'integer'],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*.channel' => ['required', Rule::in(Campaign::allowedChannels())],
            'channels.*.is_enabled' => ['nullable', 'boolean'],
            'channels.*.subject_template' => ['nullable', 'string', 'max:255'],
            'channels.*.title_template' => ['nullable', 'string', 'max:255'],
            'channels.*.body_template' => ['nullable', 'string'],
            'channels.*.message_template_id' => [
                'nullable',
                'integer',
                Rule::exists(MessageTemplate::class, 'id'),
            ],
            'channels.*.content_override' => ['nullable', 'array'],
            'channels.*.metadata' => ['nullable', 'array'],
            'channels.*.metadata.ab_testing' => ['nullable', 'array'],
            'channels.*.metadata.ab_testing.enabled' => ['nullable', 'boolean'],
            'channels.*.metadata.ab_testing.split_a_percent' => ['nullable', 'integer', 'min:1', 'max:99'],
            'channels.*.metadata.ab_testing.variant_a' => ['nullable', 'array'],
            'channels.*.metadata.ab_testing.variant_a.subject_template' => ['nullable', 'string', 'max:255'],
            'channels.*.metadata.ab_testing.variant_a.title_template' => ['nullable', 'string', 'max:255'],
            'channels.*.metadata.ab_testing.variant_a.body_template' => ['nullable', 'string'],
            'channels.*.metadata.ab_testing.variant_b' => ['nullable', 'array'],
            'channels.*.metadata.ab_testing.variant_b.subject_template' => ['nullable', 'string', 'max:255'],
            'channels.*.metadata.ab_testing.variant_b.title_template' => ['nullable', 'string', 'max:255'],
            'channels.*.metadata.ab_testing.variant_b.body_template' => ['nullable', 'string'],
            'audience' => ['nullable', 'array'],
            'audience.smart_filters' => ['nullable', 'array'],
            'audience.exclusion_filters' => ['nullable', 'array'],
            'audience.manual_customer_ids' => ['nullable', 'array'],
            'audience.manual_customer_ids.*' => ['integer'],
            'audience.include_mailing_list_ids' => ['nullable', 'array'],
            'audience.include_mailing_list_ids.*' => ['integer'],
            'audience.exclude_mailing_list_ids' => ['nullable', 'array'],
            'audience.exclude_mailing_list_ids.*' => ['integer'],
            'audience.source_logic' => ['nullable', Rule::in(CampaignAudienceSourceLogic::values())],
            'audience.source_summary' => ['nullable', 'array'],
            'audience.manual_contacts' => ['nullable'],
            'audience.estimated_counts' => ['nullable', 'array'],
            'settings' => ['nullable', 'array'],
            'settings.holdout' => ['nullable', 'array'],
            'settings.holdout.enabled' => ['nullable', 'boolean'],
            'settings.holdout.percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'settings.channel_fallback' => ['nullable', 'array'],
            'settings.channel_fallback.enabled' => ['nullable', 'boolean'],
            'settings.channel_fallback.max_depth' => ['nullable', 'integer', 'min:1', 'max:3'],
            'settings.channel_fallback.map' => ['nullable', 'array'],
            'settings.channel_fallback.map.*' => ['nullable', 'array'],
            'settings.channel_fallback.map.*.*' => ['string', Rule::in(Campaign::allowedChannels())],
        ];
    }

    protected function prepareForValidation(): void
    {
        $campaignType = $this->input('campaign_type');
        if ($campaignType === null || trim((string) $campaignType) === '') {
            $legacy = $this->input('type');
            if ($legacy !== null && trim((string) $legacy) !== '') {
                $campaignType = $legacy;
            }
        }

        $offerMode = $this->input('offer_mode');
        if ($offerMode === null || trim((string) $offerMode) === '') {
            $offers = $this->input('offers');
            if (is_array($offers) && $offers !== []) {
                $types = collect($offers)
                    ->map(fn ($offer) => strtolower((string) ($offer['offer_type'] ?? '')))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                if (count($types) > 1) {
                    $offerMode = Campaign::OFFER_MODE_MIXED;
                } elseif (($types[0] ?? null) === 'service') {
                    $offerMode = Campaign::OFFER_MODE_SERVICES;
                } else {
                    $offerMode = Campaign::OFFER_MODE_PRODUCTS;
                }
            } elseif (is_array($this->input('product_ids')) && $this->input('product_ids') !== []) {
                $offerMode = Campaign::OFFER_MODE_PRODUCTS;
            }
        }

        $this->merge(array_filter([
            'campaign_type' => $campaignType ? strtoupper((string) $campaignType) : null,
            'offer_mode' => $offerMode ? strtoupper((string) $offerMode) : null,
            'language_mode' => $this->input('language_mode')
                ? strtoupper((string) $this->input('language_mode'))
                : null,
            'audience' => $this->normalizedAudience(),
        ], fn ($value) => $value !== null));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizedAudience(): ?array
    {
        $audience = $this->input('audience');
        if (! is_array($audience)) {
            return null;
        }

        if (! array_key_exists('source_logic', $audience)) {
            return $audience;
        }

        $audience['source_logic'] = CampaignAudienceSourceLogic::normalize(
            (string) ($audience['source_logic'] ?? '')
        )->value;

        return $audience;
    }
}
