<?php

use App\Models\Customer;
use App\Models\PlatformAdmin;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

test('auth me api returns a stable bootstrap payload for the account owner', function () {
    $owner = User::factory()->create([
        'role_id' => authMeRoleId('owner', 'Account owner role'),
        'company_name' => 'Acme Studio',
        'company_type' => 'services',
        'company_logo' => 'https://example.com/logo.png',
        'company_sector' => 'salon',
        'company_features' => [
            'assistant' => true,
            'campaigns' => false,
            'reservations' => true,
        ],
        'onboarding_completed_at' => now(),
    ]);

    Sanctum::actingAs($owner);

    $this->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('user.id', $owner->id)
        ->assertJsonPath('meta.role_name', 'owner')
        ->assertJsonPath('meta.owner_id', $owner->id)
        ->assertJsonPath('meta.is_owner', true)
        ->assertJsonPath('meta.is_client', false)
        ->assertJsonPath('meta.is_superadmin', false)
        ->assertJsonPath('meta.is_platform_admin', false)
        ->assertJsonPath('meta.company.name', 'Acme Studio')
        ->assertJsonPath('meta.company.type', 'services')
        ->assertJsonPath('meta.company.onboarded', true)
        ->assertJsonPath('meta.company.logo_url', 'https://example.com/logo.png')
        ->assertJsonPath('meta.features.assistant', true)
        ->assertJsonPath('meta.features.reservations', true)
        ->assertJsonMissingPath('meta.features.campaigns')
        ->assertJsonPath('meta.platform', null)
        ->assertJsonPath('meta.team', null);
});

test('auth me api returns the owner context and team membership for an employee', function () {
    $owner = User::factory()->create([
        'role_id' => authMeRoleId('owner', 'Account owner role'),
        'company_name' => 'Northwind Services',
        'company_type' => 'services',
        'company_logo' => 'https://example.com/northwind.png',
        'company_features' => [
            'assistant' => true,
        ],
    ]);

    $employee = User::factory()->create([
        'role_id' => authMeRoleId('employee', 'Employee role'),
        'company_name' => null,
        'company_type' => null,
        'company_logo' => null,
    ]);

    TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $employee->id,
        'role' => 'member',
        'permissions' => ['tasks.view', 'quotes.send'],
        'is_active' => true,
    ]);

    Sanctum::actingAs($employee);

    $this->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('user.id', $employee->id)
        ->assertJsonPath('meta.role_name', 'employee')
        ->assertJsonPath('meta.owner_id', $owner->id)
        ->assertJsonPath('meta.is_owner', false)
        ->assertJsonPath('meta.is_client', false)
        ->assertJsonPath('meta.is_platform_admin', false)
        ->assertJsonPath('meta.company.name', 'Northwind Services')
        ->assertJsonPath('meta.company.type', 'services')
        ->assertJsonPath('meta.company.logo_url', 'https://example.com/northwind.png')
        ->assertJsonPath('meta.features.assistant', true)
        ->assertJsonPath('meta.platform', null)
        ->assertJsonPath('meta.team.role', 'member')
        ->assertJsonPath('meta.team.permissions.0', 'tasks.view')
        ->assertJsonPath('meta.team.permissions.1', 'quotes.send');
});

test('auth me api resolves the owning workspace for a portal client user', function () {
    $owner = User::factory()->create([
        'role_id' => authMeRoleId('owner', 'Account owner role'),
        'company_name' => 'Malikia Spa',
        'company_type' => 'services',
        'company_logo' => 'https://example.com/malikia-spa.png',
        'company_features' => [
            'reservations' => true,
            'assistant' => true,
        ],
    ]);

    $client = User::factory()->create([
        'role_id' => authMeRoleId('client', 'Client role'),
        'company_name' => null,
        'company_type' => null,
        'company_logo' => null,
    ]);

    Customer::factory()->create([
        'user_id' => $owner->id,
        'portal_user_id' => $client->id,
        'portal_access' => true,
        'company_name' => 'Portal Client',
        'email' => $client->email,
    ]);

    Sanctum::actingAs($client);

    $this->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('user.id', $client->id)
        ->assertJsonPath('meta.role_name', 'client')
        ->assertJsonPath('meta.owner_id', $owner->id)
        ->assertJsonPath('meta.is_owner', false)
        ->assertJsonPath('meta.is_client', true)
        ->assertJsonPath('meta.is_platform_admin', false)
        ->assertJsonPath('meta.company.name', 'Malikia Spa')
        ->assertJsonPath('meta.company.type', 'services')
        ->assertJsonPath('meta.company.logo_url', 'https://example.com/malikia-spa.png')
        ->assertJsonPath('meta.features.assistant', true)
        ->assertJsonPath('meta.features.reservations', true)
        ->assertJsonPath('meta.platform', null)
        ->assertJsonPath('meta.team', null);
});

test('auth me api exposes platform admin permissions when the current user is a platform admin', function () {
    $platformAdminUser = User::factory()->create([
        'role_id' => authMeRoleId('admin', 'Platform admin role'),
        'company_name' => 'Platform HQ',
        'company_type' => 'services',
        'company_logo' => 'https://example.com/platform-hq.png',
    ]);

    PlatformAdmin::query()->create([
        'user_id' => $platformAdminUser->id,
        'role' => 'operations',
        'permissions' => ['tenants.manage', 'settings.manage'],
        'is_active' => true,
        'require_2fa' => true,
    ]);

    Sanctum::actingAs($platformAdminUser);

    $this->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('user.id', $platformAdminUser->id)
        ->assertJsonPath('meta.role_name', 'admin')
        ->assertJsonPath('meta.owner_id', $platformAdminUser->id)
        ->assertJsonPath('meta.is_owner', false)
        ->assertJsonPath('meta.is_client', false)
        ->assertJsonPath('meta.is_superadmin', false)
        ->assertJsonPath('meta.is_platform_admin', true)
        ->assertJsonPath('meta.company.name', 'Platform HQ')
        ->assertJsonPath('meta.company.logo_url', 'https://example.com/platform-hq.png')
        ->assertJsonPath('meta.platform.role', 'operations')
        ->assertJsonPath('meta.platform.permissions.0', 'tenants.manage')
        ->assertJsonPath('meta.platform.permissions.1', 'settings.manage')
        ->assertJsonPath('meta.platform.is_active', true)
        ->assertJsonPath('meta.team', null);
});

test('auth me api requires authentication', function () {
    $this->getJson('/api/v1/auth/me')->assertUnauthorized();
});

function authMeRoleId(string $name, string $description): int
{
    return Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $description],
    )->id;
}
