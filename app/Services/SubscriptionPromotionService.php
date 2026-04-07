<?php

namespace App\Services;

use App\Enums\BillingPeriod;
use App\Enums\CurrencyCode;
use App\Models\SubscriptionPromotion;
use App\Support\CurrencyFormatter;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class SubscriptionPromotionService
{
    public const ALLOWED_DISCOUNT_PERCENTS = [20, 25, 30, 35, 40, 45, 50];

    public function allowedDiscountPercents(): array
    {
        return self::ALLOWED_DISCOUNT_PERCENTS;
    }

    public function global(): SubscriptionPromotion
    {
        return SubscriptionPromotion::query()->firstOrCreate(
            ['key' => SubscriptionPromotion::GLOBAL_KEY],
            [
                'name' => 'Global subscription promotion',
                'is_enabled' => false,
                'monthly_discount_percent' => null,
                'yearly_discount_percent' => null,
            ]
        );
    }

    public function active(BillingPeriod|string|null $billingPeriod = null): ?SubscriptionPromotion
    {
        $promotion = $this->global();

        if (! $promotion->is_enabled) {
            return null;
        }

        if ($this->discountPercentForPromotion($promotion, $billingPeriod) === null) {
            return null;
        }

        return $promotion;
    }

    public function activeDiscountPercent(BillingPeriod|string|null $billingPeriod = null): ?int
    {
        $promotion = $this->active($billingPeriod);

        return $promotion ? $this->discountPercentForPromotion($promotion, $billingPeriod) : null;
    }

    public function activeCouponId(BillingPeriod|string|null $billingPeriod = null): ?string
    {
        $promotion = $this->active($billingPeriod);

        return $promotion ? $this->couponIdForPromotion($promotion, $billingPeriod) : null;
    }

    public function adminPayload(): array
    {
        $promotion = $this->global();

        return [
            'enabled' => (bool) $promotion->is_enabled,
            'monthly_discount_percent' => $promotion->monthly_discount_percent,
            'yearly_discount_percent' => $promotion->yearly_discount_percent,
            'monthly_stripe_coupon_id' => $promotion->monthly_stripe_coupon_id,
            'yearly_stripe_coupon_id' => $promotion->yearly_stripe_coupon_id,
            'last_synced_at' => $promotion->last_synced_at,
        ];
    }

    public function updateFromAdminPayload(array $input): SubscriptionPromotion
    {
        $promotion = $this->global();
        $enabled = (bool) ($input['enabled'] ?? false);
        $monthlyDiscountPercent = $this->normalizeDiscountPercent(
            $input['monthly_discount_percent'] ?? $promotion->monthly_discount_percent
        );
        $yearlyDiscountPercent = $this->normalizeDiscountPercent(
            $input['yearly_discount_percent'] ?? $promotion->yearly_discount_percent
        );

        if ($enabled && $monthlyDiscountPercent === null && $yearlyDiscountPercent === null) {
            throw new InvalidArgumentException('At least one billing period discount is required when the promotion is enabled.');
        }

        $this->assertAllowedDiscountPercent($monthlyDiscountPercent);
        $this->assertAllowedDiscountPercent($yearlyDiscountPercent);

        $promotion->forceFill([
            'is_enabled' => $enabled,
            'monthly_discount_percent' => $monthlyDiscountPercent,
            'yearly_discount_percent' => $yearlyDiscountPercent,
            'monthly_stripe_coupon_id' => null,
            'yearly_stripe_coupon_id' => null,
        ])->save();

        $couponSyncService = app(StripePromotionCouponSyncService::class);
        if ($couponSyncService->isConfigured()) {
            $couponSyncService->syncAll();

            $promotion->forceFill([
                'monthly_stripe_coupon_id' => $enabled && $monthlyDiscountPercent !== null
                    ? $couponSyncService->ensureCouponIdForDiscountPercent($monthlyDiscountPercent)
                    : null,
                'yearly_stripe_coupon_id' => $enabled && $yearlyDiscountPercent !== null
                    ? $couponSyncService->ensureCouponIdForDiscountPercent($yearlyDiscountPercent)
                    : null,
                'last_synced_at' => Carbon::now(),
            ])->save();
        }

        return $promotion->fresh();
    }

    public function pricePresentation(
        mixed $amount,
        CurrencyCode|string|null $currencyCode = null,
        bool $contactOnly = false,
        BillingPeriod|string|null $billingPeriod = null
    ): array {
        $normalizedAmount = $this->normalizeAmount($amount);
        $promotion = $contactOnly ? null : $this->active($billingPeriod);
        $discountPercent = $promotion ? $this->discountPercentForPromotion($promotion, $billingPeriod) : null;
        $discountedAmount = $this->discountedAmount($normalizedAmount, $discountPercent);
        $isDiscounted = $normalizedAmount !== null
            && $discountedAmount !== null
            && $discountPercent !== null
            && $discountedAmount !== $normalizedAmount;

        $originalDisplayPrice = $this->formatAmount($currencyCode, $normalizedAmount);
        $discountedDisplayPrice = $this->formatAmount($currencyCode, $discountedAmount);

        return [
            'amount' => $normalizedAmount,
            'original_amount' => $normalizedAmount,
            'discounted_amount' => $discountedAmount,
            'display_price' => $discountedDisplayPrice,
            'original_display_price' => $originalDisplayPrice,
            'discounted_display_price' => $discountedDisplayPrice,
            'is_discounted' => $isDiscounted,
            'promotion' => $this->serializePromotion($promotion, $isDiscounted, $billingPeriod),
        ];
    }

    public function decoratePriceOption(
        array $priceOption,
        CurrencyCode|string|null $currencyCode = null,
        bool $contactOnly = false
    ): array {
        $currency = $currencyCode ?? ($priceOption['currency_code'] ?? null);
        $billingPeriod = $priceOption['billing_period'] ?? null;

        return array_merge(
            $priceOption,
            $this->pricePresentation($priceOption['amount'] ?? null, $currency, $contactOnly, $billingPeriod)
        );
    }

    public function decorateDisplayPrice(
        mixed $rawPrice,
        CurrencyCode|string|null $currencyCode = null,
        bool $contactOnly = false,
        BillingPeriod|string|null $billingPeriod = null
    ): array {
        if ($contactOnly) {
            return $this->pricePresentation(null, $currencyCode, true, $billingPeriod);
        }

        if (is_numeric($rawPrice)) {
            return $this->pricePresentation($rawPrice, $currencyCode, false, $billingPeriod);
        }

        $displayPrice = is_string($rawPrice) ? trim($rawPrice) : null;

        return [
            'amount' => null,
            'original_amount' => null,
            'discounted_amount' => null,
            'display_price' => $displayPrice !== '' ? $displayPrice : null,
            'original_display_price' => $displayPrice !== '' ? $displayPrice : null,
            'discounted_display_price' => $displayPrice !== '' ? $displayPrice : null,
            'is_discounted' => false,
            'promotion' => $this->serializePromotion(null, false, $billingPeriod),
        ];
    }

    public function isAllowedDiscountPercent(mixed $discountPercent): bool
    {
        return in_array((int) $discountPercent, $this->allowedDiscountPercents(), true);
    }

    private function normalizeDiscountPercent(mixed $discountPercent): ?int
    {
        if ($discountPercent === null || $discountPercent === '') {
            return null;
        }

        return (int) $discountPercent;
    }

    private function assertAllowedDiscountPercent(?int $discountPercent): void
    {
        if ($discountPercent !== null && ! $this->isAllowedDiscountPercent($discountPercent)) {
            throw new InvalidArgumentException('The selected promotion discount percent is invalid.');
        }
    }

    private function normalizeBillingPeriod(BillingPeriod|string|null $billingPeriod): BillingPeriod
    {
        if ($billingPeriod instanceof BillingPeriod) {
            return $billingPeriod;
        }

        return BillingPeriod::tryFromMixed($billingPeriod) ?? BillingPeriod::MONTHLY;
    }

    private function discountPercentForPromotion(
        SubscriptionPromotion $promotion,
        BillingPeriod|string|null $billingPeriod = null
    ): ?int {
        $discountPercent = $this->normalizeBillingPeriod($billingPeriod) === BillingPeriod::YEARLY
            ? $promotion->yearly_discount_percent
            : $promotion->monthly_discount_percent;

        return $this->isAllowedDiscountPercent($discountPercent) ? (int) $discountPercent : null;
    }

    private function couponIdForPromotion(
        SubscriptionPromotion $promotion,
        BillingPeriod|string|null $billingPeriod = null
    ): ?string {
        $couponId = $this->normalizeBillingPeriod($billingPeriod) === BillingPeriod::YEARLY
            ? $promotion->yearly_stripe_coupon_id
            : $promotion->monthly_stripe_coupon_id;

        return is_string($couponId) && trim($couponId) !== '' ? trim($couponId) : null;
    }

    private function normalizeAmount(mixed $amount): ?string
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        if (! is_numeric($amount)) {
            return null;
        }

        return number_format((float) $amount, 2, '.', '');
    }

    private function discountedAmount(?string $amount, ?int $discountPercent): ?string
    {
        if ($amount === null) {
            return null;
        }

        if (! $this->isAllowedDiscountPercent($discountPercent)) {
            return $amount;
        }

        return number_format(round(((float) $amount) * (1 - ($discountPercent / 100)), 2), 2, '.', '');
    }

    private function formatAmount(CurrencyCode|string|null $currencyCode, ?string $amount = null): ?string
    {
        if ($amount === null) {
            return null;
        }

        return CurrencyFormatter::format((float) $amount, $currencyCode);
    }

    private function serializePromotion(
        ?SubscriptionPromotion $promotion,
        bool $isDiscounted,
        BillingPeriod|string|null $billingPeriod = null
    ): array
    {
        return [
            'is_active' => $promotion !== null && $isDiscounted,
            'discount_percent' => $promotion ? $this->discountPercentForPromotion($promotion, $billingPeriod) : null,
        ];
    }
}
