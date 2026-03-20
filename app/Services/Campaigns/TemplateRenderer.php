<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignChannel;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
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
        'campaignId',
        'ctaUrl',
        'trackedCtaUrl',
        'unsubscribeUrl',
        'customerFullName',
        'contactName',
        'appointmentDate',
        'appointmentTime',
        'appointmentLocation',
        'amount',
        'amountFormatted',
        'discountAmount',
        'discountPercent',
        'discountCode',
        'expiryDate',
        'serviceName',
        'serviceCategory',
        'brandName',
        'brandTagline',
        'brandDescription',
        'brandLogoUrl',
        'brandWebsiteUrl',
        'brandBookingUrl',
        'brandSupportUrl',
        'brandContactUrl',
        'brandContactEmail',
        'brandReplyToEmail',
        'brandPhone',
        'brandAddressLine1',
        'brandAddressLine2',
        'brandCity',
        'brandProvince',
        'brandCountry',
        'brandPostalCode',
        'brandAddress',
        'brandPrimaryColor',
        'brandSecondaryColor',
        'brandAccentColor',
        'brandSurfaceColor',
        'brandHeroBackgroundColor',
        'brandFooterBackgroundColor',
        'brandTextColor',
        'brandMutedColor',
        'brandFacebookUrl',
        'brandInstagramUrl',
        'brandLinkedinUrl',
        'brandYoutubeUrl',
        'brandTiktokUrl',
        'brandWhatsappUrl',
        'brandFooterNote',
        'companyBrandName',
        'companyLogoUrl',
        'companyWebsiteUrl',
        'companyContactEmail',
        'companyPhone',
        'companyAddress',
    ];

    public function __construct(
        private readonly BrandProfileService $brandProfileService,
    ) {}

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
            return ! in_array($token, self::ALLOWED_TOKENS, true);
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
        $isEmailChannel = strtoupper((string) $channel->channel) === Campaign::CHANNEL_EMAIL;
        $subject = $this->render($channel->subject_template, $context, false);
        $title = $this->render($channel->title_template, $context, false);
        $body = $this->render(
            $channel->body_template,
            $context,
            $isEmailChannel
        );
        if (! $isEmailChannel) {
            $body = $this->plainTextFromHtml($body);
        }

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

    private function plainTextFromHtml(string $html): string
    {
        $text = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $html) ?? $html;
        $text = preg_replace('/<\/p>/i', "\n\n", $text) ?? $text;
        $text = preg_replace('/<\/div>/i', "\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
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

        $accountOwner = $campaign->relationLoaded('user')
            ? $campaign->user
            : User::query()->find($campaign->user_id);
        $brandTokens = $accountOwner
            ? $this->brandProfileService->tokenMap($accountOwner)
            : [];

        $base = [
            'firstName' => (string) ($customer?->first_name ?? ''),
            'lastName' => (string) ($customer?->last_name ?? ''),
            'customerFullName' => trim(implode(' ', array_filter([
                (string) ($customer?->first_name ?? ''),
                (string) ($customer?->last_name ?? ''),
            ]))),
            'contactName' => trim(implode(' ', array_filter([
                (string) ($customer?->first_name ?? ''),
                (string) ($customer?->last_name ?? ''),
            ]))),
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
            'campaignId' => (string) ($campaign->id ?? ''),
            'ctaUrl' => (string) ($campaign->cta_url ?? ''),
            'trackedCtaUrl' => (string) ($campaign->cta_url ?? ''),
            'unsubscribeUrl' => '',
            'appointmentDate' => (string) Arr::get($campaign->settings ?? [], 'appointment_date', ''),
            'appointmentTime' => (string) Arr::get($campaign->settings ?? [], 'appointment_time', ''),
            'appointmentLocation' => (string) Arr::get($campaign->settings ?? [], 'appointment_location', ''),
            'amount' => (string) Arr::get($campaign->settings ?? [], 'amount', ''),
            'amountFormatted' => (string) Arr::get($campaign->settings ?? [], 'amount_formatted', ''),
            'discountAmount' => (string) Arr::get($campaign->settings ?? [], 'discount_amount', ''),
            'discountPercent' => $promoPercent !== null ? (string) $promoPercent : '',
            'discountCode' => (string) ($promoCode ?? ''),
            'expiryDate' => (string) ($promoEndDate ?? ''),
            'serviceName' => (string) ($offer?->item_type === Product::ITEM_TYPE_SERVICE ? $offer?->name : ''),
            'serviceCategory' => '',
        ];

        return array_merge($brandTokens, $base, $extra);
    }
}
