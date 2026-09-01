<?php

use App\Models\CompanyRole;
use App\Models\Permission;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Rbac\CompanyRoleTemplateCatalog;
use App\Services\Rbac\CompanyRoleTemplateProvisioner;
use Illuminate\Support\Facades\Notification;

function companyRoleTemplateOwner(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'company_type' => 'services',
        'company_sector' => 'salon',
        'company_features' => [
            'services' => true,
            'reservations' => true,
            'sales' => true,
            'team_members' => true,
            'presence' => true,
            'performance' => true,
            'jobs' => true,
            'tasks' => true,
            'quotes' => true,
            'requests' => true,
            'invoices' => true,
            'expenses' => true,
            'accounting' => true,
            'products' => true,
        ],
    ], $attributes));
}

test('the template catalog covers every onboarding sector and a custom sector fallback', function () {
    $catalog = app(CompanyRoleTemplateCatalog::class);
    $serviceSectors = [
        'salon',
        'restaurant',
        'service_general',
        'menuiserie',
        'plomberie',
        'electricite',
        'peinture',
        'toiture',
        'renovation',
        'paysagisme',
        'climatisation',
        'nettoyage',
    ];
    $productSectors = [
        'retail',
        'wholesale',
        'grocery',
        'convenience',
        'specialty',
        'pharmacy',
        'electronics',
        'home_hardware',
    ];

    foreach ($serviceSectors as $sector) {
        $templates = collect($catalog->templatesFor('services', $sector));

        expect($templates)->toHaveCount(4)
            ->and($templates->pluck('slug')->all())->toEqualCanonicalizing([
                'standard_manager',
                'standard_specialist',
                'standard_coordinator',
                'standard_accounting',
            ])
            ->and($catalog->invitationRoleSlugs('services', $sector))->toBe([
                'admin' => 'standard_manager',
                'member' => 'standard_specialist',
            ]);
    }

    foreach ($productSectors as $sector) {
        $templates = collect($catalog->templatesFor('products', $sector));

        expect($templates)->toHaveCount(4)
            ->and($templates->pluck('slug')->all())->toEqualCanonicalizing([
                'standard_manager',
                'standard_sales',
                'standard_inventory',
                'standard_accounting',
            ])
            ->and($catalog->invitationRoleSlugs('products', $sector))->toBe([
                'admin' => 'standard_manager',
                'member' => 'standard_sales',
            ]);
    }

    $customTemplates = collect($catalog->templatesFor('services', 'Photographie événementielle'));

    expect($customTemplates->firstWhere('slug', 'standard_specialist')['name'])->toBe('Spécialiste de service')
        ->and($customTemplates->firstWhere('slug', 'standard_manager')['description'])
        ->toContain('Photographie événementielle');
});

test('it creates independent editable standard roles tailored to each tenant sector', function () {
    $salonOwner = companyRoleTemplateOwner();
    $retailOwner = companyRoleTemplateOwner([
        'company_type' => 'products',
        'company_sector' => 'retail',
        'company_features' => [
            'products' => true,
            'sales' => true,
            'team_members' => true,
            'presence' => true,
            'performance' => true,
            'tasks' => true,
            'invoices' => true,
            'expenses' => true,
            'accounting' => true,
        ],
    ]);

    $provisioner = app(CompanyRoleTemplateProvisioner::class);
    $salonRoles = $provisioner->provision($salonOwner);
    $retailRoles = $provisioner->provision($retailOwner);

    expect($salonRoles)->toHaveCount(4)
        ->and($salonRoles->pluck('slug')->all())->toEqualCanonicalizing([
            'standard_manager',
            'standard_specialist',
            'standard_coordinator',
            'standard_accounting',
        ])
        ->and($salonRoles->pluck('name'))->toContain(
            'Gestionnaire de salon',
            'Professionnel coiffure et beauté',
            'Accueil et réception',
        )
        ->and($retailRoles)->toHaveCount(4)
        ->and($retailRoles->pluck('slug')->all())->toEqualCanonicalizing([
            'standard_manager',
            'standard_sales',
            'standard_inventory',
            'standard_accounting',
        ])
        ->and($retailRoles->pluck('name'))->toContain(
            'Gestionnaire de boutique',
            'Vente et caisse',
            'Inventaire et approvisionnement',
        );

    expect($salonRoles->every(fn (CompanyRole $role): bool => (
        (int) $role->company_id === $salonOwner->id
        && ! $role->is_system
        && $role->is_default
        && $role->is_editable
        && ! $role->is_deletable
    )))->toBeTrue();
    expect($retailRoles->every(fn (CompanyRole $role): bool => (
        (int) $role->company_id === $retailOwner->id
        && ! $role->is_system
        && $role->is_default
        && $role->is_editable
        && ! $role->is_deletable
    )))->toBeTrue();
    expect($salonRoles->pluck('id')->intersect($retailRoles->pluck('id')))->toBeEmpty();

    $salonSpecialistPermissions = $salonRoles
        ->firstWhere('slug', 'standard_specialist')
        ->permissions
        ->pluck('slug');
    $retailInventoryPermissions = $retailRoles
        ->firstWhere('slug', 'standard_inventory')
        ->permissions
        ->pluck('slug');
    $retailSalesPermissions = $retailRoles
        ->firstWhere('slug', 'standard_sales')
        ->permissions
        ->pluck('slug');

    expect($salonSpecialistPermissions)->toContain('view_own_reservations')
        ->not->toContain('manage_inventory')
        ->and($retailInventoryPermissions)->toContain('manage_inventory', 'adjust_stock')
        ->not->toContain('view_reservations')
        ->and($retailSalesPermissions)->toContain('view_products', 'create_sales', 'manage_cash_register')
        ->not->toContain('manage_inventory', 'adjust_stock', 'apply_discount');
});

test('it never overwrites a standard role after the tenant customizes it', function () {
    $owner = companyRoleTemplateOwner();
    $provisioner = app(CompanyRoleTemplateProvisioner::class);
    $manager = $provisioner->provision($owner)->firstWhere('slug', 'standard_manager');

    $response = $this->actingAs($owner)
        ->putJson(route('settings.roles_permissions.roles.update', $manager), [
            'name' => 'Direction personnalisée',
            'description' => 'Accès décidé par cette entreprise.',
            'is_active' => false,
            'permissions' => ['view_clients'],
        ])
        ->assertOk()
        ->assertJsonPath('role.name', 'Direction personnalisée')
        ->assertJsonPath('role.is_default', true)
        ->assertJsonPath('role.is_editable', true);

    expect($response->json('role.permissions'))->toBe(['view_clients']);

    $provisioner->provision($owner->fresh());
    $manager->refresh()->load('permissions');

    expect($manager->name)->toBe('Direction personnalisée')
        ->and($manager->description)->toBe('Accès décidé par cette entreprise.')
        ->and($manager->is_active)->toBeFalse()
        ->and($manager->permissions->pluck('slug')->all())->toBe(['view_clients']);
});

test('onboarding installs standard roles and assigns them to invited members', function () {
    Notification::fake();

    $owner = companyRoleTemplateOwner([
        'company_name' => null,
        'company_type' => 'services',
        'company_sector' => null,
        'onboarding_completed_at' => null,
    ]);

    $this->actingAs($owner)
        ->post(route('onboarding.store'), [
            'company_name' => 'Atelier Propre',
            'company_type' => 'services',
            'company_sector' => 'nettoyage',
            'invites' => [
                [
                    'name' => 'Responsable opérations',
                    'email' => 'responsable-operations@example.test',
                    'role' => 'admin',
                ],
                [
                    'name' => 'Spécialiste terrain',
                    'email' => 'specialiste-terrain@example.test',
                    'role' => 'member',
                ],
            ],
            'accept_terms' => true,
        ])
        ->assertRedirect(route('dashboard'));

    $roles = CompanyRole::query()
        ->where('company_id', $owner->id)
        ->where('is_default', true)
        ->get();
    $members = TeamMember::query()
        ->forAccount($owner->id)
        ->with(['user', 'companyRole.permissions'])
        ->get()
        ->keyBy(fn (TeamMember $member): ?string => $member->user?->email);

    expect($owner->fresh()->onboarding_completed_at)->not->toBeNull()
        ->and($roles)->toHaveCount(4)
        ->and($roles->pluck('name'))->toContain(
            'Gestionnaire des opérations',
            'Préposé à l’entretien',
            'Coordination des interventions',
            'Comptabilité',
        )
        ->and($members['responsable-operations@example.test']->companyRole?->slug)->toBe('standard_manager')
        ->and($members['specialiste-terrain@example.test']->companyRole?->slug)->toBe('standard_specialist')
        ->and($members['responsable-operations@example.test']->permissions)->toBe([])
        ->and($members['specialiste-terrain@example.test']->permissions)->toBe([])
        ->and($members['responsable-operations@example.test']->hasPermission('view_team_members'))->toBeTrue()
        ->and($members['responsable-operations@example.test']->hasPermission('update_team_members'))->toBeFalse()
        ->and($members['responsable-operations@example.test']->hasPermission('assign_roles'))->toBeFalse()
        ->and($members['specialiste-terrain@example.test']->hasPermission('view_jobs'))->toBeTrue()
        ->and($members['specialiste-terrain@example.test']->hasPermission('assign_roles'))->toBeFalse();

    $this->actingAs($members['responsable-operations@example.test']->user)
        ->putJson(route('team.update', $members['responsable-operations@example.test']), [
            'permissions' => ['manage_roles_permissions'],
        ])
        ->assertForbidden();

    expect(Permission::query()->count())->toBeGreaterThan(0);
});

test('resetting onboarding never reapplies or mixes templates over tenant roles', function () {
    Notification::fake();

    $owner = companyRoleTemplateOwner([
        'company_name' => null,
        'company_type' => 'services',
        'company_sector' => null,
        'onboarding_completed_at' => null,
    ]);

    $this->actingAs($owner)
        ->post(route('onboarding.store'), [
            'company_name' => 'Services Boréal',
            'company_type' => 'services',
            'company_sector' => 'nettoyage',
            'accept_terms' => true,
        ])
        ->assertRedirect(route('dashboard'));

    $manager = CompanyRole::query()
        ->where('company_id', $owner->id)
        ->where('slug', 'standard_manager')
        ->firstOrFail();
    $manager->update(['name' => 'Direction Boréal']);
    $owner->forceFill(['onboarding_completed_at' => null])->save();

    $this->actingAs($owner->fresh())
        ->post(route('onboarding.store'), [
            'company_name' => 'Boutique Boréal',
            'company_type' => 'products',
            'company_sector' => 'retail',
            'accept_terms' => true,
        ])
        ->assertRedirect(route('dashboard'));

    $tenantRoles = CompanyRole::query()
        ->where('company_id', $owner->id)
        ->get();

    expect($tenantRoles)->toHaveCount(4)
        ->and($manager->fresh()->name)->toBe('Direction Boréal')
        ->and($tenantRoles->pluck('slug'))->toContain('standard_specialist', 'standard_coordinator')
        ->not->toContain('standard_sales', 'standard_inventory');
});

test('it filters standard role permissions through the tenant enabled modules', function () {
    $owner = companyRoleTemplateOwner([
        'company_features' => [
            'services' => true,
            'reservations' => true,
            'sales' => false,
            'team_members' => false,
            'presence' => true,
            'performance' => false,
            'jobs' => false,
            'tasks' => false,
            'quotes' => false,
            'requests' => false,
            'invoices' => false,
            'expenses' => false,
            'accounting' => false,
            'products' => false,
        ],
    ]);

    $roles = app(CompanyRoleTemplateProvisioner::class)->provision($owner);
    $managerPermissions = $roles
        ->firstWhere('slug', 'standard_manager')
        ->permissions
        ->pluck('slug');

    expect($managerPermissions)->toContain('view_clients', 'view_services', 'view_reservations')
        ->not->toContain(
            'view_sales',
            'view_team_members',
            'view_reports',
            'view_tasks',
            'view_invoices',
        );
});
