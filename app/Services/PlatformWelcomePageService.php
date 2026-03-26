<?php

namespace App\Services;

use App\Models\PlatformPage;
use App\Models\PlatformSection;

class PlatformWelcomePageService
{
    public const WELCOME_SLUG = 'welcome';

    public function ensurePageExists(?int $userId = null): PlatformPage
    {
        $page = PlatformPage::query()
            ->where('slug', self::WELCOME_SLUG)
            ->first();

        if ($page) {
            return $page;
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
        $orderedSections[] = $this->createSection(
            'Welcome Workflow',
            'welcome_workflow',
            [
                'fr' => $this->mapWorkflowSection($legacy['fr'] ?? []),
                'en' => $this->mapWorkflowSection($legacy['en'] ?? []),
            ],
            $userId
        );
        $orderedSections[] = $this->createSection(
            'Welcome Field',
            'welcome_field',
            [
                'fr' => $this->mapFieldSection($legacy['fr'] ?? []),
                'en' => $this->mapFieldSection($legacy['en'] ?? []),
            ],
            $userId
        );

        $frCustomSections = is_array($legacy['fr']['custom_sections'] ?? null) ? $legacy['fr']['custom_sections'] : [];
        $enCustomSections = is_array($legacy['en']['custom_sections'] ?? null) ? $legacy['en']['custom_sections'] : [];
        $customCount = max(count($frCustomSections), count($enCustomSections));
        for ($index = 0; $index < $customCount; $index++) {
            $orderedSections[] = $this->createSection(
                'Welcome Custom '.($index + 1),
                'welcome_custom',
                [
                    'fr' => $this->mapCustomSection($frCustomSections[$index] ?? []),
                    'en' => $this->mapCustomSection($enCustomSections[$index] ?? []),
                ],
                $userId
            );
        }

        $orderedSections[] = $this->createSection(
            'Welcome CTA',
            'welcome_cta',
            [
                'fr' => $this->mapCtaSection($legacy['fr'] ?? []),
                'en' => $this->mapCtaSection($legacy['en'] ?? []),
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
                'sections' => array_map(function (PlatformSection $section, int $index) use ($locale, $sectionContentService) {
                    $content = $sectionContentService->resolveForLocale($section, $locale);

                    return [
                        'id' => 'welcome-section-'.($index + 1),
                        'enabled' => $section->is_active,
                        'source_id' => $section->id,
                        'use_source' => true,
                        'layout' => (string) ($content['layout'] ?? 'split'),
                    ];
                }, $orderedSections, array_keys($orderedSections)),
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
        if ($locale === 'fr') {
            return [
                'layout' => 'feature_tabs',
                'background_color' => '#f7f2e8',
                'image_position' => 'left',
                'alignment' => 'center',
                'density' => 'normal',
                'tone' => 'default',
                'kicker' => 'Un systeme qui couvre tout le cycle client',
                'title' => 'La solution tout-en-un pour les pros du service a domicile',
                'body' => '<p>De la visibilite locale jusqu au paiement final, chaque etape reste dans un meme flux plutot que dans quatre outils separes.</p>',
                'feature_tabs_font_size' => 28,
                'feature_tabs' => [
                    [
                        'id' => 'welcome-showcase-fr-1',
                        'label' => 'Se faire remarquer',
                        'icon' => 'clipboard-check',
                        'title' => 'Gardez votre marque visible la ou les clients vous cherchent',
                        'body' => '<p>Pages publiques, formulaires et campagnes restent alignes du premier clic jusqu au suivi.</p>',
                        'items' => ['Avis', 'Demandes', 'Campagnes', 'Liens'],
                        'cta_label' => 'Voir Marketing & Loyalty',
                        'cta_href' => '/pages/marketing-loyalty',
                        'image_url' => '/images/landing/hero-dashboard.svg',
                        'image_alt' => 'Apercu marketing',
                    ],
                    [
                        'id' => 'welcome-showcase-fr-2',
                        'label' => 'Gagner des jobs',
                        'icon' => 'file-text',
                        'title' => 'Transformez plus vite une demande entrante en devis signe',
                        'body' => '<p>Qualification, devis et relances avancent dans un seul flux partage avec votre equipe.</p>',
                        'items' => ['Qualification', 'Modeles', 'Options', 'Relances'],
                        'cta_label' => 'Voir Sales & CRM',
                        'cta_href' => '/pages/sales-crm',
                        'image_url' => '/images/landing/workflow-board.svg',
                        'image_alt' => 'Apercu pipeline commercial',
                    ],
                    [
                        'id' => 'welcome-showcase-fr-3',
                        'label' => 'Travailler mieux',
                        'icon' => 'calendar-days',
                        'title' => 'Passez du bureau au terrain avec le meme niveau de clarte',
                        'body' => '<p>Planning, dispatch, checklists et historique client restent accessibles au bureau comme sur mobile.</p>',
                        'items' => ['Planning', 'Dispatch', 'Checklists', 'Historique'],
                        'cta_label' => 'Voir Operations',
                        'cta_href' => '/pages/operations',
                        'image_url' => '/images/landing/mobile-field.svg',
                        'image_alt' => 'Apercu mobile terrain',
                    ],
                    [
                        'id' => 'welcome-showcase-fr-4',
                        'label' => 'Booster les profits',
                        'icon' => 'circle-dollar-sign',
                        'title' => 'Facturez plus vite et raccourcissez le cycle d encaissement',
                        'body' => '<p>Factures, paiements et rappels restent connectes au travail realise pour proteger vos revenus.</p>',
                        'items' => ['Factures', 'Paiements', 'Rappels', 'Rapports'],
                        'cta_label' => 'Voir Commerce',
                        'cta_href' => '/pages/commerce',
                        'image_url' => '/images/mega-menu/commerce-suite.svg',
                        'image_alt' => 'Apercu commerce et paiements',
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
            'kicker' => 'One system across the full customer journey',
            'title' => 'The all-in-one solution for home service pros',
            'body' => '<p>From local visibility to final payment, each step stays inside one operating flow instead of being split across disconnected tools.</p>',
            'feature_tabs_font_size' => 28,
            'feature_tabs' => [
                [
                    'id' => 'welcome-showcase-en-1',
                    'label' => 'Get Noticed',
                    'icon' => 'clipboard-check',
                    'title' => 'Keep your brand visible where customers are already searching',
                    'body' => '<p>Public pages, intake forms, and campaigns stay aligned from the first click through follow-up.</p>',
                    'items' => ['Reviews', 'Requests', 'Campaigns', 'Links'],
                    'cta_label' => 'See Marketing & Loyalty',
                    'cta_href' => '/pages/marketing-loyalty',
                    'image_url' => '/images/landing/hero-dashboard.svg',
                    'image_alt' => 'Marketing preview',
                ],
                [
                    'id' => 'welcome-showcase-en-2',
                    'label' => 'Win Jobs',
                    'icon' => 'file-text',
                    'title' => 'Turn inbound demand into approved quotes faster',
                    'body' => '<p>Qualification, quotes, and follow-ups move inside one shared workflow instead of scattered tools.</p>',
                    'items' => ['Qualification', 'Templates', 'Upsells', 'Follow-ups'],
                    'cta_label' => 'See Sales & CRM',
                    'cta_href' => '/pages/sales-crm',
                    'image_url' => '/images/landing/workflow-board.svg',
                    'image_alt' => 'Sales workflow preview',
                ],
                [
                    'id' => 'welcome-showcase-en-3',
                    'label' => 'Work Smarter',
                    'icon' => 'calendar-days',
                    'title' => 'Move from office to field with the same level of clarity',
                    'body' => '<p>Scheduling, dispatch, checklists, and customer history stay visible to crews and office staff alike.</p>',
                    'items' => ['Scheduling', 'Dispatch', 'Checklists', 'History'],
                    'cta_label' => 'See Operations',
                    'cta_href' => '/pages/operations',
                    'image_url' => '/images/landing/mobile-field.svg',
                    'image_alt' => 'Field mobile preview',
                ],
                [
                    'id' => 'welcome-showcase-en-4',
                    'label' => 'Boost Profits',
                    'icon' => 'circle-dollar-sign',
                    'title' => 'Invoice faster and shorten the time between work and cash',
                    'body' => '<p>Invoices, payments, and reminders stay tied to completed work so revenue is easier to protect.</p>',
                    'items' => ['Invoices', 'Payments', 'Reminders', 'Reporting'],
                    'cta_label' => 'See Commerce',
                    'cta_href' => '/pages/commerce',
                    'image_url' => '/images/mega-menu/commerce-suite.svg',
                    'image_alt' => 'Commerce and payments preview',
                ],
            ],
        ];
    }
}
