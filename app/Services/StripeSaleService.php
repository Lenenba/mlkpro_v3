<?php

namespace App\Services;

use App\Models\Sale;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;

class StripeSaleService
{
    private ?StripeClient $client = null;

    public function isConfigured(): bool
    {
        return (bool) config('services.stripe.enabled')
            && (bool) config('services.stripe.secret');
    }

    public function createCheckoutSession(
        Sale $sale,
        string $successUrl,
        string $cancelUrl,
        ?float $amountOverride = null,
        ?string $paymentType = null
    ): array {
        $sale->loadMissing(['customer', 'user']);

        $amount = $amountOverride ?? (float) $sale->total;
        $amountCents = (int) round($amount * 100);
        if ($amountCents <= 0) {
            return [
                'id' => null,
                'url' => null,
            ];
        }

        $currency = strtolower((string) config('cashier.currency', 'USD'));
        $label = $sale->number ? "Sale {$sale->number}" : "Sale #{$sale->id}";
        if ($paymentType === 'deposit') {
            $label = $sale->number ? "Deposit for Sale {$sale->number}" : "Deposit for Sale #{$sale->id}";
        } elseif ($paymentType === 'balance') {
            $label = $sale->number ? "Balance for Sale {$sale->number}" : "Balance for Sale #{$sale->id}";
        }
        $companyName = $sale->user?->company_name ?: config('app.name');

        $metadata = array_filter([
            'sale_id' => (string) $sale->id,
            'user_id' => (string) ($sale->user_id ?? ''),
            'customer_id' => (string) ($sale->customer_id ?? ''),
        ]);
        if ($paymentType) {
            $metadata['payment_type'] = $paymentType;
        }
        $metadata['payment_amount'] = number_format($amount, 2, '.', '');

        $connectAccountId = $this->resolveConnectedAccountId($sale);
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
            'client_reference_id' => (string) $sale->id,
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

        if ($sale->customer?->email) {
            $payload['customer_email'] = $sale->customer->email;
        }

        $options = $connectAccountId ? ['stripe_account' => $connectAccountId] : [];
        $session = $this->client()->checkout->sessions->create($payload, $options);

        return [
            'id' => $session->id ?? null,
            'url' => $session->url ?? null,
        ];
    }

    public function syncFromCheckoutSessionId(string $sessionId, ?Sale $sale = null): ?Sale
    {
        $options = [];
        if ($sale) {
            $connectAccountId = $this->resolveConnectedAccountId($sale);
            if ($connectAccountId) {
                $options = ['stripe_account' => $connectAccountId];
            }
        }

        $session = $this->client()->checkout->sessions->retrieve($sessionId, [], $options);
        $payload = is_array($session) ? $session : $session->toArray();

        return $this->recordPaymentFromCheckoutSession($payload);
    }

    public function recordPaymentFromCheckoutSession(array $session): ?Sale
    {
        $paymentStatus = $session['payment_status'] ?? null;
        if ($paymentStatus !== 'paid') {
            return null;
        }

        $paymentIntentId = $session['payment_intent'] ?? null;
        if (!$paymentIntentId) {
            return null;
        }

        $metadata = $session['metadata'] ?? [];
        $saleId = $metadata['sale_id'] ?? $session['client_reference_id'] ?? null;
        if (!$saleId) {
            return null;
        }

        $sale = Sale::query()->find($saleId);
        if (!$sale || in_array($sale->status, [Sale::STATUS_CANCELED], true)) {
            return null;
        }

        $policyDecision = app(TenantPaymentMethodGuardService::class)->evaluate(
            (int) $sale->user_id,
            'stripe',
            'sale_webhook'
        );
        if (!$policyDecision['allowed']) {
            Log::warning('Stripe sale payment policy mismatch.', [
                'account_id' => $sale->user_id,
                'sale_id' => $sale->id,
                'provider_reference' => $paymentIntentId,
                'event' => 'checkout.session',
                'error_code' => TenantPaymentMethodGuardService::ERROR_CODE,
            ]);
        }

        $amountTotal = $session['amount_total'] ?? null;
        if (!$amountTotal) {
            return null;
        }

        $amount = round(((int) $amountTotal) / 100, 2);
        if ($amount <= 0) {
            return null;
        }

        return app(SalePaymentService::class)
            ->recordStripePayment($sale, $amount, $paymentIntentId, $session['id'] ?? null);
    }

    public function recordPaymentFromPaymentIntent(array $intent): ?Sale
    {
        $paymentIntentId = $intent['id'] ?? null;
        if (!$paymentIntentId) {
            return null;
        }

        $metadata = $intent['metadata'] ?? [];
        $saleId = $metadata['sale_id'] ?? null;
        if (!$saleId) {
            return null;
        }

        $sale = Sale::query()->find($saleId);
        if (!$sale || in_array($sale->status, [Sale::STATUS_CANCELED], true)) {
            return null;
        }

        $policyDecision = app(TenantPaymentMethodGuardService::class)->evaluate(
            (int) $sale->user_id,
            'stripe',
            'sale_webhook'
        );
        if (!$policyDecision['allowed']) {
            Log::warning('Stripe sale payment policy mismatch.', [
                'account_id' => $sale->user_id,
                'sale_id' => $sale->id,
                'provider_reference' => $paymentIntentId,
                'event' => 'payment_intent',
                'error_code' => TenantPaymentMethodGuardService::ERROR_CODE,
            ]);
        }

        $amountTotal = $intent['amount_received'] ?? $intent['amount'] ?? null;
        if (!$amountTotal) {
            return null;
        }

        $amount = round(((int) $amountTotal) / 100, 2);
        if ($amount <= 0) {
            return null;
        }

        return app(SalePaymentService::class)->recordStripePayment($sale, $amount, $paymentIntentId, null);
    }


    private function client(): StripeClient
    {
        if ($this->client) {
            return $this->client;
        }

        $secret = config('services.stripe.secret');
        if (!$secret) {
            Log::warning('Stripe secret key is missing for sale payments.');
        }

        $this->client = new StripeClient($secret ?: '');

        return $this->client;
    }

    private function resolveConnectedAccountId(Sale $sale): ?string
    {
        $owner = $sale->relationLoaded('user')
            ? $sale->user
            : $sale->user()->first();
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
