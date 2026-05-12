<?php

namespace App\Services\OfferPackages;

use App\Models\CustomerBehaviorEvent;
use App\Models\CustomerPackage;
use App\Models\OfferPackageItem;

class CustomerPackageMarketingEventService
{
    public const EVENT_PURCHASED = 'customer_package_purchased';

    public const EVENT_LOW_BALANCE = 'customer_package_low_balance';

    public const EVENT_EXPIRING_SOON = 'customer_package_expiring_soon';

    public const EVENT_EXPIRED = 'customer_package_expired';

    public const EVENT_RENEWED = 'customer_package_renewed';

    public const EVENT_SUSPENDED = 'customer_package_suspended';

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(CustomerPackage $package, string $eventType, array $metadata = []): ?CustomerBehaviorEvent
    {
        if ((int) $package->customer_id < 1 || (int) $package->user_id < 1) {
            return null;
        }

        $package->loadMissing(['offerPackage.items.product']);
        $primaryItem = $this->primaryItem($package);
        $product = $primaryItem?->product;

        return CustomerBehaviorEvent::query()->create([
            'user_id' => $package->user_id,
            'customer_id' => $package->customer_id,
            'product_id' => $primaryItem?->product_id,
            'category_id' => $product?->category_id,
            'event_type' => $eventType,
            'occurred_at' => now(),
            'metadata' => $this->compact(array_merge([
                'customer_package_id' => $package->id,
                'offer_package_id' => $package->offer_package_id,
                'offer_package_name' => $package->offerPackage?->name
                    ?: data_get($package->source_details, 'offer_package.name'),
                'status' => $package->status,
                'remaining_quantity' => (int) $package->remaining_quantity,
                'initial_quantity' => (int) $package->initial_quantity,
                'unit_type' => $package->unit_type,
                'expires_at' => $package->expires_at?->toDateString(),
                'is_recurring' => (bool) $package->is_recurring,
                'recurrence_frequency' => $package->recurrence_frequency,
                'recurrence_status' => $package->recurrence_status,
                'next_renewal_at' => $package->next_renewal_at?->toDateString(),
            ], $metadata)),
        ]);
    }

    private function primaryItem(CustomerPackage $package): ?OfferPackageItem
    {
        $items = $package->offerPackage?->items;
        if (! $items) {
            return null;
        }

        return $items->first(fn (OfferPackageItem $item): bool => (int) $item->product_id > 0);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function compact(array $payload): array
    {
        return array_filter($payload, fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
