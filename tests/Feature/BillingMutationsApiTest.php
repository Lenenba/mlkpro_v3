<?php

use App\Enums\BillingMutationErrorCode;
use App\Enums\BillingPeriod;
use App\Models\Billing\StripeSubscription;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\CreateStripeSubscriptionForTenant;
use App\Services\StripeBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('billing checkout api returns a mobile redirect contract with resolved plan details', function () {
    config()->set('billing.provider', 'stripe');
    config()->set('billing.provider_effective', 'stripe');
    config()->set('billing.provider_ready', true);

    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_team_size' => 1,
        'onboarding_completed_at' => now(),
        'currency_code' => 'CAD',
    ]);

    $starterPlanId = Plan::query()->where('code', 'starter')->value('id');
    expect($starterPlanId)->not->toBeNull();

    PlanPrice::query()->updateOrCreate(
        [
            'plan_id' => $starterPlanId,
            'currency_code' => 'CAD',
            'billing_period' => BillingPeriod::MONTHLY->value,
        ],
        [
            'amount' => '29.00',
            'stripe_price_id' => 'price_test_starter_monthly',
            'is_active' => true,
        ]
    );

    $capturedCheckoutCall = [];

    $checkoutService = \Mockery::mock(CreateStripeSubscriptionForTenant::class);
    $checkoutService
        ->shouldReceive('checkoutSession')
        ->once()
        ->andReturnUsing(function (User $user, string $planCode, string $successUrl, string $cancelUrl, int $quantity, $trialEndsAt, $billingPeriod) use (&$capturedCheckoutCall) {
            $capturedCheckoutCall = [
                'user_id' => $user->id,
                'plan_code' => $planCode,
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'quantity' => $quantity,
                'trial_ends_at' => $trialEndsAt,
                'billing_period' => $billingPeriod,
            ];

            return [
                'id' => 'cs_checkout_mobile_123',
                'url' => 'https://checkout.stripe.test/subscription-mobile',
            ];
        });

    app()->instance(CreateStripeSubscriptionForTenant::class, $checkoutService);

    Sanctum::actingAs($owner);

    $this->postJson('/api/v1/settings/billing/checkout', [
        'plan_key' => 'starter',
        'billing_period' => 'monthly',
        'success_url' => 'mlkpro://billing/subscription-success',
        'cancel_url' => 'mlkpro://billing/subscription-cancel',
    ])
        ->assertOk()
        ->assertJsonPath('status', 'requires_redirect')
        ->assertJsonPath('action', 'open_checkout')
        ->assertJsonPath('url', 'https://checkout.stripe.test/subscription-mobile')
        ->assertJsonPath('resolved_plan.plan_key', 'starter')
        ->assertJsonPath('resolved_plan.billing_period', 'monthly')
        ->assertJsonPath('resolved_plan.currency_code', 'CAD')
        ->assertJsonPath('return_urls.success_url', 'mlkpro://billing/subscription-success?session_id={CHECKOUT_SESSION_ID}')
        ->assertJsonPath('return_urls.cancel_url', 'mlkpro://billing/subscription-cancel');

    expect($capturedCheckoutCall['user_id'] ?? null)->toBe($owner->id)
        ->and($capturedCheckoutCall['plan_code'] ?? null)->toBe('starter')
        ->and($capturedCheckoutCall['success_url'] ?? null)->toBe('mlkpro://billing/subscription-success?session_id={CHECKOUT_SESSION_ID}')
        ->and($capturedCheckoutCall['cancel_url'] ?? null)->toBe('mlkpro://billing/subscription-cancel')
        ->and($capturedCheckoutCall['quantity'] ?? null)->toBe(1)
        ->and($capturedCheckoutCall['trial_ends_at'] ?? null)->toBeNull()
        ->and($capturedCheckoutCall['billing_period'] ?? null)->toBe(BillingPeriod::MONTHLY);
});

test('billing assistant credits api returns a mobile redirect contract', function () {
    config()->set('billing.provider', 'stripe');
    config()->set('billing.provider_effective', 'stripe');
    config()->set('billing.provider_ready', true);
    config()->set('services.stripe.ai_credit_price', 'price_ai_credits_test');
    config()->set('services.stripe.ai_credit_pack', 100);

    $owner = User::factory()->create([
        'company_type' => 'services',
        'onboarding_completed_at' => now(),
        'company_features' => [
            'assistant' => true,
        ],
    ]);

    $planModules = \App\Services\CompanyFeatureService::defaultPlanModules();
    $planModules['starter']['assistant'] = false;
    PlatformSetting::setValue('plan_modules', $planModules);

    StripeSubscription::query()->create([
        'user_id' => $owner->id,
        'stripe_id' => 'sub_assistant_credits_123',
        'stripe_customer_id' => 'cus_assistant_credits_123',
        'price_id' => 'price_plan_credits_123',
        'plan_code' => 'starter',
        'currency_code' => 'CAD',
        'billing_period' => 'monthly',
        'status' => 'active',
        'current_period_end' => now()->addMonth(),
    ]);

    $stripeBillingService = \Mockery::mock(StripeBillingService::class);
    $stripeBillingService
        ->shouldReceive('isConfigured')
        ->once()
        ->andReturn(true);
    $stripeBillingService
        ->shouldReceive('createAssistantCreditCheckoutSession')
        ->once()
        ->withArgs(function (User $user, int $packs, string $successUrl, string $cancelUrl) use ($owner) {
            expect($user->is($owner))->toBeTrue();
            expect($packs)->toBe(3);
            expect($successUrl)->toBe('mlkpro://billing/assistant-success?session_id={CHECKOUT_SESSION_ID}');
            expect($cancelUrl)->toBe('mlkpro://billing/assistant-cancel');

            return true;
        })
        ->andReturn([
            'id' => 'cs_assistant_credits_123',
            'url' => 'https://checkout.stripe.test/assistant-credits',
        ]);

    app()->instance(StripeBillingService::class, $stripeBillingService);

    Sanctum::actingAs($owner);

    $this->postJson('/api/v1/settings/billing/assistant-credits', [
        'packs' => 3,
        'success_url' => 'mlkpro://billing/assistant-success',
        'cancel_url' => 'mlkpro://billing/assistant-cancel',
    ])
        ->assertOk()
        ->assertJsonPath('status', 'requires_redirect')
        ->assertJsonPath('action', 'open_checkout')
        ->assertJsonPath('url', 'https://checkout.stripe.test/assistant-credits')
        ->assertJsonPath('credits.pack_count', 3)
        ->assertJsonPath('credits.pack_size', 100)
        ->assertJsonPath('credits.total_credits', 300)
        ->assertJsonPath('return_urls.success_url', 'mlkpro://billing/assistant-success?session_id={CHECKOUT_SESSION_ID}')
        ->assertJsonPath('return_urls.cancel_url', 'mlkpro://billing/assistant-cancel');
});

test('billing checkout api returns a stable error code when provider is not stripe', function () {
    config()->set('billing.provider', 'paddle');
    config()->set('billing.provider_effective', 'paddle');
    config()->set('billing.provider_ready', true);

    $owner = User::factory()->create([
        'onboarding_completed_at' => now(),
    ]);

    Sanctum::actingAs($owner);

    $this->postJson('/api/v1/settings/billing/checkout', [
        'plan_key' => 'starter',
        'billing_period' => 'monthly',
    ])
        ->assertStatus(422)
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('code', BillingMutationErrorCode::ProviderNotStripe->value)
        ->assertJsonPath('message', 'Billing provider is not Stripe.');
});

test('billing swap api returns a noop code when the selected plan is already active', function () {
    config()->set('billing.provider', 'stripe');
    config()->set('billing.provider_effective', 'stripe');
    config()->set('billing.provider_ready', true);

    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_team_size' => 1,
        'onboarding_completed_at' => now(),
        'currency_code' => 'CAD',
    ]);

    $starterPlanId = Plan::query()->where('code', 'starter')->value('id');
    expect($starterPlanId)->not->toBeNull();

    PlanPrice::query()->updateOrCreate(
        [
            'plan_id' => $starterPlanId,
            'currency_code' => 'CAD',
            'billing_period' => BillingPeriod::MONTHLY->value,
        ],
        [
            'amount' => '29.00',
            'stripe_price_id' => 'price_swap_noop_test',
            'is_active' => true,
        ]
    );

    StripeSubscription::query()->create([
        'user_id' => $owner->id,
        'stripe_id' => 'sub_swap_noop_123',
        'stripe_customer_id' => 'cus_swap_noop_123',
        'price_id' => 'price_swap_noop_test',
        'plan_code' => 'starter',
        'currency_code' => 'CAD',
        'billing_period' => 'monthly',
        'status' => 'active',
        'current_period_end' => now()->addMonth(),
    ]);

    Sanctum::actingAs($owner);

    $this->postJson('/api/v1/settings/billing/swap', [
        'plan_key' => 'starter',
        'billing_period' => 'monthly',
    ])
        ->assertOk()
        ->assertJsonPath('status', 'noop')
        ->assertJsonPath('code', BillingMutationErrorCode::PlanUnchanged->value)
        ->assertJsonPath('message', 'You are already on this plan.');
});

test('billing assistant addon api returns a success action contract', function () {
    config()->set('billing.provider', 'stripe');
    config()->set('billing.provider_effective', 'stripe');
    config()->set('billing.provider_ready', true);
    config()->set('services.stripe.ai_credit_price', 'price_ai_addon_test');
    config()->set('services.stripe.ai_credit_pack', 100);

    $owner = User::factory()->create([
        'company_type' => 'services',
        'onboarding_completed_at' => now(),
        'company_features' => [
            'assistant' => false,
        ],
    ]);

    $planModules = \App\Services\CompanyFeatureService::defaultPlanModules();
    $planModules['starter']['assistant'] = false;
    PlatformSetting::setValue('plan_modules', $planModules);

    StripeSubscription::query()->create([
        'user_id' => $owner->id,
        'stripe_id' => 'sub_assistant_addon_123',
        'stripe_customer_id' => 'cus_assistant_addon_123',
        'price_id' => 'price_plan_assistant_addon_123',
        'plan_code' => 'starter',
        'currency_code' => 'CAD',
        'billing_period' => 'monthly',
        'status' => 'active',
        'current_period_end' => now()->addMonth(),
    ]);

    $stripeBillingService = \Mockery::mock(StripeBillingService::class);
    $stripeBillingService
        ->shouldReceive('isConfigured')
        ->once()
        ->andReturn(true);

    app()->instance(StripeBillingService::class, $stripeBillingService);

    Sanctum::actingAs($owner);

    $this->postJson('/api/v1/settings/billing/assistant-addon', [
        'enabled' => true,
    ])
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('action', 'assistant_addon_updated')
        ->assertJsonPath('message', 'Assistant IA mis a jour.')
        ->assertJsonPath('enabled', true);
});

test('billing assistant credits api returns a stable error code when assistant is not enabled', function () {
    config()->set('billing.provider', 'stripe');
    config()->set('billing.provider_effective', 'stripe');
    config()->set('billing.provider_ready', true);
    config()->set('services.stripe.ai_credit_price', 'price_ai_credits_test');
    config()->set('services.stripe.ai_credit_pack', 100);

    $owner = User::factory()->create([
        'company_type' => 'services',
        'onboarding_completed_at' => now(),
        'company_features' => [
            'assistant' => false,
        ],
    ]);

    $planModules = \App\Services\CompanyFeatureService::defaultPlanModules();
    $planModules['starter']['assistant'] = false;
    PlatformSetting::setValue('plan_modules', $planModules);

    StripeSubscription::query()->create([
        'user_id' => $owner->id,
        'stripe_id' => 'sub_assistant_activation_123',
        'stripe_customer_id' => 'cus_assistant_activation_123',
        'price_id' => 'price_plan_activation_123',
        'plan_code' => 'starter',
        'currency_code' => 'CAD',
        'billing_period' => 'monthly',
        'status' => 'active',
        'current_period_end' => now()->addMonth(),
    ]);

    Sanctum::actingAs($owner);

    $this->postJson('/api/v1/settings/billing/assistant-credits', [
        'packs' => 1,
    ])
        ->assertStatus(422)
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('code', BillingMutationErrorCode::AssistantActivationRequired->value)
        ->assertJsonPath('message', 'Activez l option IA avant d acheter des credits.');
});

test('billing connect api returns a stable error code when stripe connect is not configured', function () {
    config()->set('services.stripe.connect_enabled', false);
    config()->set('services.stripe.enabled', false);
    config()->set('services.stripe.secret', null);

    $owner = User::factory()->create([
        'onboarding_completed_at' => now(),
    ]);

    Sanctum::actingAs($owner);

    $this->postJson('/api/v1/settings/billing/connect')
        ->assertStatus(400)
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('code', BillingMutationErrorCode::StripeConnectNotConfigured->value)
        ->assertJsonPath('message', 'Stripe Connect is not configured.');
});

test('billing summary enables assistant credit capability when stripe assistant credits are configured', function () {
    config()->set('billing.provider', 'stripe');
    config()->set('billing.provider_effective', 'stripe');
    config()->set('billing.provider_ready', true);
    config()->set('services.stripe.secret', 'sk_test_assistant_capability');
    config()->set('services.stripe.ai_credit_price', 'price_ai_credits_test');
    config()->set('services.stripe.ai_credit_pack', 100);

    $owner = User::factory()->create([
        'company_type' => 'services',
        'onboarding_completed_at' => now(),
        'company_features' => [
            'assistant' => true,
        ],
    ]);

    $planModules = \App\Services\CompanyFeatureService::defaultPlanModules();
    $planModules['starter']['assistant'] = false;
    PlatformSetting::setValue('plan_modules', $planModules);

    StripeSubscription::query()->create([
        'user_id' => $owner->id,
        'stripe_id' => 'sub_assistant_capability_123',
        'stripe_customer_id' => 'cus_assistant_capability_123',
        'price_id' => 'price_plan_capability_123',
        'plan_code' => 'starter',
        'currency_code' => 'CAD',
        'billing_period' => 'monthly',
        'status' => 'active',
        'current_period_end' => now()->addMonth(),
    ]);

    Sanctum::actingAs($owner);

    $this->getJson('/api/v1/settings/billing')
        ->assertOk()
        ->assertJsonPath('assistant.credits.enabled', true)
        ->assertJsonPath('capabilities.can_manage_assistant_addon', true)
        ->assertJsonPath('capabilities.can_buy_assistant_credits', true);
});
