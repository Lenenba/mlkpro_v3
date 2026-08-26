<?php

use App\Models\Customer;
use App\Models\DemoWorkspace;
use App\Models\User;
use App\Services\Demo\DemoAccountService;
use App\Services\Demo\DemoResetService;
use App\Services\Demo\DemoSeedService;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config()->set('demo.enabled', true);
    config()->set('demo.allow_reset', true);
});

function demoResetGuardOwner(string $demoType, string $demoRole): User
{
    return User::factory()->create([
        'is_demo' => true,
        'is_demo_user' => true,
        'demo_type' => $demoType,
        'demo_role' => $demoRole,
        'company_type' => 'services',
    ]);
}

function demoResetGuardWorkspace(User $owner): DemoWorkspace
{
    return DemoWorkspace::query()->create([
        'owner_user_id' => $owner->id,
        'prospect_name' => 'Managed scenario prospect',
        'company_name' => $owner->company_name,
        'company_type' => 'services',
        'company_sector' => 'salon',
        'seed_profile' => 'small',
        'team_size' => 3,
        'locale' => 'fr',
        'timezone' => 'America/Toronto',
        'selected_modules' => ['customers', 'reservations'],
        'expires_at' => now()->addWeek(),
    ]);
}

function demoResetGuardSentinelCustomer(User $owner): Customer
{
    return Customer::query()->create([
        'user_id' => $owner->id,
        'first_name' => 'Scenario',
        'last_name' => 'Sentinel',
        'email' => "scenario-sentinel-{$owner->id}@example.test",
    ]);
}

test('a managed scenario cannot invoke the legacy reset or seed pipeline', function () {
    $owner = demoResetGuardOwner('scenario:studio_naya_coiffure', 'scenario_owner');
    $workspace = demoResetGuardWorkspace($owner);
    $sentinel = demoResetGuardSentinelCustomer($owner);

    $reset = Mockery::mock(DemoResetService::class);
    $reset->shouldNotReceive('reset');
    app()->instance(DemoResetService::class, $reset);

    $seeds = Mockery::mock(DemoSeedService::class);
    $seeds->shouldNotReceive('seed');
    app()->instance(DemoSeedService::class, $seeds);

    $this->actingAs($owner)
        ->postJson(route('demo.reset'))
        ->assertStatus(409)
        ->assertJson([
            'message' => 'Managed demo scenarios must be reset from their saved baseline.',
        ]);

    $this->assertDatabaseHas('demo_workspaces', [
        'id' => $workspace->id,
        'owner_user_id' => $owner->id,
    ]);
    $this->assertDatabaseHas('customers', [
        'id' => $sentinel->id,
        'user_id' => $owner->id,
    ]);
    expect($owner->fresh()->demo_type)->toBe('scenario:studio_naya_coiffure');

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('demo.reset_mode', 'managed_baseline')
            ->where('demo.allow_reset', false));
});

test('an unregistered demo type cannot fall back to the service reset', function () {
    $owner = demoResetGuardOwner('scenario:unregistered', 'scenario_owner');
    $sentinel = demoResetGuardSentinelCustomer($owner);

    $reset = Mockery::mock(DemoResetService::class);
    $reset->shouldNotReceive('reset');
    app()->instance(DemoResetService::class, $reset);

    $seeds = Mockery::mock(DemoSeedService::class);
    $seeds->shouldNotReceive('seed');
    app()->instance(DemoSeedService::class, $seeds);

    $this->actingAs($owner)
        ->postJson(route('demo.reset'))
        ->assertStatus(409)
        ->assertJson([
            'message' => 'This demo account does not support self-service reset.',
        ]);

    $this->assertDatabaseHas('customers', [
        'id' => $sentinel->id,
        'user_id' => $owner->id,
    ]);
});

test('an exact legacy demo type still runs the legacy reset and seed pipeline', function () {
    $owner = demoResetGuardOwner(DemoAccountService::TYPE_SERVICE, 'service_demo');

    $reset = Mockery::mock(DemoResetService::class);
    $reset->shouldReceive('reset')
        ->once()
        ->withArgs(fn (User $account): bool => $account->is($owner));
    app()->instance(DemoResetService::class, $reset);

    $seeds = Mockery::mock(DemoSeedService::class);
    $seeds->shouldReceive('seed')
        ->once()
        ->withArgs(fn (User $account, string $type): bool => $account->is($owner)
            && $type === DemoAccountService::TYPE_SERVICE);
    app()->instance(DemoSeedService::class, $seeds);

    $this->actingAs($owner)
        ->postJson(route('demo.reset'))
        ->assertOk()
        ->assertJson([
            'message' => 'Demo reset complete.',
        ]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('demo.reset_mode', 'legacy')
            ->where('demo.allow_reset', true));
});

test('the legacy seed service rejects an unknown type instead of seeding service data', function () {
    $owner = User::factory()->create();

    expect(fn () => app(DemoSeedService::class)->seed($owner, 'scenario:unknown'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported demo type [scenario:unknown].');

    expect(Customer::query()->where('user_id', $owner->id)->count())->toBe(0);
});
