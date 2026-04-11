<?php

use App\Models\PlatformPage;
use App\Models\PlatformSection;
use App\Models\User;
use App\Services\MegaMenus\MegaMenuManagerService;
use App\Services\MegaMenus\MegaMenuRenderer;
use App\Services\PlatformPageContentService;
use App\Services\PlatformSectionContentService;
use App\Support\LocalePreference;

it('accepts spanish as a locale preference', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from('/settings/profile')
        ->post(route('locale.update'), [
            'locale' => 'es',
        ])
        ->assertRedirect('/settings/profile');

    expect($user->fresh()->locale)->toBe('es')
        ->and(session('locale'))->toBe('es')
        ->and(LocalePreference::supported())->toContain('es');
});

it('falls back to english section content when spanish content is only partial', function () {
    $section = PlatformSection::query()->create([
        'name' => 'Spanish fallback section',
        'type' => 'generic',
        'is_active' => true,
        'content' => [
            'locales' => [
                'fr' => [
                    'layout' => 'split',
                    'title' => 'Bonjour',
                    'background_color' => '#111111',
                    'hero_images' => [
                        ['image_url' => '/fr-section.jpg', 'image_alt' => 'Francais'],
                    ],
                ],
                'en' => [
                    'layout' => 'split',
                    'title' => 'Hello',
                    'background_color' => '#222222',
                    'hero_images' => [
                        ['image_url' => '/en-section.jpg', 'image_alt' => 'English'],
                    ],
                ],
                'es' => [
                    'layout' => 'split',
                    'title' => 'Hola',
                ],
            ],
        ],
    ]);

    $resolved = app(PlatformSectionContentService::class)->resolveForLocale($section, 'es');

    expect($resolved['title'])->toBe('Hola')
        ->and($resolved['background_color'])->toBe('#222222')
        ->and($resolved['hero_images'][0]['image_url'])->toBe('/en-section.jpg');
});

it('falls back to english page visuals when spanish content is only partial', function () {
    $page = PlatformPage::query()->create([
        'slug' => 'spanish-fallback-page',
        'title' => 'Spanish fallback page',
        'is_active' => true,
        'content' => [
            'locales' => [
                'fr' => [
                    'page_title' => 'Bonjour',
                    'page_subtitle' => '',
                    'header' => [
                        'background_type' => 'color',
                        'background_color' => '#111111',
                        'background_image_url' => '',
                        'background_image_alt' => '',
                        'alignment' => 'center',
                    ],
                    'sections' => [
                        [
                            'id' => 'section-1',
                            'title' => 'Section FR',
                            'background_color' => '#111111',
                            'hero_images' => [
                                ['image_url' => '/fr-page.jpg', 'image_alt' => 'Francais'],
                            ],
                        ],
                    ],
                ],
                'en' => [
                    'page_title' => 'Hello',
                    'page_subtitle' => '',
                    'header' => [
                        'background_type' => 'color',
                        'background_color' => '#222222',
                        'background_image_url' => '',
                        'background_image_alt' => '',
                        'alignment' => 'center',
                    ],
                    'sections' => [
                        [
                            'id' => 'section-1',
                            'title' => 'English section',
                            'background_color' => '#222222',
                            'hero_images' => [
                                ['image_url' => '/en-page.jpg', 'image_alt' => 'English'],
                            ],
                        ],
                    ],
                ],
                'es' => [
                    'page_title' => 'Hola',
                    'page_subtitle' => '',
                    'header' => [
                        'background_type' => 'color',
                        'alignment' => 'center',
                    ],
                    'sections' => [
                        [
                            'id' => 'section-1',
                            'title' => 'Seccion ES',
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $resolved = app(PlatformPageContentService::class)->resolveForLocale($page, 'es');

    expect($resolved['page_title'])->toBe('Hola')
        ->and($resolved['header']['background_color'])->toBe('#222222')
        ->and($resolved['sections'][0]['title'])->toBe('Seccion ES')
        ->and($resolved['sections'][0]['background_color'])->toBe('#222222')
        ->and($resolved['sections'][0]['hero_images'][0]['image_url'])->toBe('/en-page.jpg');
});

it('falls back to english mega menu translations for spanish locale requests', function () {
    $user = User::factory()->create();
    $manager = app(MegaMenuManagerService::class);

    $menu = $manager->create([
        'title' => 'Fallback menu',
        'slug' => 'fallback-menu',
        'status' => 'active',
        'display_location' => 'header',
        'description' => 'Fallback menu description',
        'settings' => [
            'translations' => [
                'fr' => [
                    'title' => 'Menu FR',
                    'description' => 'Description FR',
                ],
                'en' => [
                    'title' => 'Menu EN',
                    'description' => 'Description EN',
                ],
            ],
        ],
        'items' => [
            [
                'label' => 'Base item',
                'description' => '',
                'link_type' => 'internal_page',
                'link_value' => '/pricing',
                'link_target' => '_self',
                'panel_type' => 'link',
                'is_visible' => true,
                'settings' => [
                    'translations' => [
                        'fr' => [
                            'label' => 'Tarifs',
                        ],
                        'en' => [
                            'label' => 'Pricing',
                        ],
                    ],
                ],
            ],
        ],
    ], $user->id);

    app()->setLocale('es');

    $resolved = app(MegaMenuRenderer::class)->serialize($menu->fresh());

    expect($resolved['title'])->toBe('Menu EN')
        ->and($resolved['description'])->toBe('Description EN')
        ->and($resolved['items'][0]['label'])->toBe('Pricing');
});
