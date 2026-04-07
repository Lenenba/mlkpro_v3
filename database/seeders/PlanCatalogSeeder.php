<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\PlanPrice;
use App\Support\Billing\DefaultPlanCatalog;
use Illuminate\Database\Seeder;

class PlanCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $sortOrder = 0;

        foreach (DefaultPlanCatalog::periodicDefinitions() as $definition) {
            $plan = Plan::query()->updateOrCreate(
                ['code' => $definition['code']],
                [
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'is_active' => (bool) ($definition['is_active'] ?? true),
                    'contact_only' => (bool) ($definition['contact_only'] ?? false),
                    'sort_order' => $sortOrder++,
                ]
            );

            foreach ($definition['prices'] as $currencyCode => $periods) {
                foreach ($periods as $billingPeriod => $price) {
                    PlanPrice::query()->updateOrCreate(
                        [
                            'plan_id' => $plan->id,
                            'currency_code' => $currencyCode,
                            'billing_period' => $billingPeriod,
                        ],
                        [
                            'amount' => $price['amount'],
                            'stripe_price_id' => $price['stripe_price_id'],
                            'is_active' => true,
                        ]
                    );
                }
            }
        }
    }
}
