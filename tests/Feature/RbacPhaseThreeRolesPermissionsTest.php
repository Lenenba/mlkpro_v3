<?php

use App\Models\CompanyRole;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use Database\Seeders\RbacSeeder;

function rbacPhaseThreeEmployeeRoleId(): int
{
    return Role::query()->firstOrCreate(
        ['name' => 'employee'],
        ['description' => 'Employee role']
    )->id;
}

function rbacPhaseThreeOwner(): User
{
    return User::factory()->create([
        'company_type' => 'services',
        'company_sector' => 'salon',
        'company_features' => [
            'team_members' => true,
            'presence' => true,
            'reservations' => true,
            'tasks' => true,
        ],
        'selected_plan_key' => null,
    ]);
}

function rbacPhaseThreeMember(User $owner, array $permissionSlugs = []): User
{
    $employee = User::factory()
        ->withRole(rbacPhaseThreeEmployeeRoleId())
        ->create();

    $companyRoleId = null;

    if ($permissionSlugs !== []) {
        $role = CompanyRole::query()->create([
            'company_id' => $owner->id,
            'name' => 'Phase 3 access role '.str()->random(6),
            'slug' => 'phase_3_access_role_'.str()->random(10),
            'description' => 'Custom role for phase 3 tests.',
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

function rbacPhaseThreeCustomRole(User $owner, string $name = 'Assistant salon'): CompanyRole
{
    $role = CompanyRole::query()->create([
        'company_id' => $owner->id,
        'name' => $name,
        'slug' => str($name)->slug('_').'_'.str()->random(8),
        'description' => 'Custom test role.',
        'is_system' => false,
        'is_default' => false,
        'is_editable' => true,
        'is_deletable' => true,
        'is_active' => true,
    ]);

    $role->permissions()->sync(
        Permission::query()->whereIn('slug', ['view_team_members'])->pluck('id')->all()
    );

    return $role;
}

it('protects the roles and permissions settings page with manage roles permission', function () {
    $this->seed(RbacSeeder::class);

    $owner = rbacPhaseThreeOwner();
    $blockedMember = rbacPhaseThreeMember($owner);

    $this->actingAs($blockedMember)
        ->getJson(route('settings.roles_permissions.edit'))
        ->assertForbidden()
        ->assertJsonPath('message', 'Permission denied.');

    $allowedMember = rbacPhaseThreeMember($owner, ['manage_roles_permissions']);

    $this->actingAs($allowedMember)
        ->getJson(route('settings.roles_permissions.edit'))
        ->assertOk()
        ->assertJsonStructure([
            'roles',
            'permissions',
            'teamMembers',
        ]);
});

it('keeps scoped permissions from satisfying manager-level aliases', function () {
    $member = new TeamMember([
        'permissions' => [
            'update_reservations',
            'sales.pos',
            'update_tasks',
        ],
    ]);

    expect($member->hasPermission('reservations.manage'))->toBeFalse()
        ->and($member->hasPermission('view_all_reservations'))->toBeFalse()
        ->and($member->hasPermission('sales.manage'))->toBeFalse()
        ->and($member->hasPermission('tasks.edit'))->toBeTrue();
});

it('does not grant team module access to the default coiffeur role', function () {
    $this->seed(RbacSeeder::class);

    $owner = rbacPhaseThreeOwner();
    $employee = User::factory()
        ->withRole(rbacPhaseThreeEmployeeRoleId())
        ->create();
    $coiffeurRole = CompanyRole::query()->where('slug', 'coiffeur')->firstOrFail();
    $membership = TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $employee->id,
        'role' => 'member',
        'company_role_id' => $coiffeurRole->id,
        'permissions' => [],
    ]);

    $membership->load('companyRole.permissions');

    expect($coiffeurRole->permissions->pluck('slug')->all())->not->toContain('view_team_members')
        ->and($membership->hasPermission('team.view'))->toBeFalse();

    $this->actingAs($employee)
        ->getJson(route('team.index'))
        ->assertForbidden();
});

it('creates updates duplicates and deletes custom company roles', function () {
    $this->seed(RbacSeeder::class);

    $owner = rbacPhaseThreeOwner();
    $manager = rbacPhaseThreeMember($owner, ['manage_roles_permissions']);

    $createResponse = $this->actingAs($manager)
        ->postJson(route('settings.roles_permissions.roles.store'), [
            'name' => 'Responsable caisse',
            'description' => 'Peut gerer la caisse et les clients.',
            'is_active' => true,
            'permissions' => ['view_clients', 'view_sales'],
        ])
        ->assertCreated()
        ->assertJsonPath('role.name', 'Responsable caisse')
        ->assertJsonPath('role.company_id', $owner->id);

    $roleId = (int) $createResponse->json('role.id');

    $this->assertDatabaseHas('company_roles', [
        'id' => $roleId,
        'company_id' => $owner->id,
        'name' => 'Responsable caisse',
    ]);

    $this->actingAs($manager)
        ->putJson(route('settings.roles_permissions.roles.update', $roleId), [
            'name' => 'Responsable caisse senior',
            'description' => 'Role caisse personnalise.',
            'is_active' => true,
            'permissions' => ['view_clients'],
        ])
        ->assertOk()
        ->assertJsonPath('role.name', 'Responsable caisse senior')
        ->assertJsonPath('role.permissions.0', 'view_clients');

    $systemRole = CompanyRole::query()->where('slug', 'coiffeur')->firstOrFail();

    $this->actingAs($manager)
        ->postJson(route('settings.roles_permissions.roles.duplicate', $systemRole), [
            'name' => 'Coiffeur senior',
        ])
        ->assertCreated()
        ->assertJsonPath('role.name', 'Coiffeur senior')
        ->assertJsonPath('role.company_id', $owner->id)
        ->assertJsonPath('role.is_system', false);

    $this->actingAs($manager)
        ->deleteJson(route('settings.roles_permissions.roles.destroy', $roleId))
        ->assertOk()
        ->assertJsonPath('message', 'Role deleted.');

    $this->assertDatabaseMissing('company_roles', [
        'id' => $roleId,
    ]);
});

it('prevents deleting protected system roles and custom roles currently used by members', function () {
    $this->seed(RbacSeeder::class);

    $owner = rbacPhaseThreeOwner();
    $manager = rbacPhaseThreeMember($owner, ['manage_roles_permissions']);
    $systemOwnerRole = CompanyRole::query()->where('slug', 'owner')->firstOrFail();

    $this->actingAs($manager)
        ->deleteJson(route('settings.roles_permissions.roles.destroy', $systemOwnerRole))
        ->assertForbidden();

    $customRole = rbacPhaseThreeCustomRole($owner, 'Gestionnaire accueil');
    $assignedMember = rbacPhaseThreeMember($owner);
    TeamMember::query()
        ->where('user_id', $assignedMember->id)
        ->where('account_id', $owner->id)
        ->update(['company_role_id' => $customRole->id]);

    $this->actingAs($manager)
        ->deleteJson(route('settings.roles_permissions.roles.destroy', $customRole))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Role is assigned to team members and cannot be deleted.');
});

it('allows assigning a company role to a team member when assign roles permission is present', function () {
    $this->seed(RbacSeeder::class);

    $owner = rbacPhaseThreeOwner();
    $manager = rbacPhaseThreeMember($owner, [
        'view_team_members',
        'update_team_members',
        'assign_roles',
    ]);
    $targetUser = rbacPhaseThreeMember($owner);
    $targetMembership = TeamMember::query()
        ->where('account_id', $owner->id)
        ->where('user_id', $targetUser->id)
        ->firstOrFail();
    $role = rbacPhaseThreeCustomRole($owner, 'Coiffeur senior');

    $this->actingAs($manager)
        ->putJson(route('team.update', $targetMembership), [
            'company_role_id' => $role->id,
        ])
        ->assertOk()
        ->assertJsonPath('team_member.company_role_id', $role->id);

    $this->assertDatabaseHas('team_members', [
        'id' => $targetMembership->id,
        'company_role_id' => $role->id,
    ]);
});

it('blocks changing a team member role without assign roles permission', function () {
    $this->seed(RbacSeeder::class);

    $owner = rbacPhaseThreeOwner();
    $manager = rbacPhaseThreeMember($owner, [
        'view_team_members',
        'update_team_members',
    ]);
    $targetUser = rbacPhaseThreeMember($owner);
    $targetMembership = TeamMember::query()
        ->where('account_id', $owner->id)
        ->where('user_id', $targetUser->id)
        ->firstOrFail();
    $role = rbacPhaseThreeCustomRole($owner, 'Assistant reservations');

    $this->actingAs($manager)
        ->putJson(route('team.update', $targetMembership), [
            'company_role_id' => $role->id,
        ])
        ->assertForbidden();

    $this->assertDatabaseHas('team_members', [
        'id' => $targetMembership->id,
        'company_role_id' => null,
    ]);
});

it('allows updating another team field without assign roles when the role is unchanged', function () {
    $this->seed(RbacSeeder::class);

    $owner = rbacPhaseThreeOwner();
    $manager = rbacPhaseThreeMember($owner, [
        'view_team_members',
        'update_team_members',
    ]);
    $targetUser = rbacPhaseThreeMember($owner);
    $targetMembership = TeamMember::query()
        ->where('account_id', $owner->id)
        ->where('user_id', $targetUser->id)
        ->firstOrFail();

    $this->actingAs($manager)
        ->putJson(route('team.update', $targetMembership), [
            'company_role_id' => null,
            'title' => 'Styliste',
        ])
        ->assertOk()
        ->assertJsonPath('team_member.title', 'Styliste');
});
