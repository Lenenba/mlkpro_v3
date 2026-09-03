<?php

use App\Models\CompanyRole;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Rbac\AccessControl;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Notification;

function rbacPhaseThreeEmployeeRoleId(): int
{
    return Role::query()->firstOrCreate(
        ['name' => 'employee'],
        ['description' => 'Employee role']
    )->id;
}

function rbacPhaseThreeOwner(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'company_type' => 'services',
        'company_sector' => 'salon',
        'company_features' => [
            'services' => true,
            'team_members' => true,
            'presence' => true,
            'reservations' => true,
            'sales' => true,
            'tasks' => true,
        ],
        'selected_plan_key' => null,
    ], $attributes));
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

it('scopes shared system role member counts to the current tenant', function () {
    $this->seed(RbacSeeder::class);

    $firstOwner = rbacPhaseThreeOwner();
    $firstManager = rbacPhaseThreeMember($firstOwner, ['manage_roles_permissions']);
    $firstMember = rbacPhaseThreeMember($firstOwner);
    $secondOwner = rbacPhaseThreeOwner();
    $secondMember = rbacPhaseThreeMember($secondOwner);
    $systemRole = CompanyRole::query()->where('slug', 'coiffeur')->firstOrFail();

    TeamMember::query()
        ->whereIn('user_id', [$firstMember->id, $secondMember->id])
        ->update(['company_role_id' => $systemRole->id]);

    $response = $this->actingAs($firstManager)
        ->getJson(route('settings.roles_permissions.edit'))
        ->assertOk();
    $systemRolePayload = collect($response->json('roles'))->firstWhere('id', $systemRole->id);

    expect($systemRolePayload['members_count'] ?? null)->toBe(1);
});

it('only exposes catalog permissions for enabled tenant modules while keeping core groups', function () {
    $this->seed(RbacSeeder::class);

    $owner = rbacPhaseThreeOwner([
        'company_sector' => 'service_general',
        'company_features' => [
            'services' => false,
            'reservations' => true,
            'products' => false,
            'sales' => false,
            'team_members' => false,
            'presence' => false,
            'performance' => false,
            'campaigns' => false,
            'jobs' => false,
            'tasks' => false,
            'quotes' => false,
            'requests' => false,
            'social' => false,
            'invoices' => false,
            'expenses' => false,
            'accounting' => false,
        ],
    ]);
    $unavailablePermission = Permission::query()->where('slug', 'view_tasks')->firstOrFail();
    $orphanedPermission = Permission::query()->create([
        'group' => 'legacy',
        'name' => 'Legacy permission',
        'slug' => 'legacy_permission',
        'description' => null,
    ]);
    $customRole = rbacPhaseThreeCustomRole($owner, 'Accueil limité');
    $customRole->permissions()->syncWithoutDetaching([
        $unavailablePermission->id,
        $orphanedPermission->id,
    ]);

    $response = $this->actingAs($owner)
        ->getJson(route('settings.roles_permissions.edit'))
        ->assertOk();
    $permissionGroups = collect($response->json('permissions'));
    $rolePayload = collect($response->json('roles'))->firstWhere('id', $customRole->id);

    expect($permissionGroups->pluck('group')->all())->toBe([
        'chairs',
        'clients',
        'reservations',
        'settings',
    ])->and($permissionGroups->pluck('permissions')->flatten(1)->pluck('slug')->all())
        ->not->toContain('view_tasks', 'legacy_permission')
        ->and($rolePayload['permissions'] ?? [])->toBe([]);

    $this->actingAs($owner)
        ->postJson(route('settings.roles_permissions.roles.store'), [
            'name' => 'Ancien droit interdit',
            'permissions' => ['legacy_permission'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('permissions.0');

    $this->assertDatabaseMissing('company_roles', [
        'company_id' => $owner->id,
        'name' => 'Ancien droit interdit',
    ]);
});

it('filters finance and storefront permissions at permission level', function () {
    $this->seed(RbacSeeder::class);

    $owner = rbacPhaseThreeOwner([
        'company_type' => 'products',
        'company_sector' => 'retail',
        'company_features' => [
            'products' => true,
            'services' => false,
            'invoices' => true,
            'expenses' => false,
            'accounting' => false,
        ],
    ]);

    $response = $this->actingAs($owner)
        ->getJson(route('settings.roles_permissions.edit'))
        ->assertOk();
    $permissionGroups = collect($response->json('permissions'))->keyBy('group');

    expect(collect($permissionGroups['finance']['permissions'])->pluck('slug')->all())
        ->toEqualCanonicalizing([
            'view_invoices',
            'create_invoices',
            'update_invoices',
            'approve_invoices',
            'approve_high_value_invoices',
        ])
        ->and(collect($permissionGroups['storefront']['permissions'])->pluck('slug')->all())
        ->toEqualCanonicalizing([
            'view_storefront',
            'manage_storefront',
            'manage_public_products',
        ]);
});

it('labels enabled social permissions as Pulse and hides them when the module is disabled', function () {
    $this->seed(RbacSeeder::class);

    $enabledOwner = rbacPhaseThreeOwner([
        'company_sector' => 'service_general',
        'company_features' => [
            'social' => true,
        ],
    ]);
    $disabledOwner = rbacPhaseThreeOwner([
        'company_sector' => 'service_general',
        'company_features' => [
            'social' => false,
        ],
    ]);

    $enabledResponse = $this->actingAs($enabledOwner)
        ->getJson(route('settings.roles_permissions.edit'))
        ->assertOk();
    $pulseGroup = collect($enabledResponse->json('permissions'))->firstWhere('group', 'social');

    expect($pulseGroup['label'] ?? null)->toBe('Pulse')
        ->and(collect($pulseGroup['permissions'] ?? [])->pluck('slug')->all())
        ->toEqualCanonicalizing([
            'view_social',
            'manage_social',
            'publish_social',
            'approve_social',
        ]);

    $roleResponse = $this->actingAs($enabledOwner)
        ->postJson(route('settings.roles_permissions.roles.store'), [
            'name' => 'Éditeur Pulse',
            'permissions' => ['view_social', 'publish_social'],
        ])
        ->assertCreated();
    $delegatedRole = CompanyRole::query()->findOrFail($roleResponse->json('role.id'));

    expect($roleResponse->json('role.permissions'))
        ->toEqualCanonicalizing(['view_social', 'publish_social'])
        ->and($delegatedRole->permissions()->pluck('slug')->all())
        ->toEqualCanonicalizing(['view_social', 'publish_social']);

    $disabledResponse = $this->actingAs($disabledOwner)
        ->getJson(route('settings.roles_permissions.edit'))
        ->assertOk();

    expect(collect($disabledResponse->json('permissions'))->pluck('group'))
        ->not->toContain('social');
});

it('rejects unavailable permissions and purges hidden permissions when saving a role', function () {
    $this->seed(RbacSeeder::class);

    $owner = rbacPhaseThreeOwner([
        'company_sector' => 'service_general',
        'company_features' => [
            'tasks' => true,
            'reservations' => false,
        ],
    ]);

    $this->actingAs($owner)
        ->postJson(route('settings.roles_permissions.roles.store'), [
            'name' => 'Réservations interdites',
            'permissions' => ['view_reservations'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('permissions.0');

    $this->assertDatabaseMissing('company_roles', [
        'company_id' => $owner->id,
        'name' => 'Réservations interdites',
    ]);

    $customRole = rbacPhaseThreeCustomRole($owner, 'Opérations limitées');
    $customRole->permissions()->sync(
        Permission::query()
            ->whereIn('slug', ['view_clients', 'view_reservations'])
            ->pluck('id')
            ->all()
    );

    $this->actingAs($owner)
        ->putJson(route('settings.roles_permissions.roles.update', $customRole), [
            'name' => 'Opérations limitées',
            'description' => 'Le droit masqué doit être supprimé.',
            'is_active' => true,
            'permissions' => ['view_clients', 'view_reservations'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('permissions.1');

    expect($customRole->fresh()->permissions()->pluck('slug')->all())
        ->toEqualCanonicalizing(['view_clients', 'view_reservations']);

    $this->actingAs($owner)
        ->putJson(route('settings.roles_permissions.roles.update', $customRole), [
            'name' => 'Opérations limitées',
            'description' => 'Le droit masqué doit être supprimé.',
            'is_active' => true,
            'permissions' => ['view_clients'],
        ])
        ->assertOk()
        ->assertJsonPath('role.permissions', ['view_clients']);

    expect($customRole->fresh()->permissions()->pluck('slug')->all())->toBe(['view_clients']);
});

it('only copies permissions available to the tenant when duplicating a role', function () {
    $this->seed(RbacSeeder::class);

    $owner = rbacPhaseThreeOwner([
        'company_sector' => 'service_general',
        'company_features' => [
            'services' => false,
            'reservations' => false,
            'products' => false,
            'sales' => false,
            'team_members' => false,
            'presence' => false,
            'performance' => false,
            'jobs' => false,
            'tasks' => false,
            'quotes' => false,
            'requests' => false,
        ],
    ]);
    $systemRole = CompanyRole::query()->where('slug', 'manager')->firstOrFail();

    $response = $this->actingAs($owner)
        ->postJson(route('settings.roles_permissions.roles.duplicate', $systemRole), [
            'name' => 'Gestionnaire limité',
        ])
        ->assertCreated();
    $copy = CompanyRole::query()->findOrFail($response->json('role.id'));
    $copiedPermissions = $copy->permissions()->get(['group', 'slug']);

    expect($copiedPermissions)->not->toBeEmpty()
        ->and($copiedPermissions->pluck('group')->unique()->values()->all())->toBe(['clients'])
        ->and($copiedPermissions->pluck('slug')->all())->not->toContain('view_tasks', 'view_reservations');
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
        ->putJson(route('settings.roles_permissions.roles.update', $customRole), [
            'name' => 'Gestionnaire accueil',
            'description' => 'Role assigned to one member.',
            'is_active' => true,
            'permissions' => ['view_team_members'],
        ])
        ->assertOk()
        ->assertJsonPath('role.members_count', 1);

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

it('hides orphaned custom roles and rejects assigning them to a team member', function () {
    $this->seed(RbacSeeder::class);

    $owner = rbacPhaseThreeOwner();
    $manager = rbacPhaseThreeMember($owner, [
        'view_team_members',
        'update_team_members',
        'assign_roles',
        'manage_roles_permissions',
    ]);
    $targetUser = rbacPhaseThreeMember($owner);
    $targetMembership = TeamMember::query()
        ->where('account_id', $owner->id)
        ->where('user_id', $targetUser->id)
        ->firstOrFail();
    $orphanedRole = CompanyRole::query()->create([
        'company_id' => null,
        'name' => 'Orphaned tenant role',
        'slug' => 'orphaned_tenant_role',
        'description' => 'Must never be exposed as a global role.',
        'is_system' => false,
        'is_default' => false,
        'is_editable' => true,
        'is_deletable' => true,
        'is_active' => true,
    ]);

    $settingsResponse = $this->actingAs($manager)
        ->getJson(route('settings.roles_permissions.edit'))
        ->assertOk();

    expect(collect($settingsResponse->json('roles'))->pluck('id')->all())
        ->not->toContain($orphanedRole->id);

    $this->actingAs($manager)
        ->putJson(route('team.update', $targetMembership), [
            'company_role_id' => $orphanedRole->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('company_role_id');
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

it('uses only the assigned access role even when legacy admin and direct permissions are stale', function () {
    $this->seed(RbacSeeder::class);

    $owner = rbacPhaseThreeOwner();
    $member = rbacPhaseThreeMember($owner, ['view_tasks']);
    $membership = TeamMember::query()
        ->where('account_id', $owner->id)
        ->where('user_id', $member->id)
        ->firstOrFail();

    $membership->update([
        'role' => 'admin',
        'permissions' => [
            'view_clients',
            'view_products',
            'manage_roles_permissions',
            'admin',
        ],
    ]);
    $membership = $membership->fresh('companyRole.permissions');

    expect($membership->resolvedPermissions())->toBe(['view_tasks'])
        ->and($membership->hasPermission('view_tasks'))->toBeTrue()
        ->and($membership->hasPermission('view_clients'))->toBeFalse()
        ->and($membership->hasPermission('view_products'))->toBeFalse()
        ->and($membership->hasPermission('manage_roles_permissions'))->toBeFalse()
        ->and($membership->hasPermission('admin'))->toBeFalse()
        ->and(app(AccessControl::class)->userHasPermission($member, 'view_clients', $owner->id))->toBeFalse()
        ->and(app(AccessControl::class)->userHasPermission($member, 'view_products', $owner->id))->toBeFalse()
        ->and(app(AccessControl::class)->userHasPermission($member, 'manage_roles_permissions', $owner->id))->toBeFalse();

    $this->actingAs($member)
        ->getJson(route('settings.roles_permissions.edit'))
        ->assertForbidden();
});

it('does not fall back to direct permissions when an assigned access role is inactive', function () {
    $this->seed(RbacSeeder::class);

    $owner = rbacPhaseThreeOwner();
    $member = rbacPhaseThreeMember($owner, ['view_tasks']);
    $membership = TeamMember::query()
        ->where('account_id', $owner->id)
        ->where('user_id', $member->id)
        ->firstOrFail();

    $membership->update([
        'permissions' => ['view_clients', 'view_products', 'manage_roles_permissions'],
    ]);
    $membership->companyRole()->firstOrFail()->update(['is_active' => false]);
    $membership = $membership->fresh('companyRole.permissions');

    expect($membership->resolvedPermissions())->toBe([])
        ->and($membership->hasPermission('view_tasks'))->toBeFalse()
        ->and($membership->hasPermission('view_clients'))->toBeFalse()
        ->and($membership->hasPermission('view_products'))->toBeFalse()
        ->and($membership->hasPermission('manage_roles_permissions'))->toBeFalse()
        ->and(app(AccessControl::class)->userHasPermission($member, 'view_clients', $owner->id))->toBeFalse();
});

it('blocks an updater without role management permission from adding or clearing direct permissions', function () {
    $this->seed(RbacSeeder::class);

    $owner = rbacPhaseThreeOwner();
    $updater = rbacPhaseThreeMember($owner, ['update_team_members']);
    $targetUser = rbacPhaseThreeMember($owner);
    $targetMembership = TeamMember::query()
        ->where('account_id', $owner->id)
        ->where('user_id', $targetUser->id)
        ->firstOrFail();
    $targetMembership->update(['permissions' => ['tasks.view']]);

    $this->actingAs($updater)
        ->putJson(route('team.update', $targetMembership), [
            'permissions' => ['tasks.create'],
        ])
        ->assertForbidden();

    expect($targetMembership->fresh()->permissions)->toBe(['tasks.view']);

    $this->actingAs($updater)
        ->putJson(route('team.update', $targetMembership), [
            'permissions' => [],
        ])
        ->assertForbidden();

    expect($targetMembership->fresh()->permissions)->toBe(['tasks.view']);
});

it('rejects combining an access role with non empty direct permissions on update and creation', function () {
    $this->seed(RbacSeeder::class);

    $owner = rbacPhaseThreeOwner();
    $manager = rbacPhaseThreeMember($owner, [
        'create_team_members',
        'update_team_members',
        'assign_roles',
        'manage_roles_permissions',
    ]);
    $targetUser = rbacPhaseThreeMember($owner);
    $targetMembership = TeamMember::query()
        ->where('account_id', $owner->id)
        ->where('user_id', $targetUser->id)
        ->firstOrFail();
    $role = rbacPhaseThreeCustomRole($owner, 'Role without direct permissions');

    $this->actingAs($manager)
        ->putJson(route('team.update', $targetMembership), [
            'company_role_id' => $role->id,
            'permissions' => ['tasks.view'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('permissions')
        ->assertJsonPath('errors.permissions.0', 'Direct permissions cannot be combined with an access role.');

    $targetMembership->refresh();

    expect($targetMembership->company_role_id)->toBeNull()
        ->and($targetMembership->permissions)->toBe([]);

    Notification::fake();
    $email = 'role-and-direct-permissions@example.test';

    $this->actingAs($manager)
        ->postJson(route('team.store'), [
            'name' => 'Invalid Mixed Access Member',
            'email' => $email,
            'role' => 'member',
            'company_role_id' => $role->id,
            'permissions' => ['tasks.view'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('permissions')
        ->assertJsonPath('errors.permissions.0', 'Direct permissions cannot be combined with an access role.');

    $this->assertDatabaseMissing('users', ['email' => $email]);
    Notification::assertNothingSent();
});

it('does not create an orphan user when a creator cannot configure legacy direct permissions', function () {
    $this->seed(RbacSeeder::class);

    $owner = rbacPhaseThreeOwner();
    $creator = rbacPhaseThreeMember($owner, ['create_team_members']);
    $email = 'legacy-member-without-access-role@example.test';
    $userCountBefore = User::query()->count();
    $membershipCountBefore = TeamMember::query()->count();

    Notification::fake();

    $this->actingAs($creator)
        ->postJson(route('team.store'), [
            'name' => 'Forbidden Legacy Member',
            'email' => $email,
            'role' => 'member',
        ])
        ->assertForbidden();

    expect(User::query()->count())->toBe($userCountBefore)
        ->and(TeamMember::query()->count())->toBe($membershipCountBefore);

    $this->assertDatabaseMissing('users', ['email' => $email]);
    Notification::assertNothingSent();
});
