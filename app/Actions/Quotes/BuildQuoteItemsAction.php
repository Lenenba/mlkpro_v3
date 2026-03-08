<?php

namespace App\Actions\Quotes;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\ResolveTenantCurrency;

class BuildQuoteItemsAction
{
    public function execute(array $lines, string $itemType, int $userId, int $accountId, int $creatorId): array
    {
        $lines = collect($lines);
        $productIds = $lines->pluck('id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $productMap = $productIds->isNotEmpty()
            ? Product::byUser($userId)->whereIn('id', $productIds)->get()->keyBy('id')
            : collect();
        $currencyCode = app(ResolveTenantCurrency::class)->forAccountId($accountId)->currencyCode->value;

        return $lines->map(function (array $line) use ($productMap, $itemType, $userId, $accountId, $creatorId, $currencyCode) {
            $quantity = (int) ($line['quantity'] ?? 1);
            $price = (float) ($line['price'] ?? 0);
            $description = $line['description'] ?? null;
            $sourceDetails = $this->normalizeSourceDetails($line['source_details'] ?? null);
            $productId = isset($line['id']) && $line['id'] !== null ? (int) $line['id'] : null;
            $lineItemType = $line['item_type'] ?? $itemType;
            $model = null;

            if (! $productId) {
                $product = $this->createProductFromLine($userId, $accountId, $creatorId, $lineItemType, $line, $sourceDetails);
                $productId = $product->id;
                $model = $product;

                if (! $description) {
                    $description = $product->description;
                }
            } else {
                $model = $productMap->get($productId);
                $lineItemType = $model?->item_type ?? $lineItemType;

                if (! $description) {
                    $description = $model?->description;
                }
            }

            return [
                'id' => $productId,
                'quantity' => $quantity,
                'price' => $price,
                'currency_code' => $model?->currency_code ?? $currencyCode,
                'total' => round($quantity * $price, 2),
                'description' => $description,
                'source_details' => $sourceDetails,
            ];
        })->values()->all();
    }

    private function normalizeSourceDetails(mixed $details): ?array
    {
        if (! $details) {
            return null;
        }

        if (is_string($details)) {
            $decoded = json_decode($details, true);

            return is_array($decoded) ? $decoded : null;
        }

        if (is_object($details)) {
            $details = json_decode(json_encode($details), true);
        }

        return is_array($details) ? $details : null;
    }

    private function createProductFromLine(
        int $userId,
        int $accountId,
        int $creatorId,
        string $itemType,
        array $line,
        ?array $sourceDetails
    ): Product {
        $name = trim((string) ($line['name'] ?? ''));
        $existing = Product::byUser($userId)
            ->where('item_type', $itemType)
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->first();

        if ($existing) {
            return $existing;
        }

        $category = ProductCategory::resolveForAccount(
            $accountId,
            $creatorId,
            $itemType === Product::ITEM_TYPE_PRODUCT ? 'Products' : 'Services'
        );

        $selected = $sourceDetails['selected_source'] ?? null;
        $best = $sourceDetails['best_source'] ?? null;
        $source = is_array($selected) ? $selected : (is_array($best) ? $best : null);
        $supplierName = is_array($source) ? ($source['name'] ?? null) : null;
        $imageUrl = is_array($source) ? ($source['image_url'] ?? null) : null;
        $sourcePrice = is_array($source) && isset($source['price']) ? (float) $source['price'] : null;

        $price = (float) ($line['price'] ?? 0);
        $costPrice = $sourcePrice ?? $price;
        $marginPercent = 0.0;
        if ($price > 0 && $costPrice > 0) {
            $marginPercent = round((($price - $costPrice) / $price) * 100, 2);
        }

        $description = $line['description'] ?? null;
        if (! $description && is_array($source)) {
            $description = $source['title'] ?? null;
        }

        return Product::create([
            'user_id' => $userId,
            'name' => $name ?: 'Quote line',
            'description' => $description ?: 'Auto-generated from quote line.',
            'category_id' => $category->id,
            'price' => $price,
            'currency_code' => $currencyCode,
            'cost_price' => $costPrice,
            'margin_percent' => $marginPercent,
            'unit' => $line['unit'] ?? null,
            'supplier_name' => $supplierName,
            'stock' => 0,
            'minimum_stock' => 0,
            'is_active' => true,
            'item_type' => $itemType,
            'image' => $imageUrl,
        ]);
    }
}
