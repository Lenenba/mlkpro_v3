<?php

use App\Models\Role;
use App\Models\SubscriptionPromotion;
use App\Models\User;
use App\Services\SubscriptionPromotionService;
use Inertia\Testing\AssertableInertia as Assert;

function makeSubscriptionPromotionSuperadmin(): User
{
    $role = Role::query()->firstOrCreate(
        ['name' => 'superadmin'],
        ['description' => 'Superadmin role']
    );

    return User::query()->create([
        'name' => 'Root User',
        'email' => 'promotion-superadmin@example.com',
        'password' => 'password',
        'role_id' => $role->id,
        'onboarding_completed_at' => now(),
    ]);
}

it('shows subscription promotion settings in the super admin settings form', function () {
    $user = makeSubscriptionPromotionSuperadmin();

    SubscriptionPromotion::query()->updateOrCreate(
        ['key' => SubscriptionPromotion::GLOBAL_KEY],
        [
            'name' => 'Global subscription promotion',
            'is_enabled' => true,
            'monthly_discount_percent' => 30,
            'yearly_discount_percent' => 45,
            'monthly_stripe_coupon_id' => 'coupon_promo_30',
            'yearly_stripe_coupon_id' => 'coupon_promo_45',
        ]
    );

    $this->actingAs($user)
        ->get(route('superadmin.settings.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('SuperAdmin/Settings/Edit')
            ->where('subscription_promotion.enabled', true)
            ->where('subscription_promotion.monthly_discount_percent', 30)
            ->where('subscription_promotion.yearly_discount_percent', 45)
            ->where('subscription_promotion.monthly_stripe_coupon_id', 'coupon_promo_30')
            ->where('subscription_promotion.yearly_stripe_coupon_id', 'coupon_promo_45')
            ->where('promotion_discount_options', [20, 25, 30, 35, 40, 45, 50])
            ->where('annual_discount_percent', 0)
        );
});

it('shows the sales module in the super admin plan modules form payload', function () {
    $user = makeSubscriptionPromotionSuperadmin();
    $planKey = (string) (array_key_first(config('billing.plans', [])) ?? 'free');

    $this->actingAs($user)
        ->get(route('superadmin.settings.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('SuperAdmin/Settings/Edit')
            ->where("plan_modules.$planKey.sales", (bool) config("billing.plans.$planKey.default_modules.sales", true))
        );
});

it('persists subscription promotion changes from the super admin settings form', function () {
    config()->set('services.stripe.secret', null);

    $user = makeSubscriptionPromotionSuperadmin();

    $this->actingAs($user)
        ->put(route('superadmin.settings.update'), [
            'maintenance' => [
                'enabled' => false,
                'message' => '',
            ],
            'templates' => [
                'email_default' => '',
                'quote_default' => '',
                'invoice_default' => '',
            ],
            'public_navigation' => [
                'contact_form_url' => '',
            ],
            'subscription_promotion' => [
                'enabled' => true,
                'monthly_discount_percent' => 20,
                'yearly_discount_percent' => 35,
            ],
        ])
        ->assertRedirect();

    $promotion = app(SubscriptionPromotionService::class)->global()->fresh();

    expect($promotion->is_enabled)->toBeTrue()
        ->and($promotion->monthly_discount_percent)->toBe(20)
        ->and($promotion->yearly_discount_percent)->toBe(35)
        ->and($promotion->monthly_stripe_coupon_id)->toBeNull()
        ->and($promotion->yearly_stripe_coupon_id)->toBeNull();
});
