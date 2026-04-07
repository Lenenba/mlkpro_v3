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

it('replaces an existing nested header structure without accumulating top-level items', function () {
    $user = megaMenuTestUser();
    $service = app(MegaMenuManagerService::class);

    $menu = $service->create(megaMenuPayload([
        'slug' => 'showcase-header',
        'title' => 'Showcase Header',
        'items' => [
            megaMenuPayload()['items'][0],
            [
                'label' => 'Solutions',
                'description' => 'Solution paths',
                'link_type' => 'none',
                'link_target' => '_self',
                'panel_type' => 'classic',
                'is_visible' => true,
                'children' => [
                    [
                        'label' => 'Field teams',
                        'description' => 'Operations and jobs',
                        'link_type' => 'internal_page',
                        'link_value' => '/jobs',
                        'link_target' => '_self',
                        'panel_type' => 'link',
                        'is_visible' => true,
                    ],
                ],
            ],
            [
                'label' => 'Pricing',
                'description' => 'Plans',
                'link_type' => 'internal_page',
                'link_value' => '/pricing',
                'link_target' => '_self',
                'panel_type' => 'link',
                'is_visible' => true,
            ],
            [
                'label' => 'Demo',
                'description' => 'Demo access',
                'link_type' => 'internal_page',
                'link_value' => '/demo',
                'link_target' => '_self',
                'panel_type' => 'link',
                'is_visible' => true,
            ],
            [
                'label' => 'Resources',
                'description' => 'Guides and legal pages',
                'link_type' => 'none',
                'link_target' => '_self',
                'panel_type' => 'classic',
                'is_visible' => true,
                'children' => [
                    [
                        'label' => 'Terms',
                        'description' => 'Usage terms',
                        'link_type' => 'internal_page',
                        'link_value' => '/terms',
                        'link_target' => '_self',
                        'panel_type' => 'link',
                        'is_visible' => true,
                    ],
                ],
            ],
        ],
    ]), $user->id);

    $updatePayload = megaMenuPayload([
        'slug' => 'showcase-header',
        'title' => 'Showcase Header',
        'items' => [
            megaMenuPayload()['items'][0],
            [
                'label' => 'Solutions',
                'description' => 'Updated solution paths',
                'link_type' => 'none',
                'link_target' => '_self',
                'panel_type' => 'classic',
                'is_visible' => true,
                'children' => [
                    [
                        'label' => 'Reservations',
                        'description' => 'Booking workflows',
                        'link_type' => 'internal_page',
                        'link_value' => '/app/reservations',
                        'link_target' => '_self',
                        'panel_type' => 'link',
                        'is_visible' => true,
                    ],
                ],
            ],
            [
                'label' => 'Tarifs',
                'description' => 'Plans',
                'link_type' => 'internal_page',
                'link_value' => '/pricing',
                'link_target' => '_self',
                'panel_type' => 'link',
                'is_visible' => true,
            ],
            [
                'label' => 'Démo',
                'description' => 'Demo access',
                'link_type' => 'internal_page',
                'link_value' => '/demo',
                'link_target' => '_self',
                'panel_type' => 'link',
                'is_visible' => true,
            ],
            [
                'label' => 'Ressources',
                'description' => 'Guides and legal pages',
                'link_type' => 'none',
                'link_target' => '_self',
                'panel_type' => 'classic',
                'is_visible' => true,
                'children' => [
                    [
                        'label' => 'Confidentialité',
                        'description' => 'Privacy policy',
                        'link_type' => 'internal_page',
                        'link_value' => '/privacy',
                        'link_target' => '_self',
                        'panel_type' => 'link',
                        'is_visible' => true,
                    ],
                ],
            ],
        ],
    ]);

    $service->update($menu, $updatePayload, $user->id);
    $service->update($menu->fresh(), $updatePayload, $user->id);

    $labels = $menu->fresh()->items->pluck('label')->values()->all();

    expect($labels)->toBe([
        'Platform',
        'Solutions',
        'Tarifs',
        'Démo',
        'Ressources',
    ]);
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

it('renders translated mega menu fields for the active locale', function () {
    $user = megaMenuTestUser();
    $service = app(MegaMenuManagerService::class);

    $service->create(megaMenuPayload([
        'slug' => 'translated-header',
        'status' => 'active',
        'settings' => [
            'theme' => 'default',
            'container_width' => 'xl',
            'accent_color' => '#16a34a',
            'panel_background' => '#ffffff',
            'open_on_hover' => true,
            'show_dividers' => true,
            'translations' => [
                'en' => [
                    'title' => 'Translated Header',
                    'description' => 'Localized header menu',
                ],
            ],
        ],
        'items' => [
            [
                'label' => 'Produits & Services',
                'description' => 'Catalogue complet',
                'link_type' => 'none',
                'link_target' => '_self',
                'panel_type' => 'mega',
                'icon' => 'grid',
                'badge_text' => 'Nouveau',
                'badge_variant' => 'new',
                'is_visible' => true,
                'settings' => [
                    'eyebrow' => 'Modules',
                    'note' => 'Navigation principale',
                    'featured' => false,
                    'highlight_color' => '#16a34a',
                    'translations' => [
                        'en' => [
                            'label' => 'Products & Services',
                            'description' => 'Full product catalog',
                            'badge_text' => 'New',
                            'eyebrow' => 'Modules',
                            'note' => 'Primary navigation',
                        ],
                    ],
                ],
                'columns' => [
                    [
                        'title' => 'Primaire',
                        'width' => '1fr',
                        'settings' => [
                            'alignment' => 'start',
                            'background_color' => '',
                            'translations' => [
                                'en' => [
                                    'title' => 'Primary',
                                ],
                            ],
                        ],
                        'blocks' => [
                            [
                                'type' => 'navigation_group',
                                'title' => 'Explorer',
                                'settings' => [
                                    'tone' => 'default',
                                    'show_border' => false,
                                    'translations' => [
                                        'en' => [
                                            'title' => 'Explore',
                                        ],
                                    ],
                                ],
                                'payload' => [
                                    'title' => 'Explorer',
                                    'description' => 'Liens principaux',
                                    'links' => [
                                        [
                                            'label' => 'Tarifs',
                                            'href' => '/pricing',
                                            'note' => 'Forfaits et modules',
                                            'badge' => 'Populaire',
                                            'target' => '_self',
                                        ],
                                    ],
                                    'translations' => [
                                        'en' => [
                                            'title' => 'Explore',
                                            'description' => 'Primary links',
                                            'links' => [
                                                [
                                                    'label' => 'Pricing',
                                                    'href' => '/pricing',
                                                    'note' => 'Plans and modules',
                                                    'badge' => 'Popular',
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
            ],
        ],
    ]), $user->id);

    app()->setLocale('en');

    $menu = app(MegaMenuRenderer::class)->resolveBySlug('translated-header');

    expect($menu['title'])->toBe('Translated Header');
    expect($menu['description'])->toBe('Localized header menu');
    expect($menu['items'][0]['label'])->toBe('Products & Services');
    expect($menu['items'][0]['badge_text'])->toBe('New');
    expect($menu['items'][0]['settings']['note'])->toBe('Primary navigation');
    expect($menu['items'][0]['columns'][0]['title'])->toBe('Primary');
    expect($menu['items'][0]['columns'][0]['blocks'][0]['title'])->toBe('Explore');
    expect($menu['items'][0]['columns'][0]['blocks'][0]['payload']['title'])->toBe('Explore');
    expect($menu['items'][0]['columns'][0]['blocks'][0]['payload']['links'][0]['label'])->toBe('Pricing');
    expect($menu['items'][0]['columns'][0]['blocks'][0]['payload']['links'][0]['badge'])->toBe('Popular');
});

it('preserves canonical product showcase links when localized payload snapshots are stale', function () {
    $user = megaMenuTestUser();
    $service = app(MegaMenuManagerService::class);

    $service->create(megaMenuPayload([
        'slug' => 'stale-showcase-header',
        'status' => 'active',
        'items' => [
            [
                'label' => 'Produits & Services',
                'description' => 'Catalogue complet',
                'link_type' => 'none',
                'link_target' => '_self',
                'panel_type' => 'mega',
                'icon' => 'grid',
                'is_visible' => true,
                'settings' => [
                    'translations' => [
                        'en' => [
                            'label' => 'Products & Services',
                        ],
                    ],
                ],
                'columns' => [
                    [
                        'title' => 'Primaire',
                        'width' => '1fr',
                        'settings' => [
                            'alignment' => 'start',
                            'background_color' => '',
                        ],
                        'blocks' => [
                            [
                                'type' => 'product_showcase',
                                'title' => 'Produits & Services',
                                'settings' => [
                                    'tone' => 'default',
                                    'show_border' => false,
                                ],
                                'payload' => [
                                    'title' => 'Produits & Services',
                                    'description' => 'Survolez un produit pour voir l interface.',
                                    'items' => [
                                        [
                                            'label' => 'Sales & CRM',
                                            'href' => '/pages/sales-crm',
                                            'note' => 'Requests, quotes, customers, and pipelines.',
                                            'badge' => 'Popular',
                                            'summary' => 'Capture demand, qualify opportunities, and move faster from first request to approved quote.',
                                            'target' => '_self',
                                            'image_url' => '/images/landing/stock/desk-phone-laptop.jpg',
                                            'image_alt' => 'Sales lead working from a desk with phone and laptop',
                                            'image_title' => 'Sales and CRM',
                                        ],
                                    ],
                                    'translations' => [
                                        'en' => [
                                            'title' => 'Products & Services',
                                            'description' => 'Hover a product to preview the interface.',
                                            'items' => [
                                                [
                                                    'label' => 'Sales & CRM',
                                                    'href' => '/pricing#sales-crm',
                                                    'note' => 'Requests, quotes, customers, and pipelines.',
                                                    'badge' => 'Popular',
                                                    'summary' => 'Capture demand, qualify opportunities, and convert faster from one workspace.',
                                                    'target' => '_self',
                                                    'image_url' => '',
                                                    'image_alt' => '',
                                                    'image_title' => '',
                                                ],
                                            ],
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

    app()->setLocale('en');

    $menu = app(MegaMenuRenderer::class)->resolveBySlug('stale-showcase-header');
    $item = $menu['items'][0]['columns'][0]['blocks'][0]['payload']['items'][0];

    expect($item['href'])->toBe('/pages/sales-crm');
    expect($item['image_url'])->toBe('/images/landing/stock/desk-phone-laptop.jpg');
    expect($item['summary'])->toBe('Capture demand, qualify opportunities, and convert faster from one workspace.');
});
