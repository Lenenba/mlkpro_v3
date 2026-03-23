<?php

use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

test('account owner can complete onboarding from the web', function () {
    Notification::fake();

    $user = User::factory()->create([
        'company_name' => null,
        'company_type' => 'services',
        'company_sector' => null,
        'onboarding_completed_at' => null,
    ]);

    $payload = [
        'company_name' => 'Acme Services',
        'company_type' => 'services',
        'company_sector' => 'nettoyage',
        'accept_terms' => true,
    ];

    $this->actingAs($user)
        ->post(route('onboarding.store'), $payload)
        ->assertRedirect(route('dashboard'));

    $user->refresh();

    expect($user->company_name)->toBe('Acme Services');
    expect($user->company_sector)->toBe('nettoyage');
    expect($user->onboarding_completed_at)->not->toBeNull();
    expect(
        ProductCategory::where('user_id', $user->id)
            ->where('name', 'Post-chantier')
            ->exists()
    )->toBeTrue();
});

test('non-owners cannot complete onboarding from the web', function () {
    $employeeRoleId = Role::query()->firstOrCreate(
        ['name' => 'employee'],
        ['description' => 'Employee role']
    )->id;

    $user = User::factory()->create([
        'role_id' => $employeeRoleId,
        'onboarding_completed_at' => null,
    ]);

    $payload = [
        'company_name' => 'Acme Services',
        'company_type' => 'services',
        'company_sector' => 'nettoyage',
        'accept_terms' => true,
    ];

    $this->actingAs($user)
        ->post(route('onboarding.store'), $payload)
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('error', 'Only the account owner can complete onboarding.');

    expect($user->refresh()->onboarding_completed_at)->toBeNull();
});

test('owner cannot select a solo plan when invites or extra team size are provided', function () {
    config()->set('billing.provider_effective', 'paddle');
    config()->set('billing.provider_ready', false);

    $user = User::factory()->create([
        'company_name' => null,
        'company_type' => 'services',
        'company_sector' => null,
        'onboarding_completed_at' => null,
    ]);

    $payload = [
        'company_name' => 'Acme Services',
        'company_type' => 'services',
        'company_sector' => 'nettoyage',
        'company_team_size' => 2,
        'plan_key' => 'solo_pro',
        'invites' => [
            [
                'name' => 'Team Mate',
                'email' => 'solo-team@example.test',
                'role' => 'member',
            ],
        ],
        'accept_terms' => true,
    ];

    $this->actingAs($user)
        ->from(route('onboarding.index'))
        ->post(route('onboarding.store'), $payload)
        ->assertRedirect(route('onboarding.index'))
        ->assertSessionHasErrors(['plan_key']);

    expect($user->refresh()->onboarding_completed_at)->toBeNull();
});

test('onboarding preselects the requested pricing plan from the pricing page', function () {
    $this->get(route('onboarding.index', ['plan' => 'starter']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Onboarding/Index')
            ->where('selectedPlanKey', 'starter')
            ->where('preset.company_team_size', 2)
        );

    $this->get(route('onboarding.index', ['plan' => 'solo_pro']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Onboarding/Index')
            ->where('selectedPlanKey', 'solo_pro')
            ->where('preset.company_team_size', 1)
        );
});
