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
            ->where('footerMenu.display_location', 'footer')
            ->where('footerSection.layout', 'footer')
            ->where('footerMenu.items.0.label', 'Legal')
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

it('preserves an empty sections array for public pages', function () {
    $user = User::factory()->create();

    $page = PlatformPage::query()->create([
        'slug' => 'empty-sections-check',
        'title' => 'Empty sections check',
        'is_active' => true,
        'content' => [
            'locales' => [],
        ],
    ]);

    $service = app(PlatformPageContentService::class);
    $payload = $service->defaultContent('fr', $page);
    $payload['sections'] = [];

    $service->updateLocale($page, 'fr', $payload, $user->id);
    $service->updateLocale($page, 'en', $payload, $user->id);

    $resolved = $service->resolveForLocale($page->fresh(), 'fr');

    expect($resolved['sections'])->toBe([]);

    $this->get(route('public.pages.show', ['slug' => $page->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $inertia) => $inertia
            ->component('Public/Page')
            ->where('content.sections', [])
        );
});

it('supports the feature pairs section layout for public pages', function () {
    $user = User::factory()->create();

    $page = PlatformPage::query()->create([
        'slug' => 'feature-pairs-layout-check',
        'title' => 'Feature pairs layout check',
        'is_active' => true,
        'content' => [
            'locales' => [],
        ],
    ]);

    $service = app(PlatformPageContentService::class);
    $payload = $service->defaultContent('fr', $page);
    $payload['sections'][0] = array_merge($payload['sections'][0], [
        'layout' => 'feature_pairs',
        'kicker' => 'HVAC Scheduling Software',
        'title' => 'Streamline scheduling and dispatching',
        'body' => '<p>Dispatch the right tech to every job with a simple drag-and-drop calendar.</p>',
        'image_url' => 'https://example.com/feature-pairs-primary.jpg',
        'image_alt' => 'Planning dashboard',
        'primary_label' => 'Learn more',
        'primary_href' => '/pages/contact-us',
        'aside_kicker' => 'Workiz Pay',
        'aside_title' => 'Accept all forms of payments',
        'aside_body' => '<p>Accept payment by credit card, mobile wallet, cash, ACH, or consumer financing.</p>',
        'aside_link_label' => 'Learn more',
        'aside_link_href' => 'https://example.com/payments',
        'aside_image_url' => 'https://example.com/feature-pairs-secondary.jpg',
        'aside_image_alt' => 'Mobile payment image',
    ]);

    $service->updateLocale($page, 'fr', $payload, $user->id);
    $service->updateLocale($page, 'en', $payload, $user->id);

    $resolved = $service->resolveForLocale($page->fresh(), 'fr');

    expect($resolved['sections'][0]['layout'])->toBe('feature_pairs');
    expect($resolved['sections'][0]['aside_title'])->toBe('Accept all forms of payments');
    expect($resolved['sections'][0]['aside_image_url'])->toBe('https://example.com/feature-pairs-secondary.jpg');

    $this->get(route('public.pages.show', ['slug' => $page->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $inertia) => $inertia
            ->component('Public/Page')
            ->where('content.sections.0.layout', 'feature_pairs')
            ->where('content.sections.0.image_url', 'https://example.com/feature-pairs-primary.jpg')
            ->where('content.sections.0.aside_title', 'Accept all forms of payments')
            ->where('content.sections.0.aside_image_url', 'https://example.com/feature-pairs-secondary.jpg')
        );
});

it('supports the showcase CTA section layout for public pages', function () {
    $user = User::factory()->create();

    $page = PlatformPage::query()->create([
        'slug' => 'showcase-cta-layout-check',
        'title' => 'Showcase CTA layout check',
        'is_active' => true,
        'content' => [
            'locales' => [],
        ],
    ]);

    $service = app(PlatformPageContentService::class);
    $payload = $service->defaultContent('fr', $page);
    $payload['sections'][0] = array_merge($payload['sections'][0], [
        'layout' => 'showcase_cta',
        'image_position' => 'right',
        'alignment' => 'left',
        'tone' => 'contrast',
        'background_color' => '#202322',
        'title' => 'Essayez-le gratuitement.',
        'body' => '<p>Un bloc hero avec media principal, overlay et badge de confiance.</p>',
        'image_url' => 'https://example.com/showcase-cta-desktop.jpg',
        'image_alt' => 'Showcase desktop',
        'aside_image_url' => 'https://example.com/showcase-cta-mobile.jpg',
        'aside_image_alt' => 'Showcase mobile',
        'aside_link_label' => 'Voir la visite produit',
        'aside_link_href' => 'https://example.com/product-tour',
        'showcase_badge_label' => 'Adopte par',
        'showcase_badge_value' => '+120,000',
        'showcase_badge_note' => 'pros du service',
        'primary_label' => 'Commencer gratuitement',
        'primary_href' => '/pages/contact-us',
    ]);

    $service->updateLocale($page, 'fr', $payload, $user->id);
    $service->updateLocale($page, 'en', $payload, $user->id);

    $resolved = $service->resolveForLocale($page->fresh(), 'fr');

    expect($resolved['sections'][0]['layout'])->toBe('showcase_cta');
    expect($resolved['sections'][0]['aside_link_label'])->toBe('Voir la visite produit');
    expect($resolved['sections'][0]['showcase_badge_value'])->toBe('+120,000');
    expect($resolved['sections'][0]['aside_image_url'])->toBe('https://example.com/showcase-cta-mobile.jpg');

    $this->get(route('public.pages.show', ['slug' => $page->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $inertia) => $inertia
            ->component('Public/Page')
            ->where('content.sections.0.layout', 'showcase_cta')
            ->where('content.sections.0.background_color', '#202322')
            ->where('content.sections.0.image_url', 'https://example.com/showcase-cta-desktop.jpg')
            ->where('content.sections.0.aside_image_url', 'https://example.com/showcase-cta-mobile.jpg')
            ->where('content.sections.0.showcase_badge_value', '+120,000')
            ->where('content.sections.0.aside_link_label', 'Voir la visite produit')
        );
});

it('supports the industry grid section layout for public pages', function () {
    $user = User::factory()->create();

    $page = PlatformPage::query()->create([
        'slug' => 'industry-grid-layout-check',
        'title' => 'Industry grid layout check',
        'is_active' => true,
        'content' => [
            'locales' => [],
        ],
    ]);

    $service = app(PlatformPageContentService::class);
    $payload = $service->defaultContent('fr', $page);
    $payload['sections'][0] = array_merge($payload['sections'][0], [
        'layout' => 'industry_grid',
        'alignment' => 'center',
        'background_color' => '#f7f2e8',
        'title' => 'Fier partenaire des services a domicile dans plus de 50 industries.',
        'primary_label' => 'Voir toutes les industries',
        'primary_href' => '/pages/contact-us',
        'industry_cards' => [
            ['id' => 'card-1', 'label' => 'Plomberie', 'href' => '/pages/industry-plumbing', 'icon' => 'shower-head'],
            ['id' => 'card-2', 'label' => 'HVAC', 'href' => '/pages/industry-hvac', 'icon' => 'fan'],
            ['id' => 'card-3', 'label' => 'Electricite', 'href' => '/pages/industry-electrical', 'icon' => 'plug-zap'],
        ],
    ]);

    $service->updateLocale($page, 'fr', $payload, $user->id);
    $service->updateLocale($page, 'en', $payload, $user->id);

    $resolved = $service->resolveForLocale($page->fresh(), 'fr');

    expect($resolved['sections'][0]['layout'])->toBe('industry_grid');
    expect($resolved['sections'][0]['industry_cards'])->toHaveCount(3);
    expect($resolved['sections'][0]['industry_cards'][0]['label'])->toBe('Plomberie');
    expect($resolved['sections'][0]['industry_cards'][0]['icon'])->toBe('shower-head');

    $this->get(route('public.pages.show', ['slug' => $page->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $inertia) => $inertia
            ->component('Public/Page')
            ->where('content.sections.0.layout', 'industry_grid')
            ->where('content.sections.0.background_color', '#f7f2e8')
            ->where('content.sections.0.industry_cards.1.label', 'HVAC')
            ->where('content.sections.0.industry_cards.1.icon', 'fan')
            ->where('content.sections.0.primary_label', 'Voir toutes les industries')
        );
});

it('supports the testimonial grid section layout for public pages', function () {
    $user = User::factory()->create();

    $page = PlatformPage::query()->create([
        'slug' => 'testimonial-grid-layout-check',
        'title' => 'Testimonial grid layout check',
        'is_active' => true,
        'content' => [
            'locales' => [],
        ],
    ]);

    $service = app(PlatformPageContentService::class);
    $payload = $service->defaultContent('fr', $page);
    $payload['sections'][0] = array_merge($payload['sections'][0], [
        'layout' => 'testimonial_grid',
        'alignment' => 'center',
        'background_color' => '#f7f2e8',
        'title' => 'Approuve par les meilleures equipes d entretien.',
        'body' => '<p>Les pros de l entretien utilisent Malikia pro pour mieux coordonner leur equipe.</p>',
        'testimonial_cards' => [
            [
                'id' => 'testimonial-grid-card-1',
                'quote' => '<p>\"Nos checklists rassurent les clients et montrent clairement ce qui a ete fait.\"</p>',
                'author_name' => 'Julie Morin',
                'author_role' => 'Fondatrice',
                'author_company' => 'Maison Claire Montreal',
                'image_url' => 'https://example.com/testimonial-grid-1.jpg',
                'image_alt' => 'Julie Morin',
            ],
            [
                'id' => 'testimonial-grid-card-2',
                'quote' => '<p>\"Toute mon equipe a les details du job sur son telephone.\"</p>',
                'author_name' => 'Cynthia Gagnon',
                'author_company' => 'Nordik Clean',
            ],
        ],
    ]);

    $service->updateLocale($page, 'fr', $payload, $user->id);
    $service->updateLocale($page, 'en', $payload, $user->id);

    $resolved = $service->resolveForLocale($page->fresh(), 'fr');

    expect($resolved['sections'][0]['layout'])->toBe('testimonial_grid');
    expect($resolved['sections'][0]['testimonial_cards'])->toHaveCount(2);
    expect($resolved['sections'][0]['testimonial_cards'][0]['author_name'])->toBe('Julie Morin');
    expect($resolved['sections'][0]['testimonial_cards'][0]['image_url'])->toBe('https://example.com/testimonial-grid-1.jpg');

    $this->get(route('public.pages.show', ['slug' => $page->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $inertia) => $inertia
            ->component('Public/Page')
            ->where('content.sections.0.layout', 'testimonial_grid')
            ->where('content.sections.0.background_color', '#f7f2e8')
            ->where('content.sections.0.testimonial_cards.0.author_name', 'Julie Morin')
            ->where('content.sections.0.testimonial_cards.1.author_company', 'Nordik Clean')
        );
});

it('supports the story grid section layout for public pages', function () {
    $user = User::factory()->create();

    $page = PlatformPage::query()->create([
        'slug' => 'story-grid-layout-check',
        'title' => 'Story grid layout check',
        'is_active' => true,
        'content' => [
            'locales' => [],
        ],
    ]);

    $service = app(PlatformPageContentService::class);
    $payload = $service->defaultContent('fr', $page);
    $payload['sections'][0] = array_merge($payload['sections'][0], [
        'layout' => 'story_grid',
        'alignment' => 'center',
        'background_color' => '#f7f2e8',
        'title' => 'Une IA pensee pour les entreprises de terrain.',
        'primary_label' => 'Voir comment ca marche',
        'primary_href' => '/pages/contact-us',
        'story_cards' => [
            [
                'id' => 'story-card-1',
                'title' => 'Concue pour le terrain',
                'body' => '<p>Elle aide les equipes a mieux qualifier et chiffrer les jobs.</p>',
                'image_url' => 'https://example.com/story-grid-1.jpg',
                'image_alt' => 'Apercu terrain',
            ],
            [
                'id' => 'story-card-2',
                'title' => 'S adapte a votre workflow',
                'body' => '<p>Elle apprend votre facon de decrire et d organiser le travail.</p>',
                'image_url' => 'https://example.com/story-grid-2.jpg',
                'image_alt' => 'Apercu workflow',
            ],
            [
                'id' => 'story-card-3',
                'title' => 'Intervient au bon moment',
                'body' => '<p>Elle apparait quand une suggestion ou une relance a plus de valeur.</p>',
                'image_url' => 'https://example.com/story-grid-3.jpg',
                'image_alt' => 'Apercu intelligence',
            ],
        ],
    ]);

    $service->updateLocale($page, 'fr', $payload, $user->id);
    $service->updateLocale($page, 'en', $payload, $user->id);

    $resolved = $service->resolveForLocale($page->fresh(), 'fr');

    expect($resolved['sections'][0]['layout'])->toBe('story_grid');
    expect($resolved['sections'][0]['story_cards'])->toHaveCount(3);
    expect($resolved['sections'][0]['story_cards'][0]['title'])->toBe('Concue pour le terrain');
    expect($resolved['sections'][0]['story_cards'][2]['image_url'])->toBe('https://example.com/story-grid-3.jpg');

    $this->get(route('public.pages.show', ['slug' => $page->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $inertia) => $inertia
            ->component('Public/Page')
            ->where('content.sections.0.layout', 'story_grid')
            ->where('content.sections.0.background_color', '#f7f2e8')
            ->where('content.sections.0.story_cards.1.title', 'S adapte a votre workflow')
            ->where('content.sections.0.story_cards.2.image_alt', 'Apercu intelligence')
        );
});

it('supports the feature tabs section layout for public pages', function () {
    $user = User::factory()->create();

    $page = PlatformPage::query()->create([
        'slug' => 'feature-tabs-layout-check',
        'title' => 'Feature tabs layout check',
        'is_active' => true,
        'content' => [
            'locales' => [],
        ],
    ]);

    $service = app(PlatformPageContentService::class);
    $payload = $service->defaultContent('fr', $page);
    $payload['sections'][0] = array_merge($payload['sections'][0], [
        'layout' => 'feature_tabs',
        'alignment' => 'center',
        'background_color' => '#f7f2e8',
        'title' => 'Un logiciel de gestion terrain qui travaille pour vous.',
        'body' => '<p>Centralisez votre operation dans un seul flux.</p>',
        'primary_label' => 'Voir comment ca marche',
        'primary_href' => '/pages/contact-us',
        'feature_tabs_font_size' => 32,
        'feature_tabs' => [
            [
                'id' => 'tab-1',
                'label' => 'Planifier',
                'icon' => 'calendar-days',
                'items' => ['Calendrier glisser-deposer', 'Affectation equipe'],
                'children' => [
                    [
                        'id' => 'tab-1-child-1',
                        'label' => 'Calendrier glisser-deposer',
                        'title' => 'Planifiez chaque intervention sans friction',
                        'body' => '<p>Organisez vos jobs et gardez toute l equipe synchronisee.</p>',
                        'image_url' => 'https://example.com/feature-tabs-schedule.jpg',
                        'image_alt' => 'Planning mobile',
                        'cta_label' => 'Voir la planification',
                        'cta_href' => '/pages/contact-us',
                    ],
                    [
                        'id' => 'tab-1-child-2',
                        'label' => 'Affectation equipe',
                        'title' => 'Affectez la bonne equipe plus rapidement',
                        'body' => '<p>Visualisez les disponibilites et assignez les meilleurs techniciens.</p>',
                        'image_url' => 'https://example.com/feature-tabs-dispatch.jpg',
                        'image_alt' => 'Dispatch equipe',
                        'cta_label' => 'Voir le dispatch',
                        'cta_href' => '/pages/contact-us',
                    ],
                ],
                'title' => 'Planifiez chaque intervention sans friction',
                'body' => '<p>Organisez vos jobs et gardez toute l equipe synchronisee.</p>',
                'image_url' => 'https://example.com/feature-tabs-schedule.jpg',
                'image_alt' => 'Planning mobile',
                'cta_label' => 'Voir la planification',
                'cta_href' => '/pages/contact-us',
            ],
            [
                'id' => 'tab-2',
                'label' => 'Etre paye',
                'icon' => 'circle-dollar-sign',
                'items' => ['Paiements sur place', 'Rappels automatiques'],
                'children' => [
                    [
                        'id' => 'tab-2-child-1',
                        'label' => 'Paiements sur place',
                        'title' => 'Encaissez plus vite sur le terrain',
                        'body' => '<p>Acceptez plusieurs moyens de paiement sans attendre le retour au bureau.</p>',
                        'image_url' => 'https://example.com/feature-tabs-payments.jpg',
                        'image_alt' => 'Paiement mobile',
                        'cta_label' => 'Voir les paiements',
                        'cta_href' => 'https://example.com/payments',
                    ],
                ],
                'title' => 'Encaissez plus vite sans relances manuelles',
                'body' => '<p>Accélérez vos encaissements avec plusieurs moyens de paiement.</p>',
                'image_url' => 'https://example.com/feature-tabs-payments.jpg',
                'image_alt' => 'Paiement mobile',
                'cta_label' => 'Voir les paiements',
                'cta_href' => 'https://example.com/payments',
                'metric' => 'Encaissements 2x plus rapides',
                'story' => '<p>Les rappels automatiques ont reduit nos retards de paiement.</p>',
                'person' => 'Equipe finance',
                'role' => 'Facturation',
                'avatar_url' => 'https://example.com/feature-tabs-finance-avatar.jpg',
                'avatar_alt' => 'Portrait equipe finance',
            ],
        ],
    ]);

    $service->updateLocale($page, 'fr', $payload, $user->id);
    $service->updateLocale($page, 'en', $payload, $user->id);

    $resolved = $service->resolveForLocale($page->fresh(), 'fr');

    expect($resolved['sections'][0]['layout'])->toBe('feature_tabs');
    expect($resolved['sections'][0]['feature_tabs'])->toHaveCount(2);
    expect($resolved['sections'][0]['feature_tabs_font_size'])->toBe(32);
    expect($resolved['sections'][0]['feature_tabs'][0]['icon'])->toBe('calendar-days');
    expect($resolved['sections'][0]['feature_tabs'][1]['cta_label'])->toBe('Voir les paiements');
    expect($resolved['sections'][0]['feature_tabs'][1]['metric'])->toBe('Encaissements 2x plus rapides');
    expect($resolved['sections'][0]['feature_tabs'][1]['person'])->toBe('Equipe finance');
    expect($resolved['sections'][0]['feature_tabs'][0]['children'])->toHaveCount(2);
    expect($resolved['sections'][0]['feature_tabs'][0]['children'][1]['label'])->toBe('Affectation equipe');

    $this->get(route('public.pages.show', ['slug' => $page->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $inertia) => $inertia
            ->component('Public/Page')
            ->where('content.sections.0.layout', 'feature_tabs')
            ->where('content.sections.0.background_color', '#f7f2e8')
            ->where('content.sections.0.feature_tabs_font_size', 32)
            ->where('content.sections.0.feature_tabs.0.label', 'Planifier')
            ->where('content.sections.0.feature_tabs.0.children.0.label', 'Calendrier glisser-deposer')
            ->where('content.sections.0.feature_tabs.1.icon', 'circle-dollar-sign')
            ->where('content.sections.0.feature_tabs.1.metric', 'Encaissements 2x plus rapides')
            ->where('content.sections.0.primary_label', 'Voir comment ca marche')
        );
});

it('shares public page media across locales while keeping text localized', function () {
    $user = User::factory()->create();

    $page = PlatformPage::query()->create([
        'slug' => 'shared-page-media-check',
        'title' => 'Shared page media check',
        'is_active' => true,
        'content' => [
            'locales' => [],
        ],
    ]);

    $service = app(PlatformPageContentService::class);
    $frPayload = $service->defaultContent('fr', $page);
    $frPayload['page_title'] = 'Titre FR';
    $frPayload['header']['background_type'] = 'image';
    $frPayload['header']['background_image_url'] = 'https://example.com/shared-header.jpg';
    $frPayload['header']['background_image_alt'] = 'Banniere FR';
    $frPayload['sections'][0] = array_merge($frPayload['sections'][0], [
        'layout' => 'story_grid',
        'title' => 'Bloc FR',
        'body' => '<p>Texte FR</p>',
        'image_url' => 'https://example.com/shared-primary.jpg',
        'image_alt' => 'Image FR',
        'story_cards' => [
            [
                'id' => 'story-card-1',
                'title' => 'Carte FR',
                'body' => '<p>Corps FR</p>',
                'image_url' => 'https://example.com/shared-story.jpg',
                'image_alt' => 'Carte image FR',
            ],
        ],
    ]);

    $service->updateLocale($page, 'fr', $frPayload, $user->id);

    $enPayload = $service->resolveForLocale($page->fresh(), 'en');
    $enPayload['page_title'] = 'EN title';
    $enPayload['page_subtitle'] = '<p>EN subtitle</p>';
    $enPayload['header']['background_image_alt'] = 'EN banner';
    $enPayload['sections'][0]['title'] = 'EN block';
    $enPayload['sections'][0]['body'] = '<p>EN body</p>';
    $enPayload['sections'][0]['image_alt'] = 'EN image';
    $enPayload['sections'][0]['story_cards'][0]['title'] = 'EN card';
    $enPayload['sections'][0]['story_cards'][0]['image_alt'] = 'EN card image';

    $service->updateLocale($page->fresh(), 'en', $enPayload, $user->id);

    $freshPage = $page->fresh();
    $resolvedFr = $service->resolveForLocale($freshPage, 'fr');
    $resolvedEn = $service->resolveForLocale($freshPage, 'en');

    expect($freshPage->content['shared_media']['header']['background_image_url'] ?? null)->toBe('https://example.com/shared-header.jpg');
    expect($freshPage->content['shared_media']['sections'][0]['image_url'] ?? null)->toBe('https://example.com/shared-primary.jpg');
    expect($freshPage->content['shared_media']['sections'][0]['story_cards'][0]['image_url'] ?? null)->toBe('https://example.com/shared-story.jpg');
    expect($resolvedFr['page_title'])->toBe('Titre FR');
    expect($resolvedEn['page_title'])->toBe('EN title');
    expect($resolvedFr['header']['background_image_url'])->toBe('https://example.com/shared-header.jpg');
    expect($resolvedEn['header']['background_image_url'])->toBe('https://example.com/shared-header.jpg');
    expect($resolvedFr['header']['background_image_alt'])->toBe('Banniere FR');
    expect($resolvedEn['header']['background_image_alt'])->toBe('EN banner');
    expect($resolvedFr['sections'][0]['image_url'])->toBe('https://example.com/shared-primary.jpg');
    expect($resolvedEn['sections'][0]['image_url'])->toBe('https://example.com/shared-primary.jpg');
    expect($resolvedFr['sections'][0]['image_alt'])->toBe('Image FR');
    expect($resolvedEn['sections'][0]['image_alt'])->toBe('EN image');
    expect($resolvedFr['sections'][0]['story_cards'][0]['image_url'])->toBe('https://example.com/shared-story.jpg');
    expect($resolvedEn['sections'][0]['story_cards'][0]['image_url'])->toBe('https://example.com/shared-story.jpg');
    expect($resolvedFr['sections'][0]['story_cards'][0]['image_alt'])->toBe('Carte image FR');
    expect($resolvedEn['sections'][0]['story_cards'][0]['image_alt'])->toBe('EN card image');
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

it('stores reusable feature pairs library sections with alternating media fields', function () {
    $user = User::factory()->create();
    $service = app(\App\Services\PlatformSectionContentService::class);

    $featurePairsSection = PlatformSection::query()->create([
        'name' => 'Reusable feature pairs',
        'type' => 'feature_pairs',
        'is_active' => true,
        'content' => ['locales' => []],
    ]);

    $service->updateLocale($featurePairsSection, 'fr', [
        'kicker' => 'HVAC Scheduling Software',
        'title' => 'Streamline scheduling and dispatching',
        'body' => '<p>Une premiere rangee avec image et texte.</p>',
        'image_url' => 'https://example.com/reusable-feature-pairs-primary.jpg',
        'image_alt' => 'Feature pairs primary image',
        'primary_label' => 'Learn more',
        'primary_href' => '/pages/contact-us',
        'aside_kicker' => 'Workiz Pay',
        'aside_title' => 'Accept all forms of payments',
        'aside_body' => '<p>Une seconde rangee alternee avec son propre media.</p>',
        'aside_link_label' => 'Voir les details',
        'aside_link_href' => 'https://example.com/payments',
        'aside_image_url' => 'https://example.com/reusable-feature-pairs-secondary.jpg',
        'aside_image_alt' => 'Feature pairs secondary image',
    ], $user->id);

    $resolved = $service->resolveForLocale($featurePairsSection->fresh(), 'fr');

    expect($resolved['layout'])->toBe('feature_pairs');
    expect($resolved['title'])->toBe('Streamline scheduling and dispatching');
    expect($resolved['aside_title'])->toBe('Accept all forms of payments');
    expect($resolved['aside_image_url'])->toBe('https://example.com/reusable-feature-pairs-secondary.jpg');
    expect($resolved['aside_link_label'])->toBe('Voir les details');
});

it('stores reusable showcase CTA library sections with layered media fields', function () {
    $user = User::factory()->create();
    $service = app(\App\Services\PlatformSectionContentService::class);

    $showcaseSection = PlatformSection::query()->create([
        'name' => 'Reusable showcase CTA',
        'type' => 'showcase_cta',
        'is_active' => true,
        'content' => ['locales' => []],
    ]);

    $service->updateLocale($showcaseSection, 'fr', [
        'title' => 'Essayez-le gratuitement.',
        'body' => '<p>Une section hero media en librairie reutilisable.</p>',
        'background_color' => '#202322',
        'image_position' => 'right',
        'image_url' => 'https://example.com/reusable-showcase-main.jpg',
        'image_alt' => 'Reusable showcase main',
        'aside_image_url' => 'https://example.com/reusable-showcase-mobile.jpg',
        'aside_image_alt' => 'Reusable showcase mobile',
        'aside_link_label' => 'Voir la visite produit',
        'aside_link_href' => 'https://example.com/product-tour',
        'showcase_badge_label' => 'Adopte par',
        'showcase_badge_value' => '+120,000',
        'showcase_badge_note' => 'pros du service',
        'primary_label' => 'Commencer gratuitement',
        'primary_href' => '/pages/contact-us',
    ], $user->id);

    $resolved = $service->resolveForLocale($showcaseSection->fresh(), 'fr');

    expect($resolved['layout'])->toBe('showcase_cta');
    expect($resolved['background_color'])->toBe('#202322');
    expect($resolved['image_position'])->toBe('right');
    expect($resolved['aside_image_url'])->toBe('https://example.com/reusable-showcase-mobile.jpg');
    expect($resolved['aside_link_label'])->toBe('Voir la visite produit');
    expect($resolved['showcase_badge_value'])->toBe('+120,000');
});

it('shares reusable section media across locales while keeping text localized', function () {
    $user = User::factory()->create();
    $service = app(\App\Services\PlatformSectionContentService::class);

    $section = PlatformSection::query()->create([
        'name' => 'Shared media section',
        'type' => 'showcase_cta',
        'is_active' => true,
        'content' => ['locales' => []],
    ]);

    $service->updateLocale($section, 'fr', [
        'layout' => 'showcase_cta',
        'title' => 'Titre FR',
        'body' => '<p>Corps FR</p>',
        'image_url' => 'https://example.com/shared-section-main.jpg',
        'image_alt' => 'Image principale FR',
        'aside_image_url' => 'https://example.com/shared-section-aside.jpg',
        'aside_image_alt' => 'Image aside FR',
        'showcase_badge_label' => 'Badge FR',
    ], $user->id);

    $enPayload = $service->resolveForLocale($section->fresh(), 'en');
    $enPayload['title'] = 'Title EN';
    $enPayload['body'] = '<p>Body EN</p>';
    $enPayload['image_alt'] = 'Main image EN';
    $enPayload['aside_image_alt'] = 'Aside image EN';
    $enPayload['showcase_badge_label'] = 'Badge EN';

    $service->updateLocale($section->fresh(), 'en', $enPayload, $user->id);

    $freshSection = $section->fresh();
    $resolvedFr = $service->resolveForLocale($freshSection, 'fr');
    $resolvedEn = $service->resolveForLocale($freshSection, 'en');

    expect($freshSection->content['shared_media']['image_url'] ?? null)->toBe('https://example.com/shared-section-main.jpg');
    expect($freshSection->content['shared_media']['aside_image_url'] ?? null)->toBe('https://example.com/shared-section-aside.jpg');
    expect($resolvedFr['title'])->toBe('Titre FR');
    expect($resolvedEn['title'])->toBe('Title EN');
    expect($resolvedFr['image_url'])->toBe('https://example.com/shared-section-main.jpg');
    expect($resolvedEn['image_url'])->toBe('https://example.com/shared-section-main.jpg');
    expect($resolvedFr['image_alt'])->toBe('Image principale FR');
    expect($resolvedEn['image_alt'])->toBe('Main image EN');
    expect($resolvedFr['aside_image_url'])->toBe('https://example.com/shared-section-aside.jpg');
    expect($resolvedEn['aside_image_url'])->toBe('https://example.com/shared-section-aside.jpg');
    expect($resolvedFr['aside_image_alt'])->toBe('Image aside FR');
    expect($resolvedEn['aside_image_alt'])->toBe('Aside image EN');
});

it('stores reusable industry grid library sections with card items', function () {
    $user = User::factory()->create();
    $service = app(\App\Services\PlatformSectionContentService::class);

    $industryGridSection = PlatformSection::query()->create([
        'name' => 'Reusable industries',
        'type' => 'industry_grid',
        'is_active' => true,
        'content' => ['locales' => []],
    ]);

    $service->updateLocale($industryGridSection, 'fr', [
        'title' => 'Fier partenaire des services a domicile dans plus de 50 industries.',
        'background_color' => '#f7f2e8',
        'primary_label' => 'Voir toutes les industries',
        'primary_href' => '/pages/contact-us',
        'industry_cards' => [
            ['id' => 'card-1', 'label' => 'Plomberie', 'href' => '/pages/industry-plumbing', 'icon' => 'shower-head'],
            ['id' => 'card-2', 'label' => 'HVAC', 'href' => '/pages/industry-hvac', 'icon' => 'fan'],
            ['id' => 'card-3', 'label' => 'Electricite', 'href' => '/pages/industry-electrical', 'icon' => 'plug-zap'],
            ['id' => 'card-4', 'label' => 'Securite', 'href' => '/pages/industry-security', 'icon' => 'shield-check'],
        ],
    ], $user->id);

    $resolved = $service->resolveForLocale($industryGridSection->fresh(), 'fr');

    expect($resolved['layout'])->toBe('industry_grid');
    expect($resolved['background_color'])->toBe('#f7f2e8');
    expect($resolved['industry_cards'])->toHaveCount(4);
    expect($resolved['industry_cards'][2]['label'])->toBe('Electricite');
    expect($resolved['industry_cards'][2]['icon'])->toBe('plug-zap');
    expect($resolved['industry_cards'][3]['icon'])->toBe('shield-check');
    expect($resolved['primary_label'])->toBe('Voir toutes les industries');
});

it('stores reusable story grid library sections with visual cards', function () {
    $user = User::factory()->create();
    $service = app(\App\Services\PlatformSectionContentService::class);

    $storyGridSection = PlatformSection::query()->create([
        'name' => 'Reusable story grid',
        'type' => 'story_grid',
        'is_active' => true,
        'content' => ['locales' => []],
    ]);

    $service->updateLocale($storyGridSection, 'fr', [
        'title' => 'Une IA pensee pour les entreprises de terrain.',
        'background_color' => '#f7f2e8',
        'story_cards' => [
            [
                'id' => 'story-card-1',
                'title' => 'Concue pour le terrain',
                'body' => '<p>Elle comprend mieux les realites terrain.</p>',
                'image_url' => 'https://example.com/reusable-story-grid-1.jpg',
                'image_alt' => 'Visuel terrain',
            ],
            [
                'id' => 'story-card-2',
                'title' => 'S adapte a votre workflow',
                'body' => '<p>Elle suit vos devis, vos descriptions et votre cadence.</p>',
                'image_url' => 'https://example.com/reusable-story-grid-2.jpg',
                'image_alt' => 'Visuel workflow',
            ],
            [
                'id' => 'story-card-3',
                'title' => 'Intervient au bon moment',
                'body' => '<p>Elle ressort les bons signaux au moment utile.</p>',
                'image_url' => 'https://example.com/reusable-story-grid-3.jpg',
                'image_alt' => 'Visuel intelligence',
            ],
        ],
    ], $user->id);

    $resolved = $service->resolveForLocale($storyGridSection->fresh(), 'fr');

    expect($resolved['layout'])->toBe('story_grid');
    expect($resolved['background_color'])->toBe('#f7f2e8');
    expect($resolved['story_cards'])->toHaveCount(3);
    expect($resolved['story_cards'][0]['title'])->toBe('Concue pour le terrain');
    expect($resolved['story_cards'][1]['image_url'])->toBe('https://example.com/reusable-story-grid-2.jpg');
    expect($resolved['story_cards'][2]['image_alt'])->toBe('Visuel intelligence');
});

it('stores reusable feature tabs library sections with tab content', function () {
    $user = User::factory()->create();
    $service = app(\App\Services\PlatformSectionContentService::class);

    $featureTabsSection = PlatformSection::query()->create([
        'name' => 'Reusable feature tabs',
        'type' => 'feature_tabs',
        'is_active' => true,
        'content' => ['locales' => []],
    ]);

    $service->updateLocale($featureTabsSection, 'fr', [
        'title' => 'Un logiciel de gestion terrain qui travaille pour vous.',
        'body' => '<p>Centralisez votre operation dans un seul flux.</p>',
        'primary_label' => 'Voir comment ca marche',
        'primary_href' => '/pages/contact-us',
        'feature_tabs_font_size' => 30,
        'feature_tabs' => [
            [
                'id' => 'tab-1',
                'label' => 'Planifier',
                'icon' => 'calendar-days',
                'items' => ['Calendrier glisser-deposer', 'Affectation equipe'],
                'children' => [
                    [
                        'id' => 'tab-1-child-1',
                        'label' => 'Calendrier glisser-deposer',
                        'title' => 'Planifiez chaque intervention sans friction',
                        'body' => '<p>Organisez vos jobs et gardez toute l equipe synchronisee.</p>',
                        'image_url' => 'https://example.com/reusable-feature-tabs-schedule.jpg',
                        'image_alt' => 'Planning mobile',
                        'cta_label' => 'Voir la planification',
                        'cta_href' => '/pages/contact-us',
                    ],
                ],
                'title' => 'Planifiez chaque intervention sans friction',
                'body' => '<p>Organisez vos jobs et gardez toute l equipe synchronisee.</p>',
                'image_url' => 'https://example.com/reusable-feature-tabs-schedule.jpg',
                'image_alt' => 'Planning mobile',
                'cta_label' => 'Voir la planification',
                'cta_href' => '/pages/contact-us',
            ],
            [
                'id' => 'tab-2',
                'label' => 'Etre paye',
                'icon' => 'circle-dollar-sign',
                'items' => ['Paiements sur place', 'Rappels automatiques'],
                'children' => [
                    [
                        'id' => 'tab-2-child-1',
                        'label' => 'Paiements sur place',
                        'title' => 'Encaissez plus vite sur le terrain',
                        'body' => '<p>Acceptez plusieurs moyens de paiement sans attendre le retour au bureau.</p>',
                        'image_url' => 'https://example.com/reusable-feature-tabs-payments.jpg',
                        'image_alt' => 'Paiement mobile',
                        'cta_label' => 'Voir les paiements',
                        'cta_href' => 'https://example.com/payments',
                    ],
                ],
                'title' => 'Encaissez plus vite sans relances manuelles',
                'body' => '<p>Accélérez vos encaissements avec plusieurs moyens de paiement.</p>',
                'image_url' => 'https://example.com/reusable-feature-tabs-payments.jpg',
                'image_alt' => 'Paiement mobile',
                'cta_label' => 'Voir les paiements',
                'cta_href' => 'https://example.com/payments',
                'metric' => 'Encaissements 2x plus rapides',
                'story' => '<p>Les rappels automatiques ont reduit nos retards de paiement.</p>',
                'person' => 'Equipe finance',
                'role' => 'Facturation',
                'avatar_url' => 'https://example.com/reusable-feature-tabs-finance-avatar.jpg',
                'avatar_alt' => 'Portrait equipe finance',
            ],
        ],
    ], $user->id);

    $resolved = $service->resolveForLocale($featureTabsSection->fresh(), 'fr');

    expect($resolved['layout'])->toBe('feature_tabs');
    expect($resolved['feature_tabs'])->toHaveCount(2);
    expect($resolved['feature_tabs_font_size'])->toBe(30);
    expect($resolved['feature_tabs'][0]['label'])->toBe('Planifier');
    expect($resolved['feature_tabs'][1]['icon'])->toBe('circle-dollar-sign');
    expect($resolved['feature_tabs'][1]['cta_label'])->toBe('Voir les paiements');
    expect($resolved['feature_tabs'][1]['metric'])->toBe('Encaissements 2x plus rapides');
    expect($resolved['feature_tabs'][1]['person'])->toBe('Equipe finance');
    expect($resolved['feature_tabs'][1]['children'])->toHaveCount(1);
    expect($resolved['feature_tabs'][1]['children'][0]['label'])->toBe('Paiements sur place');
});

it('stores reusable testimonial grid library sections with card content', function () {
    $user = User::factory()->create();
    $service = app(\App\Services\PlatformSectionContentService::class);

    $testimonialGridSection = PlatformSection::query()->create([
        'name' => 'Reusable testimonial grid',
        'type' => 'testimonial_grid',
        'is_active' => true,
        'content' => ['locales' => []],
    ]);

    $service->updateLocale($testimonialGridSection, 'fr', [
        'title' => 'Approuve par les meilleures equipes d entretien.',
        'body' => '<p>Les pros de l entretien utilisent Malikia pro pour mieux coordonner leur equipe.</p>',
        'background_color' => '#f7f2e8',
        'testimonial_cards' => [
            [
                'id' => 'testimonial-grid-card-1',
                'quote' => '<p>\"Nos checklists rassurent les clients et montrent clairement ce qui a ete fait.\"</p>',
                'author_name' => 'Julie Morin',
                'author_role' => 'Fondatrice',
                'author_company' => 'Maison Claire Montreal',
                'image_url' => 'https://example.com/reusable-testimonial-grid-1.jpg',
                'image_alt' => 'Julie Morin',
            ],
            [
                'id' => 'testimonial-grid-card-2',
                'quote' => '<p>\"Toute mon equipe a les details du job sur son telephone.\"</p>',
                'author_name' => 'Cynthia Gagnon',
                'author_company' => 'Nordik Clean',
            ],
        ],
    ], $user->id);

    $resolved = $service->resolveForLocale($testimonialGridSection->fresh(), 'fr');

    expect($resolved['layout'])->toBe('testimonial_grid');
    expect($resolved['testimonial_cards'])->toHaveCount(2);
    expect($resolved['testimonial_cards'][0]['author_role'])->toBe('Fondatrice');
    expect($resolved['testimonial_cards'][1]['author_company'])->toBe('Nordik Clean');
});

it('stores reusable footer library sections with support content', function () {
    $user = User::factory()->create();
    $service = app(\App\Services\PlatformSectionContentService::class);

    $footerSection = PlatformSection::query()->create([
        'name' => 'Reusable footer',
        'type' => 'footer',
        'is_active' => true,
        'content' => ['locales' => []],
    ]);

    $service->updateLocale($footerSection, 'fr', [
        'background_color' => '#0b3b4a',
        'kicker' => 'Accompagnement',
        'title' => 'Parlez a notre equipe',
        'body' => '<p>Un bloc de footer partage pour toutes les pages publiques.</p>',
        'items' => [
            'Accompagnement produit',
            'Parcours public personnalise',
            'Disponible en francais et en anglais',
        ],
        'primary_label' => 'Nous contacter',
        'primary_href' => '/pages/contact-us',
        'secondary_label' => 'Voir les tarifs',
        'secondary_href' => '/pricing',
        'copy' => 'Tous droits reserves.',
        'brand_logo_url' => '/images/footer-logo-test.svg',
        'brand_logo_alt' => 'Logo footer',
        'brand_href' => '/',
        'contact_phone' => '+1 514 555 0189',
        'contact_email' => 'bonjour@example.test',
        'social_instagram_href' => 'https://instagram.com/example',
        'social_linkedin_href' => 'https://linkedin.com/company/example',
        'google_play_href' => 'https://play.google.com/store/apps/details?id=example.app',
        'app_store_href' => 'https://apps.apple.com/app/example-app/id123456789',
        'footer_groups' => [
            [
                'id' => 'footer-group-products',
                'title' => 'Produits',
                'layout' => 'stack',
                'links' => [
                    ['id' => 'footer-link-sales', 'label' => 'Sales & CRM', 'href' => '/pages/sales-crm', 'note' => ''],
                    ['id' => 'footer-link-reservations', 'label' => 'Reservations', 'href' => '/pages/reservations', 'note' => ''],
                ],
            ],
            [
                'id' => 'footer-group-resources',
                'title' => 'Ressources',
                'layout' => 'stack',
                'links' => [
                    ['id' => 'footer-link-pricing', 'label' => 'Tarification', 'href' => '/pricing', 'note' => ''],
                ],
            ],
        ],
        'legal_links' => [
            ['id' => 'legal-link-pricing', 'label' => 'Tarification', 'href' => '/pricing', 'note' => ''],
            ['id' => 'legal-link-privacy', 'label' => 'Confidentialite', 'href' => '/privacy', 'note' => ''],
        ],
    ], $user->id);

    $resolved = $service->resolveForLocale($footerSection->fresh(), 'fr');

    expect($resolved['layout'])->toBe('footer');
    expect($resolved['background_color'])->toBe('#0b3b4a');
    expect($resolved['title'])->toBe('Parlez a notre equipe');
    expect($resolved['items'])->toHaveCount(3);
    expect($resolved['primary_label'])->toBe('Nous contacter');
    expect($resolved['secondary_href'])->toBe('/pricing');
    expect($resolved['copy'])->toBe('Tous droits reserves.');
    expect($resolved['brand_logo_url'])->toBe('/images/footer-logo-test.svg');
    expect($resolved['brand_href'])->toBe('/');
    expect($resolved['contact_phone'])->toBe('+1 514 555 0189');
    expect($resolved['contact_email'])->toBe('bonjour@example.test');
    expect($resolved['social_instagram_href'])->toBe('https://instagram.com/example');
    expect($resolved['google_play_href'])->toContain('play.google.com');
    expect($resolved['app_store_href'])->toContain('apps.apple.com');
    expect($resolved['footer_groups'])->toHaveCount(2);
    expect($resolved['footer_groups'][0]['title'])->toBe('Produits');
    expect($resolved['footer_groups'][0]['links'][0]['label'])->toBe('Sales & CRM');
    expect($resolved['legal_links'])->toHaveCount(2);
    expect($resolved['legal_links'][1]['href'])->toBe('/privacy');
});

it('exposes the active reusable footer section on public pages', function () {
    $this->seed(MegaMenuSeeder::class);

    PlatformSection::query()->create([
        'name' => 'Shared footer',
        'type' => 'footer',
        'is_active' => true,
        'content' => [
            'locales' => [
                'fr' => [
                    'layout' => 'footer',
                    'background_color' => '#0b3b4a',
                    'kicker' => 'Accompagnement',
                    'title' => 'Parlez a notre equipe',
                    'body' => '<p>Un footer partage.</p>',
                    'items' => ['Equipe produit', 'Setup public', 'Bilingue'],
                    'primary_label' => 'Nous contacter',
                    'primary_href' => '/pages/contact-us',
                    'secondary_label' => 'Voir les tarifs',
                    'secondary_href' => '/pricing',
                    'copy' => 'Tous droits reserves.',
                    'brand_logo_url' => '/images/footer-logo-fr.svg',
                    'brand_logo_alt' => 'Logo footer FR',
                    'brand_href' => '/',
                    'contact_phone' => '+1 514 555 0189',
                    'contact_email' => 'bonjour@example.test',
                    'social_x_href' => 'https://x.com/example',
                    'google_play_href' => 'https://play.google.com/store/apps/details?id=example.app',
                    'app_store_href' => 'https://apps.apple.com/app/example-app/id123456789',
                    'footer_groups' => [
                        [
                            'id' => 'footer-group-fr-products',
                            'title' => 'Produits',
                            'layout' => 'stack',
                            'links' => [
                                ['id' => 'footer-group-fr-products-sales', 'label' => 'Sales & CRM', 'href' => '/pages/sales-crm', 'note' => ''],
                            ],
                        ],
                    ],
                    'legal_links' => [
                        ['id' => 'legal-link-fr-pricing', 'label' => 'Tarification', 'href' => '/pricing', 'note' => ''],
                    ],
                ],
                'en' => [
                    'layout' => 'footer',
                    'background_color' => '#0b3b4a',
                    'kicker' => 'Support',
                    'title' => 'Talk to our team',
                    'body' => '<p>A shared footer section.</p>',
                    'items' => ['Product enablement', 'Public setup', 'Bilingual support'],
                    'primary_label' => 'Contact us',
                    'primary_href' => '/pages/contact-us',
                    'secondary_label' => 'View pricing',
                    'secondary_href' => '/pricing',
                    'copy' => 'All rights reserved.',
                    'brand_logo_url' => '/images/footer-logo-en.svg',
                    'brand_logo_alt' => 'Footer logo EN',
                    'brand_href' => '/',
                    'contact_phone' => '+1 514 555 0189',
                    'contact_email' => 'hello@example.test',
                    'social_x_href' => 'https://x.com/example',
                    'google_play_href' => 'https://play.google.com/store/apps/details?id=example.app',
                    'app_store_href' => 'https://apps.apple.com/app/example-app/id123456789',
                    'footer_groups' => [
                        [
                            'id' => 'footer-group-en-products',
                            'title' => 'Products',
                            'layout' => 'stack',
                            'links' => [
                                ['id' => 'footer-group-en-products-sales', 'label' => 'Sales & CRM', 'href' => '/pages/sales-crm', 'note' => ''],
                            ],
                        ],
                    ],
                    'legal_links' => [
                        ['id' => 'legal-link-en-pricing', 'label' => 'Pricing', 'href' => '/pricing', 'note' => ''],
                    ],
                ],
            ],
            'updated_by' => null,
            'updated_at' => now()->toIso8601String(),
        ],
    ]);

    $this->get(route('public.pages.show', ['slug' => 'sales-crm']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/Page')
            ->where('footerSection.layout', 'footer')
            ->where('footerSection.background_color', '#0b3b4a')
            ->where('footerSection.title', 'Talk to our team')
            ->where('footerSection.items.0', 'Product enablement')
            ->where('footerSection.copy', 'All rights reserved.')
            ->where('footerSection.brand_logo_url', '/images/footer-logo-en.svg')
            ->where('footerSection.contact_phone', '+1 514 555 0189')
            ->where('footerSection.contact_email', 'hello@example.test')
            ->where('footerSection.social_x_href', 'https://x.com/example')
            ->where('footerSection.google_play_href', 'https://play.google.com/store/apps/details?id=example.app')
            ->where('footerSection.footer_groups.0.title', 'Products')
            ->where('footerSection.footer_groups.0.links.0.label', 'Sales & CRM')
            ->where('footerSection.legal_links.0.label', 'Pricing')
        );
});
