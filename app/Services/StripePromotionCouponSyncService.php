<?php

namespace App\Services;

use App\Models\SubscriptionPromotionCoupon;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

class StripePromotionCouponSyncService
{
    private ?StripeClient $client = null;

    public function isConfigured(): bool
    {
        return (bool) config('services.stripe.secret');
    }

    public function syncAll(): array
    {
        if (! $this->isConfigured()) {
            return [
                'items' => [],
                'synced' => false,
            ];
        }

        $coupons = $this->client()->coupons->all([
            'limit' => 100,
        ])->data ?? [];

        $items = [];

        foreach (app(SubscriptionPromotionService::class)->allowedDiscountPercents() as $discountPercent) {
            $coupon = $this->resolveCoupon($discountPercent, $coupons);
            $action = 'reused';

            if (! $coupon) {
                $coupon = $this->client()->coupons->create([
                    'percent_off' => $discountPercent,
                    'duration' => 'forever',
                    'name' => $this->couponName($discountPercent),
                    'metadata' => $this->couponMetadata($discountPercent),
                ]);

                $coupons[] = $coupon;
                $action = 'created';
            }

            SubscriptionPromotionCoupon::query()->updateOrCreate(
                ['discount_percent' => $discountPercent],
                [
                    'stripe_coupon_id' => $coupon->id ?? null,
                    'name' => $coupon->name ?? $this->couponName($discountPercent),
                    'metadata' => $this->couponMetadataArray($coupon),
                    'synced_at' => now(),
                ]
            );

            $items[] = [
                'discount_percent' => $discountPercent,
                'stripe_coupon_id' => $coupon->id ?? null,
                'action' => $action,
            ];
        }

        return [
            'items' => $items,
            'synced' => true,
        ];
    }

    public function ensureCouponIdForDiscountPercent(int $discountPercent): ?string
    {
        if (! app(SubscriptionPromotionService::class)->isAllowedDiscountPercent($discountPercent)) {
            return null;
        }

        $record = SubscriptionPromotionCoupon::query()
            ->where('discount_percent', $discountPercent)
            ->first();

        if ($record?->stripe_coupon_id) {
            return $record->stripe_coupon_id;
        }

        $this->syncAll();

        return SubscriptionPromotionCoupon::query()
            ->where('discount_percent', $discountPercent)
            ->value('stripe_coupon_id');
    }

    public function isPromotionCoupon(mixed $coupon, ?int $expectedDiscountPercent = null): bool
    {
        $couponId = $this->extractCouponValue($coupon, 'id');
        $discountPercent = $this->extractCouponPercent($coupon);

        if ($couponId) {
            $record = SubscriptionPromotionCoupon::query()
                ->where('stripe_coupon_id', $couponId)
                ->first();

            if ($record) {
                return $expectedDiscountPercent === null
                    ? true
                    : $record->discount_percent === $expectedDiscountPercent;
            }
        }

        $metadata = $this->extractCouponMetadata($coupon);
        $isMarkedPromotion = ($metadata['source'] ?? null) === 'subscription_promotion';

        if (! $isMarkedPromotion) {
            return false;
        }

        if ($expectedDiscountPercent === null) {
            return $discountPercent !== null;
        }

        return $discountPercent === $expectedDiscountPercent;
    }

    private function resolveCoupon(int $discountPercent, array $coupons): ?object
    {
        $storedCouponId = SubscriptionPromotionCoupon::query()
            ->where('discount_percent', $discountPercent)
            ->value('stripe_coupon_id');

        if ($storedCouponId) {
            try {
                $coupon = $this->client()->coupons->retrieve($storedCouponId, []);
                if ($this->isPromotionCoupon($coupon, $discountPercent)) {
                    return $coupon;
                }
            } catch (ApiErrorException) {
                // Ignore stale coupon IDs and continue with fallback discovery.
            }
        }

        foreach ($coupons as $coupon) {
            if ($this->isPromotionCoupon($coupon, $discountPercent)) {
                return $coupon;
            }
        }

        return null;
    }

    private function couponMetadata(int $discountPercent): array
    {
        return [
            'source' => 'subscription_promotion',
            'discount_percent' => (string) $discountPercent,
        ];
    }

    private function couponName(int $discountPercent): string
    {
        return sprintf('Subscription promotion %d%% off', $discountPercent);
    }

    private function couponMetadataArray(mixed $coupon): array
    {
        return $this->extractCouponMetadata($coupon);
    }

    private function extractCouponMetadata(mixed $coupon): array
    {
        $metadata = $this->extractCouponValue($coupon, 'metadata');

        if (is_array($metadata)) {
            return $metadata;
        }

        if (is_object($metadata) && method_exists($metadata, 'toArray')) {
            return $metadata->toArray();
        }

        return [];
    }

    private function extractCouponPercent(mixed $coupon): ?int
    {
        $percentOff = $this->extractCouponValue($coupon, 'percent_off');

        return is_numeric($percentOff) ? (int) round((float) $percentOff) : null;
    }

    private function extractCouponValue(mixed $coupon, string $key): mixed
    {
        if (is_array($coupon)) {
            return $coupon[$key] ?? null;
        }

        if (is_object($coupon)) {
            return $coupon->{$key} ?? null;
        }

        return null;
    }

    private function client(): StripeClient
    {
        if ($this->client) {
            return $this->client;
        }

        $this->client = new StripeClient((string) config('services.stripe.secret', ''));

        return $this->client;
    }
}
