<?php

use App\Models\PlatformPage;
use App\Models\PlatformSection;
use App\Models\PlatformSetting;
use App\Models\Role;
use App\Models\User;
use App\Services\MegaMenus\MegaMenuRenderer;
use App\Services\PlatformPageContentService;
use Database\Seeders\MegaMenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('seeds public product pages and links the showcase menu to them', function () {
    $this->seed(MegaMenuSeeder::class);

    $menu = app(MegaMenuRenderer::class)->resolveBySlug('main-header-menu');
    $showcaseItem = $menu['items'][0]['columns'][0]['blocks'][0]['payload']['items'][0] ?? null;

    expect($showcaseItem)->not->toBeNull();
    expect($showcaseItem['href'])->toBe('/pages/sales-crm');

    $this->get(route('public.pages.show', ['slug' => 'sales-crm']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/Page')
            ->where('page.slug', 'sales-crm')
            ->where('content.page_title', 'Sales & CRM')
            ->has('content.sections', 3)
        );
});

it('seeds industries and contact us in the public header', function () {
    $ownerRole = Role::query()->firstOrCreate(['name' => 'owner'], ['description' => 'Owner']);
    User::query()->create([
        'name' => 'Owner Services',
        'email' => 'owner.services@example.com',
        'role_id' => $ownerRole->id,
        'password' => 'password',
        'company_features' => ['requests' => true],
    ]);

    $this->seed(MegaMenuSeeder::class);

    $menu = app(MegaMenuRenderer::class)->resolveBySlug('main-header-menu');
    $labels = collect($menu['items'])->pluck('label')->values()->all();
    $industries = $menu['items'][3] ?? null;
    $contact = $menu['items'][4] ?? null;

    expect($labels)->toBe([
        'Produits & Services',
        'Solutions',
        'Tarifs',
        'Industries',
        'Contact us',
    ]);

    expect($industries)->not->toBeNull();
    expect($industries['panel_type'])->toBe('classic');
    expect($industries['children'])->toHaveCount(6);
    expect($contact)->not->toBeNull();
    expect($contact['resolved_href'])->toBe('/pages/contact-us');

    $contactPage = PlatformPage::query()
        ->where('slug', 'contact-us')
        ->firstOrFail();
    $embedUrl = $contactPage->content['locales']['fr']['sections'][0]['embed_url'] ?? '';

    $this->get(route('public.pages.show', ['slug' => 'contact-us']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/Page')
            ->where('page.slug', 'contact-us')
            ->has('content.sections', 2)
            ->where('content.sections.0.embed_height', 820)
            ->where('content.sections.0.embed_title', fn ($value) => in_array($value, ['Formulaire de demande commerciale', 'Commercial inquiry form'], true))
            ->where('content.sections.0.embed_url', fn ($value) => is_string($value) && str_contains($value, '/public/requests/') && str_contains($value, 'embed=1'))
            ->where('content.sections.1.layout', 'contact')
            ->where('content.sections.1.image_url', '/images/mega-menu/contact-map.svg')
        );

    expect($embedUrl)->not->toBeEmpty();

    $this->get($embedUrl)->assertOk();
});

it('seeds public solution pages and links the solutions menu to them', function () {
    $this->seed(MegaMenuSeeder::class);

    $menu = app(MegaMenuRenderer::class)->resolveBySlug('main-header-menu');
    $solutions = $menu['items'][1] ?? null;
    $firstSolution = $solutions['children'][0] ?? null;

    expect($solutions)->not->toBeNull();
    expect($solutions['label'])->toBe('Solutions');
    expect($solutions['children'])->toHaveCount(6);
    expect($firstSolution)->not->toBeNull();
    expect($firstSolution['resolved_href'])->toBe('/pages/solution-field-services');

    $this->get(route('public.pages.show', ['slug' => 'solution-field-services']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/Page')
            ->where('page.slug', 'solution-field-services')
            ->where('content.page_title', fn ($value) => in_array($value, ['Services terrain', 'Field services'], true))
            ->has('content.sections', 3)
        );
});

it('uses the configurable contact form url for the contact us header item', function () {
    $this->seed(MegaMenuSeeder::class);

    PlatformSetting::setValue('public_navigation', [
        'contact_form_url' => 'https://example.com/forms/contact',
    ]);

    $menu = app(MegaMenuRenderer::class)->resolveBySlug('main-header-menu');
    $contact = collect($menu['items'])->firstWhere('label', 'Contact us');

    expect($contact)->not->toBeNull();
    expect($contact['resolved_href'])->toBe('https://example.com/forms/contact');
});

it('resolves header presentation settings for public pages', function () {
    $page = PlatformPage::query()->create([
        'slug' => 'header-showcase',
        'title' => 'Header showcase',
        'is_active' => true,
        'content' => [
            'locales' => [
                'fr' => [
                    'page_title' => 'Header dynamique',
                    'page_subtitle' => '<p>Contenu de test</p>',
                    'header' => [
                        'background_type' => 'image',
                        'background_image_url' => '/images/mega-menu/commerce-dashboard.jpg',
                        'background_image_alt' => 'Tableau de bord commerce',
                        'alignment' => 'right',
                    ],
                    'sections' => [],
                ],
                'en' => [
                    'page_title' => 'Dynamic header',
                    'page_subtitle' => '<p>Test content</p>',
                    'header' => [
                        'background_type' => 'image',
                        'background_image_url' => '/images/mega-menu/commerce-dashboard.jpg',
                        'background_image_alt' => 'Commerce dashboard',
                        'alignment' => 'right',
                    ],
                    'sections' => [],
                ],
            ],
        ],
    ]);

    $this->get(route('public.pages.show', ['slug' => $page->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $inertia) => $inertia
            ->component('Public/Page')
            ->where('content.header.background_type', 'image')
            ->where('content.header.background_image_url', '/images/mega-menu/commerce-dashboard.jpg')
            ->where('content.header.alignment', 'right')
        );
});

it('preserves utf8 characters when saving rich text page content', function () {
    $user = User::factory()->create();

    $page = PlatformPage::query()->create([
        'slug' => 'utf8-check',
        'title' => 'UTF-8 check',
        'is_active' => true,
        'content' => [
            'locales' => [
                'fr' => [
                    'page_title' => 'UTF-8 check',
                    'page_subtitle' => '',
                    'header' => [
                        'background_type' => 'none',
                        'alignment' => 'left',
                    ],
                    'sections' => [
                        [
                            'id' => 'section-1',
                            'enabled' => true,
                            'layout' => 'split',
                            'alignment' => 'left',
                            'density' => 'normal',
                            'tone' => 'default',
                            'visibility' => [
                                'locales' => [],
                                'auth' => 'any',
                                'roles' => [],
                                'plans' => [],
                                'device' => 'all',
                                'start_at' => null,
                                'end_at' => null,
                            ],
                            'kicker' => 'Contact',
                            'title' => 'Expliquez-nous votre besoin',
                            'body' => '',
                            'items' => [],
                            'image_url' => '',
                            'image_alt' => '',
                            'primary_label' => '',
                            'primary_href' => '',
                            'secondary_label' => '',
                            'secondary_href' => '',
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $payload = app(PlatformPageContentService::class)->resolveForLocale($page, 'fr');
    $payload['sections'][0]['body'] = '<p>Malikia Pro a été pensé pour les entreprises de services qui veulent arrêter de jongler entre plusieurs outils et retrouver une vue claire de leur activité. Dites-nous où vous perdez du temps aujourd’hui, et nous verrons ensemble comment le simplifier.</p>';

    app(PlatformPageContentService::class)->updateLocale($page, 'fr', $payload, $user->id);

    $stored = $page->fresh()->content['locales']['fr']['sections'][0]['body'] ?? '';

    expect($stored)->toContain('été');
    expect($stored)->toContain('arrêter');
    expect($stored)->toContain('activité');
    expect($stored)->toContain('aujourd’hui');
    expect($stored)->not->toContain('Ã');
    expect($stored)->not->toContain('â');
});

it('supports the duo section layout for public pages', function () {
    $user = User::factory()->create();

    $page = PlatformPage::query()->create([
        'slug' => 'duo-layout-check',
        'title' => 'Duo layout check',
        'is_active' => true,
        'content' => [
            'locales' => [],
        ],
    ]);

    $service = app(PlatformPageContentService::class);
    $payload = $service->defaultContent('fr', $page);
    $payload['sections'][0] = array_merge($payload['sections'][0], [
        'layout' => 'duo',
        'image_position' => 'right',
        'alignment' => 'left',
        'tone' => 'contrast',
        'background_color' => '#0f172a',
        'kicker' => 'Avant / apres',
        'title' => 'Deux blocs simples',
        'body' => '<p>Un bloc image plein cadre et un bloc contenu colore.</p>',
        'image_url' => 'https://example.com/duo-panel.jpg',
        'image_alt' => 'Duo panel',
        'primary_label' => 'Nous contacter',
        'primary_href' => '/pages/contact-us',
    ]);

    $service->updateLocale($page, 'fr', $payload, $user->id);
    $service->updateLocale($page, 'en', $payload, $user->id);

    $resolved = $service->resolveForLocale($page->fresh(), 'fr');

    expect($resolved['sections'][0]['layout'])->toBe('duo');
    expect($resolved['sections'][0]['image_position'])->toBe('right');
    expect($resolved['sections'][0]['background_color'])->toBe('#0f172a');

    $this->get(route('public.pages.show', ['slug' => $page->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $inertia) => $inertia
            ->component('Public/Page')
            ->where('content.sections.0.layout', 'duo')
            ->where('content.sections.0.image_position', 'right')
            ->where('content.sections.0.background_color', '#0f172a')
            ->where('content.sections.0.image_url', 'https://example.com/duo-panel.jpg')
        );
});

it('supports the testimonial section layout for public pages', function () {
    $user = User::factory()->create();

    $page = PlatformPage::query()->create([
        'slug' => 'testimonial-layout-check',
        'title' => 'Testimonial layout check',
        'is_active' => true,
        'content' => [
            'locales' => [],
        ],
    ]);

    $service = app(PlatformPageContentService::class);
    $payload = $service->defaultContent('fr', $page);
    $payload['sections'][0] = array_merge($payload['sections'][0], [
        'layout' => 'testimonial',
        'image_position' => 'left',
        'background_color' => '#e5ecef',
        'title' => 'Une citation marquee qui presente le produit.',
        'body' => '<p>Le temoignage peut aussi contenir une ligne de contexte complementaire.</p>',
        'testimonial_author' => 'Dan Kadosh',
        'testimonial_role' => 'Co-founder Workiz',
        'image_url' => 'https://example.com/testimonial.jpg',
        'image_alt' => 'Portrait client',
        'primary_label' => 'Watch video',
        'primary_href' => 'https://example.com/video',
        'secondary_label' => 'Lire l etude de cas',
        'secondary_href' => '/pages/contact-us',
    ]);

    $service->updateLocale($page, 'fr', $payload, $user->id);
    $service->updateLocale($page, 'en', $payload, $user->id);

    $resolved = $service->resolveForLocale($page->fresh(), 'fr');

    expect($resolved['sections'][0]['layout'])->toBe('testimonial');
    expect($resolved['sections'][0]['image_position'])->toBe('left');
    expect($resolved['sections'][0]['testimonial_author'])->toBe('Dan Kadosh');
    expect($resolved['sections'][0]['testimonial_role'])->toBe('Co-founder Workiz');

    $this->get(route('public.pages.show', ['slug' => $page->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $inertia) => $inertia
            ->component('Public/Page')
            ->where('content.sections.0.layout', 'testimonial')
            ->where('content.sections.0.image_position', 'left')
            ->where('content.sections.0.testimonial_author', 'Dan Kadosh')
            ->where('content.sections.0.testimonial_role', 'Co-founder Workiz')
            ->where('content.sections.0.primary_label', 'Watch video')
            ->where('content.sections.0.image_url', 'https://example.com/testimonial.jpg')
        );
});

it('stores reusable duo and testimonial library sections with their enhanced fields', function () {
    $user = User::factory()->create();
    $service = app(\App\Services\PlatformSectionContentService::class);

    $duoSection = PlatformSection::query()->create([
        'name' => 'Reusable duo',
        'type' => 'duo',
        'is_active' => true,
        'content' => ['locales' => []],
    ]);

    $service->updateLocale($duoSection, 'fr', [
        'kicker' => 'Avant / apres',
        'title' => 'Bloc duo reutilisable',
        'body' => '<p>Ce layout doit garder son fond et sa position d image.</p>',
        'image_position' => 'right',
        'background_color' => '#0f172a',
        'image_url' => 'https://example.com/reusable-duo.jpg',
        'image_alt' => 'Reusable duo',
        'primary_label' => 'Nous contacter',
        'primary_href' => '/pages/contact-us',
    ], $user->id);

    $resolvedDuo = $service->resolveForLocale($duoSection->fresh(), 'fr');

    expect($resolvedDuo['layout'])->toBe('duo');
    expect($resolvedDuo['image_position'])->toBe('right');
    expect($resolvedDuo['background_color'])->toBe('#0f172a');
    expect($resolvedDuo['tone'])->toBe('contrast');

    $testimonialSection = PlatformSection::query()->create([
        'name' => 'Reusable testimonial',
        'type' => 'testimonial',
        'is_active' => true,
        'content' => ['locales' => []],
    ]);

    $service->updateLocale($testimonialSection, 'fr', [
        'title' => 'Temoignage reutilisable',
        'body' => '<p>Une citation en librairie reutilisable.</p>',
        'image_position' => 'left',
        'background_color' => '#e5ecef',
        'testimonial_author' => 'Dan Kadosh',
        'testimonial_role' => 'Co-founder Workiz',
        'image_url' => 'https://example.com/reusable-testimonial.jpg',
        'image_alt' => 'Reusable testimonial',
        'primary_label' => 'Watch video',
        'primary_href' => 'https://example.com/video',
    ], $user->id);

    $resolvedTestimonial = $service->resolveForLocale($testimonialSection->fresh(), 'fr');

    expect($resolvedTestimonial['layout'])->toBe('testimonial');
    expect($resolvedTestimonial['image_position'])->toBe('left');
    expect($resolvedTestimonial['background_color'])->toBe('#e5ecef');
    expect($resolvedTestimonial['testimonial_author'])->toBe('Dan Kadosh');
    expect($resolvedTestimonial['testimonial_role'])->toBe('Co-founder Workiz');
});
