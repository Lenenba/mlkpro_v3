<?php

namespace App\Services\OfferPackages;

use App\Enums\CurrencyCode;
use App\Models\OfferPackage;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OfferPackageService
{
    public function create(User $actor, array $payload): OfferPackage
    {
        $accountId = $actor->accountOwnerId();

        return DB::transaction(function () use ($accountId, $payload): OfferPackage {
            $offer = OfferPackage::query()->create($this->offerAttributes($accountId, $payload));
            $this->syncItems($offer, (array) ($payload['items'] ?? []), $accountId);

            return $offer->fresh(['items.product']);
        });
    }

    public function update(User $actor, OfferPackage $offer, array $payload): OfferPackage
    {
        $this->assertOwnership($actor, $offer);
        $accountId = (int) $offer->user_id;

        return DB::transaction(function () use ($offer, $payload, $accountId): OfferPackage {
            $offer->forceFill($this->offerAttributes($accountId, $payload, $offer))->save();

            if (array_key_exists('items', $payload)) {
                $this->syncItems($offer, (array) $payload['items'], $accountId);
            }

            return $offer->fresh(['items.product']);
        });
    }

    public function duplicate(User $actor, OfferPackage $offer): OfferPackage
    {
        $this->assertOwnership($actor, $offer);

        return DB::transaction(function () use ($offer): OfferPackage {
            $copy = $offer->replicate([
                'slug',
                'status',
                'is_public',
                'created_at',
                'updated_at',
            ]);

            $copy->name = $offer->name.' copy';
            $copy->slug = OfferPackage::uniqueSlug((int) $offer->user_id, $copy->name);
            $copy->status = OfferPackage::STATUS_DRAFT;
            $copy->is_public = false;
            $copy->save();

            foreach ($offer->items as $item) {
                $copy->items()->create($item->replicate([
                    'offer_package_id',
                    'created_at',
                    'updated_at',
                ])->toArray());
            }

            return $copy->fresh(['items.product']);
        });
    }

    public function archive(User $actor, OfferPackage $offer): OfferPackage
    {
        $this->assertOwnership($actor, $offer);

        $offer->forceFill([
            'status' => OfferPackage::STATUS_ARCHIVED,
            'is_public' => false,
        ])->save();

        return $offer->fresh(['items.product']);
    }

    public function reactivate(User $actor, OfferPackage $offer): OfferPackage
    {
        $this->assertOwnership($actor, $offer);

        $offer->forceFill([
            'status' => OfferPackage::STATUS_ACTIVE,
        ])->save();

        return $offer->fresh(['items.product']);
    }

    public function assertOwnership(User $actor, OfferPackage $offer): void
    {
        if ((int) $offer->user_id !== $actor->accountOwnerId()) {
            abort(404);
        }
    }

    private function offerAttributes(int $accountId, array $payload, ?OfferPackage $existing = null): array
    {
        $type = (string) ($payload['type'] ?? $existing?->type ?? OfferPackage::TYPE_PACK);
        if (! in_array($type, OfferPackage::types(), true)) {
            throw ValidationException::withMessages([
                'type' => 'Choose a valid offer type.',
            ]);
        }

        $status = (string) ($payload['status'] ?? $existing?->status ?? OfferPackage::STATUS_DRAFT);
        if (! in_array($status, OfferPackage::statuses(), true)) {
            throw ValidationException::withMessages([
                'status' => 'Choose a valid offer status.',
            ]);
        }

        $currencyCode = CurrencyCode::tryFromMixed($payload['currency_code'] ?? $existing?->currency_code)
            ?->value ?? CurrencyCode::default()->value;

        $includedQuantity = $type === OfferPackage::TYPE_FORFAIT
            ? max(1, (int) ($payload['included_quantity'] ?? $existing?->included_quantity ?? 1))
            : null;
        $unitType = $type === OfferPackage::TYPE_FORFAIT
            ? (string) ($payload['unit_type'] ?? $existing?->unit_type ?? OfferPackage::UNIT_SESSION)
            : null;
        $isRecurring = $type === OfferPackage::TYPE_FORFAIT
            && (bool) ($payload['is_recurring'] ?? $existing?->is_recurring ?? false);
        $recurrenceFrequency = null;
        $renewalNoticeDays = null;
        $carryOverUnusedBalance = false;
        $paymentGraceDays = null;
        $paymentReminderDays = [];

        if ($unitType !== null && ! in_array($unitType, OfferPackage::unitTypes(), true)) {
            throw ValidationException::withMessages([
                'unit_type' => 'Choose a valid forfait unit.',
            ]);
        }

        if ($isRecurring) {
            $recurrenceFrequency = (string) (
                $payload['recurrence_frequency']
                ?? $existing?->recurrence_frequency
                ?? OfferPackage::RECURRENCE_MONTHLY
            );

            if (! in_array($recurrenceFrequency, OfferPackage::recurrenceFrequencies(), true)) {
                throw ValidationException::withMessages([
                    'recurrence_frequency' => 'Choose a valid renewal frequency.',
                ]);
            }

            $renewalNoticeDays = $this->nullablePositiveInt(
                $payload,
                'renewal_notice_days',
                $existing?->renewal_notice_days ?? 7
            ) ?? 7;

            $carryOverUnusedBalance = (bool) (
                $payload['carry_over_unused_balance']
                ?? data_get($existing?->metadata, 'recurrence.carry_over_unused_balance', false)
            );

            $paymentGraceDays = $this->nullablePositiveInt(
                $payload,
                'payment_grace_days',
                data_get($existing?->metadata, 'recurrence.payment_grace_days', 7)
            ) ?? 7;
            $paymentReminderDays = $this->normalizePaymentReminderDays(
                $payload['payment_reminder_days']
                ?? data_get($existing?->metadata, 'recurrence.payment_reminder_days', [0, 3, 6])
            );
        }

        $metadata = (array) ($existing?->metadata ?? []);
        $metadata['phase'] = 'v1_catalog';
        $metadata['non_refundable'] = true;
        $metadata['nested_packages_allowed'] = false;
        $metadata['optional_items_allowed'] = false;
        $metadata['recurrence_enabled'] = $isRecurring;
        $metadata['recurrence'] = array_merge((array) data_get($metadata, 'recurrence', []), [
            'carry_over_unused_balance' => $carryOverUnusedBalance,
            'payment_grace_days' => $paymentGraceDays,
            'payment_reminder_days' => $paymentReminderDays,
        ]);

        return [
            'user_id' => $accountId,
            'name' => trim((string) ($payload['name'] ?? $existing?->name ?? '')),
            'type' => $type,
            'status' => $status,
            'description' => $this->nullableString($payload, 'description', $existing?->description),
            'image_path' => $this->nullableString($payload, 'image_path', $existing?->image_path),
            'pricing_mode' => OfferPackage::PRICING_FIXED,
            'price' => round((float) ($payload['price'] ?? $existing?->price ?? 0), 2),
            'currency_code' => $currencyCode,
            'validity_days' => $this->nullablePositiveInt($payload, 'validity_days', $existing?->validity_days),
            'included_quantity' => $includedQuantity,
            'unit_type' => $unitType,
            'is_public' => (bool) ($payload['is_public'] ?? $existing?->is_public ?? false),
            'is_recurring' => $isRecurring,
            'recurrence_frequency' => $recurrenceFrequency,
            'renewal_notice_days' => $renewalNoticeDays,
            'metadata' => $metadata,
        ];
    }

    private function syncItems(OfferPackage $offer, array $items, int $accountId): void
    {
        if ($items === []) {
            throw ValidationException::withMessages([
                'items' => 'Add at least one product or service to this offer.',
            ]);
        }

        $offer->items()->delete();

        foreach (array_values($items) as $index => $item) {
            if ((bool) ($item['is_optional'] ?? false)) {
                throw ValidationException::withMessages([
                    'items' => 'Optional items are not available in V1.',
                ]);
            }

            $product = Product::query()
                ->byUser($accountId)
                ->whereKey((int) ($item['product_id'] ?? 0))
                ->first();

            if (! $product) {
                throw ValidationException::withMessages([
                    'items' => 'One included product or service is invalid.',
                ]);
            }

            $quantity = max(0.01, (float) ($item['quantity'] ?? 1));
            $unitPrice = array_key_exists('unit_price', $item)
                ? max(0, (float) $item['unit_price'])
                : (float) $product->price;

            $offer->items()->create([
                'product_id' => $product->id,
                'item_type_snapshot' => (string) $product->item_type,
                'name_snapshot' => (string) $product->name,
                'description_snapshot' => $product->description,
                'quantity' => $quantity,
                'unit_price' => round($unitPrice, 2),
                'included' => true,
                'is_optional' => false,
                'sort_order' => (int) ($item['sort_order'] ?? $index),
                'metadata' => [
                    'source' => 'product_catalog',
                    'sku' => $product->sku,
                    'unit' => $product->unit,
                ],
            ]);
        }
    }

    private function nullableString(array $payload, string $key, ?string $fallback = null): ?string
    {
        if (! array_key_exists($key, $payload)) {
            return $fallback;
        }

        $value = trim((string) $payload[$key]);

        return $value !== '' ? $value : null;
    }

    private function nullablePositiveInt(array $payload, string $key, mixed $fallback = null): ?int
    {
        if (! array_key_exists($key, $payload)) {
            return $fallback !== null ? (int) $fallback : null;
        }

        $value = (int) $payload[$key];

        return $value > 0 ? $value : null;
    }

    /**
     * @return array<int, int>
     */
    private function normalizePaymentReminderDays(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[,\s]+/', $value) ?: [];
        }

        if (! is_array($value)) {
            $value = [$value];
        }

        $days = array_values(array_unique(array_filter(array_map(
            fn (mixed $day): int => max(0, (int) $day),
            $value
        ), fn (int $day): bool => $day <= 365)));
        sort($days);

        return $days !== [] ? $days : [0, 3, 6];
    }
}
