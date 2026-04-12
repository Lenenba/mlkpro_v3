<?php

use App\Models\User;
use App\Services\StripeBillingService;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

test('onboarding billing api returns a canceled status when checkout is canceled', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
        'onboarding_completed_at' => null,
    ]);

    Sanctum::actingAs($owner);

    $this->getJson('/api/v1/onboarding/billing?status=cancel')
        ->assertStatus(409)
        ->assertJsonPath('status', 'canceled')
        ->assertJsonPath('message', __('ui.onboarding.checkout_canceled'))
        ->assertJsonPath('onboarding_completed', false);
});

test('onboarding billing api requires a checkout session id for stripe success callbacks', function () {
    config()->set('billing.provider', 'stripe');
    config()->set('billing.provider_effective', 'stripe');
    config()->set('billing.provider_ready', true);

    $owner = User::factory()->create([
        'company_type' => 'services',
        'onboarding_completed_at' => null,
    ]);

    Sanctum::actingAs($owner);

    $this->getJson('/api/v1/onboarding/billing?status=success')
        ->assertStatus(422)
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('message', __('ui.onboarding.checkout_session_missing'))
        ->assertJsonPath('onboarding_completed', false);
});

test('onboarding billing api completes onboarding after a successful stripe callback', function () {
    Notification::fake();

    config()->set('billing.provider', 'stripe');
    config()->set('billing.provider_effective', 'stripe');
    config()->set('billing.provider_ready', true);

    $owner = User::factory()->create([
        'company_type' => 'services',
        'onboarding_completed_at' => null,
    ]);

    $stripeBillingService = \Mockery::mock(StripeBillingService::class);
    $stripeBillingService
        ->shouldReceive('syncFromCheckoutSession')
        ->once()
        ->with('cs_test_123', \Mockery::on(fn ($user) => $user instanceof User && $user->is($owner)));

    app()->instance(StripeBillingService::class, $stripeBillingService);

    Sanctum::actingAs($owner);

    $this->getJson('/api/v1/onboarding/billing?status=success&session_id=cs_test_123')
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('message', __('ui.onboarding.completed'))
        ->assertJsonPath('onboarding_completed', true)
        ->assertJsonPath('user.id', $owner->id);

    expect($owner->fresh()->onboarding_completed_at)->not->toBeNull();
});
