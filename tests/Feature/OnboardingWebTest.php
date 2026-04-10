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
            ->has('plans', 6)
            ->where('plans.0.name', 'Solo Core')
            ->where('plans.0.description', 'Core plan for solo operators who need a clear operating foundation.')
            ->where('plans.0.audience', 'solo')
            ->where('plans.3.name', 'Team Core')
            ->where('plans.3.description', 'Core team plan for shared execution and collaboration.')
            ->where('plans.3.audience', 'team')
            ->where('selectedPlanKey', 'starter')
            ->where('preset.company_team_size', 2)
        );

    $this->get(route('onboarding.index', ['plan' => 'solo_pro']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Onboarding/Index')
            ->where('selectedPlanKey', 'solo_pro')
            ->where('plans.1.name', 'Solo Growth')
            ->where('plans.1.description', 'Growth plan for solo operators who need more structure and execution capacity.')
            ->where('preset.company_team_size', 1)
        );
});

test('onboarding keeps the requested pricing currency from the pricing page', function () {
    $this->get(route('onboarding.index', ['plan' => 'starter', 'currency_code' => 'USD']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Onboarding/Index')
            ->where('selectedPlanKey', 'starter')
            ->has('plans', 6)
            ->where('plans.0.prices_by_period.monthly.currency_code', 'USD')
            ->where('plans.0.prices_by_period.yearly.currency_code', 'USD')
            ->where('plans.3.key', 'starter')
            ->where('plans.3.name', 'Team Core')
            ->where('plans.3.prices_by_period.monthly.currency_code', 'USD')
            ->where('plans.3.prices_by_period.yearly.currency_code', 'USD')
        );
});

test('onboarding stores the selected plan context when checkout is skipped so entitlements match the chosen plan', function () {
    config()->set('billing.provider', 'stripe');
    config()->set('billing.provider_effective', 'stripe');
    config()->set('billing.provider_ready', false);

    $user = User::factory()->create([
        'company_name' => null,
        'company_type' => 'services',
        'company_sector' => null,
        'onboarding_completed_at' => null,
        'company_features' => [],
    ]);

    $payload = [
        'company_name' => 'Acme Services',
        'company_type' => 'services',
        'company_sector' => 'nettoyage',
        'plan_key' => 'solo_pro',
        'billing_period' => 'yearly',
        'accept_terms' => true,
    ];

    $this->actingAs($user)
        ->post(route('onboarding.store'), $payload)
        ->assertRedirect(route('dashboard'));

    expect($user->fresh()->selected_plan_key)->toBe('solo_pro')
        ->and($user->fresh()->selected_billing_period)->toBe('yearly')
        ->and($user->fresh()->hasCompanyFeature('jobs'))->toBeTrue()
        ->and($user->fresh()->hasCompanyFeature('tasks'))->toBeTrue()
        ->and($user->fresh()->hasCompanyFeature('assistant'))->toBeTrue()
        ->and($user->fresh()->hasCompanyFeature('plan_scans'))->toBeTrue()
        ->and($user->fresh()->hasCompanyFeature('campaigns'))->toBeTrue()
        ->and($user->fresh()->hasCompanyFeature('loyalty'))->toBeTrue()
        ->and($user->fresh()->hasCompanyFeature('team_members'))->toBeFalse();

    $this->actingAs($user->fresh())
        ->get(route('settings.billing.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Billing')
            ->where('activePlanKey', 'solo_pro')
            ->where('subscription.plan_code', 'solo_pro')
            ->where('subscription.billing_period', 'yearly')
        );
});
