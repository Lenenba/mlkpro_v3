<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
});

function workspaceHubRoleId(string $name, string $description): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $description]
    )->id;
}

function workspaceHubOwner(array $overrides = []): User
{
    return User::factory()->create(array_replace_recursive([
        'role_id' => workspaceHubRoleId('owner', 'Account owner role'),
        'company_type' => 'services',
        'company_sector' => 'field_services',
        'onboarding_completed_at' => now(),
        'company_features' => [
            'campaigns' => true,
            'expenses' => true,
            'invoices' => true,
            'accounting' => true,
            'jobs' => true,
            'tasks' => true,
            'planning' => true,
            'requests' => true,
            'quotes' => true,
            'services' => true,
            'team_members' => true,
        ],
    ], $overrides));
}

test('owner can open a workspace category hub page', function () {
    $owner = workspaceHubOwner();

    $this->actingAs($owner)
        ->get(route('workspace.hubs.show', ['category' => 'finance']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Workspace/CategoryHub')
            ->where('category', 'finance')
        );
});

test('client users cannot access workspace category hubs', function () {
    $client = User::factory()->create([
        'role_id' => workspaceHubRoleId('client', 'Client role'),
        'company_type' => null,
        'company_sector' => null,
        'onboarding_completed_at' => now(),
    ]);

    $this->actingAs($client)
        ->getJson(route('workspace.hubs.show', ['category' => 'finance']))
        ->assertForbidden();
});

test('unknown workspace hub categories return a not found response', function () {
    $owner = workspaceHubOwner();

    $this->actingAs($owner)
        ->get('/workspace-hubs/not-a-real-category')
        ->assertNotFound();
});
