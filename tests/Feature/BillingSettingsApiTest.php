<?php

use App\Enums\BillingPeriod;
use App\Models\Billing\StripeSubscription;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('billing api returns a normalized mobile billing payload with stable sections and flow state', function () {
    config()->set('billing.provider', 'stripe');
    config()->set('billing.provider_effective', 'stripe');
    config()->set('billing.provider_ready', true);
    config()->set('services.stripe.secret', 'sk_test_billing_summary');
    config()->set('services.stripe.enabled', true);
    config()->set('services.stripe.connect_enabled', true);

    $owner = User::factory()->create([
        'company_type' => 'services',
        'onboarding_completed_at' => now(),
        'company_features' => [
            'assistant' => true,
        ],
    ]);
    $soloProPlanId = Plan::query()->where('code', 'solo_pro')->value('id');
    expect($soloProPlanId)->not->toBeNull();

    PlanPrice::query()->updateOrCreate(
        [
            'plan_id' => $soloProPlanId,
            'currency_code' => 'CAD',
            'billing_period' => BillingPeriod::YEARLY->value,
        ],
        [
            'amount' => '720.00',
            'stripe_price_id' => 'price_billing_api_123',
            'is_active' => true,
        ]
    );

    StripeSubscription::query()->create([
        'user_id' => $owner->id,
        'stripe_id' => 'sub_billing_api_123',
        'stripe_customer_id' => 'cus_billing_api_123',
        'price_id' => 'price_billing_api_123',
        'plan_code' => 'solo_pro',
        'currency_code' => 'CAD',
        'billing_period' => 'yearly',
        'status' => 'active',
        'current_period_end' => now()->addMonth(),
    ]);

    Sanctum::actingAs($owner);

    $this->getJson('/api/v1/settings/billing?checkout=success&plan=solo_pro&billing_period=yearly&credits=cancel&connect=refresh')
        ->assertOk()
        ->assertJsonStructure([
            'status',
            'billing',
            'subscription',
            'plan_catalog' => [
                'plans',
                'active_plan_key',
                'seat_quantity',
            ],
            'capabilities',
            'assistant',
            'provider_details' => [
                'paddle',
                'stripe_connect',
            ],
            'payment_methods' => [
                'available_methods',
                'enabled_methods',
                'default_method',
                'cash_allowed_contexts',
                'settings',
                'tip_settings',
            ],
            'loyalty',
            'flow_state' => [
                'checkout',
                'assistant_credits',
                'stripe_connect',
            ],
        ])
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('billing.provider_effective', 'stripe')
        ->assertJsonPath('subscription.plan_key', 'solo_pro')
        ->assertJsonPath('subscription.billing_period', 'yearly')
        ->assertJsonPath('plan_catalog.active_plan_key', 'solo_pro')
        ->assertJsonPath('capabilities.can_checkout', true)
        ->assertJsonPath('capabilities.can_swap', true)
        ->assertJsonPath('capabilities.can_open_portal', true)
        ->assertJsonPath('capabilities.can_update_store_payment_settings', true)
        ->assertJsonPath('capabilities.is_owner', true)
        ->assertJsonPath('flow_state.checkout.status', 'success')
        ->assertJsonPath('flow_state.checkout.plan_key', 'solo_pro')
        ->assertJsonPath('flow_state.checkout.billing_period', 'yearly')
        ->assertJsonPath('flow_state.assistant_credits.status', 'cancel')
        ->assertJsonPath('flow_state.stripe_connect.status', 'refresh')
        ->assertJsonPath('payment_methods.enabled_methods.0', 'cash');
});

test('billing api still forbids non owners', function () {
    $employeeRoleId = Role::query()->firstOrCreate(
        ['name' => 'employee'],
        ['description' => 'Employee role']
    )->id;

    $employee = User::factory()->create([
        'role_id' => $employeeRoleId,
        'onboarding_completed_at' => now(),
    ]);

    Sanctum::actingAs($employee);

    $this->getJson('/api/v1/settings/billing')->assertForbidden();
});
