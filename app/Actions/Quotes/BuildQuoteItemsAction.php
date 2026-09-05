<?php

namespace App\Actions\Quotes;

use App\Models\OfferPackage;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\OfferPackages\OfferPackageSalesLineBuilder;
use App\Services\ResolveTenantCurrency;
use Illuminate\Validation\ValidationException;

class BuildQuoteItemsAction
{
    public function __construct(
        private readonly OfferPackageSalesLineBuilder $offerPackageSalesLineBuilder
    ) {}

    public function execute(
        array $lines,
        string $itemType,
        int $userId,
        int $accountId,
        int $creatorId,
        array $existingOfferPackageSources = []
    ): array {
        $lines = collect($lines);
        $productIds = $lines->pluck('id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $productMap = $productIds->isNotEmpty()
            ? Product::byUser($userId)->whereIn('id', $productIds)->get()->keyBy('id')
            : collect();
        $currencyCode = app(ResolveTenantCurrency::class)->forAccountId($accountId)->currencyCode->value;

        return $lines->map(function (array $line) use ($productMap, $itemType, $userId, $accountId, $creatorId, $currencyCode, $existingOfferPackageSources) {
            $quantity = (int) ($line['quantity'] ?? 1);
            $price = (float) ($line['price'] ?? 0);
            $description = $line['description'] ?? null;
            $sourceDetails = $this->normalizeSourceDetails($line['source_details'] ?? null);

            if ($offerSource = $this->offerPackageFromSourceDetails($sourceDetails, $accountId, $existingOfferPackageSources)) {
                $offer = $offerSource['offer'];
                $line = array_merge(
                    $line,
                    $this->offerPackageSalesLineBuilder->quoteLinePayload($offer, $quantity, $price)
                );
                if ($offerSource['preserve_snapshot']) {
                    $line['name'] = data_get($offerSource['source_details'], 'offer_package.name', $line['name']);
                    $line['description'] = data_get(
                        $offerSource['source_details'],
                        'summary',
                        data_get($offerSource['source_details'], 'offer_package.description', $line['description'])
                    );
                }
                $description = $line['description'];
                $sourceDetails = $offerSource['source_details'];
            }

            $productId = isset($line['id']) && $line['id'] !== null ? (int) $line['id'] : null;
            $lineItemType = $line['item_type'] ?? $itemType;
            $model = null;

            if (! $productId) {
                $product = $this->createProductFromLine(
                    $userId,
                    $accountId,
                    $creatorId,
                    $lineItemType,
                    $line,
                    $sourceDetails,
                    $currencyCode
                );
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

    /**
     * @param  array<int, array<string, mixed>>  $existingOfferPackageSources
     * @return array{offer: OfferPackage, source_details: array<string, mixed>, preserve_snapshot: bool}|null
     */
    private function offerPackageFromSourceDetails(
        ?array $sourceDetails,
        int $accountId,
        array $existingOfferPackageSources
    ): ?array {
        if (($sourceDetails['source'] ?? null) !== 'offer_package') {
            return null;
        }

        $offerPackageId = (int) ($sourceDetails['offer_package_id'] ?? 0);
        $offer = $offerPackageId > 0
            ? OfferPackage::query()
                ->forAccount($accountId)
                ->with('items')
                ->find($offerPackageId)
            : null;
        $existingSourceDetails = $existingOfferPackageSources[$offerPackageId] ?? null;

        if (! $offer || ($offer->status !== OfferPackage::STATUS_ACTIVE && ! $existingSourceDetails)) {
            throw ValidationException::withMessages([
                'product' => 'The selected pack or forfait is no longer available.',
            ]);
        }

        return [
            'offer' => $offer,
            'source_details' => $existingSourceDetails ?: $this->offerPackageSalesLineBuilder->sourceDetails($offer),
            'preserve_snapshot' => $existingSourceDetails !== null,
        ];
    }

    private function createProductFromLine(
        int $userId,
        int $accountId,
        int $creatorId,
        string $itemType,
        array $line,
        ?array $sourceDetails,
        string $currencyCode
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
