<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\CompanyRole;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function teamPermissionRoleId(string $name): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $name.' role']
    )->id;
}

function teamPermissionOwner(array $attributes = []): User
{
    $defaults = [
        'name' => 'Team Permission Owner',
        'email' => 'owner-'.Str::lower(Str::random(10)).'@example.com',
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
            'email' => 'member-'.Str::lower(Str::random(10)).'@example.com',
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
            'email' => 'member-default-'.Str::lower(Str::random(10)).'@example.com',
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

test('team member page exposes finance permissions when expenses and invoices are enabled', function () {
    $owner = teamPermissionOwner([
        'company_features' => [
            'team_members' => true,
            'tasks' => false,
            'expenses' => true,
            'accounting' => true,
            'invoices' => true,
        ],
    ]);

    $this->actingAs($owner)
        ->get(route('team.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Team/Index')
            ->where('availablePermissions', function ($permissions) {
                $ids = collect($permissions)->pluck('id')->all();

                return collect([
                    'expenses.view',
                    'expenses.create',
                    'expenses.edit',
                    'expenses.approve',
                    'expenses.approve_high',
                    'expenses.pay',
                    'accounting.view',
                    'accounting.manage',
                    'invoices.view',
                    'invoices.create',
                    'invoices.edit',
                    'invoices.approve',
                    'invoices.approve_high',
                ])->every(fn ($id) => in_array($id, $ids, true));
            })
        );
});

test('team member page exposes Pulse permissions when the social module is enabled', function () {
    $owner = teamPermissionOwner([
        'company_features' => [
            'team_members' => true,
            'tasks' => false,
            'social' => true,
        ],
    ]);

    $this->actingAs($owner)
        ->get(route('team.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Team/Index')
            ->where('availablePermissions', function ($permissions) {
                $ids = collect($permissions)->pluck('id')->all();

                return collect([
                    'social.view',
                    'social.manage',
                    'social.publish',
                    'social.approve',
                ])->every(fn ($id) => in_array($id, $ids, true));
            })
        );
});

test('team member page exposes prospect permissions when the requests module is enabled', function () {
    $owner = teamPermissionOwner([
        'company_features' => [
            'team_members' => true,
            'tasks' => false,
            'requests' => true,
        ],
    ]);

    $this->actingAs($owner)
        ->get(route('team.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Team/Index')
            ->where('availablePermissions', function ($permissions) {
                $ids = collect($permissions)->pluck('id')->all();

                return collect([
                    'prospects.view',
                    'prospects.create',
                    'prospects.edit',
                    'prospects.assign',
                    'prospects.convert',
                    'prospects.merge',
                    'prospects.export',
                ])->every(fn ($id) => in_array($id, $ids, true));
            })
        );
});

test('team member page filters role permissions by enabled tenant modules', function () {
    $this->seed(RbacSeeder::class);

    $owner = teamPermissionOwner([
        'company_features' => [
            'team_members' => true,
            'tasks' => false,
            'requests' => false,
            'social' => true,
        ],
    ]);
    $role = CompanyRole::query()->create([
        'company_id' => $owner->id,
        'name' => 'Éditeur Pulse',
        'slug' => 'editeur-pulse',
        'description' => 'Pulse sans accès aux prospects.',
        'is_system' => false,
        'is_default' => false,
        'is_editable' => true,
        'is_deletable' => true,
        'is_active' => true,
    ]);
    $role->permissions()->sync(
        Permission::query()
            ->whereIn('slug', ['view_prospects', 'view_social'])
            ->pluck('id')
            ->all()
    );
    $memberUser = User::factory()->create([
        'role_id' => teamPermissionRoleId('employee'),
    ]);
    $membership = TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $memberUser->id,
        'role' => 'member',
        'company_role_id' => $role->id,
        'permissions' => [],
        'is_active' => true,
    ]);

    $this->actingAs($owner)
        ->get(route('team.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Team/Index')
            ->where('companyRoles', function ($roles) use ($role) {
                $rolePayload = collect($roles)->firstWhere('id', $role->id);

                return ($rolePayload['permissions'] ?? []) === ['view_social'];
            })
            ->where('teamMembers.data', function ($members) use ($membership) {
                $memberPayload = collect($members)->firstWhere('id', $membership->id);
                $permissionSlugs = collect(data_get($memberPayload, 'company_role.permissions', []))
                    ->pluck('slug')
                    ->values()
                    ->all();

                return $permissionSlugs === ['view_social'];
            })
        );
});
