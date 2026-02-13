<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function teamPermissionRoleId(string $name): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $name . ' role']
    )->id;
}

function teamPermissionOwner(array $attributes = []): User
{
    $defaults = [
        'name' => 'Team Permission Owner',
        'email' => 'owner-' . Str::lower(Str::random(10)) . '@example.com',
        'password' => 'password',
        'role_id' => teamPermissionRoleId('owner'),
        'company_type' => 'services',
        'company_sector' => 'service_general',
        'onboarding_completed_at' => now(),
        'company_features' => [
            'team_members' => true,
            'jobs' => false,
            'tasks' => true,
            'quotes' => false,
            'reservations' => false,
            'sales' => false,
        ],
    ];

    return User::query()->create(array_merge($defaults, $attributes));
}

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
});

test('team member page only exposes permissions for enabled tenant modules', function () {
    $owner = teamPermissionOwner();

    $this->actingAs($owner)
        ->get(route('team.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Team/Index')
            ->where('availablePermissions', function ($permissions) {
                $ids = collect($permissions)->pluck('id')->all();

                return $ids === [
                    'tasks.view',
                    'tasks.create',
                    'tasks.edit',
                    'tasks.delete',
                ];
            })
        );
});

test('team member creation rejects permissions from disabled modules', function () {
    $owner = teamPermissionOwner();

    $this->actingAs($owner)
        ->from(route('team.index'))
        ->post(route('team.store'), [
            'name' => 'Member Disabled Permission',
            'email' => 'member-' . Str::lower(Str::random(10)) . '@example.com',
            'role' => 'member',
            'permissions' => ['reservations.view'],
        ])
        ->assertRedirect(route('team.index'))
        ->assertSessionHasErrors(['permissions.0']);

    expect(TeamMember::query()->where('account_id', $owner->id)->count())->toBe(0);
});

test('team member default permissions are filtered by tenant module access', function () {
    $owner = teamPermissionOwner();

    $this->actingAs($owner)
        ->from(route('team.index'))
        ->post(route('team.store'), [
            'name' => 'Member Default Permissions',
            'email' => 'member-default-' . Str::lower(Str::random(10)) . '@example.com',
            'role' => 'member',
        ])
        ->assertRedirect(route('team.index'));

    $member = TeamMember::query()
        ->where('account_id', $owner->id)
        ->latest('id')
        ->first();

    expect($member)->not->toBeNull();
    expect($member->permissions)->toBe([
        'tasks.view',
        'tasks.edit',
    ]);
});
