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
use App\Services\TipAllocationService;
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

    public function createCheckoutSession(
        Invoice $invoice,
        string $successUrl,
        string $cancelUrl,
        ?float $amount = null,
        array $tip = []
    ): array
    {
        $invoice->loadMissing(['customer', 'user']);

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

        $currency = strtolower((string) config('cashier.currency', 'USD'));
        $label = $invoice->number ? "Invoice {$invoice->number}" : "Invoice #{$invoice->id}";
        $companyName = $invoice->user?->company_name ?: config('app.name');

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

        if ($connectAccountId && $feePercent > 0) {
            $applicationFee = $this->calculateApplicationFee($amountCents + $tipCents, $feePercent);
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
    ): ?Payment
    {
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
                $this->buildPaymentDetails($invoice, $payment),
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
                $this->buildPaymentDetails($invoice, $payment),
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

    private function buildPaymentDetails(Invoice $invoice, Payment $payment): array
    {
        $tipAmount = (float) ($payment->tip_amount ?? 0);

        $details = [
            ['label' => 'Invoice', 'value' => $invoice->number ?? $invoice->id],
            ['label' => 'Amount', 'value' => '$' . number_format((float) $payment->amount, 2)],
        ];

        if ($tipAmount > 0) {
            $details[] = ['label' => 'Tip', 'value' => '$' . number_format($tipAmount, 2)];
            $details[] = ['label' => 'Total charged', 'value' => '$' . number_format((float) $payment->amount + $tipAmount, 2)];
        }

        $details[] = ['label' => 'Balance due', 'value' => '$' . number_format((float) $invoice->balance_due, 2)];

        return $details;
    }

    private function parseMetadataAmount(mixed $value): ?float
    {
        if (!is_numeric($value)) {
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
        if (!is_numeric($value)) {
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
