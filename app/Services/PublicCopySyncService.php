<?php

namespace App\Services;

use App\Models\PlatformPage;
use App\Models\PlatformSection;
use App\Models\Role;
use App\Models\User;
use App\Support\WelcomeEditorialSections;
use App\Support\WelcomeStockImages;
use Database\Seeders\MegaMenuSeeder;
use InvalidArgumentException;

class PublicCopySyncService
{
    private const TARGET_PAGES = 'pages';

    private const TARGET_WELCOME = 'welcome';

    private const TARGET_FOOTER = 'footer';

    /**
     * @return array<int, string>
     */
    private function allowedTargets(): array
    {
        return [
            self::TARGET_PAGES,
            self::TARGET_WELCOME,
            self::TARGET_FOOTER,
        ];
    }

    /**
     * @param  array<int, string>  $targets
     * @return array<string, mixed>
     */
    public function sync(array $targets = [], ?int $userId = null): array
    {
        $targets = $this->normalizeTargets($targets);
        $userId = $this->resolveUserId($userId);

        $summary = [
            'targets' => $targets,
            'user_id' => $userId,
        ];

        if (in_array(self::TARGET_PAGES, $targets, true)) {
            $summary['pages'] = app(MegaMenuSeeder::class)->sync($userId);
        }

        if (in_array(self::TARGET_WELCOME, $targets, true)) {
            $summary['welcome'] = $this->syncWelcome($userId);
        }

        if (in_array(self::TARGET_FOOTER, $targets, true)) {
            $summary['footer'] = $this->syncSharedFooter($userId);
        }

        return $summary;
    }

    /**
     * @param  array<int, string>  $targets
     * @return array<int, string>
     */
    private function normalizeTargets(array $targets): array
    {
        $normalized = [];

        foreach ($targets as $target) {
            foreach (explode(',', strtolower(trim((string) $target))) as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }

                $normalized[] = $part;
            }
        }

        if ($normalized === [] || in_array('all', $normalized, true)) {
            return $this->allowedTargets();
        }

        $normalized = array_values(array_unique($normalized));
        $invalid = array_values(array_diff($normalized, $this->allowedTargets()));

        if ($invalid !== []) {
            throw new InvalidArgumentException(
                'Invalid target(s): '.implode(', ', $invalid).'. Allowed values: '.implode(', ', [...$this->allowedTargets(), 'all']).'.'
            );
        }

        return $normalized;
    }

    private function resolveUserId(?int $userId): ?int
    {
        if ($userId !== null) {
            if (! User::query()->whereKey($userId)->exists()) {
                throw new InvalidArgumentException("User {$userId} does not exist.");
            }

            return $userId;
        }

        $superadminRoleId = Role::query()->where('name', 'superadmin')->value('id');
        if (! $superadminRoleId) {
            return null;
        }

        return User::query()
            ->where('role_id', $superadminRoleId)
            ->orderBy('id')
            ->value('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function syncWelcome(?int $userId): array
    {
        $welcomeContentService = app(WelcomeContentService::class);

        foreach ($welcomeContentService->locales() as $locale) {
            $welcomeContentService->updateLocale(
                $locale,
                $welcomeContentService->defaultContent($locale),
                [],
                $userId
            );
        }

        $page = app(PlatformWelcomePageService::class)->ensurePageExists($userId);
        $sourceSections = $this->syncWelcomeSourceSections($page, $userId);
        $pageContentService = app(PlatformPageContentService::class);
        $existing = $pageContentService->resolveAll($page);
        $theme = $pageContentService->resolveTheme($page);

        foreach ($pageContentService->locales() as $locale) {
            $current = is_array($existing[$locale] ?? null) ? $existing[$locale] : [];

            $payload = [
                'page_title' => (string) ($current['page_title'] ?? $page->title),
                'page_subtitle' => (string) ($current['page_subtitle'] ?? ''),
                'header' => is_array($current['header'] ?? null)
                    ? $current['header']
                    : $this->defaultPageHeader(),
                'sections' => [
                    ...$this->welcomeSourceReferences($sourceSections, $locale),
                    ...WelcomeEditorialSections::genericSections($locale),
                ],
            ];

            $pageContentService->updateLocale($page, $locale, $payload, $userId, $theme);
            $page = $page->fresh();
        }

        $page->forceFill([
            'title' => 'Welcome',
            'is_active' => true,
            'updated_by' => $userId,
            'published_at' => $page->published_at ?? now(),
        ])->save();

        return [
            'page_id' => $page->id,
            'source_section_ids' => array_map(
                fn (PlatformSection $section) => $section->id,
                array_values($sourceSections)
            ),
            'locales' => $pageContentService->locales(),
        ];
    }

    /**
     * @return array<string, PlatformSection>
     */
    private function syncWelcomeSourceSections(PlatformPage $page, ?int $userId): array
    {
        $sectionContentService = app(PlatformSectionContentService::class);
        $existing = $this->welcomeReferencedSectionsByType($page);
        $synced = [];

        foreach ($this->welcomeSourceDefinitions() as $definition) {
            $type = $definition['type'];
            $section = $existing[$type] ?? PlatformSection::query()->create([
                'name' => $definition['name'],
                'type' => $type,
                'is_active' => true,
                'content' => ['locales' => []],
                'updated_by' => $userId,
            ]);

            $section->forceFill([
                'name' => $definition['name'],
                'type' => $type,
                'is_active' => true,
                'updated_by' => $userId,
            ])->save();

            foreach ($sectionContentService->locales() as $locale) {
                $payload = $definition['build']($locale);
                $sectionContentService->updateLocale($section, $locale, $payload, $userId);
                $section = $section->fresh();
            }

            $synced[$definition['key']] = $section->fresh();
        }

        return $synced;
    }

    /**
     * @return array<string, PlatformSection>
     */
    private function welcomeReferencedSectionsByType(PlatformPage $page): array
    {
        $payload = is_array($page->content) ? $page->content : [];
        $locales = is_array($payload['locales'] ?? null) ? $payload['locales'] : [];
        $sourceIds = [];

        foreach ($locales as $localeContent) {
            $sections = is_array($localeContent['sections'] ?? null) ? $localeContent['sections'] : [];
            foreach ($sections as $section) {
                if (! is_array($section) || empty($section['use_source']) || empty($section['source_id'])) {
                    continue;
                }

                $sourceIds[] = (int) $section['source_id'];
            }
        }

        if ($sourceIds === []) {
            return [];
        }

        $existing = [];

        foreach (PlatformSection::query()->whereIn('id', array_values(array_unique($sourceIds)))->get() as $section) {
            $type = strtolower(trim((string) $section->type));
            if ($type === '' || array_key_exists($type, $existing)) {
                continue;
            }

            $existing[$type] = $section;
        }

        return $existing;
    }

    /**
     * @return array<int, array{build: callable, key: string, name: string, type: string}>
     */
    private function welcomeSourceDefinitions(): array
    {
        return [
            [
                'key' => 'hero',
                'name' => 'Welcome Hero',
                'type' => 'welcome_hero',
                'build' => fn (string $locale) => $this->welcomeHeroSectionPayload($locale),
            ],
            [
                'key' => 'trust',
                'name' => 'Welcome Trust',
                'type' => 'welcome_trust',
                'build' => fn (string $locale) => $this->welcomeTrustSectionPayload($locale),
            ],
            [
                'key' => 'showcase',
                'name' => 'Welcome Showcase',
                'type' => 'feature_tabs',
                'build' => fn (string $locale) => $this->welcomeShowcaseSectionPayload($locale),
            ],
            [
                'key' => 'features',
                'name' => 'Welcome Features',
                'type' => 'welcome_features',
                'build' => fn (string $locale) => $this->welcomeFeaturesSectionPayload($locale),
            ],
        ];
    }

    /**
     * @param  array<string, PlatformSection>  $sourceSections
     * @return array<int, array<string, mixed>>
     */
    private function welcomeSourceReferences(array $sourceSections, string $locale): array
    {
        $sectionContentService = app(PlatformSectionContentService::class);
        $references = [];
        $order = ['hero', 'trust', 'showcase', 'features'];

        foreach (array_values($order) as $index => $key) {
            $section = $sourceSections[$key] ?? null;
            if (! $section instanceof PlatformSection) {
                continue;
            }

            $resolved = $sectionContentService->resolveForLocale($section, $locale);

            $references[] = [
                'id' => 'welcome-section-'.($index + 1),
                'enabled' => $section->is_active,
                'source_id' => $section->id,
                'use_source' => true,
                'override_items' => false,
                'override_note' => false,
                'override_stats' => false,
                'layout' => (string) ($resolved['layout'] ?? 'split'),
            ];
        }

        return $references;
    }

    /**
     * @return array<string, mixed>
     */
    private function syncSharedFooter(?int $userId): array
    {
        $service = app(PlatformSectionContentService::class);
        $footer = $service->ensureSharedFooterSectionExists($userId);

        $footer->forceFill([
            'is_active' => true,
            'updated_by' => $userId,
        ])->save();

        foreach ($service->locales() as $locale) {
            $service->updateLocale(
                $footer,
                $locale,
                $service->defaultContent($locale, 'footer'),
                $userId
            );
            $footer = $footer->fresh();
        }

        return [
            'section_id' => $footer->id,
            'locales' => $service->locales(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultPageHeader(): array
    {
        return [
            'background_type' => 'none',
            'background_color' => '',
            'background_image_url' => '',
            'background_image_alt' => '',
            'alignment' => 'center',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function welcomeHeroSectionPayload(string $locale): array
    {
        $content = app(WelcomeContentService::class)->defaultContent($locale);
        $hero = is_array($content['hero'] ?? null) ? $content['hero'] : [];
        $heroImages = is_array($hero['hero_images'] ?? null) ? $hero['hero_images'] : [];

        if ($heroImages === []) {
            $fallbackImageUrl = trim((string) ($hero['image_url'] ?? ''));
            if ($fallbackImageUrl !== '') {
                $heroImages = [[
                    'image_url' => $fallbackImageUrl,
                    'image_alt' => (string) ($hero['image_alt'] ?? ''),
                ]];
            }
        }

        return [
            'layout' => 'split',
            'background_color' => (string) ($hero['background_color'] ?? ''),
            'image_position' => 'right',
            'alignment' => 'left',
            'density' => 'normal',
            'tone' => 'default',
            'kicker' => (string) ($hero['eyebrow'] ?? ''),
            'title' => (string) ($hero['title'] ?? ''),
            'body' => (string) ($hero['subtitle'] ?? ''),
            'note' => (string) ($hero['note'] ?? ''),
            'stats' => is_array($hero['stats'] ?? null) ? $hero['stats'] : [],
            'items' => is_array($hero['highlights'] ?? null) ? $hero['highlights'] : [],
            'preview_cards' => is_array($hero['preview_cards'] ?? null) ? $hero['preview_cards'] : [],
            'hero_images' => $heroImages,
            'image_url' => (string) ($hero['image_url'] ?? ''),
            'image_alt' => (string) ($hero['image_alt'] ?? ''),
            'primary_label' => (string) ($hero['primary_cta'] ?? ''),
            'primary_href' => (string) ($hero['primary_href'] ?? ''),
            'secondary_label' => (string) ($hero['secondary_cta'] ?? ''),
            'secondary_href' => (string) ($hero['secondary_href'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function welcomeTrustSectionPayload(string $locale): array
    {
        $content = app(WelcomeContentService::class)->defaultContent($locale);
        $trust = is_array($content['trust'] ?? null) ? $content['trust'] : [];

        return [
            'layout' => 'stack',
            'background_color' => (string) ($trust['background_color'] ?? ''),
            'image_position' => 'left',
            'alignment' => 'center',
            'density' => 'compact',
            'tone' => 'muted',
            'kicker' => '',
            'title' => (string) ($trust['title'] ?? ''),
            'body' => '',
            'items' => is_array($trust['items'] ?? null) ? $trust['items'] : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function welcomeFeaturesSectionPayload(string $locale): array
    {
        $content = app(WelcomeContentService::class)->defaultContent($locale);
        $features = is_array($content['features'] ?? null) ? $content['features'] : [];
        $secondary = is_array($features['new_features'] ?? null) ? $features['new_features'] : [];

        return [
            'layout' => 'stack',
            'background_color' => (string) ($features['background_color'] ?? ''),
            'image_position' => 'left',
            'alignment' => 'center',
            'density' => 'normal',
            'tone' => 'contrast',
            'kicker' => (string) ($features['kicker'] ?? ''),
            'title' => (string) ($features['title'] ?? ''),
            'body' => (string) ($features['subtitle'] ?? ''),
            'feature_items' => is_array($features['items'] ?? null) ? $features['items'] : [],
            'secondary_enabled' => array_key_exists('enabled', $secondary) ? (bool) $secondary['enabled'] : true,
            'secondary_background_color' => (string) ($secondary['background_color'] ?? ''),
            'secondary_kicker' => (string) ($secondary['kicker'] ?? ''),
            'secondary_title' => (string) ($secondary['title'] ?? ''),
            'secondary_body' => (string) ($secondary['subtitle'] ?? ''),
            'secondary_badge' => (string) ($secondary['badge'] ?? ''),
            'secondary_feature_items' => is_array($secondary['items'] ?? null) ? $secondary['items'] : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function welcomeShowcaseSectionPayload(string $locale): array
    {
        $normalizedLocale = WelcomeStockImages::normalizeLocale($locale);
        $getNoticedImage = WelcomeStockImages::showcaseImage('get_noticed', $normalizedLocale);
        $winJobsImage = WelcomeStockImages::showcaseImage('win_jobs', $normalizedLocale);
        $workSmarterImage = WelcomeStockImages::showcaseImage('work_smarter', $normalizedLocale);
        $boostProfitsImage = WelcomeStockImages::showcaseImage('boost_profits', $normalizedLocale);

        if ($normalizedLocale === 'fr') {
            return [
                'layout' => 'feature_tabs',
                'background_color' => '#f7f2e8',
                'image_position' => 'left',
                'alignment' => 'center',
                'density' => 'normal',
                'tone' => 'default',
                'feature_tabs_style' => 'workflow',
                'kicker' => 'Une plateforme sur tout le parcours client',
                'title' => 'Voyez comment Malikia Pro soutient la croissance du premier clic jusqu’au paiement final',
                'body' => '<p>Chaque étape du business reste connectée pour que marketing, devis, exécution et revenus ne vivent pas dans des outils séparés.</p>',
                'feature_tabs_font_size' => 28,
                'feature_tabs' => [
                    [
                        'id' => 'welcome-showcase-fr-1',
                        'label' => 'Se faire remarquer',
                        'icon' => 'clipboard-check',
                        'title' => 'Transformez votre visibilité en demandes qualifiées sans casser le parcours client',
                        'body' => '<p>Pages publiques, formulaires, campagnes et suivi restent alignés du premier clic jusqu’au premier vrai échange.</p>',
                        'items' => ['Avis', 'Demandes', 'Campagnes', 'Liens'],
                        'cta_label' => 'Explorer Marketing & Loyalty',
                        'cta_href' => '/pages/marketing-loyalty',
                        'image_url' => $getNoticedImage['image_url'],
                        'image_alt' => $getNoticedImage['image_alt'],
                    ],
                    [
                        'id' => 'welcome-showcase-fr-2',
                        'label' => 'Gagner des jobs',
                        'icon' => 'file-text',
                        'title' => 'Envoyez plus vite vos devis, relancez mieux et convertissez plus de demandes',
                        'body' => '<p>Contexte client, modèles, options et validations restent dans un même flux commercial que l’équipe peut vraiment piloter.</p>',
                        'items' => ['Qualification', 'Modèles', 'Options', 'Relances'],
                        'cta_label' => 'Explorer Sales & CRM',
                        'cta_href' => '/pages/sales-crm',
                        'image_url' => $winJobsImage['image_url'],
                        'image_alt' => $winJobsImage['image_alt'],
                    ],
                    [
                        'id' => 'welcome-showcase-fr-3',
                        'label' => 'Faire tourner les opérations',
                        'icon' => 'calendar-days',
                        'title' => 'Gardez coordination, planning et exécution connectés',
                        'body' => '<p>Dispatch, jobs, checklists, mises à jour et historique restent visibles pour toute l’équipe au lieu de se perdre dans des canaux parallèles.</p>',
                        'items' => ['Planning', 'Dispatch', 'Checklists', 'Historique'],
                        'cta_label' => 'Explorer Operations',
                        'cta_href' => '/pages/operations',
                        'image_url' => $workSmarterImage['image_url'],
                        'image_alt' => $workSmarterImage['image_alt'],
                    ],
                    [
                        'id' => 'welcome-showcase-fr-4',
                        'label' => 'Protéger les revenus',
                        'icon' => 'circle-dollar-sign',
                        'title' => 'Passez du travail réalisé à la facturation avec moins d’administration',
                        'body' => '<p>Factures, rappels et flux de paiement restent liés au travail effectué pour raccourcir le délai d’encaissement et mieux protéger le revenu.</p>',
                        'items' => ['Factures', 'Paiements', 'Rappels', 'Rapports'],
                        'cta_label' => 'Explorer Commerce',
                        'cta_href' => '/pages/commerce',
                        'image_url' => $boostProfitsImage['image_url'],
                        'image_alt' => $boostProfitsImage['image_alt'],
                    ],
                ],
            ];
        }

        if ($normalizedLocale === 'es') {
            return [
                'layout' => 'feature_tabs',
                'background_color' => '#f7f2e8',
                'image_position' => 'left',
                'alignment' => 'center',
                'density' => 'normal',
                'tone' => 'default',
                'feature_tabs_style' => 'workflow',
                'kicker' => 'Un solo sistema para todo el recorrido del cliente',
                'title' => 'Descubre como Malikia Pro impulsa el crecimiento desde el primer clic hasta el pago final',
                'body' => '<p>Cada etapa del negocio permanece conectada para que marketing, cotizaciones, ejecucion e ingresos no vivan en herramientas separadas.</p>',
                'feature_tabs_font_size' => 28,
                'feature_tabs' => [
                    [
                        'id' => 'welcome-showcase-es-1',
                        'label' => 'Hazte visible',
                        'icon' => 'clipboard-check',
                        'title' => 'Convierte la visibilidad en solicitudes calificadas sin romper el recorrido del cliente',
                        'body' => '<p>Paginas publicas, formularios, campanas y seguimiento permanecen alineados desde el primer clic hasta la primera conversacion real.</p>',
                        'items' => ['Resenas', 'Solicitudes', 'Campanas', 'Enlaces'],
                        'cta_label' => 'Explorar Marketing & Loyalty',
                        'cta_href' => '/pages/marketing-loyalty',
                        'image_url' => $getNoticedImage['image_url'],
                        'image_alt' => $getNoticedImage['image_alt'],
                    ],
                    [
                        'id' => 'welcome-showcase-es-2',
                        'label' => 'Gana trabajos',
                        'icon' => 'file-text',
                        'title' => 'Cotiza mas rapido, haz mejor seguimiento y convierte mas demanda en aprobaciones',
                        'body' => '<p>El contexto del cliente, las plantillas, las opciones y las aprobaciones permanecen dentro de un flujo comercial que tu equipo puede gestionar de verdad.</p>',
                        'items' => ['Calificacion', 'Plantillas', 'Opciones', 'Seguimiento'],
                        'cta_label' => 'Explorar Sales & CRM',
                        'cta_href' => '/pages/sales-crm',
                        'image_url' => $winJobsImage['image_url'],
                        'image_alt' => $winJobsImage['image_alt'],
                    ],
                    [
                        'id' => 'welcome-showcase-es-3',
                        'label' => 'Haz funcionar las operaciones',
                        'icon' => 'calendar-days',
                        'title' => 'Mantiene conectadas la coordinacion, la planificacion y la ejecucion',
                        'body' => '<p>Dispatch, trabajos, listas de control, actualizaciones e historial permanecen visibles para todo el equipo en lugar de perderse en canales paralelos.</p>',
                        'items' => ['Planificacion', 'Dispatch', 'Listas', 'Historial'],
                        'cta_label' => 'Explorar Operations',
                        'cta_href' => '/pages/operations',
                        'image_url' => $workSmarterImage['image_url'],
                        'image_alt' => $workSmarterImage['image_alt'],
                    ],
                    [
                        'id' => 'welcome-showcase-es-4',
                        'label' => 'Protege los ingresos',
                        'icon' => 'circle-dollar-sign',
                        'title' => 'Convierte el trabajo completado en facturas y pagos con menos carga administrativa',
                        'body' => '<p>Las facturas, los recordatorios y el flujo de pagos permanecen vinculados al trabajo realizado para que sea mas facil cobrar y proteger los ingresos.</p>',
                        'items' => ['Facturas', 'Pagos', 'Recordatorios', 'Informes'],
                        'cta_label' => 'Explorar Commerce',
                        'cta_href' => '/pages/commerce',
                        'image_url' => $boostProfitsImage['image_url'],
                        'image_alt' => $boostProfitsImage['image_alt'],
                    ],
                ],
            ];
        }

        return [
            'layout' => 'feature_tabs',
            'background_color' => '#f7f2e8',
            'image_position' => 'left',
            'alignment' => 'center',
            'density' => 'normal',
            'tone' => 'default',
            'feature_tabs_style' => 'workflow',
            'kicker' => 'One system across the full customer journey',
            'title' => 'See how Malikia Pro supports growth from first click to final payment',
            'body' => '<p>Every stage of the business stays connected so marketing, quoting, execution, and revenue do not live in separate tools.</p>',
            'feature_tabs_font_size' => 28,
            'feature_tabs' => [
                [
                    'id' => 'welcome-showcase-en-1',
                    'label' => 'Get Noticed',
                    'icon' => 'clipboard-check',
                    'title' => 'Turn visibility into qualified requests without breaking the customer journey',
                    'body' => '<p>Public pages, intake forms, campaigns, and follow-up stay aligned from the first click to the first real conversation.</p>',
                    'items' => ['Reviews', 'Requests', 'Campaigns', 'Links'],
                    'cta_label' => 'Explore Marketing & Loyalty',
                    'cta_href' => '/pages/marketing-loyalty',
                    'image_url' => $getNoticedImage['image_url'],
                    'image_alt' => $getNoticedImage['image_alt'],
                ],
                [
                    'id' => 'welcome-showcase-en-2',
                    'label' => 'Win work',
                    'icon' => 'file-text',
                    'title' => 'Quote faster, follow up better, and move more demand to approval',
                    'body' => '<p>Customer context, templates, options, and approvals stay inside one commercial workflow your team can actually manage.</p>',
                    'items' => ['Qualification', 'Templates', 'Upsells', 'Follow-ups'],
                    'cta_label' => 'Explore Sales & CRM',
                    'cta_href' => '/pages/sales-crm',
                    'image_url' => $winJobsImage['image_url'],
                    'image_alt' => $winJobsImage['image_alt'],
                ],
                [
                    'id' => 'welcome-showcase-en-3',
                    'label' => 'Run operations',
                    'icon' => 'calendar-days',
                    'title' => 'Keep office coordination, scheduling, and execution connected',
                    'body' => '<p>Dispatch, jobs, checklists, updates, and history stay visible to the whole team instead of getting lost in side channels.</p>',
                    'items' => ['Scheduling', 'Dispatch', 'Checklists', 'History'],
                    'cta_label' => 'Explore Operations',
                    'cta_href' => '/pages/operations',
                    'image_url' => $workSmarterImage['image_url'],
                    'image_alt' => $workSmarterImage['image_alt'],
                ],
                [
                    'id' => 'welcome-showcase-en-4',
                    'label' => 'Protect revenue',
                    'icon' => 'circle-dollar-sign',
                    'title' => 'Turn completed work into invoices and payments with less admin overhead',
                    'body' => '<p>Invoices, reminders, and payment flow stay linked to the work that was delivered so revenue is easier to collect and protect.</p>',
                    'items' => ['Invoices', 'Payments', 'Reminders', 'Reporting'],
                    'cta_label' => 'Explore Commerce',
                    'cta_href' => '/pages/commerce',
                    'image_url' => $boostProfitsImage['image_url'],
                    'image_alt' => $boostProfitsImage['image_alt'],
                ],
            ],
        ];
    }
}
