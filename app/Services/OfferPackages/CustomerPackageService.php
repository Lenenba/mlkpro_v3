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
        private readonly OfferPackageSalesLineBuilder $salesLineBuilder,
        private readonly CustomerPackageClientNotificationService $clientNotifications,
        private readonly CustomerPackageMarketingEventService $marketingEvents
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
        $carryOverUnusedBalance = $isRecurring
            ? $this->resolveCarryOverUnusedBalance($offer, $payload)
            : false;
        $recurrenceFrequency = $this->resolveRecurrenceFrequency(
            $payload['recurrence_frequency'] ?? $offer->recurrence_frequency ?? null,
            $isRecurring
        );
        $recurrenceDates = $isRecurring
            ? $this->recurrenceDates($startsAt, (string) $recurrenceFrequency)
            : ['period_ends_at' => null, 'next_renewal_at' => null];
        $paymentGraceDays = $isRecurring ? $this->paymentGraceDaysForOffer($offer, $payload) : null;
        $paymentReminderDays = $isRecurring ? $this->paymentReminderDaysForOffer($offer, $payload) : [];
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
                'period_allocation_quantity' => $initialQuantity,
                'carry_over_unused_balance' => $carryOverUnusedBalance,
                'payment_grace_days' => $paymentGraceDays,
                'payment_reminder_days' => $paymentReminderDays,
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
                'recurrence' => $isRecurring ? [
                    'period_allocation_quantity' => $initialQuantity,
                    'carry_over_unused_balance' => $carryOverUnusedBalance,
                    'carried_over_quantity' => 0,
                    'payment_grace_days' => $paymentGraceDays,
                    'payment_reminder_days' => $paymentReminderDays,
                ] : null,
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

        $this->marketingEvents->record($package, CustomerPackageMarketingEventService::EVENT_PURCHASED, [
            'source' => $source['source'] ?? 'manual',
            'assigned_by_user_id' => $actor->id,
            'quote_id' => $source['quote_id'] ?? null,
            'invoice_id' => $source['invoice_id'] ?? null,
            'invoice_item_id' => $source['invoice_item_id'] ?? null,
            'price_paid' => (float) $package->price_paid,
            'currency_code' => $package->currency_code,
        ]);

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
            $periodAllocationQuantity = $this->renewalAllocationQuantity($locked, $payload);
            $carryOverUnusedBalance = $this->resolveCustomerPackageCarryOverUnusedBalance($locked, $payload);
            $paymentGraceDays = $this->paymentGraceDaysForPackage($locked);
            $paymentReminderDays = $this->paymentReminderDaysForPackage($locked);
            $carriedOverQuantity = $carryOverUnusedBalance
                ? max(0, (int) $locked->remaining_quantity)
                : 0;
            $initialQuantity = $periodAllocationQuantity + $carriedOverQuantity;
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
                'period_allocation_quantity' => $periodAllocationQuantity,
                'carry_over_unused_balance' => $carryOverUnusedBalance,
                'carried_over_quantity' => $carriedOverQuantity,
                'payment_grace_days' => $paymentGraceDays,
                'payment_reminder_days' => $paymentReminderDays,
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
                    'recurrence' => [
                        'period_allocation_quantity' => $periodAllocationQuantity,
                        'carry_over_unused_balance' => $carryOverUnusedBalance,
                        'carried_over_quantity' => $carriedOverQuantity,
                        'renewed_from_remaining_quantity' => (int) $locked->remaining_quantity,
                        'payment_grace_days' => $paymentGraceDays,
                        'payment_reminder_days' => $paymentReminderDays,
                    ],
                ], fn (mixed $value) => $value !== null && $value !== ''),
            ]);

            $previousMetadata = (array) ($locked->metadata ?? []);
            $previousMetadata['recurrence'] = array_merge((array) ($previousMetadata['recurrence'] ?? []), [
                'renewed_at' => now('UTC')->toIso8601String(),
                'renewed_to_customer_package_id' => $newPackage->id,
                'renewed_by_user_id' => $actor->id,
                'carried_over_quantity' => $carriedOverQuantity,
                'carry_over_unused_balance' => $carryOverUnusedBalance,
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
            'period_allocation_quantity' => (int) data_get($renewed->metadata, 'recurrence.period_allocation_quantity', $renewed->initial_quantity),
            'carried_over_quantity' => (int) data_get($renewed->metadata, 'recurrence.carried_over_quantity', 0),
            'unit_type' => $renewed->unit_type,
            'next_renewal_at' => $renewed->next_renewal_at?->toDateString(),
        ], 'Recurring forfait renewed');

        $this->marketingEvents->record($renewed, CustomerPackageMarketingEventService::EVENT_RENEWED, [
            'renewed_from_customer_package_id' => $package->id,
            'invoice_id' => $renewed->invoice_id,
            'invoice_item_id' => $renewed->invoice_item_id,
            'period_allocation_quantity' => (int) data_get($renewed->metadata, 'recurrence.period_allocation_quantity', $renewed->initial_quantity),
            'carried_over_quantity' => (int) data_get($renewed->metadata, 'recurrence.carried_over_quantity', 0),
            'price_paid' => (float) $renewed->price_paid,
            'currency_code' => $renewed->currency_code,
        ]);

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
                    'carry_over_unused_balance' => $this->resolveCustomerPackageCarryOverUnusedBalance($locked, []),
                ]
            );

            $item = $invoice->items()->create($itemAttributes);
            $metadata = (array) ($locked->metadata ?? []);
            $recurrenceMeta = (array) ($metadata['recurrence'] ?? []);
            $renewalMeta = [
                'pending_invoice_id' => $invoice->id,
                'pending_invoice_item_id' => $item->id,
                'pending_invoice_created_at' => now('UTC')->toIso8601String(),
                'pending_invoice_status' => $invoice->status,
                'pending_invoice_total' => $price,
            ];
            $autoPaymentContext = $this->renewalAutoPaymentContext($customer, $locked);
            if ($autoPaymentContext !== []) {
                $renewalMeta['auto_payment'] = array_merge(
                    (array) ($recurrenceMeta['auto_payment'] ?? []),
                    $autoPaymentContext,
                    [
                        'linked_invoice_id' => $invoice->id,
                        'linked_at' => now('UTC')->toIso8601String(),
                    ]
                );
            }
            $metadata['recurrence'] = array_merge($recurrenceMeta, $renewalMeta);

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

    public function renewFromPaidInvoice(Invoice $invoice, ?User $actor = null): ?CustomerPackage
    {
        $invoice->loadMissing(['items', 'customer']);

        if ($invoice->status !== 'paid') {
            return null;
        }

        $renewalItem = $invoice->items
            ->first(fn ($item): bool => (int) data_get($item->meta, 'renewal_for_customer_package_id', 0) > 0);
        $sourcePackageId = (int) data_get($renewalItem?->meta, 'renewal_for_customer_package_id', 0);
        if ($sourcePackageId < 1) {
            return null;
        }

        $existingRenewal = CustomerPackage::query()
            ->forAccount((int) $invoice->user_id)
            ->where('renewed_from_customer_package_id', $sourcePackageId)
            ->where('invoice_id', $invoice->id)
            ->with(['offerPackage', 'usages.creator'])
            ->first();
        if ($existingRenewal) {
            return $existingRenewal;
        }

        $sourcePackage = CustomerPackage::query()
            ->forAccount((int) $invoice->user_id)
            ->whereKey($sourcePackageId)
            ->with(['customer', 'offerPackage.items'])
            ->first();
        if (! $sourcePackage || ! $sourcePackage->customer instanceof Customer) {
            return null;
        }

        if ((int) data_get($sourcePackage->metadata, 'recurrence.pending_invoice_id', 0) !== (int) $invoice->id) {
            return null;
        }

        $owner = $actor && (int) $actor->accountOwnerId() === (int) $invoice->user_id
            ? $actor
            : User::query()->find((int) $invoice->user_id);
        if (! $owner) {
            return null;
        }

        $startsAt = $sourcePackage->next_renewal_at?->toDateString()
            ?? $sourcePackage->expires_at?->copy()->addDay()->toDateString()
            ?? now()->toDateString();
        $renewed = $this->renew($owner, $sourcePackage->customer, $sourcePackage, [
            'starts_at' => $startsAt,
            'price_paid' => (float) $invoice->total,
            'invoice_id' => $invoice->id,
            'invoice_item_id' => $renewalItem?->id,
            'note' => 'Renewed from paid invoice '.$invoice->number,
        ]);

        $previous = CustomerPackage::query()->whereKey($sourcePackage->id)->first();
        if ($previous) {
            $metadata = (array) ($previous->metadata ?? []);
            $metadata['recurrence'] = array_merge((array) ($metadata['recurrence'] ?? []), [
                'pending_invoice_status' => $invoice->status,
                'paid_invoice_id' => $invoice->id,
                'paid_invoice_processed_at' => now('UTC')->toIso8601String(),
                'paid_renewed_to_customer_package_id' => $renewed->id,
            ]);

            $previous->forceFill([
                'recurrence_status' => CustomerPackage::RECURRENCE_ACTIVE,
                'metadata' => $metadata,
            ])->save();
        }

        ActivityLog::record($owner, $sourcePackage->customer, 'customer_package_renewal_payment_received', [
            'customer_package_id' => $sourcePackage->id,
            'renewed_to_customer_package_id' => $renewed->id,
            'invoice_id' => $invoice->id,
            'total' => (float) $invoice->total,
            'currency_code' => $invoice->currency_code,
        ], 'Recurring forfait renewal payment received');

        if ($this->clientNotifications->notifyResumed($sourcePackage, $renewed, $invoice)) {
            ActivityLog::record($owner, $sourcePackage->customer, 'customer_package_client_resume_notice_sent', [
                'customer_package_id' => $sourcePackage->id,
                'renewed_to_customer_package_id' => $renewed->id,
                'invoice_id' => $invoice->id,
            ], 'Recurring forfait resume notice sent to client');
        }

        return $renewed;
    }

    public function cancelRecurring(User $actor, Customer $customer, CustomerPackage $package, array $payload): CustomerPackage
    {
        $accountId = (int) $actor->accountOwnerId();
        if ((int) $customer->user_id !== $accountId || (int) $package->customer_id !== (int) $customer->id) {
            abort(404);
        }

        $mode = (string) ($payload['mode'] ?? 'end_of_period');
        if (! in_array($mode, ['end_of_period', 'immediate'], true)) {
            throw ValidationException::withMessages([
                'mode' => 'Choose a valid cancellation mode.',
            ]);
        }

        $reason = trim((string) ($payload['reason'] ?? ''));
        if ($mode === 'immediate' && $reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'A cancellation reason is required.',
            ]);
        }

        $updated = DB::transaction(function () use ($accountId, $actor, $customer, $package, $mode, $reason): CustomerPackage {
            $locked = CustomerPackage::query()
                ->whereKey($package->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $locked->user_id !== $accountId || (int) $locked->customer_id !== (int) $customer->id) {
                abort(404);
            }

            if (! $locked->is_recurring) {
                throw ValidationException::withMessages([
                    'customer_package_id' => 'Only recurring forfaits can be cancelled here.',
                ]);
            }

            if ($locked->status === CustomerPackage::STATUS_CANCELLED
                || $locked->recurrence_status === CustomerPackage::RECURRENCE_CANCELLED) {
                throw ValidationException::withMessages([
                    'customer_package_id' => 'This recurring forfait is already cancelled.',
                ]);
            }

            $metadata = (array) ($locked->metadata ?? []);
            $recurrence = (array) ($metadata['recurrence'] ?? []);
            $now = now('UTC');
            $effectiveAt = $this->recurrencePeriodEndDate($locked) ?? today();

            $this->voidPendingRenewalInvoice($locked, $metadata, $actor, 'recurring_package_cancelled');
            $recurrence = (array) ($metadata['recurrence'] ?? []);

            $recurrence = array_merge($recurrence, [
                'cancellation_mode' => $mode,
                'cancellation_reason' => $reason !== '' ? $reason : null,
                'cancellation_requested_at' => $now->toIso8601String(),
                'cancelled_by_user_id' => $actor->id,
                'cancel_at_period_end' => $mode === 'end_of_period',
                'cancellation_effective_at' => $mode === 'end_of_period'
                    ? $effectiveAt->toDateString()
                    : $now->toDateString(),
            ]);
            $metadata['recurrence'] = array_filter($recurrence, fn (mixed $value): bool => $value !== null && $value !== '');

            if ($mode === 'immediate') {
                $locked->forceFill([
                    'status' => CustomerPackage::STATUS_CANCELLED,
                    'recurrence_status' => CustomerPackage::RECURRENCE_CANCELLED,
                    'next_renewal_at' => null,
                    'cancelled_at' => $now,
                    'metadata' => $metadata,
                ])->save();
            } else {
                $locked->forceFill([
                    'recurrence_status' => CustomerPackage::RECURRENCE_CANCELLED,
                    'next_renewal_at' => null,
                    'expires_at' => $locked->expires_at ?: $effectiveAt->toDateString(),
                    'metadata' => $metadata,
                ])->save();
            }

            return $locked->fresh(['offerPackage', 'usages.creator']);
        });

        ActivityLog::record($actor, $customer, $mode === 'immediate'
            ? 'customer_package_recurring_cancelled_immediately'
            : 'customer_package_recurring_cancellation_scheduled', [
                'customer_package_id' => $updated->id,
                'offer_package_id' => $updated->offer_package_id,
                'mode' => $mode,
                'reason' => $reason !== '' ? $reason : null,
                'effective_at' => data_get($updated->metadata, 'recurrence.cancellation_effective_at'),
            ], $mode === 'immediate'
                ? 'Recurring forfait cancelled immediately'
                : 'Recurring forfait cancellation scheduled');

        return $updated;
    }

    public function changeRecurringOffer(
        User $actor,
        Customer $customer,
        CustomerPackage $package,
        OfferPackage $targetOffer,
        string $changeType,
        array $payload = []
    ): CustomerPackage {
        $accountId = (int) $actor->accountOwnerId();
        if ((int) $customer->user_id !== $accountId || (int) $package->customer_id !== (int) $customer->id) {
            abort(404);
        }

        $changeType = in_array($changeType, ['upgrade', 'downgrade'], true) ? $changeType : 'upgrade';
        $targetOffer->loadMissing('items');
        $this->salesLineBuilder->assertSellableFor($actor, $targetOffer, [OfferPackage::TYPE_FORFAIT]);
        if (! $targetOffer->is_recurring) {
            throw ValidationException::withMessages([
                'target_offer_package_id' => 'Choose a recurring forfait offer.',
            ]);
        }

        $changed = DB::transaction(function () use ($accountId, $actor, $customer, $package, $targetOffer, $changeType, $payload): CustomerPackage {
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
                    'customer_package_id' => 'Only recurring forfaits can be changed here.',
                ]);
            }

            if ($locked->status === CustomerPackage::STATUS_CANCELLED
                || $locked->recurrence_status === CustomerPackage::RECURRENCE_CANCELLED) {
                throw ValidationException::withMessages([
                    'customer_package_id' => 'This recurring forfait is cancelled.',
                ]);
            }

            if ((int) $locked->offer_package_id === (int) $targetOffer->id) {
                throw ValidationException::withMessages([
                    'target_offer_package_id' => 'Choose a different recurring offer.',
                ]);
            }

            $frequency = $this->resolveRecurrenceFrequency(
                $payload['recurrence_frequency'] ?? $targetOffer->recurrence_frequency ?? null,
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
            $periodAllocationQuantity = max(1, (int) (
                $payload['initial_quantity']
                ?? $targetOffer->included_quantity
                ?? 1
            ));
            $carryOverUnusedBalance = $this->resolveCarryOverUnusedBalance($targetOffer, $payload);
            $paymentGraceDays = $this->paymentGraceDaysForOffer($targetOffer, $payload);
            $paymentReminderDays = $this->paymentReminderDaysForOffer($targetOffer, $payload);
            $carriedOverQuantity = $carryOverUnusedBalance
                ? max(0, (int) $locked->remaining_quantity)
                : 0;
            $initialQuantity = $periodAllocationQuantity + $carriedOverQuantity;
            $pricePaid = round((float) (
                $payload['price_paid']
                ?? $targetOffer->price
                ?? 0
            ), 2);
            $sourceDetails = $this->salesLineBuilder->sourceDetails($targetOffer);
            $sourceDetails['assignment'] = array_filter([
                'source' => 'recurring_'.$changeType,
                'assigned_by_user_id' => $actor->id,
                'changed_from_customer_package_id' => $locked->id,
                'changed_from_offer_package_id' => $locked->offer_package_id,
                'change_reason' => $payload['reason'] ?? $payload['note'] ?? null,
            ], fn (mixed $value) => $value !== null && $value !== '');
            $sourceDetails['recurrence'] = [
                'source' => 'recurring_'.$changeType,
                'frequency' => $frequency,
                'changed_from_customer_package_id' => $locked->id,
                'changed_from_offer_package_id' => $locked->offer_package_id,
                'current_period_starts_at' => $startsAt->toDateString(),
                'current_period_ends_at' => $recurrenceDates['period_ends_at']?->toDateString(),
                'next_renewal_at' => $recurrenceDates['next_renewal_at']?->toDateString(),
                'period_allocation_quantity' => $periodAllocationQuantity,
                'carry_over_unused_balance' => $carryOverUnusedBalance,
                'carried_over_quantity' => $carriedOverQuantity,
                'payment_grace_days' => $paymentGraceDays,
                'payment_reminder_days' => $paymentReminderDays,
            ];

            $newPackage = CustomerPackage::query()->create([
                'user_id' => $accountId,
                'customer_id' => $customer->id,
                'offer_package_id' => $targetOffer->id,
                'quote_id' => $payload['quote_id'] ?? null,
                'invoice_id' => $payload['invoice_id'] ?? null,
                'invoice_item_id' => $payload['invoice_item_id'] ?? null,
                'status' => CustomerPackage::STATUS_ACTIVE,
                'starts_at' => $startsAt->toDateString(),
                'expires_at' => $expiresAt?->toDateString(),
                'initial_quantity' => $initialQuantity,
                'consumed_quantity' => 0,
                'remaining_quantity' => $initialQuantity,
                'unit_type' => $targetOffer->unit_type ?: OfferPackage::UNIT_CREDIT,
                'price_paid' => $pricePaid,
                'currency_code' => $targetOffer->currency_code,
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
                    'recurrence' => [
                        'change_type' => $changeType,
                        'changed_from_customer_package_id' => $locked->id,
                        'changed_from_offer_package_id' => $locked->offer_package_id,
                        'period_allocation_quantity' => $periodAllocationQuantity,
                        'carry_over_unused_balance' => $carryOverUnusedBalance,
                        'carried_over_quantity' => $carriedOverQuantity,
                        'renewed_from_remaining_quantity' => (int) $locked->remaining_quantity,
                        'payment_grace_days' => $paymentGraceDays,
                        'payment_reminder_days' => $paymentReminderDays,
                    ],
                ], fn (mixed $value) => $value !== null && $value !== ''),
            ]);

            $previousMetadata = (array) ($locked->metadata ?? []);
            $this->voidPendingRenewalInvoice($locked, $previousMetadata, $actor, 'recurring_package_'.$changeType);
            $previousRecurrence = (array) ($previousMetadata['recurrence'] ?? []);
            $previousRecurrence = array_merge($previousRecurrence, [
                'change_type' => $changeType,
                'changed_at' => now('UTC')->toIso8601String(),
                'changed_by_user_id' => $actor->id,
                'changed_to_customer_package_id' => $newPackage->id,
                'changed_to_offer_package_id' => $targetOffer->id,
                'change_effective_at' => $startsAt->toDateString(),
                'change_reason' => $payload['reason'] ?? $payload['note'] ?? null,
                'cancel_at_period_end' => true,
                'cancellation_effective_at' => $startsAt->copy()->subDay()->toDateString(),
                'carried_over_quantity' => $carriedOverQuantity,
                'carry_over_unused_balance' => $carryOverUnusedBalance,
            ]);
            $previousMetadata['recurrence'] = array_filter($previousRecurrence, fn (mixed $value): bool => $value !== null && $value !== '');

            $oldExpiresAt = $startsAt->copy()->subDay()->startOfDay();
            if ($locked->expires_at && $locked->expires_at->lt($oldExpiresAt)) {
                $oldExpiresAt = $locked->expires_at->copy()->startOfDay();
            }

            $oldStatus = $oldExpiresAt->lt(today())
                ? ((int) $locked->remaining_quantity <= 0 ? CustomerPackage::STATUS_CONSUMED : CustomerPackage::STATUS_EXPIRED)
                : $locked->status;

            $locked->forceFill([
                'status' => $oldStatus,
                'expires_at' => $oldExpiresAt->toDateString(),
                'recurrence_status' => CustomerPackage::RECURRENCE_CANCELLED,
                'next_renewal_at' => null,
                'metadata' => $previousMetadata,
            ])->save();

            return $newPackage->fresh(['offerPackage', 'usages.creator']);
        });

        ActivityLog::record($actor, $customer, 'customer_package_recurring_'.$changeType, [
            'customer_package_id' => $changed->id,
            'changed_from_customer_package_id' => $package->id,
            'offer_package_id' => $changed->offer_package_id,
            'changed_from_offer_package_id' => $package->offer_package_id,
            'change_type' => $changeType,
            'quantity' => $changed->initial_quantity,
            'unit_type' => $changed->unit_type,
            'starts_at' => $changed->starts_at?->toDateString(),
            'next_renewal_at' => $changed->next_renewal_at?->toDateString(),
        ], $changeType === 'upgrade'
            ? 'Recurring forfait upgraded'
            : 'Recurring forfait downgraded');

        return $changed;
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

    private function recurrencePeriodEndDate(CustomerPackage $package): ?Carbon
    {
        if ($package->current_period_ends_at) {
            return $package->current_period_ends_at->copy()->startOfDay();
        }

        if ($package->expires_at) {
            return $package->expires_at->copy()->startOfDay();
        }

        if ($package->next_renewal_at) {
            return $package->next_renewal_at->copy()->subDay()->startOfDay();
        }

        return null;
    }

    private function voidPendingRenewalInvoice(
        CustomerPackage $package,
        array &$metadata,
        User $actor,
        string $reason
    ): void {
        $invoiceId = (int) data_get($metadata, 'recurrence.pending_invoice_id', 0);
        if ($invoiceId < 1) {
            return;
        }

        $invoice = Invoice::query()
            ->whereKey($invoiceId)
            ->where('user_id', $package->user_id)
            ->whereNotIn('status', ['paid', 'void'])
            ->first();

        if (! $invoice) {
            return;
        }

        $previousStatus = $invoice->status;
        $invoice->forceFill(['status' => 'void'])->save();

        $recurrence = (array) ($metadata['recurrence'] ?? []);
        $metadata['recurrence'] = array_merge($recurrence, [
            'pending_invoice_status' => 'void',
            'pending_invoice_voided_at' => now('UTC')->toIso8601String(),
            'pending_invoice_void_reason' => $reason,
        ]);

        ActivityLog::record($actor, $invoice, 'status_changed', [
            'from' => $previousStatus,
            'to' => 'void',
            'customer_package_id' => $package->id,
            'reason' => $reason,
        ], 'Renewal invoice voided');
    }

    /**
     * @return array{stripe_customer_id?: string, stripe_payment_method_id?: string, source?: string}
     */
    private function renewalAutoPaymentContext(Customer $customer, CustomerPackage $package): array
    {
        $metadata = (array) ($package->metadata ?? []);
        $sourceDetails = (array) ($package->source_details ?? []);

        $customerId = $this->firstString(
            data_get($metadata, 'recurrence.auto_payment.stripe_customer_id'),
            data_get($metadata, 'recurrence.stripe_customer_id'),
            data_get($metadata, 'stripe_customer_id'),
            data_get($sourceDetails, 'recurrence.stripe_customer_id'),
            $customer->stripe_customer_id ?? null,
            $customer->portalUser?->stripe_customer_id
        );

        $paymentMethodId = $this->firstString(
            data_get($metadata, 'recurrence.auto_payment.stripe_payment_method_id'),
            data_get($metadata, 'recurrence.stripe_payment_method_id'),
            data_get($metadata, 'stripe_payment_method_id'),
            data_get($sourceDetails, 'recurrence.stripe_payment_method_id'),
            $customer->stripe_default_payment_method_id ?? null
        );

        return array_filter([
            'stripe_customer_id' => $customerId,
            'stripe_payment_method_id' => $paymentMethodId,
            'source' => $paymentMethodId ? 'stored_payment_method' : ($customerId ? 'stripe_customer_default' : null),
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function firstString(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (is_array($value)) {
                $value = $value['id'] ?? null;
            } elseif (is_object($value) && isset($value->id)) {
                $value = $value->id;
            }

            if (! is_string($value) && ! is_numeric($value)) {
                continue;
            }

            $string = trim((string) $value);
            if ($string !== '') {
                return $string;
            }
        }

        return null;
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

    private function resolveCarryOverUnusedBalance(OfferPackage $offer, array $payload): bool
    {
        return (bool) (
            $payload['carry_over_unused_balance']
            ?? data_get($offer->metadata, 'recurrence.carry_over_unused_balance', false)
        );
    }

    private function resolveCustomerPackageCarryOverUnusedBalance(CustomerPackage $package, array $payload): bool
    {
        return (bool) (
            $payload['carry_over_unused_balance']
            ?? data_get($package->metadata, 'recurrence.carry_over_unused_balance')
            ?? data_get($package->source_details, 'recurrence.carry_over_unused_balance')
            ?? data_get($package->offerPackage?->metadata, 'recurrence.carry_over_unused_balance', false)
        );
    }

    private function paymentGraceDaysForOffer(OfferPackage $offer, array $payload): int
    {
        return max(1, (int) (
            $payload['payment_grace_days']
            ?? data_get($offer->metadata, 'recurrence.payment_grace_days')
            ?? 7
        ));
    }

    private function paymentGraceDaysForPackage(CustomerPackage $package): int
    {
        return max(1, (int) (
            data_get($package->metadata, 'recurrence.payment_grace_days')
            ?? data_get($package->source_details, 'recurrence.payment_grace_days')
            ?? data_get($package->offerPackage?->metadata, 'recurrence.payment_grace_days')
            ?? 7
        ));
    }

    /**
     * @return array<int, int>
     */
    private function paymentReminderDaysForOffer(OfferPackage $offer, array $payload): array
    {
        return $this->normalizePaymentReminderDays(
            $payload['payment_reminder_days']
            ?? data_get($offer->metadata, 'recurrence.payment_reminder_days')
            ?? [0, 3, 6]
        );
    }

    /**
     * @return array<int, int>
     */
    private function paymentReminderDaysForPackage(CustomerPackage $package): array
    {
        return $this->normalizePaymentReminderDays(
            data_get($package->metadata, 'recurrence.payment_reminder_days')
            ?? data_get($package->source_details, 'recurrence.payment_reminder_days')
            ?? data_get($package->offerPackage?->metadata, 'recurrence.payment_reminder_days')
            ?? [0, 3, 6]
        );
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

    private function renewalAllocationQuantity(CustomerPackage $package, array $payload): int
    {
        return max(1, (int) (
            $payload['initial_quantity']
            ?? $package->offerPackage?->included_quantity
            ?? data_get($package->metadata, 'recurrence.period_allocation_quantity')
            ?? data_get($package->source_details, 'recurrence.period_allocation_quantity')
            ?? $package->initial_quantity
            ?? 1
        ));
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
