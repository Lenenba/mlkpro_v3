<?php

namespace App\Services;

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;

class StripeCatalogService
{
    private ?StripeClient $client = null;

    public function isEnabled(): bool
    {
        return (bool) config('services.stripe.connect_enabled')
            && (bool) config('services.stripe.enabled')
            && (bool) config('services.stripe.secret');
    }

    public function syncProductPrice(Product $product, bool $syncPrice = true): void
    {
        $owner = $product->relationLoaded('user') ? $product->user : User::find($product->user_id);
        if (!$owner) {
            return;
        }

        $connectService = app(StripeConnectService::class);
        if (!$connectService->isEnabled() || !$connectService->isAccountReady($owner)) {
            return;
        }

        $accountId = $owner->stripe_connect_account_id;
        if (!$accountId) {
            return;
        }

        $currency = strtolower((string) config('cashier.currency', 'USD'));

        $stripeProductId = $product->stripe_product_id;
        if (!$stripeProductId) {
            $stripeProduct = $this->client()->products->create([
                'name' => $product->name,
                'description' => $product->description,
                'active' => (bool) $product->is_active,
                'metadata' => [
                    'product_id' => (string) $product->id,
                    'owner_id' => (string) $owner->id,
                    'item_type' => (string) $product->item_type,
                ],
            ], ['stripe_account' => $accountId]);

            $stripeProductId = $stripeProduct->id ?? null;
        } else {
            try {
                $this->client()->products->update($stripeProductId, [
                    'name' => $product->name,
                    'description' => $product->description,
                    'active' => (bool) $product->is_active,
                ], ['stripe_account' => $accountId]);
            } catch (\Throwable $exception) {
                Log::warning('Stripe product update failed.', [
                    'product_id' => $product->id,
                    'stripe_product_id' => $stripeProductId,
                    'account_id' => $owner->id,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        if (!$stripeProductId) {
            return;
        }

        if (!$syncPrice) {
            $product->forceFill([
                'stripe_product_id' => $stripeProductId,
                'stripe_price_account_id' => $accountId,
            ])->save();
            return;
        }

        $amountCents = (int) round(((float) $product->price) * 100);
        if ($amountCents <= 0) {
            Log::info('Stripe catalog sync skipped (price <= 0).', [
                'product_id' => $product->id,
                'account_id' => $owner->id,
            ]);
            $product->forceFill([
                'stripe_product_id' => $stripeProductId,
                'stripe_price_account_id' => $accountId,
            ])->save();
            return;
        }

        $stripePrice = $this->client()->prices->create([
            'currency' => $currency,
            'unit_amount' => $amountCents,
            'product' => $stripeProductId,
        ], ['stripe_account' => $accountId]);

        $product->forceFill([
            'stripe_product_id' => $stripeProductId,
            'stripe_price_id' => $stripePrice->id ?? null,
            'stripe_price_account_id' => $accountId,
        ])->save();
    }

    private function client(): StripeClient
    {
        if ($this->client) {
            return $this->client;
        }

        $secret = config('services.stripe.secret');
        if (!$secret) {
            Log::warning('Stripe secret key is missing for Stripe catalog sync.');
        }

        $this->client = new StripeClient($secret ?: '');

        return $this->client;
    }
}
