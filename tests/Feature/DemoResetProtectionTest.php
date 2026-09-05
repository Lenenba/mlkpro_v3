<?php

use App\Models\CompanyRole;
use App\Models\Customer;
use App\Models\DemoWorkspace;
use App\Models\Permission;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Demo\DemoAccessRoleProvisioner;
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

test('the legacy guided demo creates an idempotent manageable access role', function () {
    $owner = demoResetGuardOwner(DemoAccountService::TYPE_GUIDED, 'guided_demo');
    $seedService = app(DemoSeedService::class);

    $seedService->seed($owner, DemoAccountService::TYPE_GUIDED);

    $member = TeamMember::query()
        ->forAccount($owner->id)
        ->with('companyRole.permissions:id,slug')
        ->firstOrFail();
    $originalRoleId = $member->company_role_id;

    expect($member->role)->toBe('member')
        ->and($member->permissions)->toBe([])
        ->and($member->companyRole?->slug)->toBe('demo_technician')
        ->and($member->companyRole?->permissions->pluck('slug')->sort()->values()->all())->toBe([
            'update_jobs',
            'update_tasks',
            'view_jobs',
            'view_tasks',
        ]);

    $member->companyRole?->update(['is_active' => false]);

    $seedService->seed($owner, DemoAccountService::TYPE_GUIDED);

    $member->refresh()->load('companyRole');

    expect($member->company_role_id)->toBe($originalRoleId)
        ->and($member->companyRole?->is_active)->toBeFalse()
        ->and(CompanyRole::query()->where('company_id', $owner->id)->count())->toBe(1);
});

test('the data migration backfills existing demo roles without touching regular tenants', function () {
    $demoOwner = demoResetGuardOwner(DemoAccountService::TYPE_GUIDED, 'guided_demo');
    $regularOwner = User::factory()->create([
        'is_demo' => false,
        'is_demo_user' => false,
    ]);
    $demoMember = TeamMember::factory()->create([
        'account_id' => $demoOwner->id,
        'role' => 'technician',
        'company_role_id' => null,
        'permissions' => ['jobs', 'tasks'],
    ]);
    $regularMember = TeamMember::factory()->create([
        'account_id' => $regularOwner->id,
        'role' => 'technician',
        'company_role_id' => null,
        'permissions' => ['jobs', 'tasks'],
    ]);
    $crossTenantMembership = TeamMember::factory()->create([
        'account_id' => $regularOwner->id,
        'user_id' => $demoOwner->id,
        'role' => 'member',
        'company_role_id' => null,
        'permissions' => ['tasks.view'],
    ]);
    $migration = require database_path('migrations/2026_09_01_184055_backfill_demo_access_roles.php');

    $migration->up();
    $migration->up();

    $demoMember->refresh()->load('companyRole.permissions:id,slug');
    $regularMember->refresh();
    $crossTenantMembership->refresh();

    expect($demoMember->role)->toBe('member')
        ->and($demoMember->permissions)->toBe([])
        ->and($demoMember->companyRole?->slug)->toBe('demo_technician')
        ->and($demoMember->companyRole?->permissions->pluck('slug')->sort()->values()->all())->toBe([
            'update_jobs',
            'update_tasks',
            'view_jobs',
            'view_tasks',
        ])
        ->and(CompanyRole::query()->where('company_id', $demoOwner->id)->count())->toBe(1)
        ->and($regularMember->company_role_id)->toBeNull()
        ->and($regularMember->permissions)->toBe(['jobs', 'tasks'])
        ->and($crossTenantMembership->company_role_id)->toBeNull()
        ->and($crossTenantMembership->permissions)->toBe(['tasks.view'])
        ->and(CompanyRole::query()->where('company_id', $regularOwner->id)->exists())->toBeFalse();
});

test('provisioning a missing demo assignment never overwrites an existing managed role', function () {
    $owner = demoResetGuardOwner(DemoAccountService::TYPE_GUIDED, 'guided_demo');
    $existingPermission = Permission::query()->where('slug', 'view_reservations')->firstOrFail();
    $existingRole = CompanyRole::query()->create([
        'company_id' => $owner->id,
        'name' => 'Stylist',
        'slug' => 'demo_stylist',
        'is_system' => false,
        'is_default' => false,
        'is_editable' => true,
        'is_deletable' => true,
        'is_active' => true,
    ]);
    $existingRole->permissions()->sync([$existingPermission->id]);
    $existingMember = TeamMember::factory()->create([
        'account_id' => $owner->id,
        'role' => 'member',
        'company_role_id' => $existingRole->id,
        'permissions' => [],
    ]);
    $newMember = TeamMember::factory()->create([
        'account_id' => $owner->id,
        'role' => 'stylist',
        'company_role_id' => null,
        'permissions' => ['sales.pos'],
    ]);

    app(DemoAccessRoleProvisioner::class)->provision($owner);

    $existingRole->refresh()->load('permissions:id,slug');
    $existingMember->refresh();
    $newMember->refresh()->load('companyRole.permissions:id,slug');

    expect($existingMember->company_role_id)->toBe($existingRole->id)
        ->and($existingRole->permissions->pluck('slug')->all())->toBe(['view_reservations'])
        ->and($newMember->company_role_id)->not->toBe($existingRole->id)
        ->and($newMember->companyRole?->slug)->toStartWith('demo_stylist_')
        ->and($newMember->companyRole?->permissions->pluck('slug')->sort()->values()->all())->toBe([
            'create_sales',
            'manage_cash_register',
            'view_sales',
        ])
        ->and(CompanyRole::query()->where('company_id', $owner->id)->count())->toBe(2);
});
