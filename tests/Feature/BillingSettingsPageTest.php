<?php

use App\Models\Billing\StripeSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('shows the stored stripe plan as the active plan during trial without surfacing the legacy free offer', function () {
    config()->set('billing.provider', 'stripe');
    config()->set('billing.provider_effective', 'stripe');
    config()->set('billing.provider_ready', false);

    $owner = User::factory()->create([
        'company_type' => 'services',
        'trial_ends_at' => now()->addDays(10),
    ]);

    StripeSubscription::query()->create([
        'user_id' => $owner->id,
        'stripe_id' => 'sub_trial_plan_123',
        'stripe_customer_id' => 'cus_trial_plan_123',
        'price_id' => 'price_unknown_trial_plan',
        'plan_code' => 'solo_pro',
        'currency_code' => 'CAD',
        'billing_period' => 'monthly',
        'status' => 'trialing',
        'trial_ends_at' => now()->addDays(10),
    ]);

    $this->actingAs($owner)
        ->get(route('settings.billing.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Billing')
            ->where('auth.account.currency_code', 'CAD')
            ->has('plans', 7)
            ->where('plans.0.key', 'solo_essential')
            ->where('plans.0.name', 'Solo Core')
            ->where('plans.0.description', 'Core plan for solo operators who need a clear operating foundation.')
            ->where('plans.0.audience', 'solo')
            ->where('plans.3.key', 'starter')
            ->where('plans.3.name', 'Team Core')
            ->where('plans.3.description', 'Core team plan for shared execution and collaboration.')
            ->where('plans.3.audience', 'team')
            ->where('activePlanKey', 'solo_pro')
            ->where('subscription.plan_code', 'solo_pro')
            ->where('subscription.billing_period', 'monthly')
            ->where('subscription.on_trial', true)
        );
});

it('keeps the legacy free plan visible only when the current workspace is still on that legacy offer', function () {
    config()->set('billing.provider', 'stripe');
    config()->set('billing.provider_effective', 'stripe');
    config()->set('billing.provider_ready', false);

    $owner = User::factory()->create([
        'company_type' => 'services',
        'trial_ends_at' => now()->addDays(10),
        'selected_plan_key' => 'free',
        'selected_billing_period' => 'monthly',
    ]);

    $this->actingAs($owner)
        ->get(route('settings.billing.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Billing')
            ->has('plans', 8)
            ->where('plans.0.key', 'free')
            ->where('plans.0.name', 'Legacy Free')
            ->where('plans.0.description', 'Grandfathered legacy access retained for older workspaces only.')
            ->where('activePlanKey', 'free')
            ->where('subscription.plan_code', 'free')
        );
});
