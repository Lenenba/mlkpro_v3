<?php

namespace App\Services;

use App\Enums\BillingPeriod;
use App\Models\Billing\StripeSubscription;
use App\Models\User;
use Carbon\Carbon;

class CreateStripeSubscriptionForTenant
{
    public function checkoutSession(
        User $tenant,
        string $planCode,
        string $successUrl,
        string $cancelUrl,
        int $quantity = 1,
        ?Carbon $trialEndsAt = null,
        BillingPeriod|string|null $billingPeriod = null
    ): array {
        $planPrice = app(ResolvePlanPriceForTenant::class)->execute(
            $tenant,
            $planCode,
            $billingPeriod
        );

        return app(StripeBillingService::class)->createCheckoutSessionForPlanPrice(
            $tenant,
            $planPrice,
            $successUrl,
            $cancelUrl,
            $quantity,
            $trialEndsAt
        );
    }

    public function swap(
        User $tenant,
        string $planCode,
        int $quantity = 1,
        BillingPeriod|string|null $billingPeriod = null
    ): ?StripeSubscription
    {
        $planPrice = app(ResolvePlanPriceForTenant::class)->execute(
            $tenant,
            $planCode,
            $billingPeriod
        );

        return app(StripeBillingService::class)->swapSubscriptionToPlanPrice(
            $tenant,
            $planPrice,
            $quantity
        );
    }

    public function assign(
        User $tenant,
        string $planCode,
        bool $comped = false,
        int $quantity = 1,
        BillingPeriod|string|null $billingPeriod = null
    ): ?StripeSubscription
    {
        $planPrice = app(ResolvePlanPriceForTenant::class)->execute(
            $tenant,
            $planCode,
            $billingPeriod
        );

        return app(StripeBillingService::class)->assignPlanPrice(
            $tenant,
            $planPrice,
            $comped,
            $quantity
        );
    }
}
