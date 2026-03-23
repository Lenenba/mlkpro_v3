<?php

use App\Models\PlatformPage;
use App\Models\PlatformSection;
use Inertia\Testing\AssertableInertia as Assert;

test('legal pages expose shared public chrome props', function () {
    $this->get(route('terms'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Terms')
            ->where('megaMenu.display_location', 'header')
            ->where('footerMenu.display_location', 'footer')
            ->where('footerSection.layout', 'footer')
        );

    $this->get(route('privacy'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Privacy')
            ->where('megaMenu.display_location', 'header')
            ->where('footerMenu.display_location', 'footer')
            ->where('footerSection.layout', 'footer')
        );

    $this->get(route('refund'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Refund')
            ->where('megaMenu.display_location', 'header')
            ->where('footerMenu.display_location', 'footer')
            ->where('footerSection.layout', 'footer')
        );
});

test('welcome page exposes shared footer navigation props', function () {
    $this->get(route('welcome'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->where('megaMenu.display_location', 'header')
            ->where('footerMenu.display_location', 'footer')
            ->where('footerSection.layout', 'footer')
        );

    expect(PlatformPage::query()->where('slug', 'welcome')->exists())->toBeTrue();
});

test('welcome page resolves reusable welcome sections from the page library', function () {
    $heroSection = PlatformSection::query()->create([
        'name' => 'Welcome Hero',
        'type' => 'welcome_hero',
        'is_active' => true,
        'content' => [
            'locales' => [
                'en' => [
                    'layout' => 'split',
                    'kicker' => 'Operations suite',
                    'title' => 'Run the whole business from one flow',
                    'body' => '<p>Public pages, quoting, scheduling, and payments stay connected.</p>',
                    'note' => '<p>Trusted by local operators.</p>',
                    'stats' => [
                        ['value' => '24/7', 'label' => 'Availability'],
                    ],
                    'hero_images' => [
                        ['image_url' => '/images/landing/hero-dashboard.svg', 'image_alt' => 'Hero dashboard'],
                        ['image_url' => '/images/mega-menu/operations-suite.svg', 'image_alt' => 'Operations suite'],
                    ],
                    'items' => ['Capture leads faster'],
                    'preview_cards' => [
                        ['title' => 'Quote faster', 'desc' => '<p>Use reusable pricing blocks.</p>'],
                    ],
                    'image_url' => '/images/landing/hero-dashboard.svg',
                    'image_alt' => 'Hero dashboard',
                    'primary_label' => 'Start now',
                    'primary_href' => '/onboarding',
                    'secondary_label' => 'Log in',
                    'secondary_href' => '/login',
                ],
                'fr' => [
                    'layout' => 'split',
                    'kicker' => 'Suite operations',
                    'title' => 'Pilotez l activite dans un seul flux',
                    'body' => '<p>Pages publiques, devis, planning et paiements restent relies.</p>',
                    'note' => '<p>Adopte par les equipes locales.</p>',
                    'stats' => [
                        ['value' => '24/7', 'label' => 'Disponibilite'],
                    ],
                    'hero_images' => [
                        ['image_url' => '/images/landing/hero-dashboard.svg', 'image_alt' => 'Dashboard hero'],
                        ['image_url' => '/images/mega-menu/operations-suite.svg', 'image_alt' => 'Suite operations'],
                    ],
                    'items' => ['Capturez vos leads plus vite'],
                    'preview_cards' => [
                        ['title' => 'Devis plus rapides', 'desc' => '<p>Utilisez des blocs tarifaires reutilisables.</p>'],
                    ],
                    'image_url' => '/images/landing/hero-dashboard.svg',
                    'image_alt' => 'Dashboard hero',
                    'primary_label' => 'Commencer',
                    'primary_href' => '/onboarding',
                    'secondary_label' => 'Connexion',
                    'secondary_href' => '/login',
                ],
            ],
        ],
    ]);

    PlatformPage::query()->create([
        'slug' => 'welcome',
        'title' => 'Welcome',
        'is_active' => true,
        'content' => [
            'locales' => [
                'en' => [
                    'page_title' => '',
                    'page_subtitle' => '',
                    'header' => [
                        'background_type' => 'none',
                        'background_color' => '',
                        'background_image_url' => '',
                        'background_image_alt' => '',
                        'alignment' => 'center',
                    ],
                    'sections' => [
                        [
                            'id' => 'section-1',
                            'enabled' => true,
                            'source_id' => $heroSection->id,
                            'use_source' => true,
                            'layout' => 'split',
                        ],
                    ],
                ],
                'fr' => [
                    'page_title' => '',
                    'page_subtitle' => '',
                    'header' => [
                        'background_type' => 'none',
                        'background_color' => '',
                        'background_image_url' => '',
                        'background_image_alt' => '',
                        'alignment' => 'center',
                    ],
                    'sections' => [
                        [
                            'id' => 'section-1',
                            'enabled' => true,
                            'source_id' => $heroSection->id,
                            'use_source' => true,
                            'layout' => 'split',
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $this->get(route('welcome'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->where('welcomeContent.hero.title', fn (string $value) => in_array($value, [
                'Run the whole business from one flow',
                'Pilotez l activite dans un seul flux',
            ], true))
            ->where('welcomeContent.hero.stats.0.value', '24/7')
            ->where('welcomeContent.hero.hero_images.1.image_url', '/images/mega-menu/operations-suite.svg')
        );
});

test('welcome page can override hero highlights note and stats from a sourced section', function () {
    $heroSection = PlatformSection::query()->create([
        'name' => 'Welcome Hero',
        'type' => 'welcome_hero',
        'is_active' => true,
        'content' => [
            'locales' => [
                'en' => [
                    'layout' => 'split',
                    'kicker' => 'Operations suite',
                    'title' => 'Run the whole business from one flow',
                    'body' => '<p>Public pages, quoting, scheduling, and payments stay connected.</p>',
                    'note' => '<p>Trusted by local operators.</p>',
                    'stats' => [
                        ['value' => '24/7', 'label' => 'Availability'],
                    ],
                    'items' => ['Capture leads faster'],
                    'image_url' => '/images/landing/hero-dashboard.svg',
                    'image_alt' => 'Hero dashboard',
                ],
                'fr' => [
                    'layout' => 'split',
                    'kicker' => 'Suite operations',
                    'title' => 'Pilotez l activite dans un seul flux',
                    'body' => '<p>Pages publiques, devis, planning et paiements restent relies.</p>',
                    'note' => '<p>Adopte par les equipes locales.</p>',
                    'stats' => [
                        ['value' => '24/7', 'label' => 'Disponibilite'],
                    ],
                    'items' => ['Capturez vos leads plus vite'],
                    'image_url' => '/images/landing/hero-dashboard.svg',
                    'image_alt' => 'Dashboard hero',
                ],
            ],
        ],
    ]);

    PlatformPage::query()->create([
        'slug' => 'welcome',
        'title' => 'Welcome',
        'is_active' => true,
        'content' => [
            'locales' => [
                'en' => [
                    'page_title' => '',
                    'page_subtitle' => '',
                    'header' => [
                        'background_type' => 'none',
                        'background_color' => '',
                        'background_image_url' => '',
                        'background_image_alt' => '',
                        'alignment' => 'center',
                    ],
                    'sections' => [
                        [
                            'id' => 'section-1',
                            'enabled' => true,
                            'source_id' => $heroSection->id,
                            'use_source' => true,
                            'layout' => 'split',
                            'title_color' => '#f8fafc',
                            'body_color' => '#d6f5e3',
                            'title_font_size' => 78,
                            'override_items' => true,
                            'override_note' => true,
                            'override_stats' => true,
                            'items' => [],
                            'note' => '',
                            'stats' => [],
                        ],
                    ],
                ],
                'fr' => [
                    'page_title' => '',
                    'page_subtitle' => '',
                    'header' => [
                        'background_type' => 'none',
                        'background_color' => '',
                        'background_image_url' => '',
                        'background_image_alt' => '',
                        'alignment' => 'center',
                    ],
                    'sections' => [
                        [
                            'id' => 'section-1',
                            'enabled' => true,
                            'source_id' => $heroSection->id,
                            'use_source' => true,
                            'layout' => 'split',
                            'title_color' => '#f8fafc',
                            'body_color' => '#d6f5e3',
                            'title_font_size' => 78,
                            'override_items' => true,
                            'override_note' => true,
                            'override_stats' => true,
                            'items' => [],
                            'note' => '',
                            'stats' => [],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $this->get(route('welcome'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->where('welcomeContent.hero.highlights', [])
            ->where('welcomeContent.hero.note', '')
            ->where('welcomeContent.hero.stats', [])
            ->where('welcomeContent.hero.title_color', '#f8fafc')
            ->where('welcomeContent.hero.body_color', '#d6f5e3')
            ->where('welcomeContent.hero.title_font_size', 78)
        );
});
