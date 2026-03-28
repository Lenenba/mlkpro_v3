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
                'plan_code' => $subscription?->plan_code,
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
            'plan_code' => null,
            'ends_at' => $subscription?->ends_at,
            'trial_ends_at' => $subscription?->trial_ends_at,
            'provider_id' => $subscription?->paddle_id,
        ];
    }

    public function resolvePlanKey(User $accountOwner, array $planConfig): ?string
    {
        $storedPlanCode = $this->resolveStoredPlanCode($accountOwner);
        if ($storedPlanCode) {
            return $storedPlanCode;
        }

        $priceId = $this->resolvePriceId($accountOwner);
        if ($priceId) {
            $planCode = app(BillingPlanService::class)->resolvePlanCodeByStripePriceId($priceId);
            if ($planCode) {
                return $planCode;
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

    public function resolveBillableQuantity(User $accountOwner, ?string $planKey = null): int
    {
        if ($planKey && app(BillingPlanService::class)->isOwnerOnlyPlan($planKey)) {
            return 1;
        }

        return $this->resolveSeatQuantity($accountOwner);
    }

    /**
     * @return array<int, string>
     */
    public function ownerOnlyPlanSelectionErrors(
        User $accountOwner,
        string $planKey,
        ?int $declaredTeamSize = null,
        ?int $inviteCount = null
    ): array {
        if (! app(BillingPlanService::class)->isOwnerOnlyPlan($planKey)) {
            return [];
        }

        $errors = [];
        $activeTeamMembers = TeamMember::query()
            ->forAccount($accountOwner->id)
            ->active()
            ->count();

        if ($activeTeamMembers > 0) {
            $errors[] = 'Solo plans require removing active team members first.';
        }

        if ($inviteCount !== null && $inviteCount > 0) {
            $errors[] = 'Solo plans do not allow invited team members.';
        }

        if ($declaredTeamSize !== null && $declaredTeamSize > 1) {
            $errors[] = 'Solo plans are limited to the owner only.';
        }

        return $errors;
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

    private function resolveStoredPlanCode(User $accountOwner): ?string
    {
        if (! $this->isStripe()) {
            return null;
        }

        $planCode = StripeSubscription::query()
            ->where('user_id', $accountOwner->id)
            ->orderByDesc('updated_at')
            ->value('plan_code');

        if (! is_string($planCode)) {
            return null;
        }

        $normalized = trim($planCode);

        return $normalized !== '' ? $normalized : null;
    }
}
