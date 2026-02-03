<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Models\Work;
use App\Notifications\ActionEmailNotification;
use App\Notifications\InvoicePaymentNotification;
use App\Support\NotificationDispatcher;
use App\Services\NotificationPreferenceService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Stripe\StripeClient;
use App\Services\StripeConnectService;

class StripeInvoiceService
{
    private ?StripeClient $client = null;

    public function isConfigured(): bool
    {
        return (bool) config('services.stripe.enabled')
            && (bool) config('services.stripe.secret');
    }

    public function createCheckoutSession(Invoice $invoice, string $successUrl, string $cancelUrl, ?float $amount = null): array
    {
        $invoice->loadMissing(['customer', 'user']);

        $balanceDue = (float) $invoice->balance_due;
        $amount = $amount !== null ? (float) $amount : $balanceDue;
        $amount = min($amount, $balanceDue);
        $amountCents = (int) round($amount * 100);
        if ($amountCents <= 0) {
            return [
                'id' => null,
                'url' => null,
            ];
        }

        $currency = strtolower((string) config('cashier.currency', 'USD'));
        $label = $invoice->number ? "Invoice {$invoice->number}" : "Invoice #{$invoice->id}";
        $companyName = $invoice->user?->company_name ?: config('app.name');

        $metadata = array_filter([
            'invoice_id' => (string) $invoice->id,
            'user_id' => (string) ($invoice->user_id ?? ''),
            'customer_id' => (string) ($invoice->customer_id ?? ''),
        ]);

        $connectAccountId = $this->resolveConnectedAccountId($invoice);
        $feePercent = (float) config('services.stripe.connect_fee_percent', 0);
        if ($connectAccountId) {
            $metadata['connect_account_id'] = $connectAccountId;
            if ($feePercent > 0) {
                $metadata['connect_fee_percent'] = (string) $feePercent;
            }
        }

        $payload = [
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => (string) $invoice->id,
            'metadata' => $metadata,
            'line_items' => [
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
            ],
            'payment_intent_data' => [
                'metadata' => $metadata,
                'description' => $label,
            ],
        ];

        if ($connectAccountId && $feePercent > 0) {
            $applicationFee = $this->calculateApplicationFee($amountCents, $feePercent);
            if ($applicationFee > 0) {
                $payload['payment_intent_data']['application_fee_amount'] = $applicationFee;
            }
        }

        if ($invoice->customer?->email) {
            $payload['customer_email'] = $invoice->customer->email;
        }

        $options = $connectAccountId ? ['stripe_account' => $connectAccountId] : [];
        $session = $this->client()->checkout->sessions->create($payload, $options);

        return [
            'id' => $session->id ?? null,
            'url' => $session->url ?? null,
        ];
    }

    public function recordPaymentFromCheckoutSession(array $session): ?Payment
    {
        $paymentStatus = $session['payment_status'] ?? null;
        if ($paymentStatus !== 'paid') {
            return null;
        }

        $paymentIntentId = $session['payment_intent'] ?? null;
        if (!$paymentIntentId) {
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
        if (!$invoiceId) {
            return null;
        }

        $invoice = Invoice::query()->find($invoiceId);
        if (!$invoice || in_array($invoice->status, ['void', 'draft'], true)) {
            return null;
        }

        $amountTotal = $session['amount_total'] ?? null;
        if (!$amountTotal) {
            return null;
        }

        $amount = round(((int) $amountTotal) / 100, 2);
        if ($amount <= 0) {
            return null;
        }

        return $this->recordStripePayment($invoice, $amount, $paymentIntentId, $session['id'] ?? null);
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
        if (!$paymentIntentId) {
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
        if (!$invoiceId) {
            return null;
        }

        $invoice = Invoice::query()->find($invoiceId);
        if (!$invoice || in_array($invoice->status, ['void', 'draft'], true)) {
            return null;
        }

        $amountTotal = $intent['amount_received'] ?? $intent['amount'] ?? null;
        if (!$amountTotal) {
            return null;
        }

        $amount = round(((int) $amountTotal) / 100, 2);
        if ($amount <= 0) {
            return null;
        }

        return $this->recordStripePayment($invoice, $amount, $paymentIntentId, $intent['id'] ?? null);
    }

    private function recordStripePayment(Invoice $invoice, float $amount, string $paymentIntentId, ?string $sessionId): ?Payment
    {
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
                'method' => 'stripe',
                'status' => 'completed',
                'reference' => $paymentIntentId,
                'notes' => $sessionId ? "Stripe session {$sessionId}" : null,
                'paid_at' => now(),
            ]
        );

        $previousStatus = $invoice->status;
        $invoice->refreshPaymentStatus();

        if ($payment->wasRecentlyCreated) {
            ActivityLog::record(null, $payment, 'created', [
                'invoice_id' => $invoice->id,
                'amount' => $payment->amount,
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

        return $payment;
    }

    private function notifyCompany(Invoice $invoice, Payment $payment): void
    {
        $owner = User::find($invoice->user_id);
        if ($owner && $owner->email) {
            $customer = $invoice->customer;
            $customerLabel = $customer?->company_name
                ?: trim(($customer?->first_name ?? '') . ' ' . ($customer?->last_name ?? ''));

            NotificationDispatcher::send($owner, new ActionEmailNotification(
                'Payment received from client',
                $customerLabel ? $customerLabel . ' paid via Stripe.' : 'A client paid via Stripe.',
                [
                    ['label' => 'Invoice', 'value' => $invoice->number ?? $invoice->id],
                    ['label' => 'Amount', 'value' => '$' . number_format((float) $payment->amount, 2)],
                    ['label' => 'Balance due', 'value' => '$' . number_format((float) $invoice->balance_due, 2)],
                ],
                route('invoice.show', $invoice->id),
                'View invoice',
                'Stripe payment received'
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
        if (!$customer) {
            return;
        }

        if ($customer->email) {
            NotificationDispatcher::send($customer, new ActionEmailNotification(
                'Payment confirmed',
                'Your payment has been received.',
                [
                    ['label' => 'Invoice', 'value' => $invoice->number ?? $invoice->id],
                    ['label' => 'Amount', 'value' => '$' . number_format((float) $payment->amount, 2)],
                    ['label' => 'Balance due', 'value' => '$' . number_format((float) $invoice->balance_due, 2)],
                ],
                route('public.invoices.show', $invoice->id),
                'View invoice',
                'Payment confirmation'
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
        if (!$secret) {
            Log::warning('Stripe secret key is missing for invoice payments.');
        }

        $this->client = new StripeClient($secret ?: '');

        return $this->client;
    }

    public function resolveConnectedAccountId(Invoice $invoice): ?string
    {
        $owner = $invoice->user;
        if (!$owner) {
            return null;
        }

        $connect = app(StripeConnectService::class);
        if (!$connect->isEnabled() || !$connect->isAccountReady($owner)) {
            return null;
        }

        return $owner->stripe_connect_account_id ?: null;
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
