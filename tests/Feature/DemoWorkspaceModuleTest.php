<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\DemoWorkspace;
use App\Models\PlatformAdmin;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Demo\DemoWorkspaceCatalog;
use App\Support\PlatformPermissions;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
    $this->withoutMiddleware(ValidateCsrfToken::class);
});

function demoWorkspaceRoleId(string $name, string $description): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $description]
    )->id;
}

function demoWorkspacePlatformAdmin(array $permissions = []): User
{
    $user = User::query()->create([
        'name' => 'Demo Platform Admin',
        'email' => 'demo-platform-admin@example.com',
        'password' => 'password',
        'role_id' => demoWorkspaceRoleId('admin', 'Platform admin role'),
        'onboarding_completed_at' => now(),
    ]);

    PlatformAdmin::query()->create([
        'user_id' => $user->id,
        'role' => 'ops',
        'permissions' => $permissions,
        'is_active' => true,
        'require_2fa' => false,
    ]);

    return $user;
}

function demoWorkspacePayload(array $overrides = []): array
{
    /** @var DemoWorkspaceCatalog $catalog */
    $catalog = app(DemoWorkspaceCatalog::class);

    $payload = [
        'prospect_name' => 'Morgan Prospect',
        'prospect_email' => 'morgan.prospect@example.com',
        'prospect_company' => 'Northwind Collective',
        'company_name' => 'Northwind Demo Studio',
        'company_type' => 'services',
        'company_sector' => 'salon',
        'seed_profile' => 'standard',
        'team_size' => 3,
        'locale' => 'fr',
        'timezone' => 'America/Toronto',
        'desired_outcome' => 'Walk the prospect through bookings, queue management, and front-desk operations.',
        'internal_notes' => 'Prepared after discovery call.',
        'selected_modules' => $catalog->defaultModules('services', 'salon'),
        'expires_at' => now()->addDays(10)->toDateString(),
    ];

    return array_replace_recursive($payload, $overrides);
}

it('forbids platform admins without demo permissions from accessing the module', function () {
    $admin = demoWorkspacePlatformAdmin([PlatformPermissions::TENANTS_VIEW]);

    $this->actingAs($admin)
        ->from('/dashboard')
        ->get(route('superadmin.demo-workspaces.index'))
        ->assertRedirect('/dashboard')
        ->assertSessionHas('warning');
});

it('allows platform admins with demo permissions to access the module', function () {
    $admin = demoWorkspacePlatformAdmin([PlatformPermissions::DEMOS_MANAGE]);

    $this->actingAs($admin)
        ->get(route('superadmin.demo-workspaces.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('SuperAdmin/DemoWorkspaces/Index')
            ->where('can_view_tenant', false)
            ->where('can_impersonate', false)
            ->has('options.modules')
            ->has('defaults.selected_modules')
        );
});

it('provisions a realistic service demo workspace from the admin module', function () {
    $admin = demoWorkspacePlatformAdmin([PlatformPermissions::DEMOS_MANAGE]);

    $this->actingAs($admin)
        ->post(route('superadmin.demo-workspaces.store'), demoWorkspacePayload())
        ->assertRedirect(route('superadmin.demo-workspaces.index'))
        ->assertSessionHas('success');

    $workspace = DemoWorkspace::query()
        ->with('owner')
        ->firstOrFail();

    expect($workspace->company_name)->toBe('Northwind Demo Studio');
    expect($workspace->company_type)->toBe('services');
    expect($workspace->owner)->not->toBeNull();
    expect($workspace->owner?->is_demo)->toBeTrue();
    expect($workspace->owner?->demo_type)->toBe('custom');
    expect($workspace->seed_summary['customers'] ?? 0)->toBeGreaterThan(0);
    expect($workspace->seed_summary['reservations'] ?? 0)->toBeGreaterThan(0);
    expect($workspace->seed_summary['queue_items'] ?? 0)->toBeGreaterThan(0);
    expect($workspace->seed_summary['invoices'] ?? 0)->toBeGreaterThan(0);
    expect(TeamMember::query()->where('account_id', $workspace->owner_user_id)->count())->toBeGreaterThan(0);
});

it('can provision and fully purge a commerce demo workspace', function () {
    $admin = demoWorkspacePlatformAdmin([PlatformPermissions::DEMOS_MANAGE]);

    $payload = demoWorkspacePayload([
        'prospect_name' => 'Taylor Retail',
        'prospect_email' => 'taylor.retail@example.com',
        'company_name' => 'Taylor Retail Demo',
        'company_type' => 'products',
        'company_sector' => 'retail',
        'desired_outcome' => 'Show a commerce flow with catalog, sales, loyalty, and campaigns.',
        'selected_modules' => app(DemoWorkspaceCatalog::class)->defaultModules('products', 'retail'),
    ]);

    $this->actingAs($admin)
        ->post(route('superadmin.demo-workspaces.store'), $payload)
        ->assertRedirect(route('superadmin.demo-workspaces.index'));

    $workspace = DemoWorkspace::query()->latest('id')->firstOrFail();
    $ownerId = $workspace->owner_user_id;

    expect($workspace->seed_summary['sales'] ?? 0)->toBeGreaterThan(0);
    expect($workspace->seed_summary['campaigns'] ?? 0)->toBe(1);
    expect($workspace->seed_summary['loyalty_program_enabled'] ?? 0)->toBe(1);

    $this->actingAs($admin)
        ->delete(route('superadmin.demo-workspaces.destroy', $workspace))
        ->assertRedirect(route('superadmin.demo-workspaces.index'))
        ->assertSessionHas('success');

    expect(DemoWorkspace::query()->find($workspace->id))->toBeNull();
    expect(User::query()->find($ownerId))->toBeNull();
});
