<?php

use App\Models\PlatformPage;
use App\Models\PlatformSection;
use App\Models\User;
use App\Services\MegaMenus\MegaMenuManagerService;
use App\Services\MegaMenus\MegaMenuRenderer;
use App\Services\PlatformPageContentService;
use App\Services\PlatformSectionContentService;
use App\Services\WelcomeContentService;
use App\Support\LocalePreference;
use App\Support\PublicPageStockImages;
use App\Support\PublicProductPageLocalizedOverrides;
use App\Support\PublicProductPageNarratives;
use App\Support\WelcomeShowcaseSection;

$loadJsLocaleModules = function (string $locale): array {
    $directory = resource_path("js/i18n/modules/{$locale}");
    $files = glob($directory.'/*.json') ?: [];
    sort($files);

    $merged = [];
    foreach ($files as $file) {
        $payload = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $merged = array_replace_recursive($merged, $payload);
    }

    return $merged;
};

it('accepts spanish as a locale preference', function () {
    $user = User::factory()->create();

    $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

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

it('keeps spanish in locale fallback defaults when config is unavailable', function () {
    config()->set('app.supported_locales', null);

    expect(LocalePreference::supported())->toContain('es')
        ->and(LocalePreference::normalize('es'))->toBe('es');
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

it('loads spanish welcome defaults from dedicated locale files', function () {
    $resolved = app(WelcomeContentService::class)->defaultContent('es');

    expect($resolved['hero']['title'])->toBe(trans('welcome.hero.title', [], 'es'))
        ->and($resolved['hero']['primary_cta'])->toBe('Iniciar prueba')
        ->and($resolved['trust']['items'][0])->toBe('Fontaneria')
        ->and($resolved['features']['items'][0]['title'])->toBe('Clientes y solicitudes');
});

it('builds localized welcome showcase payloads with english fallback', function () {
    $esPayload = WelcomeShowcaseSection::payload('es');
    $fallbackPayload = WelcomeShowcaseSection::payload('pt-BR');

    expect($esPayload['title'])->toBe('Descubre como Malikia Pro impulsa el crecimiento desde el primer clic hasta el pago final')
        ->and($esPayload['feature_tabs'][0]['label'])->toBe('Hazte visible')
        ->and($esPayload['feature_tabs'][0]['image_alt'])->toBe('Profesional gestionando mensajes y solicitudes desde su escritorio')
        ->and($fallbackPayload['title'])->toBe('See how Malikia Pro supports growth from first click to final payment')
        ->and($fallbackPayload['feature_tabs'][1]['label'])->toBe('Win work');
});

it('renders spanish helper labels for transactional emails', function () {
    app()->setLocale('es');

    $resetHtml = view('emails.auth.reset-password', [
        'recipientName' => 'Ana',
        'companyName' => 'Demo Services',
        'resetUrl' => 'https://example.com/reset-password',
        'expiresInMinutes' => 45,
    ])->render();

    $billingHtml = view('emails.billing.upcoming-reminder', [
        'recipientName' => 'Ana',
        'companyName' => 'Demo Services',
        'planName' => 'Growth',
        'billingDateLabel' => '2026-04-20',
        'formattedTotal' => '$199',
        'formattedSubtotal' => '$180',
        'formattedTax' => '$19',
        'billingPeriod' => 'monthly',
        'daysUntilBilling' => 9,
        'seatQuantity' => 4,
        'currencyCode' => 'CAD',
        'supportEmail' => 'support@example.com',
        'lineItems' => [
            ['label' => 'Growth', 'quantity' => 4, 'formatted_amount' => '$199'],
        ],
    ])->render();

    $digestHtml = view('emails.notifications.digest', [
        'frequency' => trans('mail.platform_admin_digest.weekly', [], 'es'),
        'generatedAt' => now(),
        'supportEmail' => 'support@example.com',
        'items' => [
            [
                'title' => 'Cambio operativo',
                'category' => 'ops',
                'intro' => 'Seguimiento diario',
                'created_at' => now()->toIso8601String(),
            ],
        ],
    ])->render();

    expect($resetHtml)->toContain('Enlace')
        ->and($resetHtml)->toContain('Un solo uso')
        ->and($resetHtml)->not->toContain('Single use')
        ->and($billingHtml)->toContain('Resumen de facturacion')
        ->and($billingHtml)->toContain('Asientos')
        ->and($billingHtml)->toContain('Dias')
        ->and($digestHtml)->toContain('Frecuencia')
        ->and($digestHtml)->toContain('Alcance')
        ->and(trans('mail.common.total', [], 'es'))->toBe('Total')
        ->and(trans('mail.common.label_deposit', [], 'es'))->toBe('Deposito');
});

it('returns spanish stock image alts for public visuals', function () {
    $beautyVisual = PublicPageStockImages::visual('beauty-treatment', 'es');
    $workflowVisual = PublicPageStockImages::visual('workflow-plan', 'es');

    expect($beautyVisual['image_alt'])->toBe('Profesional de belleza preparando un tratamiento en un salon')
        ->and($workflowVisual['image_alt'])->toBe('Profesionales revisando un plan antes de la ejecucion');
});

it('returns dedicated spanish narratives for public product pages', function () {
    $expectations = [
        'sales-crm' => [
            '0.title' => 'Convierte la demanda entrante en trabajo aprobado con menos friccion',
            '0.feature_tabs.0.label' => 'Captar demanda',
            '1.primary_label' => 'Ver la solucion Ventas y presupuestos',
        ],
        'reservations' => [
            '0.title' => 'Convierte la reserva en un recorrido completo del cliente',
            '0.primary_label' => 'Ver la solucion Reservas y filas',
        ],
        'operations' => [
            '0.title' => 'Planifica, asigna, ejecuta y cierra el trabajo desde una misma vista operativa',
            '0.feature_tabs.1.label' => 'Despachar',
        ],
        'commerce' => [
            '0.title' => 'Convierte tu catalogo en ingresos sin fragmentar la experiencia',
            '0.feature_tabs.1.label' => 'Pedido guiado',
        ],
        'marketing-loyalty' => [
            '0.title' => 'Convierte la actividad del cliente en acciones de retencion que realmente lo hagan volver',
            '0.feature_tabs.0.label' => 'Escuchar',
        ],
        'ai-automation' => [
            '0.title' => 'Pon la IA donde los equipos ya necesitan ayuda, velocidad y contexto',
            '0.feature_tabs.3.label' => 'Mantener control',
        ],
        'command-center' => [
            '0.title' => 'Convierte la visibilidad transversal en prioridades mas claras y accion mas rapida',
            '1.primary_label' => 'Ver la solucion Supervision multiempresa',
        ],
    ];

    foreach ($expectations as $slug => $pairs) {
        $esSections = PublicProductPageNarratives::sections($slug, 'es');
        $enSections = PublicProductPageNarratives::sections($slug, 'en');

        foreach ($pairs as $path => $expected) {
            expect(data_get($esSections, $path))->toBe($expected);
        }

        expect(data_get($esSections, '0.title'))->not->toBe(data_get($enSections, '0.title'));
    }
});

it('exposes extracted spanish override maps for all product narratives', function () {
    $expectations = [
        'sales-crm' => [
            '0.title' => 'Convierte la demanda entrante en trabajo aprobado con menos friccion',
            '1.primary_label' => 'Ver la solucion Ventas y presupuestos',
        ],
        'reservations' => [
            '0.title' => 'Convierte la reserva en un recorrido completo del cliente',
            '1.primary_label' => 'Ver la solucion Reservas y filas',
        ],
        'operations' => [
            '0.title' => 'Planifica, asigna, ejecuta y cierra el trabajo desde una misma vista operativa',
            '1.primary_label' => 'Ver la solucion Servicios de campo',
        ],
        'commerce' => [
            '0.title' => 'Convierte tu catalogo en ingresos sin fragmentar la experiencia',
            '1.secondary_label' => 'Ver la solucion Comercio y catalogo',
        ],
        'marketing-loyalty' => [
            '0.title' => 'Convierte la actividad del cliente en acciones de retencion que realmente lo hagan volver',
            '0.feature_tabs.0.label' => 'Escuchar',
        ],
        'ai-automation' => [
            '0.title' => 'Pon la IA donde los equipos ya necesitan ayuda, velocidad y contexto',
            '0.feature_tabs.3.label' => 'Mantener control',
        ],
        'command-center' => [
            '0.title' => 'Convierte la visibilidad transversal en prioridades mas claras y accion mas rapida',
            '1.primary_label' => 'Ver la solucion Supervision multiempresa',
        ],
    ];

    foreach ($expectations as $slug => $pairs) {
        $overrides = PublicProductPageLocalizedOverrides::for($slug, 'es');

        foreach ($pairs as $path => $expected) {
            expect($overrides[$path] ?? null)->toBe($expected);
        }
    }

    expect(PublicProductPageLocalizedOverrides::for('sales-crm', 'en'))->toBe([]);
});

it('loads modular locale files for all backoffice groups', function () use ($loadJsLocaleModules) {
    $en = $loadJsLocaleModules('en');
    $fr = $loadJsLocaleModules('fr');
    $es = $loadJsLocaleModules('es');

    expect(array_keys($en))->toEqualCanonicalizing(array_keys($fr))
        ->and(array_keys($en))->toEqualCanonicalizing(array_keys($es))
        ->and(data_get($es, 'dashboard.title'))->toBe('Dashboard')
        ->and(data_get($es, 'customers.stats.total'))->toBe('Clientes totales')
        ->and(data_get($es, 'client_dashboard.title'))->toBe('Portal del cliente')
        ->and(data_get($es, 'dashboard_products.owner.title'))->toBe('Dashboard de productos')
        ->and(data_get($es, 'dashboard_tasks.timeline.title'))->toBe('Agenda de hoy')
        ->and(data_get($en, 'dashboard.marketing_panel.title'))->toBe('Marketing KPI')
        ->and(data_get($fr, 'dashboard.marketing_panel.title'))->toBe('KPI marketing');
});
