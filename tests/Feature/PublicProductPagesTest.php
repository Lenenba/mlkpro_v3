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
        'body' => '<p>Les pros de l entretien utilisent MLK Pro pour mieux coordonner leur equipe.</p>',
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
            ->where('content.sections.0.primary_label', 'Voir comment ca marche')
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
        'body' => '<p>Les pros de l entretien utilisent MLK Pro pour mieux coordonner leur equipe.</p>',
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
