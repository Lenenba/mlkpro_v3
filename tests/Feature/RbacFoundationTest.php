<?php

use App\Models\CompanyRole;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Gate;

function rbacEmployeeRoleId(): int
{
    return Role::query()->firstOrCreate(
        ['name' => 'employee'],
        ['description' => 'Employee role']
    )->id;
}

it('seeds protected system roles and permissions', function () {
    $this->seed(RbacSeeder::class);

    $ownerRole = CompanyRole::query()
        ->whereNull('company_id')
        ->where('slug', 'owner')
        ->firstOrFail();

    expect($ownerRole->is_system)->toBeTrue()
        ->and($ownerRole->is_default)->toBeTrue()
        ->and($ownerRole->is_editable)->toBeFalse()
        ->and($ownerRole->is_deletable)->toBeFalse()
        ->and($ownerRole->permissions()->count())->toBe(Permission::query()->count());
});

it('allows account owners to resolve every company permission through the central gate', function () {
    $this->seed(RbacSeeder::class);

    $owner = User::factory()->create();

    expect(Gate::forUser($owner)->allows('company-permission', ['manage_roles_permissions', $owner->id]))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('company-permission', ['approve_high_value_expenses', $owner->id]))->toBeTrue();
});

it('resolves permissions from an assigned company role', function () {
    $this->seed(RbacSeeder::class);

    $owner = User::factory()->create();
    $employee = User::factory()->withRole(rbacEmployeeRoleId())->create();
    $role = CompanyRole::query()->where('slug', 'coiffeur')->firstOrFail();

    $member = TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $employee->id,
        'role' => 'member',
        'company_role_id' => $role->id,
        'permissions' => null,
    ])->fresh('companyRole.permissions');

    expect($member->hasPermission('manage_own_presence'))->toBeTrue()
        ->and($member->hasPermission('manage_team_presence'))->toBeFalse()
        ->and($member->hasPermission('view_own_reservations'))->toBeTrue();
});

it('keeps direct legacy permissions compatible while roles are migrated', function () {
    $owner = User::factory()->create();
    $employee = User::factory()->withRole(rbacEmployeeRoleId())->create();

    $member = TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $employee->id,
        'role' => 'member',
        'permissions' => ['tasks.edit', 'reservations.manage'],
    ]);

    expect($member->hasPermission('tasks.edit'))->toBeTrue()
        ->and($member->hasPermission('update_tasks'))->toBeTrue()
        ->and($member->hasPermission('reservations.manage'))->toBeTrue()
        ->and($member->hasPermission('manage_reservation_calendar'))->toBeTrue();
});

it('allows a custom company role to receive permissions and be assigned to a member', function () {
    $this->seed(RbacSeeder::class);

    $owner = User::factory()->create();
    $employee = User::factory()->withRole(rbacEmployeeRoleId())->create();
    $permission = Permission::query()->where('slug', 'view_presence')->firstOrFail();

    $role = CompanyRole::query()->create([
        'company_id' => $owner->id,
        'name' => 'Assistant salon',
        'slug' => 'assistant_salon',
        'description' => 'Custom salon assistant role.',
        'is_system' => false,
        'is_default' => false,
        'is_editable' => true,
        'is_deletable' => true,
        'is_active' => true,
    ]);
    $role->permissions()->attach($permission);

    $member = TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $employee->id,
        'role' => 'member',
        'company_role_id' => $role->id,
        'permissions' => [],
    ])->fresh('companyRole.permissions');

    expect($member->companyRole?->isSystem())->toBeFalse()
        ->and($member->hasPermission('view_presence'))->toBeTrue()
        ->and(Gate::forUser($employee)->allows('company-permission', ['view_presence', $owner->id]))->toBeTrue();
});
