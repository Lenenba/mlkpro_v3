<?php

use App\Models\Billing\StripeSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('shows the stored stripe plan as the active plan during trial instead of falling back to free', function () {
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
            ->where('activePlanKey', 'solo_pro')
            ->where('subscription.plan_code', 'solo_pro')
            ->where('subscription.billing_period', 'monthly')
            ->where('subscription.on_trial', true)
        );
});
