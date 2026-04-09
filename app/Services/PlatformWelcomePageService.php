<?php

namespace App\Services;

use App\Models\PlatformPage;
use App\Models\PlatformSection;
use App\Support\WelcomeEditorialSections;
use App\Support\WelcomeStockImages;

class PlatformWelcomePageService
{
    public const WELCOME_SLUG = 'welcome';

    public function ensurePageExists(?int $userId = null): PlatformPage
    {
        $page = PlatformPage::query()
            ->where('slug', self::WELCOME_SLUG)
            ->first();

        if ($page) {
            return $this->synchronizeExistingPageLocales($page, $userId);
        }

        return $this->createFromLegacy($userId);
    }

    public function isWelcomePage(?PlatformPage $page): bool
    {
        return $page instanceof PlatformPage && $page->slug === self::WELCOME_SLUG;
    }

    public function publicUrl(PlatformPage $page): string
    {
        return $this->isWelcomePage($page)
            ? route('welcome')
            : route('public.pages.show', ['slug' => $page->slug]);
    }

    public function displayPath(PlatformPage $page): string
    {
        return $this->isWelcomePage($page) ? '/' : '/pages/'.$page->slug;
    }

    private function synchronizeExistingPageLocales(PlatformPage $page, ?int $userId = null): PlatformPage
    {
        $payload = is_array($page->content) ? $page->content : [];
        $locales = is_array($payload['locales'] ?? null) ? $payload['locales'] : [];
        if ($locales === []) {
            return $page;
        }

        $canonicalLocale = $this->canonicalLocaleForExistingSections($locales);
        $canonicalSections = $this->sanitizeSectionList($locales[$canonicalLocale]['sections'] ?? []);
        if ($canonicalSections === []) {
            return $page;
        }

        $changed = false;

        foreach (['fr', 'en'] as $locale) {
            $localeContent = is_array($locales[$locale] ?? null) ? $locales[$locale] : [];
            $existingSections = $this->sanitizeSectionList($localeContent['sections'] ?? []);
            $existingById = collect($existingSections)
                ->filter(fn ($section) => is_array($section))
                ->mapWithKeys(function ($section) {
                    $id = trim((string) ($section['id'] ?? ''));

                    return $id !== '' ? [$id => $section] : [];
                })
                ->all();

            $rebuiltSections = array_values(array_map(function ($section) use ($existingById, $locale) {
                $id = trim((string) ($section['id'] ?? ''));

                if ($id !== '' && array_key_exists($id, $existingById)) {
                    return $existingById[$id];
                }

                return $this->localizeMissingSectionForLocale($section, $locale);
            }, $canonicalSections));

            if (($localeContent['sections'] ?? []) !== $rebuiltSections) {
                $changed = true;
            }

            $localeContent['sections'] = $rebuiltSections;
            $locales[$locale] = $localeContent;
        }

        if (! $changed) {
            return $page;
        }

        $payload['locales'] = $locales;
        $payload['updated_at'] = now()->toIso8601String();
        $payload['updated_by'] = $userId ?? ($payload['updated_by'] ?? $page->updated_by);
        $page->content = $payload;

        if ($userId !== null) {
            $page->updated_by = $userId;
        }

        $page->save();

        return $page->fresh();
    }

    private function canonicalLocaleForExistingSections(array $locales): string
    {
        $bestLocale = 'fr';
        $bestCount = -1;

        foreach (['fr', 'en'] as $locale) {
            $count = count($this->sanitizeSectionList($locales[$locale]['sections'] ?? []));
            if ($count > $bestCount) {
                $bestLocale = $locale;
                $bestCount = $count;
            }
        }

        return $bestLocale;
    }

    private function sanitizeSectionList($sections): array
    {
        if (! is_array($sections)) {
            return [];
        }

        return array_values(array_filter($sections, fn ($section) => is_array($section)));
    }

    private function localizeMissingSectionForLocale(array $section, string $locale): array
    {
        if (! empty($section['use_source']) && ! empty($section['source_id'])) {
            return $this->resetSourceDrivenFields($section);
        }

        $editorial = WelcomeEditorialSections::forId((string) ($section['id'] ?? ''), $locale);

        if ($editorial === null) {
            return $section;
        }

        return array_merge(
            $editorial,
            [
                'enabled' => array_key_exists('enabled', $section) ? (bool) $section['enabled'] : true,
            ]
        );
    }

    private function resetSourceDrivenFields(array $section): array
    {
        $section['kicker'] = '';
        $section['title'] = '';
        $section['body'] = '';
        $section['image_alt'] = '';
        $section['note'] = ! empty($section['override_note']) ? ($section['note'] ?? '') : '';
        $section['items'] = ! empty($section['override_items']) ? ($section['items'] ?? []) : [];
        $section['stats'] = ! empty($section['override_stats']) ? ($section['stats'] ?? []) : [];
        $section['hero_images'] = [];
        $section['testimonial_author'] = '';
        $section['testimonial_role'] = '';
        $section['aside_kicker'] = '';
        $section['aside_title'] = '';
        $section['aside_body'] = '';
        $section['aside_items'] = [];
        $section['aside_link_label'] = '';
        $section['aside_link_href'] = '';
        $section['aside_image_alt'] = '';
        $section['primary_label'] = '';
        $section['primary_href'] = '';
        $section['secondary_label'] = '';
        $section['secondary_href'] = '';
        $section['showcase_badge_label'] = '';
        $section['showcase_badge_value'] = '';
        $section['showcase_badge_note'] = '';

        return $section;
    }

    private function createFromLegacy(?int $userId = null): PlatformPage
    {
        $legacy = app(WelcomeContentService::class)->resolveAll();
        $sectionContentService = app(PlatformSectionContentService::class);

        $orderedSections = [];
        $orderedSections[] = $this->createSection(
            'Welcome Hero',
            'welcome_hero',
            [
                'fr' => $this->mapHeroSection($legacy['fr'] ?? []),
                'en' => $this->mapHeroSection($legacy['en'] ?? []),
            ],
            $userId
        );
        $orderedSections[] = $this->createSection(
            'Welcome Trust',
            'welcome_trust',
            [
                'fr' => $this->mapTrustSection($legacy['fr'] ?? []),
                'en' => $this->mapTrustSection($legacy['en'] ?? []),
            ],
            $userId
        );
        $orderedSections[] = $this->createSection(
            'Welcome Showcase',
            'feature_tabs',
            [
                'fr' => $this->defaultShowcaseSection('fr'),
                'en' => $this->defaultShowcaseSection('en'),
            ],
            $userId
        );
        $orderedSections[] = $this->createSection(
            'Welcome Features',
            'welcome_features',
            [
                'fr' => $this->mapFeaturesSection($legacy['fr'] ?? []),
                'en' => $this->mapFeaturesSection($legacy['en'] ?? []),
            ],
            $userId
        );

        $page = PlatformPage::query()->create([
            'slug' => self::WELCOME_SLUG,
            'title' => 'Welcome',
            'is_active' => true,
            'updated_by' => $userId,
        ]);

        $locales = [];
        foreach (['fr', 'en'] as $locale) {
            $sourceSections = array_map(function (PlatformSection $section, int $index) use ($locale, $sectionContentService) {
                $content = $sectionContentService->resolveForLocale($section, $locale);

                return [
                    'id' => $this->pageSectionIdForSource($section, $index),
                    'enabled' => $section->is_active,
                    'source_id' => $section->id,
                    'use_source' => true,
                    'layout' => (string) ($content['layout'] ?? 'split'),
                ];
            }, $orderedSections, array_keys($orderedSections));

            $locales[$locale] = [
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
                    ...$sourceSections,
                    ...WelcomeEditorialSections::genericSections($locale),
                ],
            ];
        }

        $page->content = [
            'locales' => $locales,
            'updated_by' => $userId,
            'updated_at' => now()->toIso8601String(),
        ];
        $page->save();

        return $page;
    }

    private function createSection(string $name, string $type, array $locales, ?int $userId = null): PlatformSection
    {
        return PlatformSection::query()->create([
            'name' => $name,
            'type' => $type,
            'is_active' => true,
            'content' => [
                'locales' => $locales,
                'updated_by' => $userId,
                'updated_at' => now()->toIso8601String(),
            ],
            'updated_by' => $userId,
        ]);
    }

    private function pageSectionIdForSource(PlatformSection $section, int $index): string
    {
        return 'welcome-section-'.($index + 1);
    }

    private function mapHeroSection(array $legacy): array
    {
        $hero = is_array($legacy['hero'] ?? null) ? $legacy['hero'] : [];
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

    private function mapTrustSection(array $legacy): array
    {
        $trust = is_array($legacy['trust'] ?? null) ? $legacy['trust'] : [];

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

    private function mapFeaturesSection(array $legacy): array
    {
        $features = is_array($legacy['features'] ?? null) ? $legacy['features'] : [];
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

    private function mapWorkflowSection(array $legacy): array
    {
        $workflow = is_array($legacy['workflow'] ?? null) ? $legacy['workflow'] : [];

        return [
            'layout' => 'split',
            'background_color' => (string) ($workflow['background_color'] ?? ''),
            'image_position' => 'right',
            'alignment' => 'left',
            'density' => 'normal',
            'tone' => 'default',
            'kicker' => (string) ($workflow['kicker'] ?? ''),
            'title' => (string) ($workflow['title'] ?? ''),
            'body' => (string) ($workflow['subtitle'] ?? ''),
            'preview_cards' => is_array($workflow['steps'] ?? null) ? $workflow['steps'] : [],
            'image_url' => (string) ($workflow['image_url'] ?? ''),
            'image_alt' => (string) ($workflow['image_alt'] ?? ''),
        ];
    }

    private function mapFieldSection(array $legacy): array
    {
        $field = is_array($legacy['field'] ?? null) ? $legacy['field'] : [];

        return [
            'layout' => 'split',
            'background_color' => (string) ($field['background_color'] ?? ''),
            'image_position' => 'left',
            'alignment' => 'left',
            'density' => 'normal',
            'tone' => 'default',
            'kicker' => (string) ($field['kicker'] ?? ''),
            'title' => (string) ($field['title'] ?? ''),
            'body' => (string) ($field['subtitle'] ?? ''),
            'items' => is_array($field['items'] ?? null) ? $field['items'] : [],
            'image_url' => (string) ($field['image_url'] ?? ''),
            'image_alt' => (string) ($field['image_alt'] ?? ''),
        ];
    }

    private function mapCustomSection(array $legacy): array
    {
        return [
            'layout' => 'split',
            'background_color' => (string) ($legacy['background_color'] ?? ''),
            'image_position' => 'right',
            'alignment' => 'left',
            'density' => 'normal',
            'tone' => 'default',
            'kicker' => (string) ($legacy['kicker'] ?? ''),
            'title' => (string) ($legacy['title'] ?? ''),
            'body' => (string) ($legacy['body'] ?? ''),
            'image_url' => (string) ($legacy['image_url'] ?? ''),
            'image_alt' => (string) ($legacy['image_alt'] ?? ''),
            'primary_label' => (string) ($legacy['primary_label'] ?? ''),
            'primary_href' => (string) ($legacy['primary_href'] ?? ''),
            'secondary_label' => (string) ($legacy['secondary_label'] ?? ''),
            'secondary_href' => (string) ($legacy['secondary_href'] ?? ''),
        ];
    }

    private function mapCtaSection(array $legacy): array
    {
        $cta = is_array($legacy['cta'] ?? null) ? $legacy['cta'] : [];

        return [
            'layout' => 'stack',
            'background_color' => (string) ($cta['background_color'] ?? ''),
            'image_position' => 'left',
            'alignment' => 'center',
            'density' => 'normal',
            'tone' => 'contrast',
            'kicker' => '',
            'title' => (string) ($cta['title'] ?? ''),
            'body' => (string) ($cta['subtitle'] ?? ''),
            'primary_label' => (string) ($cta['primary'] ?? ''),
            'primary_href' => (string) ($cta['primary_href'] ?? ''),
            'secondary_label' => (string) ($cta['secondary'] ?? ''),
            'secondary_href' => (string) ($cta['secondary_href'] ?? ''),
        ];
    }

    private function defaultShowcaseSection(string $locale): array
    {
        $getNoticedImage = WelcomeStockImages::showcaseImage('get_noticed', $locale);
        $winJobsImage = WelcomeStockImages::showcaseImage('win_jobs', $locale);
        $workSmarterImage = WelcomeStockImages::showcaseImage('work_smarter', $locale);
        $boostProfitsImage = WelcomeStockImages::showcaseImage('boost_profits', $locale);

        if ($locale === 'fr') {
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
