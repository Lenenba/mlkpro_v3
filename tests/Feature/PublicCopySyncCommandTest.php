<?php

use App\Models\PlatformPage;
use App\Models\PlatformSection;
use App\Services\MegaMenus\MegaMenuRenderer;
use App\Services\PlatformSectionContentService;
use App\Support\PublicProductPageNarratives;
use App\Support\WelcomeEditorialSections;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rewrites stale marketing pages and menus from repo source files', function () {
    PlatformPage::query()->create([
        'slug' => 'sales-crm',
        'title' => 'Sales & CRM',
        'is_active' => true,
        'content' => [
            'locales' => [
                'fr' => [
                    'page_title' => 'Sales & CRM',
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
                            'id' => 'stale-sales-copy',
                            'enabled' => true,
                            'layout' => 'split',
                            'title' => 'Ancien titre sans bon copy',
                            'body' => '<p>Ancien contenu.</p>',
                        ],
                    ],
                ],
                'en' => [
                    'page_title' => 'Sales & CRM',
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
                            'id' => 'stale-sales-copy',
                            'enabled' => true,
                            'layout' => 'split',
                            'title' => 'Old stale title',
                            'body' => '<p>Old body.</p>',
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $this->artisan('public-copy:sync', ['--only' => ['pages']])
        ->assertExitCode(0);

    $page = PlatformPage::query()->where('slug', 'sales-crm')->firstOrFail();
    $expectedFrSections = PublicProductPageNarratives::sections('sales-crm', 'fr');
    $expectedEnSections = PublicProductPageNarratives::sections('sales-crm', 'en');

    expect($page->content['locales']['fr']['sections'][0]['title'] ?? null)
        ->toBe($expectedFrSections[0]['title'])
        ->and($page->content['locales']['fr']['sections'][1]['title'] ?? null)
        ->toBe($expectedFrSections[1]['title'])
        ->and($page->content['locales']['en']['sections'][0]['title'] ?? null)
        ->toBe($expectedEnSections[0]['title'])
        ->and($page->content['locales']['fr']['sections'])->toHaveCount(3);

    $menu = app(MegaMenuRenderer::class)->resolveBySlug('main-header-menu');

    expect($menu['items'][0]['label'] ?? null)->toBe('Produits & Services')
        ->and($menu['items'][3]['resolved_href'] ?? null)->toBe('/pages/contact-us');
});

it('rewrites stale welcome and footer copy from repo source files', function () {
    PlatformPage::query()->create([
        'slug' => 'welcome',
        'title' => 'Welcome',
        'is_active' => true,
        'content' => [
            'locales' => [
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
                            'id' => 'welcome-proof-feature-pairs',
                            'enabled' => true,
                            'use_source' => false,
                            'layout' => 'feature_pairs',
                            'title' => 'Mauvais titre',
                            'body' => '<p>Mauvais corps.</p>',
                        ],
                    ],
                ],
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
                            'id' => 'welcome-proof-feature-pairs',
                            'enabled' => true,
                            'use_source' => false,
                            'layout' => 'feature_pairs',
                            'title' => 'Wrong title',
                            'body' => '<p>Wrong body.</p>',
                        ],
                    ],
                ],
            ],
        ],
    ]);

    PlatformSection::query()->create([
        'name' => 'Shared footer',
        'type' => 'footer',
        'is_active' => true,
        'content' => [
            'locales' => [
                'fr' => [
                    'layout' => 'footer',
                    'title' => 'Ancien footer',
                    'copy' => 'Ancien copy',
                ],
                'en' => [
                    'layout' => 'footer',
                    'title' => 'Old footer',
                    'copy' => 'Old copy',
                ],
            ],
        ],
    ]);

    $this->artisan('public-copy:sync', ['--only' => ['welcome', 'footer']])
        ->assertExitCode(0);

    $welcome = PlatformPage::query()->where('slug', 'welcome')->firstOrFail();
    $welcomeFrSections = $welcome->content['locales']['fr']['sections'] ?? [];
    $editorial = collect($welcomeFrSections)->firstWhere('id', 'welcome-proof-feature-pairs');
    $expectedEditorial = WelcomeEditorialSections::forId('welcome-proof-feature-pairs', 'fr');

    expect($editorial)->not->toBeNull()
        ->and($editorial['title'] ?? null)->toBe($expectedEditorial['title'])
        ->and($editorial['body'] ?? '')->toContain('l’expérience client')
        ->and($welcomeFrSections)->toHaveCount(7);

    $heroSection = PlatformSection::query()->where('type', 'welcome_hero')->firstOrFail();
    $hero = app(PlatformSectionContentService::class)->resolveForLocale($heroSection, 'fr');

    expect($hero['title'])->toBe(trans('welcome.hero.title', [], 'fr'))
        ->and($hero['body'])->toContain('entreprises de services');

    $footerSection = PlatformSection::query()->where('type', 'footer')->firstOrFail();
    $footer = app(PlatformSectionContentService::class)->resolveForLocale($footerSection, 'fr');

    expect($footer['title'])->toBe('Parlez à notre équipe')
        ->and($footer['copy'])->toBe('Tous droits réservés.')
        ->and($footer['body'])->toContain('parcours produit plus précis')
        ->and(collect($footer['legal_links'] ?? [])->pluck('label')->contains('Confidentialité'))->toBeTrue();
});
