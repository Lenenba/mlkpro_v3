<?php

use App\Models\Plan;
use App\Models\PlanPrice;

function runConfiguredPlanCatalogReconciliationMigration(): void
{
    $migration = require database_path('migrations/2026_08_16_000002_reconcile_configured_plan_catalog.php');
    $migration->up();
}

function configureReconciliationTestCatalog(): void
{
    config()->set('billing.plans', [
        'solo_essential' => [
            'name' => 'Solo Core',
            'contact_only' => false,
        ],
    ]);
    config()->set('billing.catalog_defaults', [
        'solo_essential' => [
            'description' => 'Core plan for solo operators.',
            'contact_only' => false,
            'prices' => [
                'CAD' => [
                    'monthly' => [
                        'amount' => 30,
                        'stripe_price_id' => 'price_configured_monthly',
                    ],
                    'yearly' => [
                        'amount' => 360,
                        'stripe_price_id' => 'price_configured_yearly',
                    ],
                ],
                'USD' => [
                    'monthly' => [
                        'amount' => 22,
                        'stripe_price_id' => 'price_configured_usd_monthly',
                    ],
                ],
            ],
        ],
    ]);
}

function removeReconciliationTestPlan(): void
{
    $planIds = Plan::query()->where('code', 'solo_essential')->pluck('id');

    PlanPrice::query()->whereIn('plan_id', $planIds)->delete();
    Plan::query()->whereIn('id', $planIds)->delete();
}

it('creates configured plans and prices missing from an existing database', function () {
    configureReconciliationTestCatalog();
    removeReconciliationTestPlan();

    runConfiguredPlanCatalogReconciliationMigration();
    runConfiguredPlanCatalogReconciliationMigration();

    $plan = Plan::query()->where('code', 'solo_essential')->sole();

    expect($plan->name)->toBe('Solo Core')
        ->and($plan->description)->toBe('Core plan for solo operators.')
        ->and($plan->is_active)->toBeTrue()
        ->and($plan->prices()->count())->toBe(3);

    $monthlyCad = $plan->prices()
        ->where('currency_code', 'CAD')
        ->where('billing_period', 'monthly')
        ->sole();

    expect($monthlyCad->amount)->toBe('30.00')
        ->and($monthlyCad->stripe_price_id)->toBe('price_configured_monthly')
        ->and($monthlyCad->is_active)->toBeTrue();
});

it('repairs stale matching prices while preserving intentional overrides and inactive states', function () {
    configureReconciliationTestCatalog();
    removeReconciliationTestPlan();

    $plan = Plan::query()->create([
        'code' => 'solo_essential',
        'name' => 'Existing Solo',
        'description' => 'Existing description',
        'is_active' => false,
        'contact_only' => false,
        'sort_order' => 99,
    ]);

    $monthlyCad = PlanPrice::query()->create([
        'plan_id' => $plan->id,
        'currency_code' => 'CAD',
        'billing_period' => 'monthly',
        'amount' => 19,
        'stripe_price_id' => null,
        'is_active' => false,
    ]);
    $yearlyCad = PlanPrice::query()->create([
        'plan_id' => $plan->id,
        'currency_code' => 'CAD',
        'billing_period' => 'yearly',
        'amount' => 228,
        'stripe_price_id' => 'price_configured_yearly',
        'is_active' => true,
    ]);
    $monthlyUsd = PlanPrice::query()->create([
        'plan_id' => $plan->id,
        'currency_code' => 'USD',
        'billing_period' => 'monthly',
        'amount' => 99,
        'stripe_price_id' => 'price_custom_override',
        'is_active' => true,
    ]);

    runConfiguredPlanCatalogReconciliationMigration();
    runConfiguredPlanCatalogReconciliationMigration();

    expect($plan->fresh()->name)->toBe('Existing Solo')
        ->and($plan->fresh()->is_active)->toBeFalse()
        ->and($monthlyCad->fresh()->amount)->toBe('30.00')
        ->and($monthlyCad->fresh()->stripe_price_id)->toBe('price_configured_monthly')
        ->and($monthlyCad->fresh()->is_active)->toBeFalse()
        ->and($yearlyCad->fresh()->amount)->toBe('360.00')
        ->and($yearlyCad->fresh()->stripe_price_id)->toBe('price_configured_yearly')
        ->and($monthlyUsd->fresh()->amount)->toBe('99.00')
        ->and($monthlyUsd->fresh()->stripe_price_id)->toBe('price_custom_override')
        ->and($plan->prices()->count())->toBe(3);
});

it('offers an idempotent local reconciliation command for later configuration changes', function () {
    configureReconciliationTestCatalog();
    removeReconciliationTestPlan();

    $this->artisan('billing:reconcile-plan-catalog')
        ->expectsOutput('Configured plan catalog reconciled locally without contacting the billing provider.')
        ->assertExitCode(0);

    $this->artisan('billing:reconcile-plan-catalog')
        ->expectsOutput('Configured plan catalog reconciled locally without contacting the billing provider.')
        ->assertExitCode(0);

    expect(Plan::query()->where('code', 'solo_essential')->count())->toBe(1)
        ->and(PlanPrice::query()
            ->whereRelation('plan', 'code', 'solo_essential')
            ->count())->toBe(3);
});
