<?php

namespace App\Services\Promotions;

use App\Enums\PromotionDiscountType;
use App\Enums\PromotionStatus;
use App\Enums\PromotionTargetType;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Sale;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PromotionPricingService
{
    public function frontendCatalogForAccount(int $accountId, ?int $ignoreSaleId = null): Collection
    {
        return $this->basePromotionQuery($accountId, $ignoreSaleId)
            ->active()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Promotion $promotion) => $this->serializePromotion($promotion));
    }

    public function resolve(
        int $accountId,
        array $items,
        Collection $products,
        ?Customer $customer = null,
        ?string $requestedCode = null,
        ?int $ignoreSaleId = null,
        ?CarbonInterface $today = null,
    ): array {
        $today = $today ?: now();
        $lines = $this->normalizeLines($items, $products);
        $subtotal = round(array_sum(array_column($lines, 'subtotal')), 2);
        $baseTaxTotal = round(array_sum(array_column($lines, 'tax_total')), 2);
        $normalizedCode = $this->normalizeCode($requestedCode);

        $fallback = $this->resolveLegacyCustomerDiscount($customer, $lines, $subtotal, $baseTaxTotal);

        if ($normalizedCode !== null) {
            $promotion = $this->basePromotionQuery($accountId, $ignoreSaleId)
                ->whereRaw('LOWER(code) = ?', [mb_strtolower($normalizedCode)])
                ->first();

            if (! $promotion) {
                return $this->emptyResult($subtotal, $baseTaxTotal, 'This promo code does not exist.');
            }

            $evaluated = $this->evaluatePromotion($promotion, $lines, $customer, $subtotal, $baseTaxTotal, $today);
            if (($evaluated['eligible'] ?? false) !== true) {
                return $this->emptyResult(
                    $subtotal,
                    $baseTaxTotal,
                    $evaluated['error_message'] ?? 'This promo code is not eligible for this order.'
                );
            }

            return $this->promotionResult($promotion, $evaluated, $subtotal, $baseTaxTotal);
        }

        $promotions = $this->basePromotionQuery($accountId, $ignoreSaleId)
            ->active()
            ->whereNull('code')
            ->get();

        $bestPromotion = null;
        foreach ($promotions as $promotion) {
            $evaluated = $this->evaluatePromotion($promotion, $lines, $customer, $subtotal, $baseTaxTotal, $today);
            if (($evaluated['eligible'] ?? false) !== true) {
                continue;
            }

            if (! $bestPromotion || $this->isBetterPromotion($promotion, $evaluated, $bestPromotion['promotion'], $bestPromotion['evaluation'])) {
                $bestPromotion = [
                    'promotion' => $promotion,
                    'evaluation' => $evaluated,
                ];
            }
        }

        if ($bestPromotion) {
            return $this->promotionResult(
                $bestPromotion['promotion'],
                $bestPromotion['evaluation'],
                $subtotal,
                $baseTaxTotal
            );
        }

        return $fallback;
    }

    public function resolveWithoutPromotions(
        array $items,
        Collection $products,
        ?Customer $customer = null,
    ): array {
        $lines = $this->normalizeLines($items, $products);
        $subtotal = round(array_sum(array_column($lines, 'subtotal')), 2);
        $baseTaxTotal = round(array_sum(array_column($lines, 'tax_total')), 2);

        return $this->resolveLegacyCustomerDiscount($customer, $lines, $subtotal, $baseTaxTotal);
    }

    public function usageSnapshot(array $pricing): ?array
    {
        if (($pricing['discount_source'] ?? null) !== 'promotion' || empty($pricing['promotion'])) {
            return null;
        }

        return [
            'promotion' => $pricing['promotion'],
            'discount_source' => $pricing['discount_source'],
            'discount_label' => $pricing['discount_label'],
            'discount_code' => $pricing['discount_code'],
            'discount_type' => $pricing['discount_type'],
            'discount_value' => $pricing['discount_value'],
            'discount_rate' => $pricing['discount_rate'],
            'discount_total' => $pricing['pricing_discount_total'],
            'subtotal' => $pricing['subtotal'],
            'tax_total' => $pricing['tax_total'],
            'base_tax_total' => $pricing['base_tax_total'],
            'total_before_loyalty' => $pricing['total_before_loyalty'],
        ];
    }

    private function basePromotionQuery(int $accountId, ?int $ignoreSaleId = null): Builder
    {
        return Promotion::query()
            ->forAccount($accountId)
            ->withCount([
                'usages as consumed_usages_count' => function (Builder $query) use ($ignoreSaleId) {
                    $query
                        ->when(
                            $ignoreSaleId,
                            fn (Builder $builder) => $builder->where('sale_id', '!=', $ignoreSaleId)
                        )
                        ->whereHas('sale', fn (Builder $saleQuery) => $saleQuery->where('status', '!=', Sale::STATUS_CANCELED));
                },
            ]);
    }

    private function serializePromotion(Promotion $promotion): array
    {
        return [
            'id' => (int) $promotion->id,
            'name' => (string) $promotion->name,
            'code' => $promotion->code ? (string) $promotion->code : null,
            'target_type' => $promotion->target_type?->value ?? PromotionTargetType::GLOBAL->value,
            'target_id' => $promotion->target_id ? (int) $promotion->target_id : null,
            'discount_type' => $promotion->discount_type?->value ?? PromotionDiscountType::PERCENTAGE->value,
            'discount_value' => (float) ($promotion->discount_value ?? 0),
            'start_date' => $promotion->start_date?->toDateString(),
            'end_date' => $promotion->end_date?->toDateString(),
            'status' => $promotion->status?->value ?? PromotionStatus::INACTIVE->value,
            'usage_limit' => $promotion->usage_limit ? (int) $promotion->usage_limit : null,
            'usage_count' => (int) ($promotion->consumed_usages_count ?? 0),
            'minimum_order_amount' => $promotion->minimum_order_amount !== null
                ? (float) $promotion->minimum_order_amount
                : null,
        ];
    }

    private function normalizeLines(array $items, Collection $products): array
    {
        $lines = [];

        foreach ($items as $index => $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            /** @var Product|null $product */
            $product = $products->get($productId);
            if (! $product) {
                continue;
            }

            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $price = max(0, (float) ($item['price'] ?? 0));
            $subtotal = round($quantity * $price, 2);
            $taxRate = max(0, (float) ($product->tax_rate ?? 0));

            $lines[] = [
                'index' => $index,
                'product_id' => $productId,
                'item_type' => (string) ($product->item_type ?? Product::ITEM_TYPE_PRODUCT),
                'quantity' => $quantity,
                'price' => $price,
                'subtotal' => $subtotal,
                'tax_rate' => $taxRate,
                'tax_total' => round($subtotal * ($taxRate / 100), 2),
            ];
        }

        return $lines;
    }

    private function resolveLegacyCustomerDiscount(?Customer $customer, array $lines, float $subtotal, float $baseTaxTotal): array
    {
        $discountRate = min(100, max(0, (float) ($customer?->discount_rate ?? 0)));
        if ($discountRate <= 0 || $subtotal <= 0) {
            return $this->emptyResult($subtotal, $baseTaxTotal);
        }

        $discountTotal = round($subtotal * ($discountRate / 100), 2);
        $lineDiscounts = $this->allocatePercentageDiscounts($lines, $discountRate, $discountTotal);
        $taxTotal = $this->recalculateTaxTotal($lines, $lineDiscounts);
        $discountedSubtotal = round(max(0, $subtotal - $discountTotal), 2);
        $totalBeforeLoyalty = round($discountedSubtotal + $taxTotal, 2);

        return [
            'promotion' => null,
            'error_message' => null,
            'subtotal' => $subtotal,
            'base_tax_total' => $baseTaxTotal,
            'tax_total' => $taxTotal,
            'discount_source' => 'customer',
            'discount_label' => 'Customer discount',
            'discount_code' => null,
            'discount_type' => PromotionDiscountType::PERCENTAGE->value,
            'discount_value' => $discountRate,
            'discount_target_type' => PromotionTargetType::CLIENT->value,
            'discount_target_id' => $customer ? (int) $customer->id : null,
            'discount_rate' => round($discountRate, 2),
            'pricing_discount_total' => $discountTotal,
            'discount_total' => $discountTotal,
            'discount_snapshot' => [
                'source' => 'customer',
                'rate' => round($discountRate, 2),
                'customer_id' => $customer ? (int) $customer->id : null,
            ],
            'total_before_loyalty' => $totalBeforeLoyalty,
        ];
    }

    private function evaluatePromotion(
        Promotion $promotion,
        array $lines,
        ?Customer $customer,
        float $subtotal,
        float $baseTaxTotal,
        CarbonInterface $today,
    ): array {
        if (($promotion->status?->value ?? null) !== PromotionStatus::ACTIVE->value) {
            return ['eligible' => false, 'error_message' => 'This promo code is inactive.'];
        }

        $todayString = $today->toDateString();
        if (
            ! $promotion->start_date
            || ! $promotion->end_date
            || $promotion->start_date->toDateString() > $todayString
            || $promotion->end_date->toDateString() < $todayString
        ) {
            return ['eligible' => false, 'error_message' => 'This promo code is outside its valid dates.'];
        }

        if ($promotion->minimum_order_amount !== null && $subtotal < (float) $promotion->minimum_order_amount) {
            return ['eligible' => false, 'error_message' => 'The minimum order amount for this promo code was not met.'];
        }

        $usageLimit = $promotion->usage_limit ? (int) $promotion->usage_limit : null;
        $usageCount = (int) ($promotion->consumed_usages_count ?? 0);
        if ($usageLimit !== null && $usageCount >= $usageLimit) {
            return ['eligible' => false, 'error_message' => 'This promo code has reached its usage limit.'];
        }

        $matchedLines = $this->matchedLines($promotion, $lines, $customer);
        if ($matchedLines === []) {
            return ['eligible' => false, 'error_message' => 'This promo code does not apply to this order.'];
        }

        $eligibleSubtotal = round(array_sum(array_column($matchedLines, 'subtotal')), 2);
        if ($eligibleSubtotal <= 0) {
            return ['eligible' => false, 'error_message' => 'This promo code does not apply to this order.'];
        }

        $discountType = $promotion->discount_type ?? PromotionDiscountType::PERCENTAGE;
        $discountValue = max(0, (float) ($promotion->discount_value ?? 0));

        if ($discountType === PromotionDiscountType::PERCENTAGE) {
            $cappedRate = min(100, $discountValue);
            $discountTotal = round($eligibleSubtotal * ($cappedRate / 100), 2);
            $lineDiscounts = $this->allocatePercentageDiscounts($matchedLines, $cappedRate, $discountTotal);
        } else {
            $discountTotal = round(min($eligibleSubtotal, $discountValue), 2);
            $lineDiscounts = $this->allocateFixedDiscounts($matchedLines, $discountTotal);
        }

        $taxTotal = $this->recalculateTaxTotal($lines, $lineDiscounts);
        $discountedSubtotal = round(max(0, $subtotal - $discountTotal), 2);
        $effectiveRate = $subtotal > 0 ? round(($discountTotal / $subtotal) * 100, 2) : 0.0;

        return [
            'eligible' => true,
            'discount_total' => $discountTotal,
            'discounted_subtotal' => $discountedSubtotal,
            'tax_total' => $taxTotal,
            'eligible_subtotal' => $eligibleSubtotal,
            'effective_rate' => $effectiveRate,
            'usage_count' => $usageCount,
            'usage_limit' => $usageLimit,
            'base_tax_total' => $baseTaxTotal,
        ];
    }

    private function matchedLines(Promotion $promotion, array $lines, ?Customer $customer): array
    {
        $targetType = $promotion->target_type ?? PromotionTargetType::GLOBAL;
        $targetId = $promotion->target_id ? (int) $promotion->target_id : null;

        if ($targetType === PromotionTargetType::CLIENT) {
            if (! $customer || $targetId === null || (int) $customer->id !== $targetId) {
                return [];
            }

            return $lines;
        }

        if ($targetType === PromotionTargetType::GLOBAL) {
            return $lines;
        }

        return array_values(array_filter($lines, function (array $line) use ($targetType, $targetId): bool {
            if ($targetId === null || (int) $line['product_id'] !== $targetId) {
                return false;
            }

            if ($targetType === PromotionTargetType::PRODUCT) {
                return (string) $line['item_type'] === Product::ITEM_TYPE_PRODUCT;
            }

            if ($targetType === PromotionTargetType::SERVICE) {
                return (string) $line['item_type'] === Product::ITEM_TYPE_SERVICE;
            }

            return false;
        }));
    }

    private function allocatePercentageDiscounts(array $lines, float $rate, float $expectedTotal): array
    {
        $discounts = [];
        $remaining = $expectedTotal;
        $lastIndex = array_key_last($lines);

        foreach ($lines as $position => $line) {
            if ($position === $lastIndex) {
                $discounts[$line['index']] = round(max(0, $remaining), 2);

                continue;
            }

            $discount = round(((float) $line['subtotal']) * ($rate / 100), 2);
            $discounts[$line['index']] = $discount;
            $remaining = round($remaining - $discount, 2);
        }

        return $discounts;
    }

    private function allocateFixedDiscounts(array $lines, float $expectedTotal): array
    {
        $discounts = [];
        $eligibleSubtotal = array_sum(array_column($lines, 'subtotal'));
        $remaining = $expectedTotal;
        $lastIndex = array_key_last($lines);

        foreach ($lines as $position => $line) {
            if ($position === $lastIndex) {
                $discounts[$line['index']] = round(max(0, $remaining), 2);

                continue;
            }

            $share = $eligibleSubtotal > 0 ? ((float) $line['subtotal']) / $eligibleSubtotal : 0;
            $discount = round($expectedTotal * $share, 2);
            $discounts[$line['index']] = $discount;
            $remaining = round($remaining - $discount, 2);
        }

        return $discounts;
    }

    private function recalculateTaxTotal(array $lines, array $lineDiscounts): float
    {
        $taxTotal = 0.0;

        foreach ($lines as $line) {
            $discount = (float) ($lineDiscounts[$line['index']] ?? 0);
            $discountedLineSubtotal = round(max(0, (float) $line['subtotal'] - $discount), 2);
            $taxTotal += round($discountedLineSubtotal * (((float) $line['tax_rate']) / 100), 2);
        }

        return round($taxTotal, 2);
    }

    private function isBetterPromotion(
        Promotion $candidatePromotion,
        array $candidateEvaluation,
        Promotion $currentPromotion,
        array $currentEvaluation,
    ): bool {
        $candidateDiscount = (float) ($candidateEvaluation['discount_total'] ?? 0);
        $currentDiscount = (float) ($currentEvaluation['discount_total'] ?? 0);

        if ($candidateDiscount !== $currentDiscount) {
            return $candidateDiscount > $currentDiscount;
        }

        $candidateSpecificity = ($candidatePromotion->target_type ?? PromotionTargetType::GLOBAL)->specificityRank();
        $currentSpecificity = ($currentPromotion->target_type ?? PromotionTargetType::GLOBAL)->specificityRank();

        if ($candidateSpecificity !== $currentSpecificity) {
            return $candidateSpecificity > $currentSpecificity;
        }

        return (int) $candidatePromotion->id < (int) $currentPromotion->id;
    }

    private function promotionResult(Promotion $promotion, array $evaluation, float $subtotal, float $baseTaxTotal): array
    {
        $discountTotal = round((float) ($evaluation['discount_total'] ?? 0), 2);
        $taxTotal = round((float) ($evaluation['tax_total'] ?? $baseTaxTotal), 2);
        $totalBeforeLoyalty = round(max(0, $subtotal - $discountTotal) + $taxTotal, 2);
        $targetType = $promotion->target_type ?? PromotionTargetType::GLOBAL;

        return [
            'promotion' => $this->serializePromotion($promotion),
            'error_message' => null,
            'subtotal' => $subtotal,
            'base_tax_total' => $baseTaxTotal,
            'tax_total' => $taxTotal,
            'discount_source' => 'promotion',
            'discount_label' => (string) $promotion->name,
            'discount_code' => $promotion->code ? (string) $promotion->code : null,
            'discount_type' => $promotion->discount_type?->value ?? PromotionDiscountType::PERCENTAGE->value,
            'discount_value' => (float) ($promotion->discount_value ?? 0),
            'discount_target_type' => $targetType->value,
            'discount_target_id' => $promotion->target_id ? (int) $promotion->target_id : null,
            'discount_rate' => round((float) ($evaluation['effective_rate'] ?? 0), 2),
            'pricing_discount_total' => $discountTotal,
            'discount_total' => $discountTotal,
            'discount_snapshot' => [
                'promotion_id' => (int) $promotion->id,
                'target_type' => $targetType->value,
                'target_id' => $promotion->target_id ? (int) $promotion->target_id : null,
                'eligible_subtotal' => round((float) ($evaluation['eligible_subtotal'] ?? 0), 2),
                'usage_count' => (int) ($evaluation['usage_count'] ?? 0),
                'usage_limit' => $evaluation['usage_limit'] ?? null,
            ],
            'total_before_loyalty' => $totalBeforeLoyalty,
        ];
    }

    private function emptyResult(float $subtotal, float $baseTaxTotal, ?string $errorMessage = null): array
    {
        return [
            'promotion' => null,
            'error_message' => $errorMessage,
            'subtotal' => $subtotal,
            'base_tax_total' => $baseTaxTotal,
            'tax_total' => $baseTaxTotal,
            'discount_source' => null,
            'discount_label' => null,
            'discount_code' => null,
            'discount_type' => null,
            'discount_value' => null,
            'discount_target_type' => null,
            'discount_target_id' => null,
            'discount_rate' => 0,
            'pricing_discount_total' => 0,
            'discount_total' => 0,
            'discount_snapshot' => null,
            'total_before_loyalty' => round($subtotal + $baseTaxTotal, 2),
        ];
    }

    private function normalizeCode(?string $value): ?string
    {
        $normalized = mb_strtoupper(trim((string) $value));

        return $normalized !== '' ? $normalized : null;
    }
}
