<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;

class StripeConnectService
{
    private ?StripeClient $client = null;

    public function isEnabled(): bool
    {
        return (bool) config('services.stripe.connect_enabled')
            && (bool) config('services.stripe.enabled')
            && (bool) config('services.stripe.secret');
    }

    public function createOnboardingLink(User $owner, string $refreshUrl, string $returnUrl): ?string
    {
        $accountId = $this->ensureAccount($owner);
        if (!$accountId) {
            return null;
        }

        $link = $this->client()->accountLinks->create([
            'account' => $accountId,
            'refresh_url' => $refreshUrl,
            'return_url' => $returnUrl,
            'type' => 'account_onboarding',
        ]);

        return $link->url ?? null;
    }

    public function refreshAccountStatus(User $owner): ?array
    {
        $accountId = $owner->stripe_connect_account_id;
        if (!$accountId) {
            return null;
        }

        $account = $this->client()->accounts->retrieve($accountId);
        $payload = is_array($account) ? $account : $account->toArray();

        $this->syncAccountPayload($owner, $payload);

        return $payload;
    }

    public function isAccountReady(User $owner): bool
    {
        return (bool) $owner->stripe_connect_account_id
            && (bool) $owner->stripe_connect_charges_enabled
            && (bool) $owner->stripe_connect_payouts_enabled;
    }

    private function ensureAccount(User $owner): ?string
    {
        if ($owner->stripe_connect_account_id) {
            return $owner->stripe_connect_account_id;
        }

        if (!$this->isEnabled()) {
            return null;
        }

        $account = $this->client()->accounts->create([
            'type' => 'express',
            'email' => $owner->email,
            'capabilities' => [
                'card_payments' => ['requested' => true],
                'transfers' => ['requested' => true],
            ],
        ]);

        $payload = is_array($account) ? $account : $account->toArray();
        $this->syncAccountPayload($owner, $payload);

        return $payload['id'] ?? null;
    }

    private function syncAccountPayload(User $owner, array $payload): void
    {
        $detailsSubmitted = (bool) ($payload['details_submitted'] ?? false);
        $shouldSetOnboardedAt = $detailsSubmitted && !$owner->stripe_connect_onboarded_at;

        $owner->forceFill([
            'stripe_connect_account_id' => $payload['id'] ?? $owner->stripe_connect_account_id,
            'stripe_connect_charges_enabled' => (bool) ($payload['charges_enabled'] ?? false),
            'stripe_connect_payouts_enabled' => (bool) ($payload['payouts_enabled'] ?? false),
            'stripe_connect_details_submitted' => $detailsSubmitted,
            'stripe_connect_requirements' => $payload['requirements'] ?? null,
            'stripe_connect_onboarded_at' => $shouldSetOnboardedAt ? now() : $owner->stripe_connect_onboarded_at,
        ])->save();
    }

    private function client(): StripeClient
    {
        if ($this->client) {
            return $this->client;
        }

        $secret = config('services.stripe.secret');
        if (!$secret) {
            Log::warning('Stripe secret key is missing for Stripe Connect.');
        }

        $this->client = new StripeClient($secret ?: '');

        return $this->client;
    }
}
