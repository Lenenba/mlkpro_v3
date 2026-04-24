<?php

namespace App\Services\Social;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\User;
use App\Services\CompanyFeatureService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SocialPrefillService
{
    public const SOURCE_PROMOTION = 'promotion';

    public const SOURCE_PRODUCT = 'product';

    public const SOURCE_SERVICE = 'service';

    public const SOURCE_CAMPAIGN = 'campaign';

    public function __construct(
        private readonly CompanyFeatureService $featureService,
    ) {}

    /**
     * @return array<int, string>
     */
    public static function allowedSourceTypes(): array
    {
        return [
            self::SOURCE_PROMOTION,
            self::SOURCE_PRODUCT,
            self::SOURCE_SERVICE,
            self::SOURCE_CAMPAIGN,
        ];
    }

    public function canOpenComposer(User $owner, ?User $actor = null): bool
    {
        if (! $this->featureService->hasFeature($owner, 'social')) {
            return false;
        }

        if (! $actor || (int) $actor->id === (int) $owner->id) {
            return true;
        }

        $membership = $actor->relationLoaded('teamMembership')
            ? $actor->teamMembership
            : $actor->teamMembership()->first();

        return (bool) (
            $membership?->hasPermission('social.view')
            || $membership?->hasPermission('social.manage')
            || $membership?->hasPermission('social.publish')
            || $membership?->hasPermission('social.approve')
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function resolveComposerPrefill(User $owner, array $payload): ?array
    {
        $sourceType = $this->normalizeSourceType($payload['source_type'] ?? null);
        $sourceId = (int) ($payload['source_id'] ?? 0);

        if ($sourceType === null || $sourceId <= 0) {
            return null;
        }

        if (! $this->featureService->hasFeature($owner, 'social')) {
            return null;
        }

        return $this->resolveSourcePayload($owner, $sourceType, $sourceId);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{source_type: string|null, source_id: int|null, source_label: string|null}
     */
    public function validateSourceReference(User $owner, array $payload): array
    {
        $sourceType = $this->normalizeSourceType($payload['source_type'] ?? null);
        $sourceId = (int) ($payload['source_id'] ?? 0);
        $hasSourceType = trim((string) ($payload['source_type'] ?? '')) !== '';
        $hasSourceId = trim((string) ($payload['source_id'] ?? '')) !== '';

        if (! $hasSourceType && ! $hasSourceId) {
            return [
                'source_type' => null,
                'source_id' => null,
                'source_label' => null,
            ];
        }

        if ($sourceType === null) {
            throw ValidationException::withMessages([
                'source_type' => 'Select a valid Pulse source type before saving this draft.',
            ]);
        }

        if ($sourceId <= 0) {
            throw ValidationException::withMessages([
                'source_id' => 'Select a valid Pulse source before saving this draft.',
            ]);
        }

        $resolved = $this->resolveSourcePayload($owner, $sourceType, $sourceId);
        if (! $resolved) {
            throw ValidationException::withMessages([
                'source_id' => 'Select a valid Pulse source for this workspace.',
            ]);
        }

        return [
            'source_type' => $resolved['source_type'],
            'source_id' => $resolved['source_id'],
            'source_label' => $resolved['source_label'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveSourcePayload(User $owner, string $sourceType, int $sourceId): ?array
    {
        if (! $this->isSourceFeatureEnabled($owner, $sourceType)) {
            return null;
        }

        return match ($sourceType) {
            self::SOURCE_PROMOTION => $this->promotionPayload($owner, $sourceId),
            self::SOURCE_PRODUCT => $this->productPayload($owner, $sourceId),
            self::SOURCE_SERVICE => $this->servicePayload($owner, $sourceId),
            self::SOURCE_CAMPAIGN => $this->campaignPayload($owner, $sourceId),
            default => null,
        };
    }

    private function isSourceFeatureEnabled(User $owner, string $sourceType): bool
    {
        return match ($sourceType) {
            self::SOURCE_PROMOTION => $this->featureService->hasFeature($owner, 'promotions'),
            self::SOURCE_PRODUCT => $this->featureService->hasFeature($owner, 'products'),
            self::SOURCE_SERVICE => $this->featureService->hasFeature($owner, 'services'),
            self::SOURCE_CAMPAIGN => $this->featureService->hasFeature($owner, 'campaigns'),
            default => false,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function promotionPayload(User $owner, int $promotionId): ?array
    {
        $promotion = Promotion::query()
            ->forAccount($owner->id)
            ->whereKey($promotionId)
            ->first();

        if (! $promotion) {
            return null;
        }

        $targetLabel = $this->promotionTargetLabel($owner, $promotion);
        $targetProduct = $this->promotionTargetProduct($owner, $promotion);
        $lines = array_filter([
            trim((string) $promotion->name),
            $this->promotionDiscountLabel($promotion),
            $promotion->code ? 'Code: '.$promotion->code : null,
            $targetLabel ? 'For: '.$targetLabel : null,
            $this->promotionWindowLabel($promotion),
            $promotion->minimum_order_amount !== null
                ? 'Minimum order: '.$this->formatMoney((float) $promotion->minimum_order_amount, $owner)
                : null,
        ]);

        return [
            'source_type' => self::SOURCE_PROMOTION,
            'source_id' => (int) $promotion->id,
            'source_label' => trim((string) $promotion->name) !== ''
                ? trim((string) $promotion->name)
                : 'Promotion #'.$promotion->id,
            'text' => $this->joinTextBlocks($lines),
            'image_url' => $targetProduct ? $this->resolveCatalogImageUrl($targetProduct) : null,
            'link_url' => null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function productPayload(User $owner, int $productId): ?array
    {
        $product = Product::query()
            ->where('user_id', $owner->id)
            ->where('item_type', Product::ITEM_TYPE_PRODUCT)
            ->whereKey($productId)
            ->first();

        if (! $product) {
            return null;
        }

        $blocks = array_filter([
            trim((string) $product->name),
            $this->excerpt($product->description),
            $this->catalogPricingLabel($product, $owner),
            $this->catalogPromoLabel($product),
        ]);

        return [
            'source_type' => self::SOURCE_PRODUCT,
            'source_id' => (int) $product->id,
            'source_label' => trim((string) $product->name) !== ''
                ? trim((string) $product->name)
                : 'Product #'.$product->id,
            'text' => $this->joinTextBlocks($blocks),
            'image_url' => $this->resolveCatalogImageUrl($product),
            'link_url' => null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function servicePayload(User $owner, int $serviceId): ?array
    {
        $service = Product::query()
            ->where('user_id', $owner->id)
            ->where('item_type', Product::ITEM_TYPE_SERVICE)
            ->whereKey($serviceId)
            ->first();

        if (! $service) {
            return null;
        }

        $blocks = array_filter([
            trim((string) $service->name),
            $this->excerpt($service->description),
            $this->catalogPricingLabel($service, $owner),
            $this->catalogPromoLabel($service),
        ]);

        return [
            'source_type' => self::SOURCE_SERVICE,
            'source_id' => (int) $service->id,
            'source_label' => trim((string) $service->name) !== ''
                ? trim((string) $service->name)
                : 'Service #'.$service->id,
            'text' => $this->joinTextBlocks($blocks),
            'image_url' => $this->resolveCatalogImageUrl($service),
            'link_url' => null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function campaignPayload(User $owner, int $campaignId): ?array
    {
        $campaign = Campaign::query()
            ->where('user_id', $owner->id)
            ->whereKey($campaignId)
            ->with([
                'offers.offer:id,name,image,item_type',
                'products:id,name,image,item_type',
            ])
            ->first();

        if (! $campaign) {
            return null;
        }

        $offerNames = $campaign->offers
            ->map(fn ($offer) => trim((string) ($offer->offer?->name ?? '')))
            ->filter()
            ->take(3);

        if ($offerNames->isEmpty()) {
            $offerNames = $campaign->products
                ->map(fn (Product $product) => trim((string) $product->name))
                ->filter()
                ->take(3);
        }

        $blocks = array_filter([
            trim((string) $campaign->name),
            $offerNames->isNotEmpty() ? 'Highlights: '.$offerNames->implode(', ') : null,
            $campaign->scheduled_at ? 'Planned for '.$campaign->scheduled_at->toIso8601String() : null,
            $this->validUrl($campaign->cta_url),
        ]);

        return [
            'source_type' => self::SOURCE_CAMPAIGN,
            'source_id' => (int) $campaign->id,
            'source_label' => trim((string) $campaign->name) !== ''
                ? trim((string) $campaign->name)
                : 'Campaign #'.$campaign->id,
            'text' => $this->joinTextBlocks($blocks),
            'image_url' => $this->campaignImageUrl($campaign),
            'link_url' => $this->validUrl($campaign->cta_url),
        ];
    }

    private function normalizeSourceType(mixed $value): ?string
    {
        $normalized = Str::of((string) $value)->trim()->lower()->toString();

        return in_array($normalized, self::allowedSourceTypes(), true)
            ? $normalized
            : null;
    }

    private function promotionTargetLabel(User $owner, Promotion $promotion): ?string
    {
        $targetType = strtolower((string) ($promotion->target_type?->value ?? $promotion->target_type ?? ''));
        $targetId = (int) ($promotion->target_id ?? 0);

        if ($targetType === '' || $targetType === 'global') {
            return 'All clients';
        }

        if ($targetType === 'client' && $targetId > 0) {
            $customer = Customer::query()
                ->where('user_id', $owner->id)
                ->whereKey($targetId)
                ->first(['company_name', 'first_name', 'last_name', 'email']);

            return $customer?->company_name
                ?: trim(($customer?->first_name ?? '').' '.($customer?->last_name ?? ''))
                ?: ($customer?->email ? (string) $customer->email : null);
        }

        if (in_array($targetType, ['product', 'service'], true) && $targetId > 0) {
            return Product::query()
                ->where('user_id', $owner->id)
                ->whereKey($targetId)
                ->value('name');
        }

        return null;
    }

    private function promotionTargetProduct(User $owner, Promotion $promotion): ?Product
    {
        $targetType = strtolower((string) ($promotion->target_type?->value ?? $promotion->target_type ?? ''));
        $targetId = (int) ($promotion->target_id ?? 0);

        if (! in_array($targetType, ['product', 'service'], true) || $targetId <= 0) {
            return null;
        }

        return Product::query()
            ->where('user_id', $owner->id)
            ->whereKey($targetId)
            ->first();
    }

    private function promotionDiscountLabel(Promotion $promotion): ?string
    {
        $discountType = strtolower((string) ($promotion->discount_type?->value ?? $promotion->discount_type ?? ''));
        $discountValue = (float) ($promotion->discount_value ?? 0);

        if ($discountValue <= 0) {
            return null;
        }

        return match ($discountType) {
            'fixed' => 'Discount: '.number_format($discountValue, 2, '.', ' '),
            default => 'Discount: '.rtrim(rtrim(number_format($discountValue, 2, '.', ''), '0'), '.').'%',
        };
    }

    private function promotionWindowLabel(Promotion $promotion): ?string
    {
        $startDate = $promotion->start_date?->toDateString();
        $endDate = $promotion->end_date?->toDateString();

        if (! $startDate && ! $endDate) {
            return null;
        }

        if ($startDate && $endDate) {
            return 'Window: '.$startDate.' -> '.$endDate;
        }

        return 'Window: '.($startDate ?: $endDate);
    }

    private function excerpt(mixed $value, int $limit = 220): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        $normalized = preg_replace('/\s+/', ' ', strip_tags($text)) ?: '';
        $normalized = trim($normalized);

        return $normalized !== ''
            ? Str::limit($normalized, $limit)
            : null;
    }

    private function catalogPricingLabel(Product $product, User $owner): ?string
    {
        $price = (float) ($product->price ?? 0);
        if ($price <= 0) {
            return null;
        }

        return $this->formatMoney($price, $owner);
    }

    private function catalogPromoLabel(Product $product): ?string
    {
        $discount = (float) ($product->promo_discount_percent ?? 0);
        if ($discount <= 0) {
            return null;
        }

        $line = 'Promo: -'.rtrim(rtrim(number_format($discount, 2, '.', ''), '0'), '.').'%';
        if ($product->promo_end_at) {
            $line .= ' until '.$product->promo_end_at->toDateString();
        }

        return $line;
    }

    private function formatMoney(float $amount, User $owner): string
    {
        $currency = trim((string) ($owner->currency_code ?? 'CAD'));

        return number_format($amount, 2, '.', ' ').($currency !== '' ? ' '.$currency : '');
    }

    private function resolveCatalogImageUrl(Product $product): ?string
    {
        $rawPath = trim((string) ($product->image ?? ''));
        if ($rawPath === '' || $rawPath === Product::LEGACY_DEFAULT_IMAGE_PATH) {
            return null;
        }

        return $product->image_url;
    }

    private function campaignImageUrl(Campaign $campaign): ?string
    {
        $offerImage = $campaign->offers
            ->map(fn ($offer) => $offer->offer instanceof Product ? $this->resolveCatalogImageUrl($offer->offer) : null)
            ->filter()
            ->first();

        if ($offerImage) {
            return $offerImage;
        }

        return $campaign->products
            ->map(fn (Product $product) => $this->resolveCatalogImageUrl($product))
            ->filter()
            ->first();
    }

    /**
     * @param  array<int, string|null>  $blocks
     */
    private function joinTextBlocks(array $blocks): ?string
    {
        $resolved = collect($blocks)
            ->map(fn ($block) => trim((string) $block))
            ->filter()
            ->values();

        return $resolved->isNotEmpty()
            ? $resolved->implode("\n\n")
            : null;
    }

    private function validUrl(mixed $value): ?string
    {
        $candidate = trim((string) $value);

        return filter_var($candidate, FILTER_VALIDATE_URL)
            ? $candidate
            : null;
    }
}
