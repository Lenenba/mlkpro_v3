<?php

use App\Models\Customer;
use App\Models\Quote;
use App\Models\Role;
use App\Models\Task;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('global search api stays available before onboarding completion and keeps short queries empty', function () {
    $owner = User::factory()->create([
        'role_id' => globalSearchRoleId('owner', 'Account owner role'),
        'onboarding_completed_at' => null,
        'two_factor_exempt' => false,
    ]);

    Sanctum::actingAs($owner);

    $this->getJson('/api/v1/global-search?q=a')
        ->assertOk()
        ->assertJsonPath('query', 'a')
        ->assertJsonPath('groups', []);
});

test('global search api returns the same grouped owner results as the web search flow', function () {
    $owner = User::factory()->create([
        'role_id' => globalSearchRoleId('owner', 'Account owner role'),
        'name' => 'Search Owner',
        'email' => 'search-owner@example.test',
        'company_features' => [
            'tasks' => true,
            'quotes' => true,
            'team_members' => true,
            'performance' => true,
        ],
        'onboarding_completed_at' => now(),
        'two_factor_exempt' => false,
    ]);

    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
        'company_name' => 'Search Client',
        'email' => 'search-client@example.test',
    ]);

    Task::query()->create([
        'account_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'customer_id' => $customer->id,
        'title' => 'Search Task',
        'description' => 'Task surfaced in search',
        'status' => 'todo',
        'due_date' => now()->toDateString(),
    ]);

    Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'property_id' => null,
        'job_title' => 'Search Quote',
        'status' => 'draft',
        'notes' => 'Quote surfaced in search',
        'total' => 100,
        'subtotal' => 100,
    ]);

    $employeeUser = User::factory()->create([
        'role_id' => globalSearchRoleId('employee', 'Employee role'),
        'name' => 'Search Employee',
        'email' => 'search-employee@example.test',
        'onboarding_completed_at' => now(),
        'two_factor_exempt' => false,
    ]);

    TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $employeeUser->id,
        'role' => 'member',
        'title' => 'Estimator',
        'permissions' => ['tasks.view', 'quotes.send'],
        'is_active' => true,
    ]);

    Sanctum::actingAs($owner);

    $response = $this->getJson('/api/v1/global-search?q=Search')
        ->assertOk()
        ->assertJsonPath('query', 'Search');

    $groups = collect($response->json('groups'))->keyBy('type');

    expect($groups->keys()->values()->all())->toBe(['customers', 'tasks', 'quotes', 'employees'])
        ->and(data_get($groups->get('customers'), 'items.0.title'))->toBe('Search Client')
        ->and(data_get($groups->get('tasks'), 'items.0.title'))->toBe('Search Task')
        ->and(data_get($groups->get('quotes'), 'items.0.title'))->not->toBeNull()
        ->and(data_get($groups->get('employees'), 'items.0.title'))->not->toBeNull();

    expect(data_get($groups->get('quotes'), 'items.0.url'))->toContain('/customer/quote/')
        ->and(data_get($groups->get('employees'), 'items.0.url'))->not->toBeNull();
});

test('global search api keeps permission-gated groups hidden for limited team members', function () {
    $owner = User::factory()->create([
        'role_id' => globalSearchRoleId('owner', 'Account owner role'),
        'company_features' => [
            'tasks' => true,
            'quotes' => true,
            'team_members' => true,
            'performance' => true,
        ],
        'onboarding_completed_at' => now(),
        'two_factor_exempt' => false,
    ]);

    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
        'company_name' => 'Member Customer',
        'email' => 'member-customer@example.test',
    ]);

    Task::query()->create([
        'account_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'customer_id' => $customer->id,
        'title' => 'Member Task',
        'description' => 'Visible task',
        'status' => 'todo',
        'due_date' => now()->toDateString(),
    ]);

    Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'property_id' => null,
        'job_title' => 'Member Quote',
        'status' => 'draft',
        'notes' => 'Should stay hidden',
        'total' => 150,
        'subtotal' => 150,
    ]);

    $employeeUser = User::factory()->create([
        'role_id' => globalSearchRoleId('employee', 'Employee role'),
        'name' => 'Member Worker',
        'email' => 'member-worker@example.test',
        'onboarding_completed_at' => now(),
        'two_factor_exempt' => false,
    ]);

    TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $employeeUser->id,
        'role' => 'member',
        'title' => 'Coordinator',
        'permissions' => ['tasks.view'],
        'is_active' => true,
    ]);

    Sanctum::actingAs($employeeUser);

    $response = $this->getJson('/api/v1/global-search?q=Member')
        ->assertOk()
        ->assertJsonPath('query', 'Member');

    $groups = collect($response->json('groups'))->keyBy('type');

    expect($groups->keys()->values()->all())->toBe(['customers', 'tasks'])
        ->and(data_get($groups->get('customers'), 'items.0.title'))->toBe('Member Customer')
        ->and(data_get($groups->get('tasks'), 'items.0.title'))->toBe('Member Task')
        ->and($groups->has('quotes'))->toBeFalse()
        ->and($groups->has('employees'))->toBeFalse();
});

function globalSearchRoleId(string $name, string $description): int
{
    return Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $description],
    )->id;
}
