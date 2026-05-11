<?php

namespace App\Services\OfferPackages;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerPackage;
use App\Models\CustomerPackageUsage;
use App\Models\Invoice;
use App\Models\OfferPackage;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Work;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
        $isRecurring = $this->resolveIsRecurring($offer, $payload);
        $recurrenceFrequency = $this->resolveRecurrenceFrequency(
            $payload['recurrence_frequency'] ?? $offer->recurrence_frequency ?? null,
            $isRecurring
        );
        $recurrenceDates = $isRecurring
            ? $this->recurrenceDates($startsAt, (string) $recurrenceFrequency)
            : ['period_ends_at' => null, 'next_renewal_at' => null];
        $expiresAt = $this->expirationDate($startsAt, $offer, $payload)
            ?? ($isRecurring ? $recurrenceDates['period_ends_at'] : null);
        $sourceDetails = $this->salesLineBuilder->sourceDetails($offer);
        $sourceDetails['assignment'] = array_filter([
            'source' => $source['source'] ?? 'manual',
            'assigned_by_user_id' => $actor->id,
            'quote_id' => $source['quote_id'] ?? null,
            'invoice_id' => $source['invoice_id'] ?? null,
            'invoice_item_id' => $source['invoice_item_id'] ?? null,
        ], fn (mixed $value) => $value !== null && $value !== '');
        if ($isRecurring) {
            $sourceDetails['recurrence'] = [
                'source' => 'offer_package',
                'frequency' => $recurrenceFrequency,
                'current_period_starts_at' => $startsAt->toDateString(),
                'current_period_ends_at' => $recurrenceDates['period_ends_at']?->toDateString(),
                'next_renewal_at' => $recurrenceDates['next_renewal_at']?->toDateString(),
            ];
        }

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
            'is_recurring' => $isRecurring,
            'recurrence_frequency' => $recurrenceFrequency,
            'recurrence_status' => $isRecurring ? CustomerPackage::RECURRENCE_ACTIVE : null,
            'current_period_starts_at' => $isRecurring ? $startsAt->toDateString() : null,
            'current_period_ends_at' => $recurrenceDates['period_ends_at']?->toDateString(),
            'next_renewal_at' => $recurrenceDates['next_renewal_at']?->toDateString(),
            'renewal_count' => 0,
            'source_details' => $sourceDetails,
            'metadata' => array_filter([
                'note' => $payload['note'] ?? null,
                'recurrence_enabled' => $isRecurring,
            ], fn (mixed $value) => $value !== null && $value !== ''),
        ]);

        ActivityLog::record($actor, $customer, 'customer_package_assigned', [
            'customer_package_id' => $package->id,
            'offer_package_id' => $offer->id,
            'offer_package_name' => $offer->name,
            'quantity' => $initialQuantity,
            'unit_type' => $package->unit_type,
            'expires_at' => $package->expires_at?->toDateString(),
            'is_recurring' => $isRecurring,
            'next_renewal_at' => $package->next_renewal_at?->toDateString(),
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

            if ($locked->expires_at && $usedAt->copy()->startOfDay()->gt($locked->expires_at)) {
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

    public function consumeForReservation(User $actor, Reservation $reservation, int $quantity = 1): ?CustomerPackage
    {
        if (! $reservation->client_id) {
            return null;
        }

        $accountId = (int) $actor->accountOwnerId();
        if ((int) $reservation->account_id !== $accountId) {
            abort(404);
        }

        $existing = CustomerPackageUsage::query()
            ->active()
            ->where('reservation_id', $reservation->id)
            ->where('user_id', $accountId)
            ->first();

        if ($existing) {
            return $existing->customerPackage()->with(['offerPackage', 'usages.creator'])->first();
        }

        $customer = Customer::query()
            ->whereKey($reservation->client_id)
            ->where('user_id', $accountId)
            ->first();

        if (! $customer) {
            return null;
        }

        $usedAt = ($reservation->ends_at ?: $reservation->starts_at ?: now())->copy();
        $package = $this->eligibleReservationPackages($reservation, $usedAt)
            ->first();

        if (! $package) {
            $this->setReservationPackageMeta($reservation, [
                'status' => 'skipped',
                'reason' => 'no_eligible_customer_package',
                'checked_at' => now('UTC')->toIso8601String(),
            ]);

            return null;
        }

        $updated = $this->consume($actor, $customer, $package, [
            'quantity' => $quantity,
            'used_at' => $usedAt->toDateString(),
            'reservation_id' => $reservation->id,
            'product_id' => $reservation->service_id,
            'source' => 'reservation_completed',
            'note' => 'Reservation #'.$reservation->id,
        ]);

        $usage = CustomerPackageUsage::query()
            ->active()
            ->where('reservation_id', $reservation->id)
            ->where('customer_package_id', $updated->id)
            ->latest('id')
            ->first();

        $this->setReservationPackageMeta($reservation, [
            'status' => 'consumed',
            'customer_package_id' => $updated->id,
            'customer_package_usage_id' => $usage?->id,
            'quantity' => $quantity,
            'consumed_at' => now('UTC')->toIso8601String(),
            'remaining_quantity' => $updated->remaining_quantity,
        ]);

        return $updated;
    }

    public function restoreReservationUsage(User $actor, Reservation $reservation, string $reason = 'reservation_status_changed'): ?CustomerPackage
    {
        $accountId = (int) $actor->accountOwnerId();
        if ((int) $reservation->account_id !== $accountId) {
            abort(404);
        }

        $usage = CustomerPackageUsage::query()
            ->active()
            ->where('reservation_id', $reservation->id)
            ->where('user_id', $accountId)
            ->latest('id')
            ->first();

        if (! $usage) {
            return null;
        }

        $updated = DB::transaction(function () use ($actor, $reservation, $usage, $reason) {
            $lockedUsage = CustomerPackageUsage::query()
                ->whereKey($usage->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedUsage->reversed_at) {
                return $lockedUsage->customerPackage()->with(['offerPackage', 'usages.creator'])->first();
            }

            $lockedPackage = CustomerPackage::query()
                ->whereKey($lockedUsage->customer_package_id)
                ->lockForUpdate()
                ->firstOrFail();

            $quantity = (int) $lockedUsage->quantity;
            $consumed = max(0, (int) $lockedPackage->consumed_quantity - $quantity);
            $remaining = (int) $lockedPackage->remaining_quantity + $quantity;

            $lockedUsage->forceFill([
                'reversed_at' => now(),
                'reversed_by_user_id' => $actor->id,
                'reversal_reason' => $reason,
            ])->save();

            $lockedPackage->forceFill([
                'consumed_quantity' => $consumed,
                'remaining_quantity' => $remaining,
                'status' => CustomerPackage::STATUS_ACTIVE,
                'consumed_at' => null,
            ])->save();

            $this->setReservationPackageMeta($reservation, [
                'status' => 'restored',
                'customer_package_id' => $lockedPackage->id,
                'customer_package_usage_id' => $lockedUsage->id,
                'quantity' => $quantity,
                'restored_at' => now('UTC')->toIso8601String(),
                'reason' => $reason,
                'remaining_quantity' => $remaining,
            ]);

            return $lockedPackage->fresh(['offerPackage', 'usages.creator']);
        });

        if ($updated) {
            ActivityLog::record($actor, $updated->customer, 'customer_package_usage_restored', [
                'customer_package_id' => $updated->id,
                'reservation_id' => $reservation->id,
                'remaining_quantity' => $updated->remaining_quantity,
                'reason' => $reason,
            ], 'Reservation forfait usage restored');
        }

        return $updated;
    }

    public function renew(User $actor, Customer $customer, CustomerPackage $package, array $payload = []): CustomerPackage
    {
        $accountId = (int) $actor->accountOwnerId();
        if ((int) $customer->user_id !== $accountId || (int) $package->customer_id !== (int) $customer->id) {
            abort(404);
        }

        $renewed = DB::transaction(function () use ($accountId, $actor, $customer, $package, $payload): CustomerPackage {
            $locked = CustomerPackage::query()
                ->whereKey($package->id)
                ->with('offerPackage.items')
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $locked->user_id !== $accountId || (int) $locked->customer_id !== (int) $customer->id) {
                abort(404);
            }

            if (! $locked->is_recurring) {
                throw ValidationException::withMessages([
                    'customer_package_id' => 'Only recurring forfaits can be renewed.',
                ]);
            }

            if ($locked->status === CustomerPackage::STATUS_CANCELLED
                || $locked->recurrence_status === CustomerPackage::RECURRENCE_CANCELLED) {
                throw ValidationException::withMessages([
                    'customer_package_id' => 'This recurring forfait is cancelled.',
                ]);
            }

            $frequency = $this->resolveRecurrenceFrequency(
                $payload['recurrence_frequency']
                    ?? $locked->recurrence_frequency
                    ?? $locked->offerPackage?->recurrence_frequency
                    ?? null,
                true
            );
            $startsAt = $this->dateOrToday(
                $payload['starts_at']
                    ?? $locked->next_renewal_at?->toDateString()
                    ?? $locked->expires_at?->copy()->addDay()->toDateString()
                    ?? null
            );
            $recurrenceDates = $this->recurrenceDates($startsAt, (string) $frequency);
            $expiresAt = ! empty($payload['expires_at'])
                ? Carbon::parse($payload['expires_at'])->startOfDay()
                : $recurrenceDates['period_ends_at'];
            $initialQuantity = max(1, (int) (
                $payload['initial_quantity']
                ?? $locked->offerPackage?->included_quantity
                ?? $locked->initial_quantity
                ?? 1
            ));
            $pricePaid = round((float) (
                $payload['price_paid']
                ?? $locked->offerPackage?->price
                ?? $locked->price_paid
                ?? 0
            ), 2);
            $sourceDetails = $locked->offerPackage instanceof OfferPackage
                ? $this->salesLineBuilder->sourceDetails($locked->offerPackage)
                : (array) ($locked->source_details ?? []);
            $sourceDetails['assignment'] = array_filter([
                'source' => 'recurring_renewal',
                'assigned_by_user_id' => $actor->id,
                'renewed_from_customer_package_id' => $locked->id,
            ], fn (mixed $value) => $value !== null && $value !== '');
            $sourceDetails['recurrence'] = [
                'source' => 'manual_renewal',
                'frequency' => $frequency,
                'renewed_from_customer_package_id' => $locked->id,
                'current_period_starts_at' => $startsAt->toDateString(),
                'current_period_ends_at' => $recurrenceDates['period_ends_at']?->toDateString(),
                'next_renewal_at' => $recurrenceDates['next_renewal_at']?->toDateString(),
            ];

            $newPackage = CustomerPackage::query()->create([
                'user_id' => $accountId,
                'customer_id' => $customer->id,
                'offer_package_id' => $locked->offer_package_id,
                'quote_id' => $payload['quote_id'] ?? null,
                'invoice_id' => $payload['invoice_id'] ?? null,
                'invoice_item_id' => $payload['invoice_item_id'] ?? null,
                'status' => CustomerPackage::STATUS_ACTIVE,
                'starts_at' => $startsAt->toDateString(),
                'expires_at' => $expiresAt?->toDateString(),
                'initial_quantity' => $initialQuantity,
                'consumed_quantity' => 0,
                'remaining_quantity' => $initialQuantity,
                'unit_type' => $locked->unit_type ?: $locked->offerPackage?->unit_type ?: OfferPackage::UNIT_CREDIT,
                'price_paid' => $pricePaid,
                'currency_code' => $locked->currency_code,
                'is_recurring' => true,
                'recurrence_frequency' => $frequency,
                'recurrence_status' => CustomerPackage::RECURRENCE_ACTIVE,
                'current_period_starts_at' => $startsAt->toDateString(),
                'current_period_ends_at' => $recurrenceDates['period_ends_at']?->toDateString(),
                'next_renewal_at' => $recurrenceDates['next_renewal_at']?->toDateString(),
                'renewal_count' => (int) $locked->renewal_count + 1,
                'renewed_from_customer_package_id' => $locked->id,
                'source_details' => $sourceDetails,
                'metadata' => array_filter([
                    'note' => $payload['note'] ?? null,
                    'recurrence_enabled' => true,
                    'renewed_from_customer_package_id' => $locked->id,
                ], fn (mixed $value) => $value !== null && $value !== ''),
            ]);

            $previousMetadata = (array) ($locked->metadata ?? []);
            $previousMetadata['recurrence'] = array_merge((array) ($previousMetadata['recurrence'] ?? []), [
                'renewed_at' => now('UTC')->toIso8601String(),
                'renewed_to_customer_package_id' => $newPackage->id,
                'renewed_by_user_id' => $actor->id,
            ]);

            if ($locked->status === CustomerPackage::STATUS_ACTIVE) {
                $locked->forceFill([
                    'status' => (int) $locked->remaining_quantity <= 0
                        ? CustomerPackage::STATUS_CONSUMED
                        : CustomerPackage::STATUS_EXPIRED,
                    'consumed_at' => (int) $locked->remaining_quantity <= 0 ? now() : $locked->consumed_at,
                    'metadata' => $previousMetadata,
                ])->save();
            } else {
                $locked->forceFill([
                    'metadata' => $previousMetadata,
                ])->save();
            }

            return $newPackage->fresh(['offerPackage', 'usages.creator']);
        });

        ActivityLog::record($actor, $customer, 'customer_package_renewed', [
            'customer_package_id' => $renewed->id,
            'renewed_from_customer_package_id' => $package->id,
            'offer_package_id' => $renewed->offer_package_id,
            'quantity' => $renewed->initial_quantity,
            'unit_type' => $renewed->unit_type,
            'next_renewal_at' => $renewed->next_renewal_at?->toDateString(),
        ], 'Recurring forfait renewed');

        return $renewed;
    }

    public function createRenewalInvoice(User $actor, Customer $customer, CustomerPackage $package, array $payload = []): Invoice
    {
        $accountId = (int) $actor->accountOwnerId();
        if ((int) $customer->user_id !== $accountId || (int) $package->customer_id !== (int) $customer->id) {
            abort(404);
        }

        $invoice = DB::transaction(function () use ($accountId, $actor, $customer, $package, $payload): Invoice {
            $locked = CustomerPackage::query()
                ->whereKey($package->id)
                ->with('offerPackage.items')
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $locked->user_id !== $accountId || (int) $locked->customer_id !== (int) $customer->id) {
                abort(404);
            }

            if (! $locked->is_recurring) {
                throw ValidationException::withMessages([
                    'customer_package_id' => 'Only recurring forfaits can receive renewal invoices.',
                ]);
            }

            if (! $locked->offerPackage instanceof OfferPackage) {
                throw ValidationException::withMessages([
                    'customer_package_id' => 'This forfait no longer has a source offer.',
                ]);
            }

            $existingInvoice = $this->pendingRenewalInvoice($locked);
            if ($existingInvoice) {
                return $existingInvoice->fresh(['items', 'customer']) ?: $existingInvoice;
            }

            $price = round((float) ($payload['price_paid'] ?? $locked->offerPackage->price ?? $locked->price_paid ?? 0), 2);
            $work = Work::query()->create([
                'user_id' => $accountId,
                'customer_id' => $customer->id,
                'job_title' => 'Recurring forfait renewal',
                'instructions' => 'Renewal invoice for '.$locked->offerPackage->name,
                'status' => Work::STATUS_CLOSED,
                'subtotal' => $price,
                'total' => $price,
            ]);

            $invoice = Invoice::query()->create([
                'customer_id' => $customer->id,
                'user_id' => $accountId,
                'created_by_user_id' => $actor->id,
                'work_id' => $work->id,
                'status' => $payload['status'] ?? 'sent',
                'total' => $price,
                'currency_code' => $locked->currency_code,
            ]);

            $itemAttributes = $this->salesLineBuilder->invoiceItemAttributes(
                $locked->offerPackage,
                1,
                $price,
                [
                    'source' => 'customer_package_renewal',
                    'added_from' => 'recurring_renewal_invoice',
                    'customer_package_id' => $locked->id,
                    'renewal_for_customer_package_id' => $locked->id,
                    'recurrence_frequency' => $locked->recurrence_frequency,
                    'next_renewal_at' => $locked->next_renewal_at?->toDateString(),
                ]
            );

            $item = $invoice->items()->create($itemAttributes);
            $metadata = (array) ($locked->metadata ?? []);
            $metadata['recurrence'] = array_merge((array) ($metadata['recurrence'] ?? []), [
                'pending_invoice_id' => $invoice->id,
                'pending_invoice_item_id' => $item->id,
                'pending_invoice_created_at' => now('UTC')->toIso8601String(),
                'pending_invoice_status' => $invoice->status,
                'pending_invoice_total' => $price,
            ]);

            $locked->forceFill([
                'recurrence_status' => CustomerPackage::RECURRENCE_PAYMENT_DUE,
                'metadata' => $metadata,
            ])->save();

            return $invoice->fresh(['items', 'customer']);
        });

        ActivityLog::record($actor, $customer, 'customer_package_renewal_invoice_created', [
            'customer_package_id' => $package->id,
            'invoice_id' => $invoice->id,
            'total' => (float) $invoice->total,
            'currency_code' => $invoice->currency_code,
            'next_renewal_at' => $package->next_renewal_at?->toDateString(),
        ], 'Recurring forfait renewal invoice created');

        return $invoice;
    }

    /**
     * @return \Illuminate\Support\Collection<int, \App\Models\CustomerPackage>
     */
    private function eligibleReservationPackages(Reservation $reservation, Carbon $usedAt): Collection
    {
        $packages = CustomerPackage::query()
            ->forAccount((int) $reservation->account_id)
            ->active()
            ->where('customer_id', $reservation->client_id)
            ->where('remaining_quantity', '>', 0)
            ->whereDate('starts_at', '<=', $usedAt->toDateString())
            ->where(function ($query) use ($usedAt) {
                $query->whereNull('expires_at')
                    ->orWhereDate('expires_at', '>=', $usedAt->toDateString());
            })
            ->with('offerPackage')
            ->get();

        if ($packages->isEmpty()) {
            return $packages;
        }

        $serviceId = (int) ($reservation->service_id ?? 0);

        return $packages
            ->sortBy(function (CustomerPackage $package) use ($serviceId) {
                $matchesService = $serviceId > 0 && collect((array) data_get($package->source_details, 'offer_package_items', []))
                    ->contains(fn (array $item): bool => (int) ($item['product_id'] ?? 0) === $serviceId);

                return sprintf(
                    '%d-%s-%010d',
                    $matchesService ? 0 : 1,
                    $package->expires_at?->toDateString() ?? '9999-12-31',
                    (int) $package->id
                );
            })
            ->values();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function setReservationPackageMeta(Reservation $reservation, array $payload): void
    {
        $metadata = (array) ($reservation->metadata ?? []);
        $metadata['customer_package'] = $payload;

        $reservation->forceFill([
            'metadata' => $metadata,
        ])->save();
    }

    private function pendingRenewalInvoice(CustomerPackage $package): ?Invoice
    {
        $invoiceId = (int) data_get($package->metadata, 'recurrence.pending_invoice_id', 0);
        if ($invoiceId < 1) {
            return null;
        }

        return Invoice::query()
            ->whereKey($invoiceId)
            ->where('user_id', $package->user_id)
            ->whereNotIn('status', ['paid', 'void'])
            ->first();
    }

    private function resolveIsRecurring(OfferPackage $offer, array $payload): bool
    {
        return $offer->type === OfferPackage::TYPE_FORFAIT
            && (bool) ($payload['is_recurring'] ?? $offer->is_recurring ?? false);
    }

    private function resolveRecurrenceFrequency(mixed $value, bool $required = false): ?string
    {
        $frequency = $value ? (string) $value : null;

        if ($frequency === null || $frequency === '') {
            if (! $required) {
                return null;
            }

            $frequency = OfferPackage::RECURRENCE_MONTHLY;
        }

        if (! in_array($frequency, OfferPackage::recurrenceFrequencies(), true)) {
            throw ValidationException::withMessages([
                'recurrence_frequency' => 'Choose a valid renewal frequency.',
            ]);
        }

        return $frequency;
    }

    /**
     * @return array{period_ends_at: \Illuminate\Support\Carbon, next_renewal_at: \Illuminate\Support\Carbon}
     */
    private function recurrenceDates(Carbon $startsAt, string $frequency): array
    {
        $nextRenewalAt = match ($frequency) {
            OfferPackage::RECURRENCE_QUARTERLY => $startsAt->copy()->addMonthsNoOverflow(3),
            OfferPackage::RECURRENCE_YEARLY => $startsAt->copy()->addYearNoOverflow(),
            default => $startsAt->copy()->addMonthNoOverflow(),
        };

        return [
            'period_ends_at' => $nextRenewalAt->copy()->subDay()->startOfDay(),
            'next_renewal_at' => $nextRenewalAt->copy()->startOfDay(),
        ];
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
