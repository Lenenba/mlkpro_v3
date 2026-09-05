<?php

use App\Models\CompanyRole;
use App\Models\MegaMenu;
use App\Models\Permission;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('seeds only the minimal platform baseline for launch reset', function () {
    $this->seed(\Database\Seeders\LaunchResetSeeder::class);

    expect(User::query()->where('email', 'superadmin@example.com')->exists())->toBeTrue();
    expect(User::query()->where('email', 'platform.admin@example.com')->exists())->toBeTrue();
    expect(PlatformAdmin::query()->where('user_id', User::query()->where('email', 'platform.admin@example.com')->value('id'))->exists())->toBeTrue();
    expect(User::query()->where('is_demo', true)->exists())->toBeFalse();
    expect(User::query()->whereIn('email', [
        'owner.services@example.com',
        'owner.products@example.com',
        'owner.salon@example.com',
    ])->exists())->toBeFalse();
    expect(MegaMenu::query()->where('slug', 'main-header-menu')->exists())->toBeTrue();

    $catalog = app(PermissionCatalog::class);
    $ownerRole = CompanyRole::query()
        ->whereNull('company_id')
        ->where('slug', 'owner')
        ->firstOrFail();

    expect(Permission::query()->count())->toBe(count($catalog->permissions()))
        ->and(CompanyRole::query()->whereNull('company_id')->where('is_system', true)->count())
        ->toBe(count($catalog->defaultRoles()))
        ->and($ownerRole->permissions()->count())->toBe(count($catalog->permissions()));
});

it('keeps the RBAC baseline idempotent across repeated launch resets', function () {
    $this->seed(\Database\Seeders\LaunchResetSeeder::class);

    $firstCounts = [
        'permissions' => Permission::query()->count(),
        'roles' => CompanyRole::query()->whereNull('company_id')->where('is_system', true)->count(),
        'assignments' => DB::table('company_role_permission')->count(),
    ];

    $this->seed(\Database\Seeders\LaunchResetSeeder::class);

    expect([
        'permissions' => Permission::query()->count(),
        'roles' => CompanyRole::query()->whereNull('company_id')->where('is_system', true)->count(),
        'assignments' => DB::table('company_role_permission')->count(),
    ])->toBe($firstCounts);
});
