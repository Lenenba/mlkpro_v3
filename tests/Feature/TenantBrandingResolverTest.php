<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\TenantBrandingResolver;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

function tenantBrandingRoleId(string $name): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => ucfirst($name).' role'],
    )->id;
}

test('tenant branding exposes only a real custom company logo', function () {
    $owner = User::factory()->create([
        'role_id' => tenantBrandingRoleId('owner'),
        'name' => 'Account Owner',
        'company_name' => 'Acme Studio',
        'company_logo' => 'company/logos/acme.png',
    ]);

    $branding = app(TenantBrandingResolver::class)->resolve($owner);

    expect($branding)->toBe([
        'name' => 'Acme Studio',
        'custom_logo_url' => Storage::disk('public')->url('company/logos/acme.png'),
        'has_custom_logo' => true,
    ]);
});

test('tenant branding rejects legacy placeholders and unsafe logo values', function (mixed $logo) {
    $owner = User::factory()->create([
        'role_id' => tenantBrandingRoleId('owner'),
        'name' => 'Fallback Owner',
        'company_name' => null,
        'company_logo' => null,
    ]);
    $owner->company_logo = $logo;

    expect(app(TenantBrandingResolver::class)->resolve($owner))->toBe([
        'name' => 'Fallback Owner',
        'custom_logo_url' => null,
        'has_custom_logo' => false,
    ]);
})->with([
    'missing logo' => null,
    'blank logo' => '   ',
    'legacy storage path' => 'customers/customer.png',
    'legacy public URL' => 'https://cdn.example.com/storage/customers/customer.png',
    'unsupported URL scheme' => 'javascript:alert(1)',
    'directory traversal' => '../company/logo.png',
    'malformed HTTPS URL' => 'https://',
]);

test('tenant branding resolves the account owner for an employee', function () {
    $owner = User::factory()->create([
        'role_id' => tenantBrandingRoleId('owner'),
        'company_name' => 'Northwind Services',
        'company_logo' => 'https://example.com/northwind.png',
    ]);
    $employee = User::factory()->create([
        'role_id' => tenantBrandingRoleId('employee'),
        'company_name' => 'Incorrect Employee Brand',
        'company_logo' => 'https://example.com/employee.png',
    ]);
    TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $employee->id,
        'is_active' => true,
    ]);

    $resolver = app(TenantBrandingResolver::class);

    expect($resolver->resolveAccountOwner($employee)?->is($owner))->toBeTrue()
        ->and($resolver->resolve($employee))->toBe([
            'name' => 'Northwind Services',
            'custom_logo_url' => 'https://example.com/northwind.png',
            'has_custom_logo' => true,
        ]);
});

test('tenant branding resolves the owning workspace for a portal client', function () {
    $owner = User::factory()->create([
        'role_id' => tenantBrandingRoleId('owner'),
        'company_name' => 'Portal Workspace',
        'company_logo' => 'https://example.com/portal-workspace.png',
    ]);
    $client = User::factory()->create([
        'role_id' => tenantBrandingRoleId('client'),
        'company_name' => 'Incorrect Client Brand',
        'company_logo' => 'https://example.com/client.png',
    ]);
    Customer::factory()->create([
        'user_id' => $owner->id,
        'portal_user_id' => $client->id,
        'portal_access' => true,
        'email' => $client->email,
    ]);

    $resolver = app(TenantBrandingResolver::class);

    expect($resolver->resolveAccountOwner($client)?->is($owner))->toBeTrue()
        ->and($resolver->resolve($client))->toBe([
            'name' => 'Portal Workspace',
            'custom_logo_url' => 'https://example.com/portal-workspace.png',
            'has_custom_logo' => true,
        ]);
});

test('inertia shares the normalized tenant branding contract', function () {
    $owner = User::factory()->create([
        'role_id' => tenantBrandingRoleId('owner'),
        'company_name' => 'Shared Branding Co.',
        'company_type' => 'services',
        'company_sector' => 'field_services',
        'company_logo' => 'customers/customer.png',
        'company_features' => [
            'invoices' => true,
        ],
        'onboarding_completed_at' => now(),
    ]);

    $this->withoutMiddleware(EnsureTwoFactorVerified::class);

    $this->actingAs($owner)
        ->get(route('workspace.hubs.show', ['category' => 'finance']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.account.company.name', 'Shared Branding Co.')
            ->where('auth.account.company.logo_url', null)
            ->where('auth.account.company.custom_logo_url', null)
            ->where('auth.account.company.has_custom_logo', false));
});
