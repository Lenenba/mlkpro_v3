<?php

namespace App\Services;

use App\Data\PlanPriceData;
use App\Enums\BillingPeriod;
use App\Models\User;

class ResolvePlanPriceForTenant
{
    public function execute(
        User $tenant,
        string $planCode,
        BillingPeriod|string|null $billingPeriod = null
    ): PlanPriceData {
        return app(BillingPlanService::class)->resolvePlanPriceForTenant(
            $tenant,
            $planCode,
            $billingPeriod
        );
    }
}
