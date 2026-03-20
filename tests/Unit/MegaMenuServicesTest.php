<?php

use App\Models\Role;
use App\Models\User;
use App\Services\MegaMenus\MegaMenuManagerService;
use App\Services\MegaMenus\MegaMenuRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function megaMenuTestRoleId(string $name): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => ucfirst($name).' role']
    )->id;
}

function megaMenuTestUser(): User
{
    return User::query()->create([
        'name' => 'Mega Menu User',
        'email' => 'mega-menu-user@example.com',
        'password' => 'password',
        'role_id' => megaMenuTestRoleId('superadmin'),
        'onboarding_completed_at' => now(),
    ]);
}

function megaMenuPayload(array $overrides = []): array
{
    $payload = [
        'title' => 'Main Header',
        'slug' => 'main-header',
        'status' => 'draft',
        'display_location' => 'header',
        'custom_zone' => null,
        'description' => 'Main site header menu',
        'css_classes' => 'main-header-menu',
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
                'label' => 'Platform',
                'description' => 'Platform entry point',
                'link_type' => 'none',
                'link_target' => '_self',
                'panel_type' => 'mega',
                'icon' => 'grid',
                'badge_text' => 'New',
                'badge_variant' => 'new',
                'is_visible' => true,
                'settings' => [
                    'eyebrow' => 'Platform',
                    'note' => 'Main navigation',
                    'featured' => true,
                    'highlight_color' => '#16a34a',
                ],
                'columns' => [
                    [
                        'title' => 'Primary',
                        'width' => '1fr',
                        'settings' => [
                            'alignment' => 'start',
                            'background_color' => '',
                        ],
                        'blocks' => [
                            [
                                'type' => 'navigation_group',
                                'title' => 'Explore',
                                'settings' => [
                                    'tone' => 'default',
                                    'show_border' => false,
                                ],
                                'payload' => [
                                    'title' => 'Explore',
                                    'description' => 'Primary links',
                                    'links' => [
                                        [
                                            'label' => 'Pricing',
                                            'href' => '/pricing',
                                            'note' => 'Plans and modules',
                                            'badge' => '',
                                            'target' => '_self',
                                        ],
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

it('deactivates the previously active menu in the same location when a new one is activated', function () {
    $user = megaMenuTestUser();
    $service = app(MegaMenuManagerService::class);

    $first = $service->create(megaMenuPayload([
        'slug' => 'header-one',
        'title' => 'Header One',
        'status' => 'active',
    ]), $user->id);

    $second = $service->create(megaMenuPayload([
        'slug' => 'header-two',
        'title' => 'Header Two',
        'status' => 'draft',
        'ordering' => 2,
    ]), $user->id);

    $service->changeStatus($second, 'active', $user->id);

    expect($first->fresh()->status)->toBe('inactive');
    expect($second->fresh()->status)->toBe('active');
});

it('duplicates nested structure into a new draft menu', function () {
    $user = megaMenuTestUser();
    $service = app(MegaMenuManagerService::class);

    $menu = $service->create(megaMenuPayload([
        'slug' => 'marketing-menu',
        'title' => 'Marketing Menu',
    ]), $user->id);

    $copy = $service->duplicate($menu, $user->id);

    expect($copy->id)->not->toBe($menu->id);
    expect($copy->status)->toBe('draft');
    expect($copy->slug)->not->toBe($menu->slug);
    expect($copy->items)->toHaveCount(1);
    expect($copy->items->first()->columns)->toHaveCount(1);
    expect($copy->items->first()->columns->first()->blocks)->toHaveCount(1);
});

it('persists product showcase blocks with hover preview items', function () {
    $user = megaMenuTestUser();
    $service = app(MegaMenuManagerService::class);

    $menu = $service->create(megaMenuPayload([
        'slug' => 'product-showcase-menu',
        'title' => 'Product Showcase Menu',
        'items' => [
            [
                'label' => 'Products & Services',
                'description' => 'Explore the product catalog',
                'link_type' => 'internal_page',
                'link_value' => '/pricing',
                'link_target' => '_self',
                'panel_type' => 'mega',
                'icon' => 'grid',
                'badge_text' => '',
                'badge_variant' => null,
                'is_visible' => true,
                'settings' => [
                    'eyebrow' => 'Catalog',
                    'note' => 'Single menu catalog',
                    'featured' => false,
                    'highlight_color' => '#0f766e',
                ],
                'columns' => [
                    [
                        'title' => 'Catalog',
                        'width' => '1fr',
                        'settings' => [
                            'alignment' => 'start',
                            'background_color' => '',
                        ],
                        'blocks' => [
                            [
                                'type' => 'product_showcase',
                                'title' => 'Products & Services',
                                'settings' => [
                                    'tone' => 'default',
                                    'show_border' => false,
                                ],
                                'payload' => [
                                    'title' => 'Products & Services',
                                    'description' => 'Hover a product to update the preview.',
                                    'items' => [
                                        [
                                            'label' => 'Sales & CRM',
                                            'href' => '/pricing#sales-crm',
                                            'note' => 'Requests, quotes, and customers.',
                                            'badge' => 'Popular',
                                            'summary' => 'Capture demand and convert faster.',
                                            'target' => '_self',
                                            'image_url' => '/images/mega-menu/sales-crm-suite.svg',
                                            'image_alt' => 'Sales and CRM suite illustration',
                                            'image_title' => 'Sales and CRM suite',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]), $user->id);

    $block = $menu->items->first()->columns->first()->blocks->first();

    expect($block->type)->toBe('product_showcase');
    expect($block->payload['items'])->toHaveCount(1);
    expect($block->payload['items'][0]['label'])->toBe('Sales & CRM');
    expect($block->payload['items'][0]['image_url'])->toBe('/images/mega-menu/sales-crm-suite.svg');
});

it('returns a fallback payload when no active menu exists for a location', function () {
    $renderer = app(MegaMenuRenderer::class);

    $fallback = $renderer->resolveForLocation('header');

    expect($fallback['exists'])->toBeFalse();
    expect($fallback['is_fallback'])->toBeTrue();
    expect($fallback['items'])->toBeArray()->toBeEmpty();
});
