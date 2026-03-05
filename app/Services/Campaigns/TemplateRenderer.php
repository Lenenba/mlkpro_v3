<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignChannel;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Support\Arr;

class TemplateRenderer
{
    private const TOKEN_PATTERN = '/\{([a-zA-Z][a-zA-Z0-9\?]*)\}/';

    private const ALLOWED_TOKENS = [
        'firstName',
        'lastName',
        'companyName',
        'lastOrderDate',
        'city',
        'preferredLanguage',
        'offerName',
        'offerPrice',
        'offerUrl',
        'offerImageUrl',
        'offerAvailability',
        'offerType',
        'productName',
        'productPrice',
        'promoPercent',
        'promoCode',
        'promoEndDate',
        'productUrl',
        'productImageUrl',
        'stockLevel?',
        'campaignName',
        'ctaUrl',
    ];

    public function allowedTokens(): array
    {
        return self::ALLOWED_TOKENS;
    }

    public function validateTemplate(?string $template): array
    {
        $raw = (string) $template;
        if ($raw === '') {
            return [];
        }

        preg_match_all(self::TOKEN_PATTERN, $raw, $matches);
        $tokens = array_values(array_unique($matches[1] ?? []));

        return array_values(array_filter($tokens, function (string $token): bool {
            return !in_array($token, self::ALLOWED_TOKENS, true);
        }));
    }

    public function render(?string $template, array $context, bool $escapeForHtml = false): string
    {
        $raw = (string) $template;
        if ($raw === '') {
            return '';
        }

        return (string) preg_replace_callback(
            self::TOKEN_PATTERN,
            function (array $matches) use ($context, $escapeForHtml): string {
                $token = (string) ($matches[1] ?? '');
                $value = (string) ($context[$token] ?? '');

                if ($escapeForHtml) {
                    return e($value);
                }

                return strip_tags($value);
            },
            $raw
        );
    }

    public function renderChannel(CampaignChannel $channel, array $context): array
    {
        $subject = $this->render($channel->subject_template, $context, false);
        $title = $this->render($channel->title_template, $context, false);
        $body = $this->render(
            $channel->body_template,
            $context,
            strtoupper((string) $channel->channel) === Campaign::CHANNEL_EMAIL
        );

        $invalid = array_values(array_unique(array_merge(
            $this->validateTemplate($channel->subject_template),
            $this->validateTemplate($channel->title_template),
            $this->validateTemplate($channel->body_template)
        )));

        $characterCount = mb_strlen($body);
        $segmentLength = max(1, (int) config('campaigns.sms.segment_length', 160));
        $segments = (int) ceil($characterCount / $segmentLength);
        $maxSegments = max(1, (int) config('campaigns.sms.max_segments', 2));

        return [
            'subject' => $subject,
            'title' => $title,
            'body' => $body,
            'invalid_tokens' => $invalid,
            'character_count' => $characterCount,
            'sms_segments' => $segments,
            'sms_too_long' => $segments > $maxSegments,
        ];
    }

    public function buildContext(
        Campaign $campaign,
        ?Customer $customer = null,
        ?Product $product = null,
        array $extra = []
    ): array {
        $lastOrderDate = null;
        if ($customer) {
            $lastSale = Sale::query()
                ->where('user_id', $campaign->user_id)
                ->where('customer_id', $customer->id)
                ->where('status', Sale::STATUS_PAID)
                ->latest('created_at')
                ->first(['created_at']);

            $lastOrderDate = $lastSale?->created_at?->toDateString();
        }

        $city = null;
        if ($customer) {
            $city = $customer->relationLoaded('defaultProperty')
                ? $customer->defaultProperty?->city
                : $customer->defaultProperty()->value('city');
        }

        $offer = $product;

        $promoPercent = Arr::get($campaign->settings ?? [], 'promo_percent');
        if ($promoPercent === null && $offer) {
            $promoPercent = $offer->promo_discount_percent;
        }

        $promoCode = Arr::get($campaign->settings ?? [], 'promo_code');
        $promoEndDate = Arr::get($campaign->settings ?? [], 'promo_end_date');
        $offerUrl = null;
        if ($offer) {
            $slug = $campaign->user?->company_slug ?: $campaign->user_id;
            if ($offer->item_type === Product::ITEM_TYPE_SERVICE) {
                $offerUrl = route('public.showcase.show', ['slug' => $slug]);
            } else {
                $offerUrl = route('public.store.show', ['slug' => $slug]);
            }
        }

        $offerAvailability = '';
        if ($offer) {
            if ($offer->item_type === Product::ITEM_TYPE_SERVICE) {
                $offerAvailability = $offer->is_active ? 'bookable' : 'unavailable';
            } else {
                $offerAvailability = ((int) $offer->stock > 0) ? 'in_stock' : 'out_of_stock';
            }
        }

        $base = [
            'firstName' => (string) ($customer?->first_name ?? ''),
            'lastName' => (string) ($customer?->last_name ?? ''),
            'companyName' => (string) ($customer?->company_name ?? ''),
            'lastOrderDate' => (string) ($lastOrderDate ?? ''),
            'city' => (string) ($city ?? ''),
            'preferredLanguage' => (string) ($customer?->portalUser?->locale ?? $campaign->locale ?? 'en'),
            'offerName' => (string) ($offer?->name ?? ''),
            'offerPrice' => $offer ? (string) $offer->price : '',
            'offerUrl' => (string) ($offerUrl ?? ''),
            'offerImageUrl' => (string) ($offer?->image_url ?? ''),
            'offerAvailability' => $offerAvailability,
            'offerType' => (string) ($offer?->item_type ?? ''),
            'productName' => (string) ($offer?->name ?? ''),
            'productPrice' => $offer ? (string) $offer->price : '',
            'promoPercent' => $promoPercent !== null ? (string) $promoPercent : '',
            'promoCode' => (string) ($promoCode ?? ''),
            'promoEndDate' => (string) ($promoEndDate ?? ''),
            'productUrl' => (string) ($offerUrl ?? ''),
            'productImageUrl' => (string) ($offer?->image_url ?? ''),
            'stockLevel?' => $offer ? (string) ($offer->stock ?? '') : '',
            'campaignName' => (string) ($campaign->name ?? ''),
            'ctaUrl' => (string) ($campaign->cta_url ?? ''),
        ];

        return array_merge($base, $extra);
    }
}
