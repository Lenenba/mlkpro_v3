<?php

use App\Models\Role;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

test('onboarding api returns a mobile friendly owner payload with explicit checkout requirements', function () {
    config()->set('billing.provider', 'stripe');
    config()->set('billing.provider_effective', 'stripe');
    config()->set('billing.provider_ready', true);

    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_sector' => 'nettoyage',
        'onboarding_completed_at' => null,
    ]);

    Sanctum::actingAs($owner);

    $this->getJson('/api/v1/onboarding?plan=solo_pro&billing_period=yearly')
        ->assertOk()
        ->assertJsonPath('status', 'ready')
        ->assertJsonPath('message', null)
        ->assertJsonPath('account.is_authenticated', true)
        ->assertJsonPath('account.is_owner', true)
        ->assertJsonPath('account.onboarding_completed', false)
        ->assertJsonPath('onboarding.state', 'ready')
        ->assertJsonPath('onboarding.can_complete', true)
        ->assertJsonPath('onboarding.requires_checkout', true)
        ->assertJsonPath('onboarding.selected_plan_key', 'solo_pro')
        ->assertJsonPath('onboarding.selected_billing_period', 'yearly')
        ->assertJsonPath('preset.company_type', 'services')
        ->assertJsonPath('preset.company_sector', 'nettoyage')
        ->assertJsonPath('preset.company_team_size', 1)
        ->assertJsonPath('plans.1.key', 'solo_pro')
        ->assertJsonCount(3, 'onboarding.supported_currencies');
});

test('onboarding api marks completed owners explicitly and disables checkout requirement', function () {
    config()->set('billing.provider', 'stripe');
    config()->set('billing.provider_effective', 'stripe');
    config()->set('billing.provider_ready', true);

    $owner = User::factory()->create([
        'company_type' => 'services',
        'onboarding_completed_at' => now(),
    ]);

    Sanctum::actingAs($owner);

    $this->getJson('/api/v1/onboarding')
        ->assertOk()
        ->assertJsonPath('status', 'completed')
        ->assertJsonPath('account.is_owner', true)
        ->assertJsonPath('account.onboarding_completed', true)
        ->assertJsonPath('onboarding.state', 'completed')
        ->assertJsonPath('onboarding.requires_checkout', false);
});

test('onboarding api returns a pending owner state for team members', function () {
    $employeeRoleId = Role::query()->firstOrCreate(
        ['name' => 'employee'],
        ['description' => 'Employee role']
    )->id;

    $employee = User::factory()->create([
        'role_id' => $employeeRoleId,
        'onboarding_completed_at' => null,
    ]);

    Sanctum::actingAs($employee);

    $this->getJson('/api/v1/onboarding')
        ->assertOk()
        ->assertJsonPath('status', 'pending_owner')
        ->assertJsonPath('message', 'Only the account owner can complete onboarding.')
        ->assertJsonPath('account.is_owner', false)
        ->assertJsonPath('account.onboarding_completed', false)
        ->assertJsonPath('onboarding.state', 'pending_owner')
        ->assertJsonPath('onboarding.can_complete', false)
        ->assertJsonPath('onboarding.requires_checkout', false)
        ->assertJsonCount(0, 'plans');
});
