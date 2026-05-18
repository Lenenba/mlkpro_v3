<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\CompanyRole;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use Database\Seeders\RbacSeeder;
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

test('team member hub props include plan default features from the account owner context', function () {
    $this->seed(RbacSeeder::class);

    $owner = workspaceHubOwner([
        'company_features' => [
            'reservations' => true,
        ],
        'selected_plan_key' => null,
        'trial_ends_at' => now()->addWeeks(2),
    ]);

    $employee = User::factory()->create([
        'role_id' => workspaceHubRoleId('employee', 'Employee role'),
        'onboarding_completed_at' => now(),
    ]);

    $coiffeurRole = CompanyRole::query()
        ->whereNull('company_id')
        ->where('slug', 'coiffeur')
        ->firstOrFail();

    TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $employee->id,
        'role' => 'member',
        'company_role_id' => $coiffeurRole->id,
        'permissions' => [],
    ]);

    $this->actingAs($employee)
        ->get(route('workspace.hubs.show', ['category' => 'operations']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Workspace/CategoryHub')
            ->where('category', 'operations')
            ->where('auth.account.features.presence', true)
            ->where('auth.account.features.team_members', true)
            ->where('auth.account.permissions', fn ($permissions): bool => collect($permissions)->contains('view_presence'))
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
