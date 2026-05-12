<?php

namespace App\Services;

use App\Enums\CurrencyCode;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerPackage;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Models\Work;
use App\Notifications\ActionEmailNotification;
use App\Notifications\InvoicePaymentNotification;
use App\Services\OfferPackages\CustomerPackageService;
use App\Support\LocalePreference;
use App\Support\NotificationDispatcher;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;
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
        array $tip = []
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
        ]);

        $connectAccountId = $this->resolveConnectedAccountId($invoice);
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

        if ($invoice->customer?->stripe_customer_id) {
            $payload['customer'] = $invoice->customer->stripe_customer_id;
        } elseif ($invoice->customer?->email) {
            $payload['customer_email'] = $invoice->customer->email;
            if ($shouldSavePaymentMethod) {
                $payload['customer_creation'] = 'always';
            }
        }

        $options = $connectAccountId ? ['stripe_account' => $connectAccountId] : [];
        $session = $this->client()->checkout->sessions->create($payload, $options);

        return [
            'id' => $session->id ?? null,
            'url' => $session->url ?? null,
        ];
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

    public function recordPaymentFromCheckoutSession(array $session): ?Payment
    {
        $paymentStatus = $session['payment_status'] ?? null;
        if ($paymentStatus !== 'paid') {
            return null;
        }

        $paymentIntentId = $session['payment_intent'] ?? null;
        if (! $paymentIntentId) {
            return null;
        }

        $existing = Payment::query()
            ->where('provider', 'stripe')
            ->where('provider_reference', $paymentIntentId)
            ->first();
        if ($existing) {
            return $existing;
        }

        $metadata = $session['metadata'] ?? [];
        $invoiceId = $metadata['invoice_id'] ?? $session['client_reference_id'] ?? null;
        if (! $invoiceId) {
            return null;
        }

        $invoice = Invoice::query()->find($invoiceId);
        if (! $invoice || in_array($invoice->status, ['void', 'draft'], true)) {
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

        $amountTotalFloat = round(((int) $amountTotal) / 100, 2);
        $amount = $this->parseMetadataAmount($metadata['payment_amount'] ?? null) ?? $amountTotalFloat;
        $tipAmount = $this->parseMetadataAmount($metadata['tip_amount'] ?? null) ?? 0.0;
        $tipType = $this->parseMetadataTipType($metadata['tip_type'] ?? null, $tipAmount);
        $tipPercent = $this->parseMetadataAmount($metadata['tip_percent'] ?? null);
        $tipBaseAmount = $this->parseMetadataAmount($metadata['tip_base_amount'] ?? null) ?? $amount;
        $chargedTotal = $this->parseMetadataAmount($metadata['charged_total'] ?? null) ?? round($amount + $tipAmount, 2);
        $tipAssigneeUserId = $this->parseMetadataInteger($metadata['tip_assignee_user_id'] ?? null);
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
            $tipAssigneeUserId
        );
    }

    public function syncFromCheckoutSessionId(string $sessionId, ?string $stripeAccountId = null): ?Payment
    {
        $options = $stripeAccountId ? ['stripe_account' => $stripeAccountId] : [];
        $session = $this->client()->checkout->sessions->retrieve($sessionId, [], $options);
        $payload = is_array($session) ? $session : $session->toArray();

        return $this->recordPaymentFromCheckoutSession($payload);
    }

    public function recordPaymentFromPaymentIntent(array $intent): ?Payment
    {
        $paymentIntentId = $intent['id'] ?? null;
        if (! $paymentIntentId) {
            return null;
        }

        $existing = Payment::query()
            ->where('provider', 'stripe')
            ->where('provider_reference', $paymentIntentId)
            ->first();
        if ($existing) {
            return $existing;
        }

        $metadata = $intent['metadata'] ?? [];
        $invoiceId = $metadata['invoice_id'] ?? null;
        if (! $invoiceId) {
            return null;
        }

        $invoice = Invoice::query()->find($invoiceId);
        if (! $invoice || in_array($invoice->status, ['void', 'draft'], true)) {
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

        $amountTotalFloat = round(((int) $amountTotal) / 100, 2);
        $amount = $this->parseMetadataAmount($metadata['payment_amount'] ?? null) ?? $amountTotalFloat;
        $tipAmount = $this->parseMetadataAmount($metadata['tip_amount'] ?? null) ?? 0.0;
        $tipType = $this->parseMetadataTipType($metadata['tip_type'] ?? null, $tipAmount);
        $tipPercent = $this->parseMetadataAmount($metadata['tip_percent'] ?? null);
        $tipBaseAmount = $this->parseMetadataAmount($metadata['tip_base_amount'] ?? null) ?? $amount;
        $chargedTotal = $this->parseMetadataAmount($metadata['charged_total'] ?? null) ?? round($amount + $tipAmount, 2);
        $tipAssigneeUserId = $this->parseMetadataInteger($metadata['tip_assignee_user_id'] ?? null);
        if ($amount <= 0) {
            return null;
        }

        return $this->recordStripePayment(
            $invoice,
            $amount,
            $paymentIntentId,
            $intent['id'] ?? null,
            $tipAmount,
            $tipType,
            $tipPercent,
            $tipBaseAmount,
            $chargedTotal,
            $tipAssigneeUserId
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
        ?int $tipAssigneeUserId = null
    ): ?Payment {
        $tipAmount = max(0, $tipAmount);
        $tipType = in_array($tipType, ['none', 'percent', 'fixed'], true) ? $tipType : ($tipAmount > 0 ? 'fixed' : 'none');
        $tipBaseAmount = $tipBaseAmount !== null ? max(0, $tipBaseAmount) : $amount;
        $chargedTotal = $chargedTotal !== null ? max(0, $chargedTotal) : round($amount + $tipAmount, 2);

        $payment = Payment::firstOrCreate(
            [
                'provider' => 'stripe',
                'provider_reference' => $paymentIntentId,
            ],
            [
                'invoice_id' => $invoice->id,
                'customer_id' => $invoice->customer_id,
                'user_id' => $invoice->user_id,
                'amount' => $amount,
                'currency_code' => $invoice->currency_code,
                'tip_amount' => $tipAmount,
                'tip_type' => $tipType,
                'tip_percent' => $tipType === 'percent' ? $tipPercent : null,
                'tip_base_amount' => $tipBaseAmount,
                'charged_total' => $chargedTotal,
                'tip_assignee_user_id' => $tipAmount > 0 ? $tipAssigneeUserId : null,
                'method' => 'stripe',
                'status' => 'completed',
                'reference' => $paymentIntentId,
                'notes' => $sessionId ? "Stripe session {$sessionId}" : null,
                'paid_at' => now(),
            ]
        );

        app(TipAllocationService::class)->syncForPayment($payment);

        $previousStatus = $invoice->status;
        $invoice->refreshPaymentStatus();

        if ($payment->wasRecentlyCreated) {
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

        if ($invoice->status === 'paid' && $invoice->work) {
            $invoice->work->status = Work::STATUS_CLOSED;
            $invoice->work->save();
        }

        if ($invoice->status === 'paid') {
            app(CustomerPackageService::class)->renewFromPaidInvoice($invoice);
        }

        return $payment;
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

        if ($customer->email) {
            $owner = $invoice->relationLoaded('user')
                ? $invoice->user
                : User::query()->select(['id', 'locale'])->find($invoice->user_id);
            $locale = LocalePreference::forCustomer($customer, $owner);
            $isFr = str_starts_with($locale, 'fr');
            NotificationDispatcher::send($customer, new ActionEmailNotification(
                $isFr ? 'Paiement confirme' : 'Payment confirmed',
                $isFr ? 'Votre paiement a bien ete recu.' : 'Your payment has been received.',
                $this->buildPaymentDetails($invoice, $payment, $locale),
                route('public.invoices.show', $invoice->id),
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
