<?php

namespace App\Services;

use App\Enums\CurrencyCode;
use App\Exceptions\StripeQueueCheckoutVerificationException;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerPackage;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationQueueItem;
use App\Models\ReservationQueuePaymentAttempt;
use App\Models\User;
use App\Models\Work;
use App\Notifications\ActionEmailNotification;
use App\Notifications\InvoicePaymentNotification;
use App\Services\OfferPackages\CustomerPackageService;
use App\Support\LocalePreference;
use App\Support\NotificationDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\CardException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\PermissionException;
use Stripe\StripeClient;

class StripeInvoiceService
{
    private ?StripeClient $client = null;

    public function isConfigured(): bool
    {
        return (bool) config('services.stripe.enabled')
            && (bool) config('services.stripe.secret');
    }

    public function createCheckoutSession(
        Invoice $invoice,
        string $successUrl,
        string $cancelUrl,
        ?float $amount = null,
        array $tip = [],
        array $context = []
    ): array {
        $invoice->loadMissing(['customer', 'items', 'user']);

        $balanceDue = (float) $invoice->balance_due;
        $amount = $amount !== null ? (float) $amount : $balanceDue;
        $amount = max(0, min($amount, $balanceDue));
        $tipAmount = max(0, (float) ($tip['tip_amount'] ?? 0));
        $tipType = (string) ($tip['tip_type'] ?? ($tipAmount > 0 ? 'fixed' : 'none'));
        $tipPercent = isset($tip['tip_percent']) ? (float) $tip['tip_percent'] : null;
        $tipBaseAmount = max(0, (float) ($tip['tip_base_amount'] ?? $amount));
        $chargedTotal = max(0, (float) ($tip['charged_total'] ?? ($amount + $tipAmount)));
        $tipAssigneeUserId = isset($tip['tip_assignee_user_id']) ? (int) $tip['tip_assignee_user_id'] : null;
        $amountCents = (int) round($amount * 100);
        $tipCents = (int) round($tipAmount * 100);
        if ($amountCents <= 0) {
            return [
                'id' => null,
                'url' => null,
            ];
        }

        $currency = CurrencyCode::tryFromMixed($invoice->currency_code)
            ?->stripeValue() ?? CurrencyCode::default()->stripeValue();
        $label = $invoice->number ? "Invoice {$invoice->number}" : "Invoice #{$invoice->id}";
        $companyName = $invoice->user?->company_name ?: config('app.name');
        $shouldSavePaymentMethod = $this->invoiceRequestsFutureStripeUsage($invoice);

        $metadata = array_filter([
            'invoice_id' => (string) $invoice->id,
            'user_id' => (string) ($invoice->user_id ?? ''),
            'customer_id' => (string) ($invoice->customer_id ?? ''),
            'payment_amount' => number_format($amount, 2, '.', ''),
            'tip_amount' => number_format($tipAmount, 2, '.', ''),
            'tip_type' => $tipType,
            'tip_percent' => $tipPercent !== null ? number_format($tipPercent, 2, '.', '') : null,
            'tip_base_amount' => number_format($tipBaseAmount, 2, '.', ''),
            'charged_total' => number_format($chargedTotal, 2, '.', ''),
            'tip_assignee_user_id' => $tipAssigneeUserId ?: null,
            'queue_payment_attempt_id' => isset($context['queue_payment_attempt_id'])
                ? (string) $context['queue_payment_attempt_id']
                : null,
            'queue_payment_attempt_public_id' => isset($context['queue_payment_attempt_public_id'])
                ? (string) $context['queue_payment_attempt_public_id']
                : null,
            'reservation_queue_item_id' => isset($context['reservation_queue_item_id'])
                ? (string) $context['reservation_queue_item_id']
                : null,
        ]);

        $resolvedConnectAccountId = $this->resolveConnectedAccountId($invoice);
        $connectAccountId = array_key_exists('stripe_account_id', $context)
            ? $this->nullableString($context['stripe_account_id'])
            : $resolvedConnectAccountId;
        if (array_key_exists('stripe_account_id', $context) && $connectAccountId !== $resolvedConnectAccountId) {
            throw new StripeQueueCheckoutVerificationException(
                'The Stripe connected account changed before checkout session creation.'
            );
        }
        $feePercent = (float) config('services.stripe.connect_fee_percent', 0);
        if ($connectAccountId) {
            $metadata['connect_account_id'] = $connectAccountId;
            if ($feePercent > 0) {
                $metadata['connect_fee_percent'] = (string) $feePercent;
            }
        }

        $lineItems = [
            [
                'price_data' => [
                    'currency' => $currency,
                    'product_data' => array_filter([
                        'name' => $label,
                        'description' => $companyName ? "Payment to {$companyName}" : null,
                    ]),
                    'unit_amount' => $amountCents,
                ],
                'quantity' => 1,
            ],
        ];
        if ($tipCents > 0) {
            $lineItems[] = [
                'price_data' => [
                    'currency' => $currency,
                    'product_data' => [
                        'name' => 'Tip',
                    ],
                    'unit_amount' => $tipCents,
                ],
                'quantity' => 1,
            ];
        }

        $payload = [
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => (string) $invoice->id,
            'metadata' => $metadata,
            'line_items' => $lineItems,
            'payment_intent_data' => [
                'metadata' => $metadata,
                'description' => $label,
            ],
        ];

        if ($shouldSavePaymentMethod) {
            $payload['payment_intent_data']['setup_future_usage'] = 'off_session';
        }

        if ($connectAccountId && $feePercent > 0) {
            $applicationFee = $this->calculateApplicationFee($amountCents + $tipCents, $feePercent);
            if ($applicationFee > 0) {
                $payload['payment_intent_data']['application_fee_amount'] = $applicationFee;
            }
        }

        $customerEmail = $invoice->customer?->email ?: data_get($invoice->customer_snapshot, 'email');
        if ($invoice->customer?->stripe_customer_id) {
            $payload['customer'] = $invoice->customer->stripe_customer_id;
        } elseif ($customerEmail) {
            $payload['customer_email'] = $customerEmail;
            if ($shouldSavePaymentMethod) {
                $payload['customer_creation'] = 'always';
            }
        }

        $options = array_filter([
            'stripe_account' => $connectAccountId,
            'idempotency_key' => $this->nullableString($context['idempotency_key'] ?? null),
        ]);
        $session = $this->client()->checkout->sessions->create($payload, $options);

        return [
            'id' => $session->id ?? null,
            'url' => $session->url ?? null,
            'status' => $session->status ?? null,
            'payment_status' => $session->payment_status ?? null,
            'expires_at' => $session->expires_at ?? null,
        ];
    }

    public function prepareQueueCheckoutAttempt(
        Invoice $invoice,
        ReservationQueueItem $queueItem,
        float $amount,
        array $tip = []
    ): ReservationQueuePaymentAttempt {
        if ((int) $invoice->reservation_queue_item_id !== (int) $queueItem->id
            || (int) $invoice->user_id !== (int) $queueItem->account_id) {
            throw new StripeQueueCheckoutVerificationException(
                'The queue item does not match the invoice prepared for Stripe.'
            );
        }

        $amount = $this->money($amount);
        $tipAmount = $this->money(max(0, (float) ($tip['tip_amount'] ?? 0)));
        $tipBaseAmount = $this->money(max(0, (float) ($tip['tip_base_amount'] ?? $amount)));
        $chargedTotal = $this->money($amount + $tipAmount);
        $tipType = $this->parseMetadataTipType($tip['tip_type'] ?? null, $tipAmount);
        $tipPercent = $tipType === 'percent'
            ? $this->parseMetadataAmount($tip['tip_percent'] ?? null)
            : null;
        $tipAssigneeUserId = $tipAmount > 0
            ? $this->parseMetadataInteger($tip['tip_assignee_user_id'] ?? null)
            : null;
        $currencyCode = CurrencyCode::tryFromMixed($invoice->currency_code)?->value
            ?? CurrencyCode::default()->value;
        $stripeAccountId = $this->resolveConnectedAccountId($invoice);

        if ($amount <= 0) {
            throw new StripeQueueCheckoutVerificationException(
                'The Stripe payment amount must be greater than zero.'
            );
        }

        $fingerprint = hash('sha256', json_encode([
            'account_id' => (int) $queueItem->account_id,
            'queue_item_id' => (int) $queueItem->id,
            'invoice_id' => (int) $invoice->id,
            'amount' => number_format($amount, 2, '.', ''),
            'tip_amount' => number_format($tipAmount, 2, '.', ''),
            'tip_type' => $tipType,
            'tip_percent' => $tipPercent !== null ? number_format($tipPercent, 2, '.', '') : null,
            'tip_base_amount' => number_format($tipBaseAmount, 2, '.', ''),
            'tip_assignee_user_id' => $tipAssigneeUserId,
            'charged_total' => number_format($chargedTotal, 2, '.', ''),
            'currency_code' => $currencyCode,
            'stripe_account_id' => $stripeAccountId,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use (
            $invoice,
            $queueItem,
            $amount,
            $tipAmount,
            $tipType,
            $tipPercent,
            $tipBaseAmount,
            $tipAssigneeUserId,
            $chargedTotal,
            $currencyCode,
            $stripeAccountId,
            $fingerprint
        ): ReservationQueuePaymentAttempt {
            $lockedQueueItem = ReservationQueueItem::query()
                ->whereKey($queueItem->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedInvoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ((string) $lockedQueueItem->status !== ReservationQueueItem::STATUS_AWAITING_PAYMENT) {
                throw ValidationException::withMessages([
                    'payment' => ['This queue item is no longer awaiting payment.'],
                ]);
            }
            if (Payment::query()
                ->where('reservation_queue_item_id', $lockedQueueItem->id)
                ->lockForUpdate()
                ->exists()) {
                throw ValidationException::withMessages([
                    'payment' => ['This queue item already has a payment.'],
                ]);
            }
            if ((int) $lockedInvoice->reservation_queue_item_id !== (int) $lockedQueueItem->id
                || (int) $lockedInvoice->user_id !== (int) $lockedQueueItem->account_id) {
                throw new StripeQueueCheckoutVerificationException(
                    'The locked queue item does not match its Stripe invoice.'
                );
            }

            $lockedInvoice->unsetRelation('payments');
            if ($this->money((float) $lockedInvoice->balance_due) !== $amount) {
                throw new StripeQueueCheckoutVerificationException(
                    'The Stripe payment amount no longer matches the locked invoice balance.'
                );
            }
            if ($this->resolveConnectedAccountId($lockedInvoice) !== $stripeAccountId) {
                throw new StripeQueueCheckoutVerificationException(
                    'The Stripe connected account changed while preparing the payment attempt.'
                );
            }

            $activeKey = $this->queueAttemptActiveKey((int) $queueItem->id);
            $active = ReservationQueuePaymentAttempt::query()
                ->where('active_key', $activeKey)
                ->lockForUpdate()
                ->first();

            if ($active) {
                if (! hash_equals((string) $active->request_fingerprint, $fingerprint)) {
                    throw ValidationException::withMessages([
                        'payment' => [
                            'A Stripe card payment is already active for this ticket. Cancel it before changing the amount, tip, or payment method.',
                        ],
                    ]);
                }

                return $active;
            }

            $retryable = ReservationQueuePaymentAttempt::query()
                ->where('reservation_queue_item_id', $lockedQueueItem->id)
                ->where('request_fingerprint', $fingerprint)
                ->where('status', ReservationQueuePaymentAttempt::STATUS_FAILED)
                ->whereNull('stripe_checkout_session_id')
                ->latest('id')
                ->lockForUpdate()
                ->first();
            if ($retryable) {
                $retryable->forceFill([
                    'active_key' => $activeKey,
                    'status' => ReservationQueuePaymentAttempt::STATUS_PREPARING,
                    'last_error' => null,
                ])->save();

                return $retryable;
            }

            return ReservationQueuePaymentAttempt::query()->create([
                'public_id' => (string) Str::uuid(),
                'active_key' => $activeKey,
                'account_id' => $queueItem->account_id,
                'reservation_queue_item_id' => $queueItem->id,
                'invoice_id' => $invoice->id,
                'provider' => 'stripe',
                'status' => ReservationQueuePaymentAttempt::STATUS_PREPARING,
                'request_fingerprint' => $fingerprint,
                'idempotency_key' => (string) Str::uuid(),
                'stripe_account_id' => $stripeAccountId,
                'amount' => $amount,
                'tip_amount' => $tipAmount,
                'tip_type' => $tipType,
                'tip_percent' => $tipPercent,
                'tip_base_amount' => $tipBaseAmount,
                'tip_assignee_user_id' => $tipAssigneeUserId,
                'charged_total' => $chargedTotal,
                'currency_code' => $currencyCode,
                'metadata' => [
                    'prepared_at' => now('UTC')->toIso8601String(),
                ],
            ]);
        });
    }

    /**
     * @return array{id: string|null, url: string|null, status: string|null, payment_status: string|null, expires_at: mixed}
     */
    public function startQueueCheckoutAttempt(
        ReservationQueuePaymentAttempt $attempt,
        string $successUrl,
        string $cancelUrl
    ): array {
        $attempt = DB::transaction(function () use ($attempt): ReservationQueuePaymentAttempt {
            $queueItem = ReservationQueueItem::query()
                ->lockForUpdate()
                ->findOrFail($attempt->reservation_queue_item_id);
            Invoice::query()->lockForUpdate()->findOrFail($attempt->invoice_id);
            $lockedAttempt = ReservationQueuePaymentAttempt::query()
                ->lockForUpdate()
                ->findOrFail($attempt->id);

            if ((int) $lockedAttempt->reservation_queue_item_id !== (int) $queueItem->id
                || (int) $lockedAttempt->invoice_id !== (int) $attempt->invoice_id) {
                throw new StripeQueueCheckoutVerificationException(
                    'The Stripe payment attempt links changed before session creation.'
                );
            }

            if ((string) $queueItem->status !== ReservationQueueItem::STATUS_AWAITING_PAYMENT
                || Payment::query()
                    ->where('reservation_queue_item_id', $queueItem->id)
                    ->lockForUpdate()
                    ->exists()) {
                throw ValidationException::withMessages([
                    'payment' => ['This queue item was paid or closed before Stripe Checkout could start.'],
                ]);
            }

            return $lockedAttempt;
        });

        if (! $attempt->isActive()) {
            throw ValidationException::withMessages([
                'payment' => ['This Stripe payment attempt is no longer active.'],
            ]);
        }

        if ($attempt->expires_at?->isPast()) {
            throw ValidationException::withMessages([
                'payment' => [
                    'This Stripe Checkout session may have expired. Verify or cancel it before starting another payment.',
                ],
            ]);
        }

        if ($attempt->checkout_url && ! $attempt->expires_at?->isPast()) {
            return [
                'id' => $attempt->stripe_checkout_session_id,
                'url' => $attempt->checkout_url,
                'status' => $attempt->status,
                'payment_status' => null,
                'expires_at' => $attempt->expires_at?->getTimestamp(),
            ];
        }

        try {
            $session = $this->createCheckoutSession(
                $attempt->invoice()->firstOrFail(),
                $successUrl,
                $cancelUrl,
                (float) $attempt->amount,
                [
                    'tip_amount' => (float) $attempt->tip_amount,
                    'tip_type' => $attempt->tip_type,
                    'tip_percent' => $attempt->tip_percent !== null ? (float) $attempt->tip_percent : null,
                    'tip_base_amount' => (float) $attempt->tip_base_amount,
                    'charged_total' => (float) $attempt->charged_total,
                    'tip_assignee_user_id' => $attempt->tip_assignee_user_id,
                ],
                [
                    'queue_payment_attempt_id' => $attempt->id,
                    'queue_payment_attempt_public_id' => $attempt->public_id,
                    'reservation_queue_item_id' => $attempt->reservation_queue_item_id,
                    'stripe_account_id' => $attempt->stripe_account_id,
                    'idempotency_key' => $attempt->idempotency_key,
                ]
            );

            if (! $session['id'] || ! $session['url']) {
                throw new StripeQueueCheckoutVerificationException(
                    'Stripe did not return a usable Checkout session.'
                );
            }

            DB::transaction(function () use ($attempt, $session): void {
                ReservationQueueItem::query()
                    ->lockForUpdate()
                    ->findOrFail($attempt->reservation_queue_item_id);
                Invoice::query()->lockForUpdate()->findOrFail($attempt->invoice_id);
                $locked = ReservationQueuePaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
                if (! $locked->isActive()) {
                    return;
                }

                $expiresAt = is_numeric($session['expires_at'] ?? null)
                    ? now('UTC')->setTimestamp((int) $session['expires_at'])
                    : now('UTC')->addHours(23);
                $locked->forceFill([
                    'status' => ReservationQueuePaymentAttempt::STATUS_OPEN,
                    'stripe_checkout_session_id' => (string) $session['id'],
                    'checkout_url' => (string) $session['url'],
                    'expires_at' => $expiresAt,
                    'last_error' => null,
                ])->save();
            });

            return $session;
        } catch (\Throwable $exception) {
            $isDeterministicFailure = $this->isDeterministicStripeSessionCreationFailure($exception);
            DB::transaction(function () use ($attempt, $exception, $isDeterministicFailure): void {
                ReservationQueueItem::query()
                    ->lockForUpdate()
                    ->find($attempt->reservation_queue_item_id);
                Invoice::query()->lockForUpdate()->find($attempt->invoice_id);
                $locked = ReservationQueuePaymentAttempt::query()
                    ->lockForUpdate()
                    ->find($attempt->id);
                if (! $locked || ! in_array($locked->status, [
                    ReservationQueuePaymentAttempt::STATUS_PREPARING,
                    ReservationQueuePaymentAttempt::STATUS_OPEN,
                ], true)) {
                    return;
                }

                $mayReleaseAttempt = $isDeterministicFailure && ! $locked->stripe_checkout_session_id;
                $locked->forceFill([
                    'active_key' => $mayReleaseAttempt ? null : $locked->active_key,
                    'status' => $mayReleaseAttempt
                        ? ReservationQueuePaymentAttempt::STATUS_FAILED
                        : $locked->status,
                    'last_error' => Str::limit($exception->getMessage(), 2000),
                ])->save();
            });

            throw $exception;
        }
    }

    public function ensureNoActiveQueueCheckoutAttempt(ReservationQueueItem $queueItem): void
    {
        $attempt = ReservationQueuePaymentAttempt::query()
            ->where('active_key', $this->queueAttemptActiveKey((int) $queueItem->id))
            ->lockForUpdate()
            ->first();

        if (! $attempt) {
            return;
        }

        throw ValidationException::withMessages([
            'payment' => [
                'A Stripe card payment is still active for this ticket. Cancel that Checkout session before using another payment method.',
            ],
        ]);
    }

    public function reconcileQueueCheckoutAttempt(
        ReservationQueuePaymentAttempt $attempt,
        ?string $sessionId = null
    ): ?Payment {
        $attempt->refresh();
        if ($attempt->status === ReservationQueuePaymentAttempt::STATUS_COMPLETED) {
            return $attempt->payment;
        }

        $sessionId = $this->nullableString($sessionId) ?: $attempt->stripe_checkout_session_id;
        if (! $sessionId || ($attempt->stripe_checkout_session_id && $sessionId !== $attempt->stripe_checkout_session_id)) {
            throw new StripeQueueCheckoutVerificationException(
                'The returned Stripe Checkout session does not match the active payment attempt.'
            );
        }

        $options = $attempt->stripe_account_id
            ? ['stripe_account' => $attempt->stripe_account_id]
            : [];
        $session = $this->client()->checkout->sessions->retrieve($sessionId, [], $options);
        $payload = is_array($session) ? $session : $session->toArray();

        $payment = $this->recordPaymentFromCheckoutSession(
            $payload,
            $attempt->stripe_account_id,
            $attempt
        );

        if (! $payment) {
            $attempt->forceFill([
                'last_verified_at' => now('UTC'),
                'last_error' => null,
            ])->save();
        }

        return $payment;
    }

    public function cancelQueueCheckoutAttempt(ReservationQueuePaymentAttempt $attempt): ?Payment
    {
        $attempt->refresh();
        if ($attempt->status === ReservationQueuePaymentAttempt::STATUS_COMPLETED) {
            return $attempt->payment;
        }

        $sessionId = $attempt->stripe_checkout_session_id;
        if (! $sessionId) {
            throw new StripeQueueCheckoutVerificationException(
                'The Stripe Checkout session is still being prepared and cannot safely be cancelled yet.'
            );
        }

        $options = $attempt->stripe_account_id
            ? ['stripe_account' => $attempt->stripe_account_id]
            : [];
        $session = $this->client()->checkout->sessions->retrieve($sessionId, [], $options);
        $payload = is_array($session) ? $session : $session->toArray();
        if (($payload['payment_status'] ?? null) === 'paid') {
            return $this->recordPaymentFromCheckoutSession($payload, $attempt->stripe_account_id, $attempt);
        }

        if (($payload['status'] ?? null) !== 'expired') {
            try {
                $expired = $this->client()->checkout->sessions->expire($sessionId, [], $options);
                $expiredPayload = is_array($expired) ? $expired : $expired->toArray();
                if (($expiredPayload['payment_status'] ?? null) === 'paid') {
                    return $this->recordPaymentFromCheckoutSession(
                        $expiredPayload,
                        $attempt->stripe_account_id,
                        $attempt
                    );
                }
            } catch (\Throwable $exception) {
                $session = $this->client()->checkout->sessions->retrieve($sessionId, [], $options);
                $payload = is_array($session) ? $session : $session->toArray();
                if (($payload['payment_status'] ?? null) === 'paid') {
                    return $this->recordPaymentFromCheckoutSession($payload, $attempt->stripe_account_id, $attempt);
                }
                if (($payload['status'] ?? null) !== 'expired') {
                    throw $exception;
                }
            }
        }

        DB::transaction(function () use ($attempt): void {
            ReservationQueueItem::query()
                ->lockForUpdate()
                ->find($attempt->reservation_queue_item_id);
            Invoice::query()->lockForUpdate()->find($attempt->invoice_id);
            $locked = ReservationQueuePaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            if ($locked->status === ReservationQueuePaymentAttempt::STATUS_COMPLETED) {
                return;
            }

            $locked->forceFill([
                'active_key' => null,
                'status' => ReservationQueuePaymentAttempt::STATUS_CANCELLED,
                'cancelled_at' => now('UTC'),
                'last_verified_at' => now('UTC'),
                'last_error' => null,
            ])->save();
        });

        return null;
    }

    public function closeQueueCheckoutAttemptFromStripe(
        array $session,
        string $terminalStatus,
        ?string $eventStripeAccountId = null
    ): bool {
        if (! in_array($terminalStatus, [
            ReservationQueuePaymentAttempt::STATUS_EXPIRED,
            ReservationQueuePaymentAttempt::STATUS_FAILED,
        ], true)) {
            throw new \InvalidArgumentException('Unsupported Stripe queue Checkout terminal status.');
        }

        $metadata = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];
        $attempt = $this->resolveQueuePaymentAttempt($metadata);
        if (! $attempt) {
            return false;
        }

        $this->verifyQueueCheckoutSession($session, $attempt, $eventStripeAccountId);
        if (($session['payment_status'] ?? null) === 'paid') {
            $this->recordPaymentFromCheckoutSession($session, $eventStripeAccountId, $attempt);

            return true;
        }
        if ($terminalStatus === ReservationQueuePaymentAttempt::STATUS_EXPIRED
            && ($session['status'] ?? null) !== 'expired') {
            $this->rejectQueuePaymentAttempt($attempt, 'Stripe sent an expired event for a non-expired Checkout session.');
        }

        DB::transaction(function () use ($attempt, $session, $terminalStatus): void {
            ReservationQueueItem::query()
                ->lockForUpdate()
                ->findOrFail($attempt->reservation_queue_item_id);
            Invoice::query()->lockForUpdate()->findOrFail($attempt->invoice_id);
            $locked = ReservationQueuePaymentAttempt::query()
                ->lockForUpdate()
                ->findOrFail($attempt->id);

            if ($locked->status === ReservationQueuePaymentAttempt::STATUS_COMPLETED) {
                return;
            }
            if (! $locked->isActive()) {
                return;
            }
            if (Payment::query()
                ->where('reservation_queue_item_id', $locked->reservation_queue_item_id)
                ->lockForUpdate()
                ->exists()) {
                throw new StripeQueueCheckoutVerificationException(
                    'Stripe reported an unpaid terminal session after a queue payment was recorded.'
                );
            }

            $locked->forceFill([
                'active_key' => null,
                'status' => $terminalStatus,
                'stripe_checkout_session_id' => (string) $session['id'],
                'expires_at' => $terminalStatus === ReservationQueuePaymentAttempt::STATUS_EXPIRED
                    ? now('UTC')
                    : $locked->expires_at,
                'last_verified_at' => now('UTC'),
                'last_error' => $terminalStatus === ReservationQueuePaymentAttempt::STATUS_EXPIRED
                    ? 'Stripe Checkout session expired without payment.'
                    : 'Stripe asynchronous Checkout payment failed.',
            ])->save();
        });

        return true;
    }

    public function attemptAutomaticPayment(Invoice $invoice, CustomerPackage $package): array
    {
        $invoice->loadMissing(['customer', 'items', 'user']);
        $package->loadMissing(['customer.portalUser']);

        if (! $this->isConfigured()) {
            return $this->automaticPaymentResult('skipped', [
                'reason' => 'stripe_not_configured',
                'message' => 'Stripe is not configured.',
            ]);
        }

        if (in_array($invoice->status, ['draft', 'paid', 'void'], true) || (float) $invoice->balance_due <= 0) {
            return $this->automaticPaymentResult('skipped', [
                'reason' => 'invoice_not_payable',
                'message' => 'Invoice cannot be charged automatically.',
            ]);
        }

        $policyDecision = app(TenantPaymentMethodGuardService::class)->evaluate(
            (int) $invoice->user_id,
            'stripe',
            'customer_package_renewal_auto'
        );
        if (! $policyDecision['allowed']) {
            return $this->automaticPaymentResult('skipped', [
                'reason' => TenantPaymentMethodGuardService::ERROR_CODE,
                'message' => TenantPaymentMethodGuardService::ERROR_MESSAGE,
            ]);
        }

        $context = $this->resolveAutomaticPaymentContext($invoice, $package);
        $customerId = $context['stripe_customer_id'] ?? null;
        if (! $customerId) {
            return $this->automaticPaymentResult('skipped', [
                'reason' => 'no_stripe_customer',
                'message' => 'No Stripe customer is linked to this client.',
            ]);
        }

        $connectAccountId = $this->resolveConnectedAccountId($invoice);
        $options = $connectAccountId ? ['stripe_account' => $connectAccountId] : [];
        $paymentMethodId = $context['stripe_payment_method_id'] ?? null;
        if (! $paymentMethodId) {
            $paymentMethodId = $this->resolveDefaultPaymentMethodId($customerId, $options);
        }

        if (! $paymentMethodId) {
            return $this->automaticPaymentResult('skipped', [
                'stripe_customer_id' => $customerId,
                'reason' => 'no_auto_payment_method',
                'message' => 'No reusable Stripe payment method is available for this client.',
            ]);
        }

        $amount = (float) $invoice->balance_due;
        $amountCents = (int) round($amount * 100);
        if ($amountCents <= 0) {
            return $this->automaticPaymentResult('skipped', [
                'reason' => 'invalid_amount',
                'message' => 'Invoice balance is not chargeable.',
            ]);
        }

        $currency = CurrencyCode::tryFromMixed($invoice->currency_code)
            ?->stripeValue() ?? CurrencyCode::default()->stripeValue();
        $label = $invoice->number ? "Invoice {$invoice->number}" : "Invoice #{$invoice->id}";
        $metadata = $this->invoicePaymentMetadata($invoice, $amount, [
            'customer_package_id' => (string) $package->id,
            'offer_package_id' => (string) ($package->offer_package_id ?? ''),
            'automatic_renewal' => 'true',
        ]);

        if ($connectAccountId) {
            $metadata['connect_account_id'] = $connectAccountId;
        }

        $payload = [
            'amount' => $amountCents,
            'currency' => $currency,
            'customer' => $customerId,
            'payment_method' => $paymentMethodId,
            'off_session' => true,
            'confirm' => true,
            'description' => $label,
            'metadata' => $metadata,
        ];

        $feePercent = (float) config('services.stripe.connect_fee_percent', 0);
        if ($connectAccountId && $feePercent > 0) {
            $applicationFee = $this->calculateApplicationFee($amountCents, $feePercent);
            if ($applicationFee > 0) {
                $payload['application_fee_amount'] = $applicationFee;
                $metadata['connect_fee_percent'] = (string) $feePercent;
                $payload['metadata'] = $metadata;
            }
        }

        try {
            $intent = $this->client()->paymentIntents->create($payload, $options);
            $intentPayload = $this->stripeObjectToArray($intent);
        } catch (CardException $exception) {
            return $this->automaticPaymentExceptionResult($exception, $customerId, $paymentMethodId);
        } catch (ApiErrorException $exception) {
            return $this->automaticPaymentExceptionResult($exception, $customerId, $paymentMethodId);
        } catch (\Throwable $exception) {
            Log::warning('Unexpected Stripe automatic renewal payment failure.', [
                'invoice_id' => $invoice->id,
                'customer_package_id' => $package->id,
                'exception' => $exception->getMessage(),
            ]);

            return $this->automaticPaymentResult('failed', [
                'attempted' => true,
                'stripe_customer_id' => $customerId,
                'stripe_payment_method_id' => $paymentMethodId,
                'reason' => 'stripe_unexpected_error',
                'message' => $exception->getMessage(),
            ]);
        }

        $paymentIntentId = $intentPayload['id'] ?? null;
        $status = (string) ($intentPayload['status'] ?? 'unknown');

        if ($status === 'succeeded' || (int) ($intentPayload['amount_received'] ?? 0) > 0) {
            $payment = $this->recordPaymentFromPaymentIntent($intentPayload);
            $this->storeInvoiceCustomerStripePaymentContext(
                $invoice,
                $intentPayload['customer'] ?? $customerId,
                $intentPayload['payment_method'] ?? $paymentMethodId
            );

            return $this->automaticPaymentResult('succeeded', [
                'attempted' => true,
                'stripe_customer_id' => $customerId,
                'stripe_payment_method_id' => $paymentMethodId,
                'payment_intent_id' => $paymentIntentId,
                'payment_id' => $payment?->id,
                'message' => 'Automatic Stripe renewal payment succeeded.',
            ]);
        }

        return $this->automaticPaymentResult('failed', [
            'attempted' => true,
            'stripe_customer_id' => $customerId,
            'stripe_payment_method_id' => $paymentMethodId,
            'payment_intent_id' => $paymentIntentId,
            'reason' => 'payment_not_succeeded',
            'message' => 'Stripe payment intent finished with status '.$status.'.',
            'stripe_status' => $status,
        ]);
    }

    public function recordPaymentFromCheckoutSession(
        array $session,
        ?string $eventStripeAccountId = null,
        ?ReservationQueuePaymentAttempt $expectedAttempt = null,
        ?Invoice $expectedInvoice = null,
    ): ?Payment {
        $metadata = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];

        if ($expectedInvoice && ! $this->checkoutSessionMatchesInvoice($session, $metadata, $expectedInvoice)) {
            Log::warning('Stripe Checkout session invoice mismatch.', [
                'expected_invoice_id' => $expectedInvoice->id,
                'metadata_invoice_id' => $metadata['invoice_id'] ?? null,
                'client_reference_id' => $session['client_reference_id'] ?? null,
                'checkout_session_id' => $session['id'] ?? null,
            ]);

            return null;
        }

        $attempt = $this->resolveQueuePaymentAttempt($metadata, $expectedAttempt);
        if ($attempt) {
            $this->verifyQueueCheckoutSession($session, $attempt, $eventStripeAccountId);
        }

        $paymentStatus = $session['payment_status'] ?? null;
        if ($paymentStatus !== 'paid') {
            return null;
        }

        $paymentIntentId = $this->stringValue($session['payment_intent'] ?? null);
        if (! $paymentIntentId) {
            return null;
        }

        $invoiceId = $attempt?->invoice_id
            ?? $metadata['invoice_id']
            ?? $session['client_reference_id']
            ?? null;
        if (! $invoiceId) {
            return null;
        }

        $invoice = Invoice::query()->find($invoiceId);
        if (! $invoice || $invoice->status === 'void' || ($invoice->status === 'draft' && ! $this->isReservationQueueInvoice($invoice))) {
            return null;
        }

        $this->storeInvoiceCustomerStripePaymentContext(
            $invoice,
            $session['customer'] ?? null,
            $session['payment_method'] ?? null
        );

        $policyDecision = app(TenantPaymentMethodGuardService::class)->evaluate(
            (int) $invoice->user_id,
            'stripe',
            'invoice_webhook'
        );
        if (! $policyDecision['allowed']) {
            Log::warning('Stripe invoice payment policy mismatch.', [
                'account_id' => $invoice->user_id,
                'invoice_id' => $invoice->id,
                'provider_reference' => $paymentIntentId,
                'event' => 'checkout.session',
                'error_code' => TenantPaymentMethodGuardService::ERROR_CODE,
            ]);
        }

        $amountTotal = $session['amount_total'] ?? null;
        if (! $amountTotal) {
            return null;
        }

        $amountTotalFloat = $this->money(((int) $amountTotal) / 100);
        $amount = $attempt
            ? (float) $attempt->amount
            : ($this->parseMetadataAmount($metadata['payment_amount'] ?? null) ?? $amountTotalFloat);
        $tipAmount = $attempt
            ? (float) $attempt->tip_amount
            : ($this->parseMetadataAmount($metadata['tip_amount'] ?? null) ?? 0.0);
        $tipType = $attempt?->tip_type
            ?? $this->parseMetadataTipType($metadata['tip_type'] ?? null, $tipAmount);
        $tipPercent = $attempt?->tip_percent !== null
            ? (float) $attempt->tip_percent
            : $this->parseMetadataAmount($metadata['tip_percent'] ?? null);
        $tipBaseAmount = $attempt
            ? (float) $attempt->tip_base_amount
            : ($this->parseMetadataAmount($metadata['tip_base_amount'] ?? null) ?? $amount);
        $chargedTotal = $attempt
            ? (float) $attempt->charged_total
            : ($this->parseMetadataAmount($metadata['charged_total'] ?? null) ?? $this->money($amount + $tipAmount));
        $tipAssigneeUserId = $attempt?->tip_assignee_user_id
            ?? $this->parseMetadataInteger($metadata['tip_assignee_user_id'] ?? null);
        if ($amount <= 0) {
            return null;
        }

        return $this->recordStripePayment(
            $invoice,
            $amount,
            $paymentIntentId,
            $session['id'] ?? null,
            $tipAmount,
            $tipType,
            $tipPercent,
            $tipBaseAmount,
            $chargedTotal,
            $tipAssigneeUserId,
            $attempt
        );
    }

    public function syncFromCheckoutSessionId(
        string $sessionId,
        ?string $stripeAccountId = null,
        ?Invoice $expectedInvoice = null,
    ): ?Payment {
        $options = $stripeAccountId ? ['stripe_account' => $stripeAccountId] : [];
        $session = $this->client()->checkout->sessions->retrieve($sessionId, [], $options);
        $payload = is_array($session) ? $session : $session->toArray();

        return $this->recordPaymentFromCheckoutSession(
            $payload,
            $stripeAccountId,
            expectedInvoice: $expectedInvoice,
        );
    }

    /**
     * @param  array<string, mixed>  $session
     * @param  array<string, mixed>  $metadata
     */
    private function checkoutSessionMatchesInvoice(array $session, array $metadata, Invoice $invoice): bool
    {
        $expectedInvoiceId = (string) $invoice->getKey();
        $metadataInvoiceId = $this->nullableString($metadata['invoice_id'] ?? null);
        $clientReferenceId = $this->nullableString($session['client_reference_id'] ?? null);

        return $metadataInvoiceId !== null
            && $clientReferenceId !== null
            && hash_equals($expectedInvoiceId, $metadataInvoiceId)
            && hash_equals($expectedInvoiceId, $clientReferenceId);
    }

    public function recordPaymentFromPaymentIntent(array $intent, ?string $eventStripeAccountId = null): ?Payment
    {
        $paymentIntentId = $this->stringValue($intent['id'] ?? null);
        if (! $paymentIntentId) {
            return null;
        }

        $metadata = is_array($intent['metadata'] ?? null) ? $intent['metadata'] : [];
        $attempt = $this->resolveQueuePaymentAttempt($metadata);
        if ($attempt) {
            $this->verifyQueuePaymentIntent($intent, $attempt, $eventStripeAccountId);
        }

        $invoiceId = $attempt?->invoice_id ?? $metadata['invoice_id'] ?? null;
        if (! $invoiceId) {
            return null;
        }

        $invoice = Invoice::query()->find($invoiceId);
        if (! $invoice || $invoice->status === 'void' || ($invoice->status === 'draft' && ! $this->isReservationQueueInvoice($invoice))) {
            return null;
        }

        $this->storeInvoiceCustomerStripePaymentContext(
            $invoice,
            $intent['customer'] ?? null,
            $intent['payment_method'] ?? null
        );

        $policyDecision = app(TenantPaymentMethodGuardService::class)->evaluate(
            (int) $invoice->user_id,
            'stripe',
            'invoice_webhook'
        );
        if (! $policyDecision['allowed']) {
            Log::warning('Stripe invoice payment policy mismatch.', [
                'account_id' => $invoice->user_id,
                'invoice_id' => $invoice->id,
                'provider_reference' => $paymentIntentId,
                'event' => 'payment_intent',
                'error_code' => TenantPaymentMethodGuardService::ERROR_CODE,
            ]);
        }

        $amountTotal = $intent['amount_received'] ?? $intent['amount'] ?? null;
        if (! $amountTotal) {
            return null;
        }

        $amountTotalFloat = $this->money(((int) $amountTotal) / 100);
        $amount = $attempt
            ? (float) $attempt->amount
            : ($this->parseMetadataAmount($metadata['payment_amount'] ?? null) ?? $amountTotalFloat);
        $tipAmount = $attempt
            ? (float) $attempt->tip_amount
            : ($this->parseMetadataAmount($metadata['tip_amount'] ?? null) ?? 0.0);
        $tipType = $attempt?->tip_type
            ?? $this->parseMetadataTipType($metadata['tip_type'] ?? null, $tipAmount);
        $tipPercent = $attempt?->tip_percent !== null
            ? (float) $attempt->tip_percent
            : $this->parseMetadataAmount($metadata['tip_percent'] ?? null);
        $tipBaseAmount = $attempt
            ? (float) $attempt->tip_base_amount
            : ($this->parseMetadataAmount($metadata['tip_base_amount'] ?? null) ?? $amount);
        $chargedTotal = $attempt
            ? (float) $attempt->charged_total
            : ($this->parseMetadataAmount($metadata['charged_total'] ?? null) ?? $this->money($amount + $tipAmount));
        $tipAssigneeUserId = $attempt?->tip_assignee_user_id
            ?? $this->parseMetadataInteger($metadata['tip_assignee_user_id'] ?? null);
        if ($amount <= 0) {
            return null;
        }

        return $this->recordStripePayment(
            $invoice,
            $amount,
            $paymentIntentId,
            $attempt?->stripe_checkout_session_id,
            $tipAmount,
            $tipType,
            $tipPercent,
            $tipBaseAmount,
            $chargedTotal,
            $tipAssigneeUserId,
            $attempt
        );
    }

    private function recordStripePayment(
        Invoice $invoice,
        float $amount,
        string $paymentIntentId,
        ?string $sessionId,
        float $tipAmount = 0,
        string $tipType = 'none',
        ?float $tipPercent = null,
        ?float $tipBaseAmount = null,
        ?float $chargedTotal = null,
        ?int $tipAssigneeUserId = null,
        ?ReservationQueuePaymentAttempt $attempt = null
    ): ?Payment {
        $amount = $this->money($amount);
        $tipAmount = $this->money(max(0, $tipAmount));
        $tipType = in_array($tipType, ['none', 'percent', 'fixed'], true) ? $tipType : ($tipAmount > 0 ? 'fixed' : 'none');
        $tipBaseAmount = $this->money($tipBaseAmount !== null ? max(0, $tipBaseAmount) : $amount);
        $chargedTotal = $this->money($chargedTotal !== null ? max(0, $chargedTotal) : ($amount + $tipAmount));
        $queueItemIdForLock = $this->isReservationQueueInvoice($invoice)
            ? (int) ($invoice->reservation_queue_item_id ?? 0)
            : 0;

        $result = DB::transaction(function () use (
            $invoice,
            $queueItemIdForLock,
            $amount,
            $paymentIntentId,
            $sessionId,
            $tipAmount,
            $tipType,
            $tipPercent,
            $tipBaseAmount,
            $chargedTotal,
            $tipAssigneeUserId,
            $attempt
        ): array {
            if ($queueItemIdForLock > 0) {
                ReservationQueueItem::query()->lockForUpdate()->findOrFail($queueItemIdForLock);
            }
            $lockedInvoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($lockedInvoice->status === 'void') {
                throw new StripeQueueCheckoutVerificationException('A void invoice cannot accept a Stripe payment.');
            }

            $queueItemId = $this->isReservationQueueInvoice($lockedInvoice)
                ? (int) ($lockedInvoice->reservation_queue_item_id ?? 0)
                : 0;
            $payment = Payment::query()
                ->where('provider', 'stripe')
                ->where('provider_reference', $paymentIntentId)
                ->lockForUpdate()
                ->first();
            $created = false;

            if ($payment) {
                if ((int) $payment->invoice_id !== (int) $lockedInvoice->id) {
                    throw new StripeQueueCheckoutVerificationException(
                        'The Stripe payment intent is already linked to another invoice.'
                    );
                }
                if ($queueItemId > 0 && (int) $payment->reservation_queue_item_id !== $queueItemId) {
                    throw new StripeQueueCheckoutVerificationException(
                        'The Stripe payment intent is already linked to another queue item.'
                    );
                }
            } else {
                $payment = Payment::query()->create([
                    'invoice_id' => $lockedInvoice->id,
                    'reservation_queue_item_id' => $queueItemId > 0 ? $queueItemId : null,
                    'customer_id' => $lockedInvoice->customer_id,
                    'user_id' => $lockedInvoice->user_id,
                    'amount' => $amount,
                    'currency_code' => $lockedInvoice->currency_code,
                    'tip_amount' => $tipAmount,
                    'tip_type' => $tipType,
                    'tip_percent' => $tipType === 'percent' ? $tipPercent : null,
                    'tip_base_amount' => $tipBaseAmount,
                    'charged_total' => $chargedTotal,
                    'tip_assignee_user_id' => $tipAmount > 0 ? $tipAssigneeUserId : null,
                    'method' => 'stripe',
                    'provider' => 'stripe',
                    'status' => Payment::STATUS_COMPLETED,
                    'reference' => $paymentIntentId,
                    'provider_reference' => $paymentIntentId,
                    'notes' => $sessionId ? "Stripe session {$sessionId}" : null,
                    'paid_at' => now('UTC'),
                ]);
                $created = true;
            }

            app(TipAllocationService::class)->syncForPayment($payment);

            $previousStatus = (string) $lockedInvoice->status;
            $lockedInvoice->unsetRelation('payments');
            $lockedInvoice->refreshPaymentStatus();
            $lockedInvoice->refresh();
            $this->settleQueueInvoicePayment($lockedInvoice, $payment);

            if ($attempt) {
                $this->completeQueuePaymentAttempt($attempt, $payment, $paymentIntentId);
            }

            if ($lockedInvoice->status === 'paid' && $lockedInvoice->work) {
                $lockedInvoice->work->forceFill(['status' => Work::STATUS_CLOSED])->save();
            }

            return [$payment, $lockedInvoice, $previousStatus, $created];
        });

        /** @var Payment $payment */
        [$payment, $invoice, $previousStatus, $created] = $result;

        if ($created) {
            ActivityLog::record(null, $payment, 'created', [
                'invoice_id' => $invoice->id,
                'amount' => $payment->amount,
                'tip_amount' => $payment->tip_amount,
                'tip_type' => $payment->tip_type,
                'tip_percent' => $payment->tip_percent,
                'charged_total' => $payment->charged_total,
                'tip_assignee_user_id' => $payment->tip_assignee_user_id,
                'method' => $payment->method,
            ], 'Stripe payment received');

            if ($previousStatus !== $invoice->status) {
                ActivityLog::record(null, $invoice, 'status_changed', [
                    'from' => $previousStatus,
                    'to' => $invoice->status,
                ], 'Invoice status updated');
            }

            $this->notifyCompany($invoice, $payment);
            $this->notifyClient($invoice, $payment);
        }

        if ($invoice->status === 'paid') {
            app(CustomerPackageService::class)->fulfillPaidInvoice($invoice);
            app(QueueInvoiceReceiptService::class)->deliver($invoice, $invoice->user);
        }

        return $payment;
    }

    private function isReservationQueueInvoice(Invoice $invoice): bool
    {
        return (string) $invoice->source === ReservationQueueInvoiceService::SOURCE_RESERVATION_QUEUE
            || (int) ($invoice->reservation_queue_item_id ?? 0) > 0;
    }

    private function settleQueueInvoicePayment(Invoice $invoice, Payment $payment): void
    {
        if (! $this->isReservationQueueInvoice($invoice) || (string) $invoice->status !== 'paid') {
            return;
        }

        $queueItem = $invoice->reservationQueueItem;
        if (! $queueItem) {
            throw new StripeQueueCheckoutVerificationException(
                'The paid queue invoice is no longer linked to its queue item.'
            );
        }
        if ((string) $queueItem->status === ReservationQueueItem::STATUS_DONE) {
            return;
        }

        if ((string) $queueItem->status !== ReservationQueueItem::STATUS_AWAITING_PAYMENT) {
            throw new StripeQueueCheckoutVerificationException(
                'The paid queue item is not awaiting payment and cannot be completed safely.'
            );
        }

        $owner = $invoice->user ?: User::query()->find($invoice->user_id);
        if (! $owner) {
            throw new StripeQueueCheckoutVerificationException(
                'The paid queue invoice has no account owner to complete the queue item.'
            );
        }

        $settings = app(ReservationAvailabilityService::class)->resolveSettings(
            (int) $queueItem->account_id,
            $queueItem->team_member_id ? (int) $queueItem->team_member_id : null
        );
        $settings['business_preset'] = 'salon';
        $settings['queue_mode_enabled'] = true;

        $updated = app(ReservationQueueService::class)->transition($queueItem, 'done', $owner, $settings, [
            'checkout_settled' => true,
            'transition_source' => Reservation::STATUS_CHANGE_SOURCE_STRIPE_WEBHOOK,
        ]);
        if ((string) $updated->status !== ReservationQueueItem::STATUS_DONE) {
            throw new StripeQueueCheckoutVerificationException(
                'The paid queue item did not reach its completed state.'
            );
        }
    }

    private function notifyCompany(Invoice $invoice, Payment $payment): void
    {
        $owner = User::find($invoice->user_id);
        if ($owner && $owner->email) {
            $customer = $invoice->customer;
            $customerLabel = $customer?->company_name
                ?: trim(($customer?->first_name ?? '').' '.($customer?->last_name ?? ''));
            $locale = LocalePreference::forUser($owner);
            $isFr = str_starts_with($locale, 'fr');

            NotificationDispatcher::send($owner, new ActionEmailNotification(
                $isFr ? 'Paiement recu du client' : 'Payment received from client',
                $customerLabel
                    ? ($isFr ? $customerLabel.' a paye via Stripe.' : $customerLabel.' paid via Stripe.')
                    : ($isFr ? 'Un client a paye via Stripe.' : 'A client paid via Stripe.'),
                $this->buildPaymentDetails($invoice, $payment, $locale),
                route('invoice.show', $invoice->id),
                $isFr ? 'Voir la facture' : 'View invoice',
                $isFr ? 'Paiement Stripe recu' : 'Stripe payment received'
            ), [
                'invoice_id' => $invoice->id,
                'payment_id' => $payment->id,
            ]);
        }

        if ($owner) {
            $preferences = app(NotificationPreferenceService::class);
            if ($preferences->shouldNotify($owner, NotificationPreferenceService::CATEGORY_BILLING)) {
                Notification::send($owner, new InvoicePaymentNotification($invoice, $payment, 'owner'));
            }
        }
    }

    private function notifyClient(Invoice $invoice, Payment $payment): void
    {
        $customer = $invoice->customer;
        if (! $customer) {
            return;
        }

        $queueReceiptWillBeDelivered = $this->isReservationQueueInvoice($invoice)
            && in_array(
                (string) $invoice->receipt_delivery,
                [QueueInvoiceReceiptService::DELIVERY_EMAIL, QueueInvoiceReceiptService::DELIVERY_SMS],
                true
            );

        if ($customer->email && ! $queueReceiptWillBeDelivered) {
            $owner = $invoice->relationLoaded('user')
                ? $invoice->user
                : User::query()->select(['id', 'locale'])->find($invoice->user_id);
            $locale = LocalePreference::forCustomer($customer, $owner);
            $isFr = str_starts_with($locale, 'fr');
            NotificationDispatcher::send($customer, new ActionEmailNotification(
                $isFr ? 'Paiement confirme' : 'Payment confirmed',
                $isFr ? 'Votre paiement a bien ete recu.' : 'Your payment has been received.',
                $this->buildPaymentDetails($invoice, $payment, $locale),
                URL::temporarySignedRoute(
                    'public.invoices.show',
                    now('UTC')->addDays(7),
                    ['invoice' => $invoice->id]
                ),
                $isFr ? 'Voir la facture' : 'View invoice',
                $isFr ? 'Confirmation de paiement' : 'Payment confirmation'
            ), [
                'invoice_id' => $invoice->id,
                'payment_id' => $payment->id,
            ]);
        }

        if ($customer->portalUser) {
            $portalUser = $customer->portalUser;
            $preferences = app(NotificationPreferenceService::class);
            if ($preferences->shouldNotify($portalUser, NotificationPreferenceService::CATEGORY_BILLING)) {
                Notification::send($portalUser, new InvoicePaymentNotification($invoice, $payment, 'client'));
            }
        }
    }

    private function client(): StripeClient
    {
        if ($this->client) {
            return $this->client;
        }

        $secret = config('services.stripe.secret');
        if (! $secret) {
            Log::warning('Stripe secret key is missing for invoice payments.');
        }

        $this->client = new StripeClient($secret ?: '');

        return $this->client;
    }

    public function resolveConnectedAccountId(Invoice $invoice): ?string
    {
        $owner = $invoice->user;
        if (! $owner) {
            return null;
        }

        $connect = app(StripeConnectService::class);
        if (! $connect->isEnabled() || ! $connect->isAccountReady($owner)) {
            return null;
        }

        return $owner->stripe_connect_account_id ?: null;
    }

    private function invoiceRequestsFutureStripeUsage(Invoice $invoice): bool
    {
        $invoice->loadMissing('items');

        return $invoice->items->contains(function ($item): bool {
            if ((int) data_get($item->meta, 'renewal_for_customer_package_id', 0) > 0) {
                return true;
            }

            $isRecurringOffer = (bool) data_get($item->meta, 'offer_package_snapshot.is_recurring', false)
                || (bool) data_get($item->meta, 'source_details.offer_package.is_recurring', false);

            return data_get($item->meta, 'offer_package_type') === 'forfait' && $isRecurringOffer;
        });
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, string>
     */
    private function invoicePaymentMetadata(Invoice $invoice, float $amount, array $extra = []): array
    {
        return array_filter(array_merge([
            'invoice_id' => (string) $invoice->id,
            'user_id' => (string) ($invoice->user_id ?? ''),
            'customer_id' => (string) ($invoice->customer_id ?? ''),
            'payment_amount' => number_format($amount, 2, '.', ''),
            'tip_amount' => '0.00',
            'tip_type' => 'none',
            'tip_base_amount' => number_format($amount, 2, '.', ''),
            'charged_total' => number_format($amount, 2, '.', ''),
        ], $extra), fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array{stripe_customer_id?: string, stripe_payment_method_id?: string, source?: string}
     */
    private function resolveAutomaticPaymentContext(Invoice $invoice, CustomerPackage $package): array
    {
        $customer = $package->customer instanceof Customer
            ? $package->customer
            : ($invoice->customer instanceof Customer ? $invoice->customer : null);

        $metadata = (array) ($package->metadata ?? []);
        $sourceDetails = (array) ($package->source_details ?? []);

        $customerId = $this->firstString(
            data_get($metadata, 'recurrence.auto_payment.stripe_customer_id'),
            data_get($metadata, 'recurrence.stripe_customer_id'),
            data_get($metadata, 'stripe_customer_id'),
            data_get($sourceDetails, 'recurrence.stripe_customer_id'),
            $customer?->stripe_customer_id,
            $customer?->portalUser?->stripe_customer_id
        );

        $paymentMethodId = $this->firstString(
            data_get($metadata, 'recurrence.auto_payment.stripe_payment_method_id'),
            data_get($metadata, 'recurrence.stripe_payment_method_id'),
            data_get($metadata, 'stripe_payment_method_id'),
            data_get($sourceDetails, 'recurrence.stripe_payment_method_id'),
            $customer?->stripe_default_payment_method_id
        );

        return array_filter([
            'stripe_customer_id' => $customerId,
            'stripe_payment_method_id' => $paymentMethodId,
            'source' => $paymentMethodId ? 'stored_payment_method' : ($customerId ? 'stripe_customer_default' : null),
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function resolveDefaultPaymentMethodId(string $customerId, array $options = []): ?string
    {
        try {
            $customer = $this->client()->customers->retrieve($customerId, [
                'expand' => ['invoice_settings.default_payment_method'],
            ], $options);
            $customerPayload = $this->stripeObjectToArray($customer);
            $default = data_get($customerPayload, 'invoice_settings.default_payment_method');
            $defaultId = $this->stringValue($default);
            if ($defaultId) {
                return $defaultId;
            }

            $paymentMethods = $this->client()->paymentMethods->all([
                'customer' => $customerId,
                'type' => 'card',
                'limit' => 1,
            ], $options);
            $paymentMethodsPayload = $this->stripeObjectToArray($paymentMethods);

            return $this->stringValue(data_get($paymentMethodsPayload, 'data.0.id'));
        } catch (\Throwable $exception) {
            Log::warning('Unable to resolve Stripe default payment method for automatic renewal.', [
                'stripe_customer_id' => $customerId,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function storeInvoiceCustomerStripePaymentContext(
        Invoice $invoice,
        mixed $stripeCustomerId,
        mixed $stripePaymentMethodId
    ): void {
        $customerId = $this->stringValue($stripeCustomerId);
        $paymentMethodId = $this->stringValue($stripePaymentMethodId);
        if (! $customerId && ! $paymentMethodId) {
            return;
        }

        $customer = $invoice->relationLoaded('customer')
            ? $invoice->customer
            : $invoice->customer()->first();
        if (! $customer instanceof Customer) {
            return;
        }

        $updates = [];
        if ($customerId && $customer->stripe_customer_id !== $customerId) {
            $updates['stripe_customer_id'] = $customerId;
        }

        if ($paymentMethodId && $customer->stripe_default_payment_method_id !== $paymentMethodId) {
            $updates['stripe_default_payment_method_id'] = $paymentMethodId;
        }

        if ($updates !== []) {
            $customer->forceFill($updates)->save();
        }
    }

    private function automaticPaymentExceptionResult(
        ApiErrorException $exception,
        string $customerId,
        string $paymentMethodId
    ): array {
        $stripeError = method_exists($exception, 'getError') ? $exception->getError() : null;
        $paymentIntentId = $this->stringValue(data_get($stripeError, 'payment_intent.id'));

        return $this->automaticPaymentResult('failed', [
            'attempted' => true,
            'stripe_customer_id' => $customerId,
            'stripe_payment_method_id' => $paymentMethodId,
            'payment_intent_id' => $paymentIntentId,
            'reason' => $stripeError?->code ?: 'stripe_api_error',
            'decline_code' => $stripeError?->decline_code ?? null,
            'message' => $stripeError?->message ?: $exception->getMessage(),
            'stripe_request_id' => method_exists($exception, 'getRequestId') ? $exception->getRequestId() : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function automaticPaymentResult(string $status, array $payload = []): array
    {
        return array_merge([
            'status' => $status,
            'attempted' => false,
        ], array_filter($payload, fn (mixed $value): bool => $value !== null && $value !== ''));
    }

    private function stripeObjectToArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value) && method_exists($value, 'toArray')) {
            $array = $value->toArray();

            return is_array($array) ? $array : [];
        }

        if (is_object($value)) {
            $json = json_encode($value);
            $array = $json ? json_decode($json, true) : null;

            return is_array($array) ? $array : [];
        }

        return [];
    }

    private function firstString(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            $string = $this->stringValue($value);
            if ($string) {
                return $string;
            }
        }

        return null;
    }

    private function stringValue(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value['id'] ?? null;
        } elseif (is_object($value) && isset($value->id)) {
            $value = $value->id;
        }

        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    private function nullableString(mixed $value): ?string
    {
        return $this->stringValue($value);
    }

    private function money(float $amount): float
    {
        return round($amount, 2);
    }

    private function isDeterministicStripeSessionCreationFailure(\Throwable $exception): bool
    {
        return $exception instanceof AuthenticationException
            || $exception instanceof PermissionException
            || $exception instanceof InvalidRequestException
            || $exception instanceof CardException;
    }

    private function moneyCents(float $amount): int
    {
        return (int) round($this->money($amount) * 100);
    }

    private function queueAttemptActiveKey(int $queueItemId): string
    {
        return 'reservation-queue:'.$queueItemId;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function resolveQueuePaymentAttempt(
        array $metadata,
        ?ReservationQueuePaymentAttempt $expectedAttempt = null
    ): ?ReservationQueuePaymentAttempt {
        $attemptId = $this->parseMetadataInteger($metadata['queue_payment_attempt_id'] ?? null);
        $publicId = $this->nullableString($metadata['queue_payment_attempt_public_id'] ?? null);

        if (! $expectedAttempt && ! $attemptId && ! $publicId) {
            return null;
        }

        $attempt = $expectedAttempt?->fresh()
            ?? ($attemptId
                ? ReservationQueuePaymentAttempt::query()->find($attemptId)
                : ReservationQueuePaymentAttempt::query()->where('public_id', $publicId)->first());

        if (! $attempt) {
            throw new StripeQueueCheckoutVerificationException(
                'Stripe referenced an unknown queue payment attempt.'
            );
        }

        if (($attemptId && (int) $attempt->id !== $attemptId)
            || ($publicId && ! hash_equals((string) $attempt->public_id, $publicId))) {
            $this->rejectQueuePaymentAttempt($attempt, 'Stripe queue payment attempt metadata does not match.');
        }

        if ($expectedAttempt && (! $attemptId || ! $publicId)) {
            $this->rejectQueuePaymentAttempt($attempt, 'Stripe queue payment attempt metadata is incomplete.');
        }

        return $attempt;
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function verifyQueueCheckoutSession(
        array $session,
        ReservationQueuePaymentAttempt $attempt,
        ?string $eventStripeAccountId
    ): void {
        $sessionId = $this->nullableString($session['id'] ?? null);
        if (! $sessionId) {
            $this->rejectQueuePaymentAttempt($attempt, 'Stripe Checkout session ID is missing.');
        }
        if ($attempt->stripe_checkout_session_id
            && ! hash_equals((string) $attempt->stripe_checkout_session_id, $sessionId)) {
            $this->rejectQueuePaymentAttempt($attempt, 'Stripe Checkout session ID does not match the active attempt.');
        }
        if (($session['mode'] ?? null) !== 'payment') {
            $this->rejectQueuePaymentAttempt($attempt, 'Stripe Checkout session is not a one-time payment.');
        }

        $this->verifyQueueStripeAccount($attempt, $eventStripeAccountId);
        $this->verifyQueuePaymentMetadata(
            is_array($session['metadata'] ?? null) ? $session['metadata'] : [],
            $attempt
        );

        if ((string) ($session['client_reference_id'] ?? '') !== (string) $attempt->invoice_id) {
            $this->rejectQueuePaymentAttempt($attempt, 'Stripe Checkout client reference does not match the invoice.');
        }
        if (! is_numeric($session['amount_total'] ?? null)
            || (int) $session['amount_total'] !== $this->moneyCents((float) $attempt->charged_total)) {
            $this->rejectQueuePaymentAttempt($attempt, 'Stripe Checkout charged total does not match the attempt.');
        }

        $currency = strtoupper(trim((string) ($session['currency'] ?? '')));
        if ($currency === '' || $currency !== strtoupper((string) $attempt->currency_code)) {
            $this->rejectQueuePaymentAttempt($attempt, 'Stripe Checkout currency does not match the invoice.');
        }

        $attempt->forceFill([
            'stripe_checkout_session_id' => $sessionId,
            'last_verified_at' => now('UTC'),
            'last_error' => null,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $intent
     */
    private function verifyQueuePaymentIntent(
        array $intent,
        ReservationQueuePaymentAttempt $attempt,
        ?string $eventStripeAccountId
    ): void {
        $this->verifyQueueStripeAccount($attempt, $eventStripeAccountId);
        $this->verifyQueuePaymentMetadata(
            is_array($intent['metadata'] ?? null) ? $intent['metadata'] : [],
            $attempt
        );

        $received = $intent['amount_received'] ?? $intent['amount'] ?? null;
        if (! is_numeric($received)
            || (int) $received !== $this->moneyCents((float) $attempt->charged_total)) {
            $this->rejectQueuePaymentAttempt($attempt, 'Stripe payment intent total does not match the attempt.');
        }

        $currency = strtoupper(trim((string) ($intent['currency'] ?? '')));
        if ($currency === '' || $currency !== strtoupper((string) $attempt->currency_code)) {
            $this->rejectQueuePaymentAttempt($attempt, 'Stripe payment intent currency does not match the invoice.');
        }

        $attempt->forceFill([
            'stripe_payment_intent_id' => $this->nullableString($intent['id'] ?? null),
            'last_verified_at' => now('UTC'),
            'last_error' => null,
        ])->save();
    }

    private function verifyQueueStripeAccount(
        ReservationQueuePaymentAttempt $attempt,
        ?string $eventStripeAccountId
    ): void {
        $expected = $this->nullableString($attempt->stripe_account_id);
        $actual = $this->nullableString($eventStripeAccountId);
        if ($expected !== $actual) {
            $this->rejectQueuePaymentAttempt(
                $attempt,
                'Stripe event connected account does not match the queue payment attempt.'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function verifyQueuePaymentMetadata(
        array $metadata,
        ReservationQueuePaymentAttempt $attempt
    ): void {
        $expectedStrings = [
            'invoice_id' => (string) $attempt->invoice_id,
            'user_id' => (string) $attempt->account_id,
            'reservation_queue_item_id' => (string) $attempt->reservation_queue_item_id,
            'queue_payment_attempt_id' => (string) $attempt->id,
            'queue_payment_attempt_public_id' => (string) $attempt->public_id,
            'tip_type' => (string) $attempt->tip_type,
        ];

        foreach ($expectedStrings as $key => $expected) {
            if (! isset($metadata[$key]) || ! hash_equals($expected, (string) $metadata[$key])) {
                $this->rejectQueuePaymentAttempt($attempt, "Stripe metadata [{$key}] does not match the attempt.");
            }
        }

        $expectedAccount = $this->nullableString($attempt->stripe_account_id);
        $metadataAccount = $this->nullableString($metadata['connect_account_id'] ?? null);
        if ($expectedAccount !== $metadataAccount) {
            $this->rejectQueuePaymentAttempt($attempt, 'Stripe metadata connected account does not match the attempt.');
        }

        $expectedMoney = [
            'payment_amount' => (float) $attempt->amount,
            'tip_amount' => (float) $attempt->tip_amount,
            'tip_base_amount' => (float) $attempt->tip_base_amount,
            'charged_total' => (float) $attempt->charged_total,
        ];
        foreach ($expectedMoney as $key => $expected) {
            if (! is_numeric($metadata[$key] ?? null)
                || $this->moneyCents((float) $metadata[$key]) !== $this->moneyCents($expected)) {
                $this->rejectQueuePaymentAttempt($attempt, "Stripe metadata [{$key}] does not match the attempt.");
            }
        }

        $expectedTipPercent = $attempt->tip_percent !== null ? (float) $attempt->tip_percent : null;
        $metadataTipPercent = $this->parseMetadataAmount($metadata['tip_percent'] ?? null);
        if (($expectedTipPercent === null) !== ($metadataTipPercent === null)
            || ($expectedTipPercent !== null
                && $this->moneyCents($expectedTipPercent) !== $this->moneyCents((float) $metadataTipPercent))) {
            $this->rejectQueuePaymentAttempt($attempt, 'Stripe tip percentage metadata does not match the attempt.');
        }

        $expectedAssignee = $attempt->tip_assignee_user_id
            ? (int) $attempt->tip_assignee_user_id
            : null;
        $metadataAssignee = $this->parseMetadataInteger($metadata['tip_assignee_user_id'] ?? null);
        if ($expectedAssignee !== $metadataAssignee) {
            $this->rejectQueuePaymentAttempt($attempt, 'Stripe tip assignee metadata does not match the attempt.');
        }

        $invoice = Invoice::query()->find($attempt->invoice_id);
        if (! $invoice
            || (int) $invoice->user_id !== (int) $attempt->account_id
            || (int) $invoice->reservation_queue_item_id !== (int) $attempt->reservation_queue_item_id
            || strtoupper((string) $invoice->currency_code) !== strtoupper((string) $attempt->currency_code)) {
            $this->rejectQueuePaymentAttempt($attempt, 'The invoice no longer matches the Stripe queue payment attempt.');
        }
    }

    private function completeQueuePaymentAttempt(
        ReservationQueuePaymentAttempt $attempt,
        Payment $payment,
        string $paymentIntentId
    ): void {
        $locked = ReservationQueuePaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
        if ((int) $locked->invoice_id !== (int) $payment->invoice_id
            || (int) $locked->reservation_queue_item_id !== (int) $payment->reservation_queue_item_id) {
            throw new StripeQueueCheckoutVerificationException(
                'The Stripe payment does not match its queue payment attempt.'
            );
        }

        $locked->forceFill([
            'active_key' => null,
            'payment_id' => $payment->id,
            'status' => ReservationQueuePaymentAttempt::STATUS_COMPLETED,
            'stripe_payment_intent_id' => $paymentIntentId,
            'completed_at' => $locked->completed_at ?: now('UTC'),
            'last_verified_at' => now('UTC'),
            'last_error' => null,
        ])->save();
    }

    private function rejectQueuePaymentAttempt(
        ReservationQueuePaymentAttempt $attempt,
        string $message
    ): never {
        $attempt->forceFill([
            'last_verified_at' => now('UTC'),
            'last_error' => Str::limit($message, 2000),
        ])->save();

        Log::warning('Stripe queue checkout verification failed.', [
            'attempt_id' => $attempt->id,
            'queue_item_id' => $attempt->reservation_queue_item_id,
            'invoice_id' => $attempt->invoice_id,
            'message' => $message,
        ]);

        throw new StripeQueueCheckoutVerificationException($message);
    }

    private function buildPaymentDetails(Invoice $invoice, Payment $payment, ?string $locale = null): array
    {
        $isFr = str_starts_with(LocalePreference::normalize($locale), 'fr');
        $tipAmount = (float) ($payment->tip_amount ?? 0);

        $details = [
            ['label' => $isFr ? 'Facture' : 'Invoice', 'value' => $invoice->number ?? $invoice->id],
            ['label' => $isFr ? 'Montant' : 'Amount', 'value' => '$'.number_format((float) $payment->amount, 2)],
        ];

        if ($tipAmount > 0) {
            $details[] = ['label' => $isFr ? 'Pourboire' : 'Tip', 'value' => '$'.number_format($tipAmount, 2)];
            $details[] = ['label' => $isFr ? 'Total facture' : 'Total charged', 'value' => '$'.number_format((float) $payment->amount + $tipAmount, 2)];
        }

        $details[] = ['label' => $isFr ? 'Solde restant' : 'Balance due', 'value' => '$'.number_format((float) $invoice->balance_due, 2)];

        return $details;
    }

    private function parseMetadataAmount(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return max(0, round((float) $value, 2));
    }

    private function parseMetadataTipType(mixed $value, float $tipAmount): string
    {
        $type = strtolower(trim((string) $value));
        if (in_array($type, ['none', 'percent', 'fixed'], true)) {
            return $type;
        }

        return $tipAmount > 0 ? 'fixed' : 'none';
    }

    private function parseMetadataInteger(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $parsed = (int) $value;

        return $parsed > 0 ? $parsed : null;
    }

    private function calculateApplicationFee(int $amountCents, float $feePercent): int
    {
        if ($amountCents <= 0 || $feePercent <= 0) {
            return 0;
        }

        $fee = (int) round($amountCents * ($feePercent / 100));

        return max(0, min($fee, $amountCents));
    }
}
