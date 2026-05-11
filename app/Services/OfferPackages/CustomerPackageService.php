<?php

namespace App\Services\OfferPackages;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerPackage;
use App\Models\OfferPackage;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerPackageService
{
    public function __construct(
        private readonly OfferPackageSalesLineBuilder $salesLineBuilder
    ) {}

    public function assign(User $actor, Customer $customer, OfferPackage $offer, array $payload = [], array $source = []): CustomerPackage
    {
        $accountId = (int) $actor->accountOwnerId();
        if ((int) $customer->user_id !== $accountId) {
            abort(404);
        }

        $offer->loadMissing('items');
        $this->salesLineBuilder->assertSellableFor($actor, $offer, [OfferPackage::TYPE_FORFAIT]);

        $initialQuantity = max(1, (int) (
            $payload['initial_quantity']
            ?? $payload['quantity']
            ?? $offer->included_quantity
            ?? 1
        ));
        $startsAt = $this->dateOrToday($payload['starts_at'] ?? null);
        $expiresAt = $this->expirationDate($startsAt, $offer, $payload);
        $sourceDetails = $this->salesLineBuilder->sourceDetails($offer);
        $sourceDetails['assignment'] = array_filter([
            'source' => $source['source'] ?? 'manual',
            'assigned_by_user_id' => $actor->id,
            'quote_id' => $source['quote_id'] ?? null,
            'invoice_id' => $source['invoice_id'] ?? null,
            'invoice_item_id' => $source['invoice_item_id'] ?? null,
        ], fn (mixed $value) => $value !== null && $value !== '');

        $package = CustomerPackage::query()->create([
            'user_id' => $accountId,
            'customer_id' => $customer->id,
            'offer_package_id' => $offer->id,
            'quote_id' => $source['quote_id'] ?? null,
            'invoice_id' => $source['invoice_id'] ?? null,
            'invoice_item_id' => $source['invoice_item_id'] ?? null,
            'status' => CustomerPackage::STATUS_ACTIVE,
            'starts_at' => $startsAt->toDateString(),
            'expires_at' => $expiresAt?->toDateString(),
            'initial_quantity' => $initialQuantity,
            'consumed_quantity' => 0,
            'remaining_quantity' => $initialQuantity,
            'unit_type' => $offer->unit_type ?: OfferPackage::UNIT_CREDIT,
            'price_paid' => $payload['price_paid'] ?? $offer->price,
            'currency_code' => $offer->currency_code,
            'source_details' => $sourceDetails,
            'metadata' => array_filter([
                'note' => $payload['note'] ?? null,
            ], fn (mixed $value) => $value !== null && $value !== ''),
        ]);

        ActivityLog::record($actor, $customer, 'customer_package_assigned', [
            'customer_package_id' => $package->id,
            'offer_package_id' => $offer->id,
            'offer_package_name' => $offer->name,
            'quantity' => $initialQuantity,
            'unit_type' => $package->unit_type,
            'expires_at' => $package->expires_at?->toDateString(),
        ], 'Forfait assigned to customer');

        return $package->fresh(['offerPackage', 'usages']);
    }

    public function consume(User $actor, Customer $customer, CustomerPackage $package, array $payload): CustomerPackage
    {
        $accountId = (int) $actor->accountOwnerId();
        if ((int) $customer->user_id !== $accountId || (int) $package->customer_id !== (int) $customer->id) {
            abort(404);
        }

        $quantity = max(1, (int) ($payload['quantity'] ?? 1));
        $allowNegative = (bool) ($payload['allow_negative'] ?? false) && (int) $actor->id === $accountId;
        $usedAt = $payload['used_at'] ?? null
            ? Carbon::parse($payload['used_at'])
            : now();

        $updated = DB::transaction(function () use ($accountId, $actor, $customer, $package, $payload, $quantity, $allowNegative, $usedAt) {
            $locked = CustomerPackage::query()
                ->whereKey($package->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $locked->user_id !== $accountId || (int) $locked->customer_id !== (int) $customer->id) {
                abort(404);
            }

            if ($locked->expires_at && $locked->expires_at->lt(today())) {
                throw ValidationException::withMessages([
                    'customer_package_id' => 'This forfait is expired.',
                ]);
            }

            if ($locked->status !== CustomerPackage::STATUS_ACTIVE) {
                throw ValidationException::withMessages([
                    'customer_package_id' => 'Only active forfaits can be consumed.',
                ]);
            }

            if (! $allowNegative && $quantity > (int) $locked->remaining_quantity) {
                throw ValidationException::withMessages([
                    'quantity' => 'The requested quantity exceeds the remaining balance.',
                ]);
            }

            $locked->usages()->create([
                'user_id' => $accountId,
                'customer_id' => $customer->id,
                'reservation_id' => $payload['reservation_id'] ?? null,
                'work_id' => $payload['work_id'] ?? null,
                'invoice_id' => $payload['invoice_id'] ?? null,
                'product_id' => $payload['product_id'] ?? null,
                'created_by_user_id' => $actor->id,
                'quantity' => $quantity,
                'used_at' => $usedAt,
                'note' => $payload['note'] ?? null,
                'metadata' => array_filter([
                    'allow_negative' => $allowNegative,
                    'source' => $payload['source'] ?? 'manual',
                ], fn (mixed $value) => $value !== null && $value !== ''),
            ]);

            $remaining = (int) $locked->remaining_quantity - $quantity;
            $locked->forceFill([
                'consumed_quantity' => (int) $locked->consumed_quantity + $quantity,
                'remaining_quantity' => $remaining,
                'status' => $remaining <= 0 ? CustomerPackage::STATUS_CONSUMED : CustomerPackage::STATUS_ACTIVE,
                'consumed_at' => $remaining <= 0 ? now() : null,
            ])->save();

            return $locked->fresh(['offerPackage', 'usages.creator']);
        });

        ActivityLog::record($actor, $customer, 'customer_package_consumed', [
            'customer_package_id' => $updated->id,
            'offer_package_id' => $updated->offer_package_id,
            'quantity' => $quantity,
            'remaining_quantity' => $updated->remaining_quantity,
            'unit_type' => $updated->unit_type,
        ], 'Forfait usage recorded');

        return $updated;
    }

    private function dateOrToday(mixed $value): Carbon
    {
        return $value ? Carbon::parse($value)->startOfDay() : today();
    }

    private function expirationDate(Carbon $startsAt, OfferPackage $offer, array $payload): ?Carbon
    {
        if (! empty($payload['expires_at'])) {
            return Carbon::parse($payload['expires_at'])->startOfDay();
        }

        if ($offer->validity_days) {
            return $startsAt->copy()->addDays((int) $offer->validity_days);
        }

        return null;
    }
}
