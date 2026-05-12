<?php

namespace App\Services\OfferPackages;

use App\Models\OfferPackage;
use App\Models\OfferPackageItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class OfferPackageSalesLineBuilder
{
    public function assertSellableFor(User $actor, OfferPackage $offer, ?array $allowedTypes = null): void
    {
        if ((int) $offer->user_id !== $actor->accountOwnerId()) {
            abort(404);
        }

        if ($offer->status !== OfferPackage::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'offer_package_id' => 'Only active packs and forfaits can be sold.',
            ]);
        }

        if ($allowedTypes !== null && ! in_array($offer->type, $allowedTypes, true)) {
            throw ValidationException::withMessages([
                'offer_package_id' => 'This offer type is not available here.',
            ]);
        }
    }

    public function catalogPayload(OfferPackage $offer): array
    {
        $offer->loadMissing('items');

        return [
            'id' => $offer->id,
            'name' => $offer->name,
            'type' => $offer->type,
            'status' => $offer->status,
            'price' => (float) $offer->price,
            'currency_code' => $offer->currency_code,
            'included_quantity' => $offer->included_quantity,
            'unit_type' => $offer->unit_type,
            'validity_days' => $offer->validity_days,
            'is_recurring' => (bool) $offer->is_recurring,
            'recurrence_frequency' => $offer->recurrence_frequency,
            'renewal_notice_days' => $offer->renewal_notice_days,
            'payment_grace_days' => (int) data_get($offer->metadata, 'recurrence.payment_grace_days', 7),
            'payment_reminder_days' => array_values((array) data_get($offer->metadata, 'recurrence.payment_reminder_days', [0, 3, 6])),
            'description' => $offer->description,
            'items' => $this->snapshotItems($offer),
            'quote_line' => $this->quoteLinePayload($offer),
        ];
    }

    public function quoteLinePayload(OfferPackage $offer, int $quantity = 1, ?float $unitPrice = null): array
    {
        $offer->loadMissing('items');
        $quantity = max(1, $quantity);
        $price = $unitPrice !== null ? max(0, $unitPrice) : (float) $offer->price;

        return [
            'id' => null,
            'name' => $offer->name,
            'description' => $this->summaryDescription($offer),
            'quantity' => $quantity,
            'price' => round($price, 2),
            'total' => round($quantity * $price, 2),
            'item_type' => Product::ITEM_TYPE_SERVICE,
            'source_details' => $this->sourceDetails($offer),
        ];
    }

    public function invoiceItemAttributes(
        OfferPackage $offer,
        int $quantity = 1,
        ?float $unitPrice = null,
        array $meta = []
    ): array {
        $offer->loadMissing('items');
        $quantity = max(1, $quantity);
        $price = $unitPrice !== null ? max(0, $unitPrice) : (float) $offer->price;
        $sourceDetails = $this->sourceDetails($offer);

        return [
            'title' => $offer->name,
            'description' => $this->summaryDescription($offer),
            'quantity' => $quantity,
            'unit_price' => round($price, 2),
            'currency_code' => $offer->currency_code,
            'total' => round($quantity * $price, 2),
            'meta' => array_merge([
                'source' => 'offer_package',
                'offer_package_id' => $offer->id,
                'offer_package_type' => $offer->type,
                'offer_package_snapshot' => $sourceDetails['offer_package'],
                'offer_package_items' => $sourceDetails['offer_package_items'],
                'source_details' => $sourceDetails,
            ], $meta),
        ];
    }

    public function sourceDetails(OfferPackage $offer): array
    {
        $offer->loadMissing('items');
        $snapshot = $this->snapshot($offer);

        return [
            'source' => 'offer_package',
            'offer_package_id' => $offer->id,
            'offer_package_type' => $offer->type,
            'offer_package' => $snapshot,
            'offer_package_items' => $snapshot['items'],
            'summary' => $this->summaryDescription($offer),
            'snapshot_at' => now()->toIso8601String(),
            'non_refundable' => true,
        ];
    }

    private function snapshot(OfferPackage $offer): array
    {
        return [
            'id' => $offer->id,
            'name' => $offer->name,
            'slug' => $offer->slug,
            'type' => $offer->type,
            'description' => $offer->description,
            'pricing_mode' => $offer->pricing_mode,
            'price' => (float) $offer->price,
            'currency_code' => $offer->currency_code,
            'validity_days' => $offer->validity_days,
            'included_quantity' => $offer->included_quantity,
            'unit_type' => $offer->unit_type,
            'is_recurring' => (bool) $offer->is_recurring,
            'recurrence_frequency' => $offer->recurrence_frequency,
            'renewal_notice_days' => $offer->renewal_notice_days,
            'payment_grace_days' => (int) data_get($offer->metadata, 'recurrence.payment_grace_days', 7),
            'payment_reminder_days' => array_values((array) data_get($offer->metadata, 'recurrence.payment_reminder_days', [0, 3, 6])),
            'items' => $this->snapshotItems($offer),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function snapshotItems(OfferPackage $offer): array
    {
        $offer->loadMissing('items');

        return $offer->items
            ->map(fn (OfferPackageItem $item): array => [
                'product_id' => $item->product_id,
                'item_type_snapshot' => $item->item_type_snapshot,
                'name_snapshot' => $item->name_snapshot,
                'description_snapshot' => $item->description_snapshot,
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'included' => (bool) $item->included,
                'sort_order' => (int) $item->sort_order,
            ])
            ->values()
            ->all();
    }

    private function summaryDescription(OfferPackage $offer): string
    {
        $parts = [];
        $description = trim((string) $offer->description);
        if ($description !== '') {
            $parts[] = $description;
        }

        if ($offer->type === OfferPackage::TYPE_FORFAIT && $offer->included_quantity) {
            $unit = $offer->unit_type ?: OfferPackage::UNIT_CREDIT;
            $parts[] = 'Droits inclus: '.$offer->included_quantity.' '.$unit;
        }

        $items = $offer->items
            ->map(fn (OfferPackageItem $item): string => $this->formatQuantity((float) $item->quantity).' x '.$item->name_snapshot)
            ->filter()
            ->values()
            ->all();

        if ($items !== []) {
            $parts[] = 'Inclus: '.implode('; ', $items);
        }

        if ($offer->validity_days) {
            $parts[] = 'Validite: '.$offer->validity_days.' jours';
        }

        if ($offer->is_recurring && $offer->recurrence_frequency) {
            $parts[] = 'Renouvellement: '.$offer->recurrence_frequency;
        }

        return implode("\n", $parts);
    }

    private function formatQuantity(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.');
    }
}
