<?php

use App\Models\PlatformAdmin;
use App\Models\PlatformSetting;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanyFeatureService;
use App\Support\PlatformPermissions;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
});

function superAdminModuleRoleId(string $name, string $description): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $description]
    )->id;
}

function superAdminModuleTenantOwner(): User
{
    return User::query()->create([
        'name' => 'Tenant Owner',
        'email' => 'tenant-owner@example.com',
        'password' => 'password',
        'role_id' => superAdminModuleRoleId('owner', 'Account owner role'),
        'company_type' => 'services',
        'onboarding_completed_at' => now(),
        'company_features' => [
            'reservations' => false,
        ],
    ]);
}

function superAdminModulePlatformAdmin(array $permissions): User
{
    $user = User::query()->create([
        'name' => 'Platform Admin',
        'email' => 'platform-admin@example.com',
        'password' => 'password',
        'role_id' => superAdminModuleRoleId('admin', 'Platform admin role'),
        'onboarding_completed_at' => now(),
    ]);

    PlatformAdmin::query()->create([
        'user_id' => $user->id,
        'role' => 'ops',
        'permissions' => $permissions,
        'is_active' => true,
        'require_2fa' => false,
    ]);

    return $user;
}

function superAdminModuleSuperadmin(): User
{
    return User::query()->create([
        'name' => 'Root User',
        'email' => 'root-user@example.com',
        'password' => 'password',
        'role_id' => superAdminModuleRoleId('superadmin', 'Superadmin role'),
        'onboarding_completed_at' => now(),
    ]);
}

function superAdminModuleFirstPlanKey(): string
{
    $plans = config('billing.plans', []);

    return (string) (array_key_first($plans) ?? 'free');
}

it('forbids platform admins from updating tenant feature flags', function () {
    $tenant = superAdminModuleTenantOwner();
    $platformAdmin = superAdminModulePlatformAdmin([PlatformPermissions::TENANTS_MANAGE]);

    $this->actingAs($platformAdmin)
        ->withSession(['two_factor_passed' => true])
        ->put(route('superadmin.tenants.features.update', $tenant), [
            'features' => [
                'reservations' => true,
            ],
        ])
        ->assertRedirect()
        ->assertSessionHas('warning');

    expect($tenant->fresh()->company_features['reservations'] ?? null)->toBeFalse();
});

it('allows superadmins to update tenant feature flags', function () {
    $tenant = superAdminModuleTenantOwner();
    $superadmin = superAdminModuleSuperadmin();

    $this->actingAs($superadmin)
        ->withSession(['two_factor_passed' => true])
        ->put(route('superadmin.tenants.features.update', $tenant), [
            'features' => [
                'reservations' => true,
            ],
        ])
        ->assertRedirect();

    expect($tenant->fresh()->company_features['reservations'] ?? null)->toBeTrue();
});

it('forbids platform admins from updating plan modules through api', function () {
    $planKey = superAdminModuleFirstPlanKey();
    $platformAdmin = superAdminModulePlatformAdmin([PlatformPermissions::SETTINGS_MANAGE]);

    PlatformSetting::setValue('plan_modules', [
        $planKey => ['reservations' => true],
    ]);

    Sanctum::actingAs($platformAdmin);

    $this->putJson('/api/v1/super-admin/settings', [
        'maintenance' => [
            'enabled' => false,
            'message' => '',
        ],
        'templates' => [
            'email_default' => '',
            'quote_default' => '',
            'invoice_default' => '',
        ],
        'plan_modules' => [
            $planKey => [
                'reservations' => false,
            ],
        ],
    ])->assertForbidden();

    $saved = PlatformSetting::getValue('plan_modules', []);
    expect((bool) ($saved[$planKey]['reservations'] ?? false))->toBeTrue();
});

it('allows superadmins to update plan modules through api', function () {
    $planKey = superAdminModuleFirstPlanKey();
    $superadmin = superAdminModuleSuperadmin();

    PlatformSetting::setValue('plan_modules', [
        $planKey => ['reservations' => true],
    ]);

    Sanctum::actingAs($superadmin);

    $this->putJson('/api/v1/super-admin/settings', [
        'maintenance' => [
            'enabled' => false,
            'message' => '',
        ],
        'templates' => [
            'email_default' => '',
            'quote_default' => '',
            'invoice_default' => '',
        ],
        'plan_modules' => [
            $planKey => [
                'reservations' => false,
            ],
        ],
    ])->assertOk();

    $saved = PlatformSetting::getValue('plan_modules', []);
    expect((bool) ($saved[$planKey]['reservations'] ?? true))->toBeFalse();
});

it('applies sector feature defaults for salon and non-salon businesses', function () {
    $planKey = superAdminModuleFirstPlanKey();
    PlatformSetting::setValue('plan_modules', [
        $planKey => [
            'quotes' => true,
            'requests' => true,
            'reservations' => true,
            'plan_scans' => true,
            'jobs' => true,
            'products' => true,
            'tasks' => true,
            'services' => true,
        ],
    ]);

    $salonOwner = User::query()->create([
        'name' => 'Salon Owner',
        'email' => 'salon-owner@example.com',
        'password' => 'password',
        'role_id' => superAdminModuleRoleId('owner', 'Account owner role'),
        'company_type' => 'services',
        'company_sector' => 'salon',
        'onboarding_completed_at' => now(),
        'trial_ends_at' => now()->addDays(14),
        'company_features' => [],
    ]);

    $serviceOwner = User::query()->create([
        'name' => 'Service Owner',
        'email' => 'service-owner@example.com',
        'password' => 'password',
        'role_id' => superAdminModuleRoleId('owner', 'Account owner role'),
        'company_type' => 'services',
        'company_sector' => 'service_general',
        'onboarding_completed_at' => now(),
        'trial_ends_at' => now()->addDays(14),
        'company_features' => [],
    ]);

    $service = app(CompanyFeatureService::class);
    $salonFeatures = $service->resolveEffectiveFeatures($salonOwner);
    $serviceFeatures = $service->resolveEffectiveFeatures($serviceOwner);

    expect((bool) ($salonFeatures['reservations'] ?? false))->toBeTrue();
    expect((bool) ($salonFeatures['requests'] ?? true))->toBeFalse();
    expect((bool) ($salonFeatures['products'] ?? true))->toBeFalse();
    expect((bool) ($salonFeatures['quotes'] ?? true))->toBeFalse();
    expect((bool) ($salonFeatures['plan_scans'] ?? true))->toBeFalse();
    expect((bool) ($salonFeatures['jobs'] ?? true))->toBeFalse();
    expect((bool) ($salonFeatures['tasks'] ?? true))->toBeFalse();

    expect((bool) ($serviceFeatures['reservations'] ?? true))->toBeFalse();
    expect((bool) ($serviceFeatures['quotes'] ?? false))->toBeTrue();
});

it('allows superadmin feature overrides to supersede sector defaults', function () {
    $planKey = superAdminModuleFirstPlanKey();
    PlatformSetting::setValue('plan_modules', [
        $planKey => [
            'reservations' => true,
            'quotes' => true,
        ],
    ]);

    $tenant = User::query()->create([
        'name' => 'Restaurant Owner',
        'email' => 'restaurant-owner@example.com',
        'password' => 'password',
        'role_id' => superAdminModuleRoleId('owner', 'Account owner role'),
        'company_type' => 'services',
        'company_sector' => 'restaurant',
        'onboarding_completed_at' => now(),
        'trial_ends_at' => now()->addDays(14),
        'company_features' => [
            'quotes' => true,
            'reservations' => false,
        ],
    ]);

    $features = app(CompanyFeatureService::class)->resolveEffectiveFeatures($tenant);

    expect((bool) ($features['quotes'] ?? false))->toBeTrue();
    expect((bool) ($features['reservations'] ?? true))->toBeFalse();
});
