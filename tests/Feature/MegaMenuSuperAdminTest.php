<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\MegaMenu;
use App\Models\PlatformAdmin;
use App\Models\Role;
use App\Models\User;
use App\Support\PlatformPermissions;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
});

function megaMenuFeatureRoleId(string $name): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => ucfirst($name).' role']
    )->id;
}

function megaMenuPlatformAdmin(array $permissions = []): User
{
    $user = User::query()->create([
        'name' => 'Platform Admin',
        'email' => 'mega-menu-admin@example.com',
        'password' => 'password',
        'role_id' => megaMenuFeatureRoleId('admin'),
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

function megaMenuSuperadmin(): User
{
    return User::query()->create([
        'name' => 'Superadmin',
        'email' => 'mega-menu-root@example.com',
        'password' => 'password',
        'role_id' => megaMenuFeatureRoleId('superadmin'),
        'onboarding_completed_at' => now(),
    ]);
}

function megaMenuOwner(): User
{
    return User::query()->create([
        'name' => 'Tenant Owner',
        'email' => 'mega-menu-owner@example.com',
        'password' => 'password',
        'role_id' => megaMenuFeatureRoleId('owner'),
        'company_type' => 'services',
        'onboarding_completed_at' => now(),
    ]);
}

function megaMenuStorePayload(array $overrides = []): array
{
    $payload = [
        'title' => 'Admin Header',
        'slug' => 'admin-header',
        'status' => 'draft',
        'display_location' => 'header',
        'custom_zone' => null,
        'description' => 'Admin managed mega menu',
        'css_classes' => 'admin-header',
        'ordering' => 1,
        'settings' => [
            'theme' => 'default',
            'container_width' => 'xl',
            'accent_color' => '#16a34a',
            'panel_background' => '#ffffff',
            'open_on_hover' => true,
            'show_dividers' => true,
        ],
        'items' => [
            [
                'label' => 'Resources',
                'description' => '',
                'link_type' => 'none',
                'link_value' => null,
                'link_target' => '_self',
                'panel_type' => 'mega',
                'icon' => '',
                'badge_text' => '',
                'badge_variant' => null,
                'is_visible' => true,
                'css_classes' => '',
                'settings' => [
                    'eyebrow' => '',
                    'note' => '',
                    'featured' => false,
                    'highlight_color' => '',
                ],
                'children' => [],
                'columns' => [
                    [
                        'title' => 'Links',
                        'width' => '1fr',
                        'css_classes' => '',
                        'settings' => [
                            'alignment' => 'start',
                            'background_color' => '',
                        ],
                        'blocks' => [
                            [
                                'type' => 'navigation_group',
                                'title' => 'Primary',
                                'css_classes' => '',
                                'settings' => [
                                    'tone' => 'default',
                                    'show_border' => false,
                                ],
                                'payload' => [
                                    'title' => 'Primary',
                                    'description' => '',
                                    'links' => [
                                        ['label' => 'Pricing', 'href' => '/pricing', 'note' => '', 'badge' => '', 'target' => '_self'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    return array_replace_recursive($payload, $overrides);
}

it('forbids non platform admins from accessing mega menu management', function () {
    $owner = megaMenuOwner();

    $this->actingAs($owner)
        ->from('/dashboard')
        ->get(route('superadmin.mega-menus.index'))
        ->assertRedirect('/dashboard')
        ->assertSessionHas('warning');
});

it('forbids platform admins without mega menu permission from accessing the index', function () {
    $admin = megaMenuPlatformAdmin([PlatformPermissions::PAGES_MANAGE]);

    $this->actingAs($admin)
        ->from('/dashboard')
        ->get(route('superadmin.mega-menus.index'))
        ->assertRedirect('/dashboard')
        ->assertSessionHas('warning');
});

it('allows platform admins with mega menu permission to access the index', function () {
    MegaMenu::query()->create([
        'title' => 'Existing Menu',
        'slug' => 'existing-menu',
        'status' => 'draft',
        'display_location' => 'header',
        'description' => 'Existing menu',
        'ordering' => 1,
        'settings' => [],
    ]);

    $admin = megaMenuPlatformAdmin([PlatformPermissions::MEGA_MENUS_MANAGE]);

    $this->actingAs($admin)
        ->get(route('superadmin.mega-menus.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('SuperAdmin/MegaMenus/Index')
            ->has('menus', 1)
        );
});

it('allows superadmins to create a mega menu', function () {
    $superadmin = megaMenuSuperadmin();

    $this->actingAs($superadmin)
        ->post(route('superadmin.mega-menus.store'), megaMenuStorePayload())
        ->assertRedirect();

    expect(MegaMenu::query()->where('slug', 'admin-header')->exists())->toBeTrue();
});
