<?php

namespace App\Services\Campaigns;

use App\Models\User;

class BrandProfileService
{
    public function __construct(
        private readonly MarketingSettingsService $marketingSettingsService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function resolve(User $accountOwner): array
    {
        $configured = $this->marketingSettingsService->getValue(
            $accountOwner,
            'templates.brand_profile',
            []
        );
        $configured = is_array($configured) ? $configured : [];

        $name = $this->firstNonEmpty(
            $configured['name'] ?? null,
            $accountOwner->company_name,
            $accountOwner->name,
            config('app.name')
        );

        $tagline = $this->firstNonEmpty(
            $configured['tagline'] ?? null,
            data_get($configured, 'hero.eyebrow'),
            $accountOwner->company_sector
        );

        $description = $this->firstNonEmpty(
            $configured['description'] ?? null,
            $accountOwner->company_description
        );

        $contactEmail = $this->firstNonEmpty(
            $configured['contact_email'] ?? null,
            data_get($configured, 'footer.contact_email'),
            $accountOwner->email
        );

        $replyToEmail = $this->firstNonEmpty(
            $configured['reply_to_email'] ?? null,
            $contactEmail
        );

        $phone = $this->firstNonEmpty(
            $configured['phone'] ?? null,
            data_get($configured, 'footer.phone'),
            $accountOwner->phone_number
        );

        $logoUrl = $this->firstNonEmpty(
            $configured['logo_url'] ?? null,
            $accountOwner->company_logo_url
        );

        $websiteUrl = $this->firstNonEmpty(
            $configured['website_url'] ?? null,
            data_get($configured, 'contact.website_url'),
            $this->publicWebsiteUrl($accountOwner)
        );

        $bookingUrl = $this->firstNonEmpty(
            $configured['booking_url'] ?? null,
            data_get($configured, 'cta.booking_url')
        );

        $supportUrl = $this->firstNonEmpty(
            $configured['support_url'] ?? null,
            data_get($configured, 'cta.support_url'),
            $websiteUrl
        );

        $contactUrl = $this->firstNonEmpty(
            $configured['contact_url'] ?? null,
            data_get($configured, 'cta.contact_url'),
            $websiteUrl
        );

        $footerNote = $this->firstNonEmpty(
            $configured['footer_note'] ?? null,
            data_get($configured, 'footer.note')
        );

        $pickupAddress = trim((string) data_get($accountOwner->company_fulfillment, 'pickup_address', ''));
        $addressLine1 = $this->firstNonEmpty(
            $configured['address_line_1'] ?? null,
            $pickupAddress
        );
        $addressLine2 = $this->firstNonEmpty($configured['address_line_2'] ?? null);
        $city = $this->firstNonEmpty($configured['city'] ?? null, $accountOwner->company_city);
        $province = $this->firstNonEmpty($configured['province'] ?? null, $accountOwner->company_province);
        $country = $this->firstNonEmpty($configured['country'] ?? null, $accountOwner->company_country);
        $postalCode = $this->firstNonEmpty($configured['postal_code'] ?? null);

        $addressParts = array_values(array_filter([
            $addressLine1,
            $addressLine2,
            $this->joinParts([$city, $province, $postalCode], ', '),
            $country,
        ]));
        $fullAddress = implode(' | ', $addressParts);

        $colors = [
            'primary_color' => $this->normalizeColor($configured['primary_color'] ?? null, '#0F766E'),
            'secondary_color' => $this->normalizeColor($configured['secondary_color'] ?? null, '#0F172A'),
            'accent_color' => $this->normalizeColor($configured['accent_color'] ?? null, '#F59E0B'),
            'surface_color' => $this->normalizeColor($configured['surface_color'] ?? null, '#F8FAFC'),
            'hero_background_color' => $this->normalizeColor($configured['hero_background_color'] ?? null, '#ECFEFF'),
            'footer_background_color' => $this->normalizeColor($configured['footer_background_color'] ?? null, '#0F172A'),
            'text_color' => $this->normalizeColor($configured['text_color'] ?? null, '#0F172A'),
            'muted_color' => $this->normalizeColor($configured['muted_color'] ?? null, '#475569'),
        ];

        return [
            'name' => $name,
            'tagline' => $tagline,
            'description' => $description,
            'logo_url' => $logoUrl,
            'website_url' => $websiteUrl,
            'booking_url' => $bookingUrl,
            'support_url' => $supportUrl,
            'contact_url' => $contactUrl,
            'contact_email' => $contactEmail,
            'reply_to_email' => $replyToEmail,
            'phone' => $phone,
            'address_line_1' => $addressLine1,
            'address_line_2' => $addressLine2,
            'city' => $city,
            'province' => $province,
            'country' => $country,
            'postal_code' => $postalCode,
            'full_address' => $fullAddress,
            'facebook_url' => $this->firstNonEmpty($configured['facebook_url'] ?? null),
            'instagram_url' => $this->firstNonEmpty($configured['instagram_url'] ?? null),
            'linkedin_url' => $this->firstNonEmpty($configured['linkedin_url'] ?? null),
            'youtube_url' => $this->firstNonEmpty($configured['youtube_url'] ?? null),
            'tiktok_url' => $this->firstNonEmpty($configured['tiktok_url'] ?? null),
            'whatsapp_url' => $this->firstNonEmpty($configured['whatsapp_url'] ?? null),
            'footer_note' => $footerNote,
            ...$colors,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function tokenMap(User $accountOwner): array
    {
        $profile = $this->resolve($accountOwner);

        return [
            'brandName' => (string) ($profile['name'] ?? ''),
            'brandTagline' => (string) ($profile['tagline'] ?? ''),
            'brandDescription' => (string) ($profile['description'] ?? ''),
            'brandLogoUrl' => (string) ($profile['logo_url'] ?? ''),
            'brandWebsiteUrl' => (string) ($profile['website_url'] ?? ''),
            'brandBookingUrl' => (string) ($profile['booking_url'] ?? ''),
            'brandSupportUrl' => (string) ($profile['support_url'] ?? ''),
            'brandContactUrl' => (string) ($profile['contact_url'] ?? ''),
            'brandContactEmail' => (string) ($profile['contact_email'] ?? ''),
            'brandReplyToEmail' => (string) ($profile['reply_to_email'] ?? ''),
            'brandPhone' => (string) ($profile['phone'] ?? ''),
            'brandAddressLine1' => (string) ($profile['address_line_1'] ?? ''),
            'brandAddressLine2' => (string) ($profile['address_line_2'] ?? ''),
            'brandCity' => (string) ($profile['city'] ?? ''),
            'brandProvince' => (string) ($profile['province'] ?? ''),
            'brandCountry' => (string) ($profile['country'] ?? ''),
            'brandPostalCode' => (string) ($profile['postal_code'] ?? ''),
            'brandAddress' => (string) ($profile['full_address'] ?? ''),
            'brandPrimaryColor' => (string) ($profile['primary_color'] ?? ''),
            'brandSecondaryColor' => (string) ($profile['secondary_color'] ?? ''),
            'brandAccentColor' => (string) ($profile['accent_color'] ?? ''),
            'brandSurfaceColor' => (string) ($profile['surface_color'] ?? ''),
            'brandHeroBackgroundColor' => (string) ($profile['hero_background_color'] ?? ''),
            'brandFooterBackgroundColor' => (string) ($profile['footer_background_color'] ?? ''),
            'brandTextColor' => (string) ($profile['text_color'] ?? ''),
            'brandMutedColor' => (string) ($profile['muted_color'] ?? ''),
            'brandFacebookUrl' => (string) ($profile['facebook_url'] ?? ''),
            'brandInstagramUrl' => (string) ($profile['instagram_url'] ?? ''),
            'brandLinkedinUrl' => (string) ($profile['linkedin_url'] ?? ''),
            'brandYoutubeUrl' => (string) ($profile['youtube_url'] ?? ''),
            'brandTiktokUrl' => (string) ($profile['tiktok_url'] ?? ''),
            'brandWhatsappUrl' => (string) ($profile['whatsapp_url'] ?? ''),
            'brandFooterNote' => (string) ($profile['footer_note'] ?? ''),
            'companyBrandName' => (string) ($profile['name'] ?? ''),
            'companyLogoUrl' => (string) ($profile['logo_url'] ?? ''),
            'companyWebsiteUrl' => (string) ($profile['website_url'] ?? ''),
            'companyContactEmail' => (string) ($profile['contact_email'] ?? ''),
            'companyPhone' => (string) ($profile['phone'] ?? ''),
            'companyAddress' => (string) ($profile['full_address'] ?? ''),
        ];
    }

    private function publicWebsiteUrl(User $accountOwner): ?string
    {
        $slug = trim((string) ($accountOwner->company_slug ?? ''));
        if ($slug === '') {
            return null;
        }

        $companyType = strtolower(trim((string) ($accountOwner->company_type ?? '')));

        return $companyType === 'services'
            ? route('public.showcase.show', ['slug' => $slug])
            : route('public.store.show', ['slug' => $slug]);
    }

    private function normalizeColor(mixed $value, string $fallback): string
    {
        $candidate = strtoupper(trim((string) $value));
        if (preg_match('/^#[0-9A-F]{6}$/', $candidate) !== 1) {
            return $fallback;
        }

        return $candidate;
    }

    private function firstNonEmpty(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            $candidate = trim((string) $value);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string|null>  $parts
     */
    private function joinParts(array $parts, string $glue): string
    {
        return implode($glue, array_values(array_filter($parts, fn (?string $value): bool => trim((string) $value) !== '')));
    }
}
