<?php

namespace App\Services;

use App\Models\Billing\StripeSubscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;

class StripeBillingService
{
    private ?StripeClient $client = null;

    public function isConfigured(): bool
    {
        return (bool) config('services.stripe.secret');
    }

    public function createCheckoutSession(User $user, string $priceId, string $successUrl, string $cancelUrl, ?string $planKey = null): array
    {
        $client = $this->client();
        $customerId = $this->resolveCustomerId($user);

        $payload = [
            'mode' => 'subscription',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => (string) $user->id,
            'line_items' => [
                [
                    'price' => $priceId,
                    'quantity' => 1,
                ],
            ],
            'metadata' => array_filter([
                'subscription_type' => 'default',
                'plan_key' => $planKey,
            ]),
        ];

        if ($customerId) {
            $payload['customer'] = $customerId;
        } else {
            $payload['customer_email'] = $user->email;
        }

        $session = $client->checkout->sessions->create($payload);

        return [
            'id' => $session->id ?? null,
            'url' => $session->url ?? null,
        ];
    }

    public function syncFromCheckoutSession(string $sessionId, User $user): ?StripeSubscription
    {
        $client = $this->client();
        $session = $client->checkout->sessions->retrieve($sessionId, [
            'expand' => ['subscription', 'subscription.items.data.price'],
        ]);

        if (empty($session->subscription)) {
            return null;
        }

        $subscription = is_string($session->subscription)
            ? $client->subscriptions->retrieve($session->subscription)
            : $session->subscription;

        return $this->upsertSubscription($user, $subscription, $session->customer ?? null);
    }

    public function swapSubscription(User $user, string $priceId): ?StripeSubscription
    {
        $client = $this->client();
        $local = $this->getLocalSubscription($user);
        if (!$local) {
            return null;
        }

        $subscription = $client->subscriptions->retrieve($local->stripe_id);
        $itemId = $subscription->items->data[0]->id ?? null;
        if (!$itemId) {
            return null;
        }

        $updated = $client->subscriptions->update($subscription->id, [
            'items' => [
                [
                    'id' => $itemId,
                    'price' => $priceId,
                ],
            ],
            'proration_behavior' => 'create_prorations',
        ]);

        return $this->upsertSubscription($user, $updated, $updated->customer ?? null);
    }

    public function createPortalSession(User $user, string $returnUrl): ?string
    {
        $client = $this->client();
        $customerId = $this->resolveCustomerId($user);
        if (!$customerId) {
            return null;
        }

        $session = $client->billingPortal->sessions->create([
            'customer' => $customerId,
            'return_url' => $returnUrl,
        ]);

        return $session->url ?? null;
    }

    public function syncFromStripeSubscription(array $subscription, ?User $user = null): ?StripeSubscription
    {
        $stripeId = $subscription['id'] ?? null;
        if (!$stripeId) {
            return null;
        }

        $customerId = $subscription['customer'] ?? null;

        if (!$user && $stripeId) {
            $local = StripeSubscription::query()->where('stripe_id', $stripeId)->first();
            $user = $local?->user;
        }

        if (!$user && $customerId) {
            $user = User::query()->where('stripe_customer_id', $customerId)->first();
        }

        if (!$user) {
            return null;
        }

        return $this->upsertSubscriptionFromArray($user, $subscription);
    }

    public function getLocalSubscription(User $user): ?StripeSubscription
    {
        return StripeSubscription::query()
            ->where('user_id', $user->id)
            ->orderByDesc('updated_at')
            ->first();
    }

    private function upsertSubscription(User $user, $subscription, ?string $customerId = null): ?StripeSubscription
    {
        $stripeId = $subscription->id ?? null;
        if (!$stripeId) {
            return null;
        }

        $customerId = $customerId ?: ($subscription->customer ?? null);
        $priceId = $this->extractPriceId($subscription);

        $record = StripeSubscription::updateOrCreate(
            ['stripe_id' => $stripeId],
            [
                'user_id' => $user->id,
                'stripe_customer_id' => $customerId,
                'price_id' => $priceId,
                'status' => $subscription->status ?? null,
                'trial_ends_at' => $this->timestampToCarbon($subscription->trial_end ?? null),
                'ends_at' => $this->timestampToCarbon($subscription->ended_at ?? $subscription->canceled_at ?? null),
                'current_period_end' => $this->timestampToCarbon($subscription->current_period_end ?? null),
            ]
        );

        if ($customerId && $user->stripe_customer_id !== $customerId) {
            $user->forceFill(['stripe_customer_id' => $customerId])->save();
        }

        return $record;
    }

    private function upsertSubscriptionFromArray(User $user, array $subscription): ?StripeSubscription
    {
        $stripeId = $subscription['id'] ?? null;
        if (!$stripeId) {
            return null;
        }

        $customerId = $subscription['customer'] ?? null;
        $priceId = $this->extractPriceIdFromArray($subscription);

        $record = StripeSubscription::updateOrCreate(
            ['stripe_id' => $stripeId],
            [
                'user_id' => $user->id,
                'stripe_customer_id' => $customerId,
                'price_id' => $priceId,
                'status' => $subscription['status'] ?? null,
                'trial_ends_at' => $this->timestampToCarbon($subscription['trial_end'] ?? null),
                'ends_at' => $this->timestampToCarbon($subscription['ended_at'] ?? $subscription['canceled_at'] ?? null),
                'current_period_end' => $this->timestampToCarbon($subscription['current_period_end'] ?? null),
            ]
        );

        if ($customerId && $user->stripe_customer_id !== $customerId) {
            $user->forceFill(['stripe_customer_id' => $customerId])->save();
        }

        return $record;
    }

    private function resolveCustomerId(User $user): ?string
    {
        if ($user->stripe_customer_id) {
            return $user->stripe_customer_id;
        }

        $local = $this->getLocalSubscription($user);
        if ($local?->stripe_customer_id) {
            $user->forceFill(['stripe_customer_id' => $local->stripe_customer_id])->save();
            return $local->stripe_customer_id;
        }

        return null;
    }

    private function extractPriceId($subscription): ?string
    {
        $items = $subscription->items->data ?? [];
        $first = $items[0] ?? null;
        return $first?->price?->id ?? null;
    }

    private function extractPriceIdFromArray(array $subscription): ?string
    {
        $items = $subscription['items']['data'] ?? [];
        $first = $items[0] ?? null;
        return $first['price']['id'] ?? null;
    }

    private function timestampToCarbon($timestamp): ?Carbon
    {
        if (!$timestamp) {
            return null;
        }

        return Carbon::createFromTimestamp($timestamp, 'UTC');
    }

    private function client(): StripeClient
    {
        if ($this->client) {
            return $this->client;
        }

        $secret = config('services.stripe.secret');
        if (!$secret) {
            Log::warning('Stripe secret key is missing.');
        }

        $this->client = new StripeClient($secret ?: '');

        return $this->client;
    }
}
