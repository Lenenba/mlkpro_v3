<?php

namespace App\Services;

use App\Models\Billing\StripeSubscription;
use App\Models\TeamMember;
use App\Models\User;
use Carbon\Carbon;
use Laravel\Paddle\Subscription as PaddleSubscription;

class BillingSubscriptionService
{
    public function providerRequested(): string
    {
        $provider = strtolower((string) config('billing.provider', 'paddle'));
        return $provider !== '' ? $provider : 'paddle';
    }

    public function providerEffective(): string
    {
        $provider = strtolower((string) config('billing.provider_effective', $this->providerRequested()));
        return $provider !== '' ? $provider : $this->providerRequested();
    }

    public function providerReady(): bool
    {
        return (bool) config('billing.provider_ready', true);
    }

    public function providerLabel(): string
    {
        $provider = $this->providerEffective();
        return $provider !== '' ? ucfirst($provider) : 'Paddle';
    }

    public function isPaddle(): bool
    {
        return $this->providerEffective() === 'paddle';
    }

    public function isStripe(): bool
    {
        return $this->providerEffective() === 'stripe';
    }

    public function subscriptionSummary(User $user): array
    {
        if ($this->isStripe()) {
            $subscription = StripeSubscription::query()
                ->where('user_id', $user->id)
                ->orderByDesc('updated_at')
                ->first();

            $status = $subscription?->status;
            $trialEndsAt = $subscription?->trial_ends_at;
            $active = $status ? $this->isStripeActiveStatus($status) : false;

            return [
                'active' => $active,
                'on_trial' => $this->isStripeOnTrial($status, $trialEndsAt),
                'status' => $status,
                'price_id' => $subscription?->price_id,
                'ends_at' => $subscription?->ends_at,
                'trial_ends_at' => $trialEndsAt,
                'provider_id' => $subscription?->stripe_id,
            ];
        }

        $subscription = $user->subscription(PaddleSubscription::DEFAULT_TYPE);
        return [
            'active' => $user->subscribed(PaddleSubscription::DEFAULT_TYPE),
            'on_trial' => $user->onTrial(PaddleSubscription::DEFAULT_TYPE),
            'status' => $subscription?->status,
            'price_id' => $subscription?->items()->value('price_id'),
            'ends_at' => $subscription?->ends_at,
            'trial_ends_at' => $subscription?->trial_ends_at,
            'provider_id' => $subscription?->paddle_id,
        ];
    }

    public function resolvePlanKey(User $accountOwner, array $planConfig): ?string
    {
        $priceId = $this->resolvePriceId($accountOwner);
        if ($priceId) {
            foreach (config('billing.plans', []) as $key => $plan) {
                if (!empty($plan['price_id']) && $plan['price_id'] === $priceId) {
                    return $key;
                }
            }
        }

        if ($this->isTrialActive($accountOwner)) {
            if (array_key_exists('free', $planConfig)) {
                return 'free';
            }

            $plans = config('billing.plans', []);
            if (array_key_exists('free', $plans)) {
                return 'free';
            }
        }

        return null;
    }

    public function resolvePriceId(User $accountOwner): ?string
    {
        if ($this->isStripe()) {
            return StripeSubscription::query()
                ->where('user_id', $accountOwner->id)
                ->orderByDesc('updated_at')
                ->value('price_id');
        }

        $subscription = $accountOwner->subscription(PaddleSubscription::DEFAULT_TYPE);
        return $subscription?->items()->value('price_id');
    }

    public function resolveSeatQuantity(User $accountOwner): int
    {
        $declared = (int) ($accountOwner->company_team_size ?? 0);
        $teamCount = TeamMember::query()
            ->forAccount($accountOwner->id)
            ->active()
            ->count();

        return max(1, $declared, $teamCount);
    }

    private function isStripeActiveStatus(string $status): bool
    {
        return in_array($status, ['active', 'trialing', 'past_due'], true);
    }

    private function isStripeOnTrial(?string $status, ?Carbon $trialEndsAt): bool
    {
        if ($status === 'trialing') {
            return true;
        }

        return $trialEndsAt ? $trialEndsAt->isFuture() : false;
    }

    private function isTrialActive(User $accountOwner): bool
    {
        $trialEndsAt = $this->resolveTrialEndsAt($accountOwner);
        return $trialEndsAt ? $trialEndsAt->isFuture() : false;
    }

    private function resolveTrialEndsAt(User $accountOwner): ?Carbon
    {
        return $accountOwner->trial_ends_at;
    }
}
