<?php

use App\Models\CompanyRole;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\ReservationResource;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Inertia\Testing\AssertableInertia as Assert;

function rbacPhaseTwoEmployeeRoleId(): int
{
    return Role::query()->firstOrCreate(
        ['name' => 'employee'],
        ['description' => 'Employee role']
    )->id;
}

function rbacPhaseTwoOwner(): User
{
    return User::factory()->create([
        'company_type' => 'services',
        'company_sector' => 'salon',
        'company_features' => [
            'presence' => true,
            'reservations' => true,
            'team_members' => true,
        ],
        'selected_plan_key' => null,
    ]);
}

function rbacPhaseTwoMember(User $owner, array $permissionSlugs = [], ?string $systemRoleSlug = null): User
{
    $employee = User::factory()->withRole(rbacPhaseTwoEmployeeRoleId())->create();
    $companyRoleId = null;

    if ($systemRoleSlug) {
        $companyRoleId = CompanyRole::query()->where('slug', $systemRoleSlug)->value('id');
    }

    if (! $companyRoleId && $permissionSlugs !== []) {
        $role = CompanyRole::query()->create([
            'company_id' => $owner->id,
            'name' => 'Phase 2 custom role',
            'slug' => 'phase_2_custom_role_'.str()->random(8),
            'description' => 'Custom role for phase 2 tests.',
            'is_system' => false,
            'is_default' => false,
            'is_editable' => true,
            'is_deletable' => true,
            'is_active' => true,
        ]);

        $role->permissions()->sync(
            Permission::query()->whereIn('slug', $permissionSlugs)->pluck('id')->all()
        );

        $companyRoleId = $role->id;
    }

    TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $employee->id,
        'role' => 'member',
        'company_role_id' => $companyRoleId,
        'permissions' => [],
    ]);

    return $employee;
}

it('blocks presence access when the team member lacks view presence permission', function () {
    $this->seed(RbacSeeder::class);

    $owner = rbacPhaseTwoOwner();
    $employee = rbacPhaseTwoMember($owner);

    $this->actingAs($employee)
        ->getJson(route('presence.index'))
        ->assertForbidden()
        ->assertJsonPath('message', 'Permission denied.');
});

it('allows a checked-in capable role to open presence and exposes resolved permissions to inertia', function () {
    $this->seed(RbacSeeder::class);

    $owner = rbacPhaseTwoOwner();
    $employee = rbacPhaseTwoMember($owner, systemRoleSlug: 'coiffeur');

    $this->actingAs($employee)
        ->get(route('presence.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Presence/Index')
            ->where('permissions.can_clock', true)
            ->where('auth.account.permissions', fn ($permissions): bool => collect($permissions)->contains('view_presence')
                && collect($permissions)->contains('presence.view')
                && collect($permissions)->contains('manage_own_presence'))
        );
});

it('hides chair resources from reservation settings without chair permissions', function () {
    $this->seed(RbacSeeder::class);

    $owner = rbacPhaseTwoOwner();
    ReservationResource::query()->create([
        'account_id' => $owner->id,
        'name' => 'Chair 1',
        'type' => ReservationResource::TYPE_CHAIR,
        'capacity' => 1,
        'is_active' => true,
    ]);
    $employee = rbacPhaseTwoMember($owner, ['manage_reservation_calendar']);

    $this->actingAs($employee)
        ->get(route('settings.reservations.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Reservations')
            ->where('permissions.can_view_chairs', false)
            ->where('permissions.can_manage_chairs', false)
            ->where('resources', [])
        );
});

it('requires manage chairs permission before saving chair resources', function () {
    $this->seed(RbacSeeder::class);

    $owner = rbacPhaseTwoOwner();
    $employee = rbacPhaseTwoMember($owner, ['manage_reservation_calendar']);

    $this->actingAs($employee)
        ->putJson(route('settings.reservations.update'), [
            'resources' => [
                [
                    'name' => 'Chair blocked',
                    'type' => ReservationResource::TYPE_CHAIR,
                    'capacity' => 1,
                    'is_active' => true,
                ],
            ],
        ])
        ->assertForbidden();

    $this->assertDatabaseMissing('reservation_resources', [
        'account_id' => $owner->id,
        'name' => 'Chair blocked',
    ]);
});

it('allows members with reservation management and chair management to save chair resources', function () {
    $this->seed(RbacSeeder::class);

    $owner = rbacPhaseTwoOwner();
    $employee = rbacPhaseTwoMember($owner, [
        'manage_reservation_calendar',
        'view_chairs',
        'manage_chairs',
    ]);

    $this->actingAs($employee)
        ->putJson(route('settings.reservations.update'), [
            'resources' => [
                [
                    'name' => 'Chair allowed',
                    'type' => ReservationResource::TYPE_CHAIR,
                    'capacity' => 1,
                    'is_active' => true,
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('message', 'Reservation settings saved.');

    $this->assertDatabaseHas('reservation_resources', [
        'account_id' => $owner->id,
        'name' => 'Chair allowed',
        'type' => ReservationResource::TYPE_CHAIR,
    ]);
});

it('filters global search customer results with resolved rbac permissions', function () {
    $this->seed(RbacSeeder::class);

    $owner = rbacPhaseTwoOwner();
    Customer::factory()->create([
        'user_id' => $owner->id,
        'first_name' => 'Ada',
        'last_name' => 'Client',
        'company_name' => 'Ada Studio',
        'email' => 'ada.client@example.test',
    ]);

    $blockedEmployee = rbacPhaseTwoMember($owner);

    $this->actingAs($blockedEmployee)
        ->getJson(route('global.search', ['q' => 'Ada']))
        ->assertOk()
        ->assertJsonPath('groups', []);

    $allowedEmployee = rbacPhaseTwoMember($owner, ['view_clients']);

    $this->actingAs($allowedEmployee)
        ->getJson(route('global.search', ['q' => 'Ada']))
        ->assertOk()
        ->assertJsonPath('groups.0.type', 'customers')
        ->assertJsonPath('groups.0.items.0.title', 'Ada Studio');
});
