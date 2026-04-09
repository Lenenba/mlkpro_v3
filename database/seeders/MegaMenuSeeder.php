<?php

namespace Database\Seeders;

use App\Models\MegaMenu;
use App\Models\PlatformPage;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\MegaMenus\MegaMenuManagerService;
use App\Services\PublicLeadFormUrlService;
use App\Support\PublicIndustryPageSections;
use App\Support\PublicPageStockImages;
use App\Support\PublicProductPageNarratives;
use Illuminate\Database\Seeder;

class MegaMenuSeeder extends Seeder
{
    public function run(): void
    {
        $userId = User::query()->where('email', 'superadmin@example.com')->value('id');

        PlatformSetting::query()->firstOrCreate(
            ['key' => 'public_navigation'],
            ['value' => ['contact_form_url' => '']]
        );

        $productPages = $this->syncProductPages($userId);
        $this->syncIndustryPages($userId);
        $this->syncSolutionPages($userId);
        $contactPage = $this->upsertPage(
            slug: 'contact-us',
            title: 'Contact us',
            content: $this->contactPageContent(),
            userId: $userId
        );
        $manager = app(MegaMenuManagerService::class);

        foreach ($this->menus($productPages, $contactPage) as $payload) {
            $existing = MegaMenu::query()->where('slug', $payload['slug'])->first();

            if ($existing) {
                $manager->update($existing, $payload, $userId);

                continue;
            }

            $manager->create($payload, $userId);
        }
    }

    /**
     * @param  array<string, PlatformPage>  $productPages
     * @return array<int, array<string, mixed>>
     */
    private function menus(array $productPages, PlatformPage $contactPage): array
    {
        return [
            $this->mainHeaderMenu($productPages, $contactPage),
            $this->footerMenu(),
        ];
    }

    /**
     * @param  array<string, PlatformPage>  $productPages
     * @return array<string, mixed>
     */
    private function mainHeaderMenu(array $productPages, PlatformPage $contactPage): array
    {
        return [
            'slug' => 'main-header-menu',
            'title' => 'Platform Modules Showcase',
            'status' => 'active',
            'display_location' => 'header',
            'description' => 'Primary marketing mega menu dedicated to platform modules and product suites.',
            'ordering' => 1,
            'settings' => [
                'theme' => 'brand',
                'container_width' => 'full',
                'accent_color' => '#0f766e',
                'panel_background' => '#ffffff',
                'open_on_hover' => true,
                'show_dividers' => true,
            ],
            'items' => [
                $this->productsAndServicesItem($productPages),
                $this->pricingItem(),
                $this->industriesItem(),
                $this->contactItem($contactPage),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function footerMenu(): array
    {
        return [
            'slug' => 'footer-resources-menu',
            'title' => 'Footer Resources Menu',
            'status' => 'active',
            'display_location' => 'footer',
            'description' => 'Secondary footer navigation.',
            'ordering' => 2,
            'items' => [
                [
                    'label' => 'Juridique',
                    'link_type' => 'none',
                    'link_target' => '_self',
                    'panel_type' => 'classic',
                    'is_visible' => true,
                    'settings' => [
                        'translations' => [
                            'en' => [
                                'label' => 'Legal',
                            ],
                        ],
                    ],
                    'children' => [
                        $this->classicLink('Conditions', '/terms', '', 'Terms'),
                        $this->classicLink('Confidentialité', '/privacy', '', 'Privacy'),
                        $this->classicLink('Remboursement', '/refund', '', 'Refund'),
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, PlatformPage>
     */
    private function syncProductPages(?int $userId): array
    {
        $pages = [];

        foreach ($this->productCatalog() as $key => $product) {
            $pages[$key] = $this->upsertPage(
                slug: $product['slug'],
                title: $product['title'],
                content: $this->productPageContent($product),
                userId: $userId
            );
        }

        return $pages;
    }

    /**
     * @return array<string, PlatformPage>
     */
    private function syncIndustryPages(?int $userId): array
    {
        $pages = [];

        foreach ($this->industryCatalog() as $key => $industry) {
            $pages[$key] = $this->upsertPage(
                slug: $industry['slug'],
                title: $industry['title'],
                content: $this->industryPageContent($industry),
                userId: $userId
            );
        }

        return $pages;
    }

    /**
     * @return array<string, PlatformPage>
     */
    private function syncSolutionPages(?int $userId): array
    {
        $pages = [];

        foreach ($this->solutionCatalog() as $key => $solution) {
            $pages[$key] = $this->upsertPage(
                slug: $solution['slug'],
                title: $solution['title'],
                content: $this->solutionPageContent($solution),
                userId: $userId
            );
        }

        return $pages;
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array<string, mixed>
     */
    private function productPageContent(array $product): array
    {
        return [
            'locales' => [
                'fr' => [
                    'page_title' => $product['title'],
                    'page_subtitle' => $product['fr']['subtitle'],
                    'header' => $this->pageHeader($product['slug'], 'fr'),
                    'sections' => $this->localizedProductSections($product, 'fr'),
                ],
                'en' => [
                    'page_title' => $product['title'],
                    'page_subtitle' => $product['en']['subtitle'],
                    'header' => $this->pageHeader($product['slug'], 'en'),
                    'sections' => $this->localizedProductSections($product, 'en'),
                ],
            ],
            'theme' => $this->publicPageTheme(),
            'updated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array<int, array<string, mixed>>
     */
    private function localizedProductSections(array $product, string $locale): array
    {
        if (PublicProductPageNarratives::has((string) ($product['slug'] ?? ''))) {
            return PublicProductPageNarratives::sections((string) $product['slug'], $locale);
        }

        $copy = $product[$locale];
        $pricingHref = $product['pricing_href'];
        $overviewVisual = $this->stockImage($product['slug'], $locale, 'overview');
        $workflowVisual = $this->stockImage($product['slug'], $locale, 'workflow');
        $pagesVisual = $this->stockImage($product['slug'], $locale, 'pages');

        $labels = $locale === 'fr'
            ? [
                'overview_kicker' => 'Vue d\'ensemble',
                'workflow_kicker' => 'Comment ça fonctionne',
                'pages_kicker' => 'Pages incluses',
                'primary_label' => 'Voir les tarifs',
                'secondary_label' => 'Voir la démo',
            ]
            : [
                'overview_kicker' => 'Overview',
                'workflow_kicker' => 'How it works',
                'pages_kicker' => 'Included pages',
                'primary_label' => 'View pricing',
                'secondary_label' => 'View demo',
            ];

        return [
            $this->pageSection(
                id: 'overview',
                kicker: $labels['overview_kicker'],
                title: $copy['overview_title'],
                body: $copy['overview_body'],
                items: $copy['overview_items'],
                imageUrl: $overviewVisual['image_url'],
                imageAlt: $overviewVisual['image_alt'],
                primaryLabel: $labels['primary_label'],
                primaryHref: $pricingHref,
                secondaryLabel: $labels['secondary_label'],
                secondaryHref: '/demo'
            ),
            $this->pageSection(
                id: 'workflow',
                kicker: $labels['workflow_kicker'],
                title: $copy['workflow_title'],
                body: $copy['workflow_body'],
                items: $copy['workflow_items'],
                imageUrl: $workflowVisual['image_url'],
                imageAlt: $workflowVisual['image_alt'],
                backgroundColor: '#f8fafc'
            ),
            $this->pageSection(
                id: 'pages',
                kicker: $labels['pages_kicker'],
                title: $copy['pages_title'],
                body: $copy['pages_body'],
                items: $copy['pages_items'],
                imageUrl: $pagesVisual['image_url'],
                imageAlt: $pagesVisual['image_alt'],
                primaryLabel: $labels['secondary_label'],
                primaryHref: '/demo',
                secondaryLabel: $labels['primary_label'],
                secondaryHref: $pricingHref
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $solution
     * @return array<string, mixed>
     */
    private function solutionPageContent(array $solution): array
    {
        return [
            'locales' => [
                'fr' => [
                    'page_title' => $solution['title'],
                    'page_subtitle' => $solution['fr']['subtitle'],
                    'header' => $this->pageHeader($solution['slug'], 'fr'),
                    'sections' => $this->localizedSolutionSections($solution, 'fr'),
                ],
                'en' => [
                    'page_title' => $solution['en']['title'],
                    'page_subtitle' => $solution['en']['subtitle'],
                    'header' => $this->pageHeader($solution['slug'], 'en'),
                    'sections' => $this->localizedSolutionSections($solution, 'en'),
                ],
            ],
            'theme' => $this->publicPageTheme(),
            'updated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $solution
     * @return array<int, array<string, mixed>>
     */
    private function localizedSolutionSections(array $solution, string $locale): array
    {
        $copy = $solution[$locale];
        $overviewVisual = $this->stockImage($solution['slug'], $locale, 'overview');
        $workflowVisual = $this->stockImage($solution['slug'], $locale, 'workflow');
        $modulesVisual = $this->stockImage($solution['slug'], $locale, 'modules');

        $labels = $locale === 'fr'
            ? [
                'overview_kicker' => 'Solution',
                'workflow_kicker' => 'Mode opératoire',
                'modules_kicker' => 'Modules et pages',
                'primary_label' => 'Voir les tarifs',
                'secondary_label' => 'Nous contacter',
            ]
            : [
                'overview_kicker' => 'Solution',
                'workflow_kicker' => 'Operating model',
                'modules_kicker' => 'Modules and pages',
                'primary_label' => 'View pricing',
                'secondary_label' => 'Contact us',
            ];

        return [
            $this->pageSection(
                id: 'solution-overview',
                kicker: $labels['overview_kicker'],
                title: $copy['overview_title'],
                body: $copy['overview_body'],
                items: $copy['overview_items'],
                imageUrl: $overviewVisual['image_url'],
                imageAlt: $overviewVisual['image_alt'],
                primaryLabel: $labels['primary_label'],
                primaryHref: '/pricing',
                secondaryLabel: $labels['secondary_label'],
                secondaryHref: '/pages/contact-us'
            ),
            $this->pageSection(
                id: 'solution-workflow',
                kicker: $labels['workflow_kicker'],
                title: $copy['workflow_title'],
                body: $copy['workflow_body'],
                items: $copy['workflow_items'],
                imageUrl: $workflowVisual['image_url'],
                imageAlt: $workflowVisual['image_alt'],
                backgroundColor: '#f8fafc'
            ),
            $this->pageSection(
                id: 'solution-modules',
                kicker: $labels['modules_kicker'],
                title: $copy['modules_title'],
                body: $copy['modules_body'],
                items: $copy['modules_items'],
                imageUrl: $modulesVisual['image_url'],
                imageAlt: $modulesVisual['image_alt']
            ),
        ];
    }

    /**
     * @param  array<int, string>  $items
     * @return array<string, mixed>
     */
    private function pageSection(
        string $id,
        string $kicker,
        string $title,
        string $body,
        array $items,
        string $imageUrl,
        string $imageAlt,
        string $primaryLabel = '',
        string $primaryHref = '',
        string $secondaryLabel = '',
        string $secondaryHref = '',
        string $backgroundColor = '',
        string $embedUrl = '',
        string $embedTitle = '',
        int $embedHeight = 760,
        array $extra = []
    ): array {
        return array_merge([
            'id' => $id,
            'enabled' => true,
            'source_id' => null,
            'use_source' => false,
            'background_color' => $backgroundColor,
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
            'kicker' => $kicker,
            'title' => $title,
            'body' => $body,
            'items' => $items,
            'aside_kicker' => '',
            'aside_title' => '',
            'aside_body' => '',
            'aside_items' => [],
            'aside_link_label' => '',
            'aside_link_href' => '',
            'image_url' => $imageUrl,
            'image_alt' => $imageAlt,
            'embed_url' => $embedUrl,
            'embed_title' => $embedTitle,
            'embed_height' => $embedHeight,
            'primary_label' => $primaryLabel,
            'primary_href' => $primaryHref,
            'secondary_label' => $secondaryLabel,
            'secondary_href' => $secondaryHref,
        ], $extra);
    }

    private function contactFormUrl(array $parameters = []): string
    {
        return app(PublicLeadFormUrlService::class)->resolve((int) config('app.lead_intake_user_id'), $parameters) ?? '';
    }

    /**
     * @return array<string, mixed>
     */
    private function partnersPageContent(): array
    {
        $partnersFr = $this->stockImage('partners', 'fr', 'overview');
        $partnersEn = $this->stockImage('partners', 'en', 'overview');

        return [
            'locales' => [
                'fr' => [
                    'page_title' => 'Partenaires',
                    'page_subtitle' => '<p>Explorez l\'écosystème de partenaires et d\'intégrations autour de la plateforme.</p>',
                    'header' => $this->pageHeader('partners', 'fr'),
                    'sections' => [
                        $this->pageSection(
                            id: 'partners-overview',
                            kicker: 'Partenariats',
                            title: 'Un écosystème pour déployer plus vite',
                            body: '<p>Retrouvez les partenaires technologiques, opérationnels, et commerciaux qui gravitent autour de la plateforme.</p>',
                            items: [
                                'Partenaires de mise en œuvre',
                                'Intégrations métier et passerelles de paiement',
                                'Partenaires de croissance et d\'acquisition',
                            ],
                            imageUrl: $partnersFr['image_url'],
                            imageAlt: $partnersFr['image_alt'],
                            primaryLabel: 'Voir les tarifs',
                            primaryHref: '/pricing',
                            secondaryLabel: 'Voir la démo',
                            secondaryHref: '/demo'
                        ),
                    ],
                ],
                'en' => [
                    'page_title' => 'Partners',
                    'page_subtitle' => '<p>Explore the partner and integration ecosystem around the platform.</p>',
                    'header' => $this->pageHeader('partners', 'en'),
                    'sections' => [
                        $this->pageSection(
                            id: 'partners-overview',
                            kicker: 'Partners',
                            title: 'An ecosystem built to accelerate deployment',
                            body: '<p>Discover the implementation, technology, and growth partners connected to the platform.</p>',
                            items: [
                                'Implementation partners',
                                'Business integrations and payment gateways',
                                'Growth and acquisition partners',
                            ],
                            imageUrl: $partnersEn['image_url'],
                            imageAlt: $partnersEn['image_alt'],
                            primaryLabel: 'View pricing',
                            primaryHref: '/pricing',
                            secondaryLabel: 'View demo',
                            secondaryHref: '/demo'
                        ),
                    ],
                ],
            ],
            'theme' => $this->publicPageTheme(),
            'updated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $industry
     * @return array<string, mixed>
     */
    private function industryPageContent(array $industry): array
    {
        return [
            'locales' => [
                'fr' => [
                    'page_title' => $industry['title'],
                    'page_subtitle' => $industry['fr']['subtitle'],
                    'header' => $this->pageHeader($industry['slug'], 'fr'),
                    'sections' => PublicIndustryPageSections::sections($industry['slug'], 'fr'),
                ],
                'en' => [
                    'page_title' => $industry['title'],
                    'page_subtitle' => $industry['en']['subtitle'],
                    'header' => $this->pageHeader($industry['slug'], 'en'),
                    'sections' => PublicIndustryPageSections::sections($industry['slug'], 'en'),
                ],
            ],
            'theme' => $this->publicPageTheme(),
            'updated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function contactPageContent(): array
    {
        $formUrl = $this->contactFormUrl();
        $embeddedFormUrl = $this->contactFormUrl(['embed' => 1]);
        $hasEmbeddedForm = $formUrl !== '';
        $contactFr = $this->stockImage('contact-us', 'fr', 'overview');
        $contactFrDetail = $this->stockImage('contact-us', 'fr', 'details');
        $contactEn = $this->stockImage('contact-us', 'en', 'overview');
        $contactEnDetail = $this->stockImage('contact-us', 'en', 'details');

        return [
            'locales' => [
                'fr' => [
                    'page_title' => 'Parlez-nous de la façon dont votre activité fonctionne aujourd\'hui',
                    'page_subtitle' => '<p>Décrivez votre fonctionnement, votre organisation et les zones où vous voulez plus de rapidité, de visibilité ou de contrôle. Nous vous aidons à évaluer la bonne configuration Malikia Pro pour la suite.</p>',
                    'header' => $this->pageHeader('contact-us', 'fr'),
                    'sections' => [
                        $this->pageSection(
                            id: 'contact-overview',
                            kicker: 'Contact commercial',
                            title: 'Commencez la conversation avec le bon contexte',
                            body: '<p>Parlez-nous de vos services, de la taille de votre équipe, de votre façon de travailler et des points où vous voulez plus de structure. Nous utiliserons ce contexte pour vous orienter vers la configuration Malikia Pro la plus adaptée à votre activité.</p>',
                            items: [
                                'Votre modèle d\'activité, la taille de l\'équipe et le contexte opérationnel',
                                'Les points de friction que vous voulez régler en priorité',
                                'Vos questions sur les devis, le planning, les jobs, les réservations, la facturation ou la coordination',
                                'Le prochain sujet que vous évaluez : tarification, déploiement ou adéquation produit',
                            ],
                            imageUrl: $contactFr['image_url'],
                            imageAlt: $contactFr['image_alt'],
                            primaryLabel: $hasEmbeddedForm ? 'Ouvrir le formulaire' : '',
                            primaryHref: $formUrl,
                            secondaryLabel: 'Voir les tarifs',
                            secondaryHref: '/pricing',
                            embedUrl: $hasEmbeddedForm ? $embeddedFormUrl : '',
                            embedTitle: $hasEmbeddedForm ? 'Formulaire de demande commerciale' : '',
                            embedHeight: 820
                        ),
                        $this->pageSection(
                            id: 'contact-details',
                            kicker: 'Parler à l\'équipe',
                            title: 'Obtenez des réponses auprès d\'une équipe qui comprend les réalités opérationnelles',
                            body: '<p>Si vous voulez valider l\'adéquation avant d\'aller plus loin, contactez-nous et nous vous aiderons à voir où Malikia Pro peut soutenir vos ventes, votre coordination, votre planning, vos réservations, votre exécution terrain et votre facturation.</p>',
                            items: [
                                'Aide pour évaluer l\'adéquation produit et le périmètre opérationnel',
                                'Orientation sur le déploiement, l\'onboarding et les prochaines étapes',
                                'Échange commercial pour les besoins qualifiés',
                            ],
                            imageUrl: $contactFrDetail['image_url'],
                            imageAlt: $contactFrDetail['image_alt'],
                            primaryLabel: $hasEmbeddedForm ? 'Nous contacter' : '',
                            primaryHref: $formUrl,
                            secondaryLabel: '',
                            secondaryHref: '',
                            backgroundColor: '#ffffff',
                            extra: [
                                'layout' => 'contact',
                                'aside_kicker' => 'Équipe commerciale',
                                'aside_title' => 'Une équipe centrée sur l\'adéquation, le déploiement et les prochaines étapes',
                                'aside_body' => '<p>Contactez-nous si vous avez besoin d\'aide pour choisir un plan, évaluer votre fonctionnement ou comprendre comment Malikia Pro peut soutenir une opération plus structurée.</p>',
                                'aside_items' => [
                                    'Pertinent pour les nouveaux prospects, les questions tarifaires et les échanges sur le déploiement',
                                    'Adapté aux entreprises qui gèrent plusieurs flux de service et plus de coordination au quotidien',
                                ],
                                'aside_link_label' => $hasEmbeddedForm ? 'Nous contacter' : '',
                                'aside_link_href' => $hasEmbeddedForm ? $formUrl : '',
                            ]
                        ),
                    ],
                ],
                'en' => [
                    'page_title' => 'Contact us',
                    'page_subtitle' => '<p>Share your workflow, team setup, and the areas where you want more speed, visibility, or control. We will help you evaluate the right Malikia Pro setup for what comes next.</p>',
                    'header' => $this->pageHeader('contact-us', 'en'),
                    'sections' => [
                        $this->pageSection(
                            id: 'contact-overview',
                            kicker: 'Commercial contact',
                            title: 'Start the conversation with the context that matters',
                            body: '<p>Tell us about your services, team size, workflow, and the points where you need more structure. We will use that context to guide you toward the best Malikia Pro setup for your business.</p>',
                            items: [
                                'Your business model, team size, and operating context',
                                'The workflow gaps you want to fix first',
                                'Questions about sales, scheduling, jobs, reservations, invoicing, or coordination',
                                'The next step you are evaluating: pricing, rollout, or product fit',
                            ],
                            imageUrl: $contactEn['image_url'],
                            imageAlt: $contactEn['image_alt'],
                            primaryLabel: $hasEmbeddedForm ? 'Open the form' : '',
                            primaryHref: $formUrl,
                            secondaryLabel: 'View pricing',
                            secondaryHref: '/pricing',
                            embedUrl: $hasEmbeddedForm ? $embeddedFormUrl : '',
                            embedTitle: $hasEmbeddedForm ? 'Commercial inquiry form' : '',
                            embedHeight: 820
                        ),
                        $this->pageSection(
                            id: 'contact-details',
                            kicker: 'Talk to our team',
                            title: 'Get guidance from people who understand operational workflows',
                            body: '<p>If you want to validate fit before going deeper, reach out and we will help you understand where Malikia Pro can support your sales flow, coordination, scheduling, reservations, field work, and revenue operations.</p>',
                            items: [
                                'Help evaluating fit and operational scope',
                                'Guidance on rollout, onboarding, and next steps',
                                'Commercial follow-up for qualified requests',
                            ],
                            imageUrl: $contactEnDetail['image_url'],
                            imageAlt: $contactEnDetail['image_alt'],
                            primaryLabel: $hasEmbeddedForm ? 'Contact our team' : '',
                            primaryHref: $formUrl,
                            secondaryLabel: '',
                            secondaryHref: '',
                            backgroundColor: '#ffffff',
                            extra: [
                                'layout' => 'contact',
                                'aside_kicker' => 'Sales desk',
                                'aside_title' => 'A commercial team focused on fit, rollout, and next steps',
                                'aside_body' => '<p>Reach out if you need help choosing a plan, evaluating your workflow, or understanding how Malikia Pro can support a more structured operation.</p>',
                                'aside_items' => [
                                    'Relevant for new prospects, pricing questions, and rollout discussions',
                                    'Suitable for businesses operating across service workflows with growing coordination needs',
                                ],
                                'aside_link_label' => $hasEmbeddedForm ? 'Contact our team' : '',
                                'aside_link_href' => $hasEmbeddedForm ? $formUrl : '',
                            ]
                        ),
                    ],
                ],
            ],
            'theme' => $this->publicPageTheme(),
            'updated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, PlatformPage>  $productPages
     * @return array<string, mixed>
     */
    private function productsAndServicesItem(array $productPages): array
    {
        return [
            'label' => 'Produits & Services',
            'description' => 'Explorez tout le catalogue plateforme depuis un point d\'entrée unique.',
            'link_type' => 'none',
            'link_target' => '_self',
            'panel_type' => 'mega',
            'icon' => 'grid-2x2',
            'badge_text' => '',
            'badge_variant' => null,
            'is_visible' => true,
            'settings' => [
                'eyebrow' => 'Modules',
                'note' => 'Explorez tout le catalogue plateforme depuis un point d\'entrée unique.',
                'featured' => false,
                'highlight_color' => '#0f766e',
                'translations' => [
                    'en' => [
                        'label' => 'Products & Services',
                        'description' => 'Browse the full platform catalog from a single entry point.',
                        'eyebrow' => 'Modules',
                        'note' => 'Browse the full platform catalog from a single entry point.',
                    ],
                ],
            ],
            'columns' => [
                $this->column('', '1fr', [
                    $this->productShowcaseBlock(
                        'Produits & Services',
                        'Products & Services',
                        'Survolez un produit pour prévisualiser l\'interface, puis cliquez pour ouvrir sa page détaillée.',
                        'Hover a product to preview the interface and click to open its detailed page.',
                        [
                            $this->showcaseProduct(
                                'Sales & CRM',
                                $this->pagePath($productPages['sales-crm']),
                                'Requests, quotes, customers, and pipelines.',
                                'Capture demand, qualify opportunities, and move faster from first request to approved quote.',
                                $this->stockImage('sales-crm', 'en')['image_url'],
                                $this->stockImage('sales-crm', 'en')['image_alt'],
                                'Sales and CRM',
                                'Popular'
                            ),
                            $this->showcaseProduct(
                                'Reservations',
                                $this->pagePath($productPages['reservations']),
                                'Bookings, availability, and self-service scheduling.',
                                'Let customers book online while teams keep live control over availability, queues, and confirmations.',
                                $this->stockImage('reservations', 'en')['image_url'],
                                $this->stockImage('reservations', 'en')['image_alt'],
                                'Reservations',
                                'Core'
                            ),
                            $this->showcaseProduct(
                                'Operations',
                                $this->pagePath($productPages['operations']),
                                'Scheduling, jobs, tasks, and dispatch.',
                                'Coordinate field execution, assignments, proof of work, and daily follow-up from one operational cockpit.',
                                $this->stockImage('operations', 'en')['image_url'],
                                $this->stockImage('operations', 'en')['image_alt'],
                                'Operations'
                            ),
                            $this->showcaseProduct(
                                'Commerce',
                                $this->pagePath($productPages['commerce']),
                                'Catalog, storefront, invoices, and payments.',
                                'Sell products and services, invoice customers, and collect payments without fragmenting the journey.',
                                $this->stockImage('commerce', 'en')['image_url'],
                                $this->stockImage('commerce', 'en')['image_alt'],
                                'Commerce'
                            ),
                            $this->showcaseProduct(
                                'Marketing & Loyalty',
                                $this->pagePath($productPages['marketing-loyalty']),
                                'Campaigns, segments, loyalty, and VIP journeys.',
                                'Build retention programs and targeted follow-up using the same customer context as sales and operations.',
                                $this->stockImage('marketing-loyalty', 'en')['image_url'],
                                $this->stockImage('marketing-loyalty', 'en')['image_alt'],
                                'Marketing and Loyalty',
                                'Growth'
                            ),
                            $this->showcaseProduct(
                                'AI & Automation',
                                $this->pagePath($productPages['ai-automation']),
                                'Assistant, drafts, summaries, and suggested actions.',
                                'Embed AI into the workflow your teams already use instead of adding another disconnected tool.',
                                $this->stockImage('ai-automation', 'en')['image_url'],
                                $this->stockImage('ai-automation', 'en')['image_alt'],
                                'AI and Automation',
                                'AI'
                            ),
                            $this->showcaseProduct(
                                'Command Center',
                                $this->pagePath($productPages['command-center']),
                                'Cross-module visibility and leadership overview.',
                                'Get one executive-level view across revenue, operations, and customer activity with a shared command center.',
                                $this->stockImage('command-center', 'en')['image_url'],
                                $this->stockImage('command-center', 'en')['image_alt'],
                                'Command Center'
                            ),
                        ]
                    ),
                ]),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pricingItem(): array
    {
        return [
            'label' => 'Tarifs',
            'description' => 'Comparez les offres et les modules.',
            'link_type' => 'internal_page',
            'link_value' => '/pricing',
            'link_target' => '_self',
            'panel_type' => 'link',
            'is_visible' => true,
            'settings' => [
                'translations' => [
                    'en' => [
                        'label' => 'Pricing',
                        'description' => 'Compare plans and platform modules.',
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function industriesItem(): array
    {
        return [
            'label' => 'Industries',
            'description' => 'Choisissez un secteur pour ouvrir sa page dédiée.',
            'link_type' => 'none',
            'link_value' => '',
            'link_target' => '_self',
            'panel_type' => 'classic',
            'icon' => 'briefcase-business',
            'is_visible' => true,
            'children' => $this->industryMenuChildren(),
            'settings' => [
                'eyebrow' => 'Industries',
                'note' => 'Choisissez un secteur pour ouvrir sa page dédiée.',
                'highlight_color' => '#0f766e',
                'translations' => [
                    'en' => [
                        'label' => 'Industries',
                        'description' => 'Choose an industry to open its dedicated page.',
                        'eyebrow' => 'Industries',
                        'note' => 'Choose an industry to open its dedicated page.',
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function industryMenuChildren(): array
    {
        return [
            $this->classicLink(
                'Plomberie',
                '/pages/industry-plumbing',
                'Flux pour équipes plomberie et service.',
                'Plumbing',
                'Flows for plumbing and service teams.'
            ),
            $this->classicLink(
                'HVAC',
                '/pages/industry-hvac',
                'Operations HVAC, maintenance et interventions.',
                'HVAC',
                'HVAC maintenance and service operations.'
            ),
            $this->classicLink(
                'Électricité',
                '/pages/industry-electrical',
                'Devis, chantiers et interventions électriques.',
                'Electrical',
                'Quotes, projects, and electrical jobs.'
            ),
            $this->classicLink(
                'Nettoyage',
                '/pages/industry-cleaning',
                'Sites récurrents, équipes et qualité de service.',
                'Cleaning',
                'Recurring sites, teams, and service quality.'
            ),
            $this->classicLink(
                'Salon & beauté',
                '/pages/industry-salon-beauty',
                'Réservations, rappels et fidélisation.',
                'Salon & Beauty',
                'Bookings, reminders, and retention.'
            ),
            $this->classicLink(
                'Restaurant',
                '/pages/industry-restaurant',
                'Réservations, attente et accueil en salle.',
                'Restaurant',
                'Bookings, waiting flow, and front-of-house.'
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function contactItem(PlatformPage $contactPage): array
    {
        return [
            'label' => 'Nous contacter',
            'description' => 'Ouvrez la page de contact et adaptez ensuite le lien du formulaire dans l\'admin Pages.',
            'link_type' => 'internal_page',
            'link_value' => $this->pagePath($contactPage),
            'link_target' => '_self',
            'panel_type' => 'link',
            'is_visible' => true,
            'settings' => [
                'eyebrow' => '',
                'note' => '',
                'featured' => false,
                'highlight_color' => '',
                'dynamic_href_setting' => 'contact_form_url',
                'translations' => [
                    'en' => [
                        'label' => 'Contact us',
                        'description' => 'Open the contact page and then update the form link from the Pages admin.',
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<string, mixed>
     */
    private function column(string $title, string $width, array $blocks, array $settings = []): array
    {
        return [
            'title' => $title,
            'width' => $width,
            'settings' => $settings,
            'blocks' => $blocks,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function productShowcaseBlock(
        string $title,
        string $englishTitle,
        string $description,
        string $englishDescription,
        array $items
    ): array {
        return [
            'type' => 'product_showcase',
            'title' => $title,
            'settings' => [
                'translations' => [
                    'en' => [
                        'title' => $englishTitle,
                    ],
                ],
            ],
            'payload' => [
                'title' => $title,
                'description' => $description,
                'translations' => [
                    'en' => [
                        'title' => $englishTitle,
                        'description' => $englishDescription,
                    ],
                ],
                'items' => $items,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function showcaseProduct(
        string $label,
        string $href,
        string $note,
        string $summary,
        string $imageUrl,
        string $imageAlt,
        string $imageTitle,
        string $badge = ''
    ): array {
        return [
            'label' => $label,
            'href' => $href,
            'note' => $note,
            'badge' => $badge,
            'summary' => $summary,
            'target' => '_self',
            'image_url' => $imageUrl,
            'image_alt' => $imageAlt,
            'image_title' => $imageTitle,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function classicLink(
        string $label,
        string $linkValue,
        string $description = '',
        ?string $englishLabel = null,
        ?string $englishDescription = null
    ): array {
        $settings = [];

        if ($englishLabel !== null || $englishDescription !== null) {
            $settings['translations'] = [
                'en' => array_filter([
                    'label' => $englishLabel,
                    'description' => $englishDescription,
                ], fn ($value) => $value !== null && $value !== ''),
            ];
        }

        return [
            'label' => $label,
            'description' => $description,
            'link_type' => 'internal_page',
            'link_value' => $linkValue,
            'link_target' => '_self',
            'panel_type' => 'link',
            'is_visible' => true,
            'settings' => $settings,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function publicPageTheme(): array
    {
        return [
            'primary_color' => '#16a34a',
            'primary_soft_color' => '#dcfce7',
            'primary_contrast_color' => '#ffffff',
            'background_style' => 'gradient',
            'background_color' => '#f8fafc',
            'background_alt_color' => '#ecfdf5',
            'surface_color' => '#ffffff',
            'text_color' => '#0f172a',
            'muted_color' => '#64748b',
            'border_color' => '#e2e8f0',
            'font_body' => 'work-sans',
            'font_heading' => 'space-grotesk',
            'radius' => 'sm',
            'shadow' => 'soft',
            'button_style' => 'solid',
        ];
    }

    private function pagePath(PlatformPage $page): string
    {
        return '/pages/'.$page->slug;
    }

    /**
     * @return array{background_type:string,background_color:string,background_image_url:string,background_image_alt:string,alignment:string}
     */
    private function pageHeader(string $key, string $locale): array
    {
        $visual = $this->stockImage($key, $locale, 'header');

        return [
            'background_type' => 'image',
            'background_color' => '',
            'background_image_url' => $visual['image_url'],
            'background_image_alt' => $visual['image_alt'],
            'alignment' => 'center',
        ];
    }

    /**
     * @return array{image_alt:string,image_url:string}
     */
    private function stockImage(string $key, string $locale, string $slot = 'header'): array
    {
        return PublicPageStockImages::slot($key, $slot, $locale);
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function upsertPage(string $slug, string $title, array $content, ?int $userId): PlatformPage
    {
        $page = PlatformPage::query()->firstOrNew(['slug' => $slug]);
        $page->fill([
            'title' => $title,
            'is_active' => true,
            'content' => $content,
            'updated_by' => $userId,
            'published_at' => now(),
        ]);
        $page->save();

        return $page;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function industryCatalog(): array
    {
        return [
            'plumbing' => [
                'slug' => 'industry-plumbing',
                'title' => 'Plomberie',
                'image_url' => $this->stockImage('industry-plumbing', 'fr')['image_url'],
                'image_alt_fr' => $this->stockImage('industry-plumbing', 'fr')['image_alt'],
                'image_alt_en' => $this->stockImage('industry-plumbing', 'en')['image_alt'],
                'fr' => [
                    'subtitle' => '<p>Gardez demandes, devis, interventions, et paiements dans un même flux plus clair pour les équipes plomberie.</p>',
                    'overview_title' => 'Un système plus clair pour la plomberie résidentielle et commerciale',
                    'overview_body' => '<p>Malikia Pro aide les équipes plomberie à capter la demande, envoyer le devis, planifier l\'intervention, et encaisser plus vite sans éclater le contexte entre plusieurs outils.</p>',
                    'overview_items' => [
                        'Demandes entrantes et devis plus rapides',
                        'Planning terrain et jobs assignés',
                        'Photos, notes, et preuve de travail',
                        'Facturation et suivi client au même endroit',
                    ],
                    'workflow_title' => 'Le parcours type pour une équipe plomberie',
                    'workflow_body' => '<p>La demande arrive, le devis est validé, l\'intervention est planifiée, puis l\'équipe clôture avec toutes les informations utiles et une facturation plus propre.</p>',
                    'workflow_items' => [
                        '1. Recevoir et qualifier la demande',
                        '2. Préparer et envoyer le devis',
                        '3. Planifier l\'intervention et affecter l\'équipe',
                        '4. Facturer et relancer le paiement',
                    ],
                ],
                'en' => [
                    'subtitle' => '<p>Keep requests, quotes, field work, and payments inside one clearer flow built for plumbing teams.</p>',
                    'overview_title' => 'A clearer system for residential and commercial plumbing',
                    'overview_body' => '<p>Malikia Pro helps plumbing teams capture demand, send the quote, schedule the visit, and collect payment faster without splitting the context across multiple tools.</p>',
                    'overview_items' => [
                        'Inbound demand and faster quoting',
                        'Field planning and assigned jobs',
                        'Photos, notes, and proof of work',
                        'Invoicing and customer follow-up in one place',
                    ],
                    'workflow_title' => 'A typical workflow for plumbing teams',
                    'workflow_body' => '<p>The request comes in, the quote is approved, the visit is scheduled, and the team closes the work with the full context still connected through invoicing.</p>',
                    'workflow_items' => [
                        '1. Receive and qualify the request',
                        '2. Prepare and send the quote',
                        '3. Schedule the visit and assign the crew',
                        '4. Invoice and follow up on payment',
                    ],
                ],
            ],
            'hvac' => [
                'slug' => 'industry-hvac',
                'title' => 'HVAC / Climatisation',
                'image_url' => $this->stockImage('industry-hvac', 'fr')['image_url'],
                'image_alt_fr' => $this->stockImage('industry-hvac', 'fr')['image_alt'],
                'image_alt_en' => $this->stockImage('industry-hvac', 'en')['image_alt'],
                'fr' => [
                    'subtitle' => '<p>Gardez appels de service, maintenance, interventions terrain, et facturation dans un même flux opérationnel conçu pour les équipes HVAC.</p>',
                    'overview_title' => 'Coordonnez appels, maintenance, et facturation dans un même système HVAC',
                    'overview_body' => '<p>Entre les urgences, les visites d\'entretien, et les interventions planifiées, Malikia Pro aide les équipes HVAC à garder un flux plus lisible du bureau jusqu\'à la facture.</p>',
                    'overview_items' => [
                        'Appels de service et maintenance récurrente',
                        'Planification des techniciens et des créneaux',
                        'Suivi des visites et compte rendu technique',
                        'Facturation et paiements au même endroit',
                    ],
                    'workflow_title' => 'Le parcours type pour une équipe HVAC',
                    'workflow_body' => '<p>Le bureau organise appels et contrats, les techniciens interviennent avec tout le contexte utile, puis la facture suit sans rupture après la visite.</p>',
                    'workflow_items' => [
                        '1. Organiser les appels et contrats d\'entretien',
                        '2. Assigner les techniciens et les créneaux',
                        '3. Documenter la visite et le travail réalisé',
                        '4. Générer la facture et assurer le suivi',
                    ],
                ],
                'en' => [
                    'subtitle' => '<p>Keep service calls, maintenance, field visits, and billing connected in one operating flow built for HVAC teams.</p>',
                    'overview_title' => 'Coordinate calls, maintenance, and billing inside one HVAC system',
                    'overview_body' => '<p>Between urgent calls, maintenance visits, and planned work, Malikia Pro helps HVAC teams keep a clearer flow from dispatch through invoicing.</p>',
                    'overview_items' => [
                        'Service calls and recurring maintenance',
                        'Technician scheduling and time windows',
                        'Visit tracking and technical reports',
                        'Billing and payments in one place',
                    ],
                    'workflow_title' => 'A typical workflow for an HVAC team',
                    'workflow_body' => '<p>The office organizes calls and service contracts, technicians arrive with the right context, and billing follows cleanly after the visit.</p>',
                    'workflow_items' => [
                        '1. Organize calls and service contracts',
                        '2. Assign technicians and time windows',
                        '3. Document the visit and completed work',
                        '4. Generate the invoice and follow up',
                    ],
                ],
            ],
            'electrical' => [
                'slug' => 'industry-electrical',
                'title' => 'Electricite',
                'image_url' => $this->stockImage('industry-electrical', 'fr')['image_url'],
                'image_alt_fr' => $this->stockImage('industry-electrical', 'fr')['image_alt'],
                'image_alt_en' => $this->stockImage('industry-electrical', 'en')['image_alt'],
                'fr' => [
                    'subtitle' => '<p>Gardez demandes, devis, chantiers, et clôture dans un même flux plus clair pour les équipes électriques.</p>',
                    'overview_title' => 'Une coordination plus claire pour les équipes électriques',
                    'overview_body' => '<p>Malikia Pro relie demande, devis, exécution, et encaissement pour que le bureau et le terrain avancent avec le même niveau de contexte.</p>',
                    'overview_items' => [
                        'Demandes et qualification commerciale',
                        'Devis et planification de l\'exécution',
                        'Suivi chantier ou intervention terrain',
                        'Clôture, relance client, et encaissement',
                    ],
                    'workflow_title' => 'Le parcours type pour une équipe électrique',
                    'workflow_body' => '<p>Les équipes commerciales et opérationnelles partagent le même flux, du premier besoin jusqu\'à la clôture et à la facture finale.</p>',
                    'workflow_items' => [
                        '1. Qualifier la demande électrique',
                        '2. Construire le devis et valider le scope',
                        '3. Planifier les techniciens ou le chantier',
                        '4. Clôturer et facturer',
                    ],
                ],
                'en' => [
                    'subtitle' => '<p>Keep requests, quotes, jobs, and closeout inside one clearer flow for electrical teams.</p>',
                    'overview_title' => 'Clearer coordination for electrical teams',
                    'overview_body' => '<p>Malikia Pro connects demand, quoting, execution, and collection so office and field teams move with the same level of context.</p>',
                    'overview_items' => [
                        'Commercial request intake and qualification',
                        'Quoting and execution planning',
                        'Project or field intervention tracking',
                        'Closeout, customer follow-up, and collection',
                    ],
                    'workflow_title' => 'A typical workflow for electrical teams',
                    'workflow_body' => '<p>Commercial and operational teams share the same flow from first request through scope validation, execution, closeout, and the final invoice.</p>',
                    'workflow_items' => [
                        '1. Qualify the electrical request',
                        '2. Build the quote and confirm scope',
                        '3. Schedule technicians or project work',
                        '4. Close the work and invoice',
                    ],
                ],
            ],
            'cleaning' => [
                'slug' => 'industry-cleaning',
                'title' => 'Nettoyage',
                'image_url' => $this->stockImage('industry-cleaning', 'fr')['image_url'],
                'image_alt_fr' => $this->stockImage('industry-cleaning', 'fr')['image_alt'],
                'image_alt_en' => $this->stockImage('industry-cleaning', 'en')['image_alt'],
                'fr' => [
                    'subtitle' => '<p>Gardez sites récurrents, présence, qualité de service, et suivi client dans un même flux plus fiable pour les équipes de nettoyage.</p>',
                    'overview_title' => 'Pilotez les opérations récurrentes avec plus de constance',
                    'overview_body' => '<p>Pour les entreprises de nettoyage, Malikia Pro aide à structurer les sites, suivre la présence, documenter la qualité, et protéger la relation client dans la durée.</p>',
                    'overview_items' => [
                        'Tâches et jobs récurrents par site',
                        'Équipe, présence, et suivi journalier',
                        'Notes terrain et preuve de passage',
                        'Suivi client et fidélisation',
                    ],
                    'workflow_title' => 'Le parcours type pour une équipe nettoyage',
                    'workflow_body' => '<p>Le travail est structuré par site, assigné par équipe, puis contrôlé avec un suivi opérationnel et commercial continu.</p>',
                    'workflow_items' => [
                        '1. Structurer les sites et la récurrence',
                        '2. Planifier les équipes et la présence',
                        '3. Suivre la qualité et les incidents',
                        '4. Facturer et conserver les clients',
                    ],
                ],
                'en' => [
                    'subtitle' => '<p>Keep recurring sites, attendance, service quality, and customer follow-up inside one more reliable flow for cleaning teams.</p>',
                    'overview_title' => 'Run recurring operations with more consistency',
                    'overview_body' => '<p>For cleaning businesses, Malikia Pro helps structure sites, track attendance, document quality, and protect the customer relationship over time.</p>',
                    'overview_items' => [
                        'Recurring tasks and jobs by site',
                        'Team, attendance, and daily follow-up',
                        'Field notes and proof of visit',
                        'Retention and customer follow-up',
                    ],
                    'workflow_title' => 'A typical workflow for a cleaning team',
                    'workflow_body' => '<p>Work is structured by site, assigned by team, and monitored with continuous operational and commercial visibility.</p>',
                    'workflow_items' => [
                        '1. Structure sites and recurrence',
                        '2. Plan teams and attendance',
                        '3. Track quality and incidents',
                        '4. Invoice and retain customers',
                    ],
                ],
            ],
            'salon-beauty' => [
                'slug' => 'industry-salon-beauty',
                'title' => 'Salon & beaute',
                'image_url' => $this->stockImage('industry-salon-beauty', 'fr')['image_url'],
                'image_alt_fr' => $this->stockImage('industry-salon-beauty', 'fr')['image_alt'],
                'image_alt_en' => $this->stockImage('industry-salon-beauty', 'en')['image_alt'],
                'fr' => [
                    'subtitle' => '<p>Gardez réservations, rappels, gestion des no-shows, et fidélisation dans une même expérience plus fluide pour l\'équipe comme pour le client.</p>',
                    'overview_title' => 'Une expérience plus fluide pour les salons et activités beauté',
                    'overview_body' => '<p>Pour les salons et activités beauté, réservation, présence, accueil, et fidélisation font partie du même revenu. Le produit doit rester simple, rapide, et premium.</p>',
                    'overview_items' => [
                        'Réservation en ligne et disponibilité en temps réel',
                        'Rappels, annulations, et no-show fees',
                        'Suivi client, fidélité, et VIP',
                        'Accueil, check-in, et file d\'attente si besoin',
                    ],
                    'workflow_title' => 'Le parcours type pour salon & beauté',
                    'workflow_body' => '<p>Le client réserve, l\'équipe prépare la journée, l\'accueil reste fluide, puis la fidélisation prend le relais après la visite.</p>',
                    'workflow_items' => [
                        '1. Ouvrir les plages de réservation',
                        '2. Confirmer et rappeler les rendez-vous',
                        '3. Gérer l\'arrivée et le service',
                        '4. Relancer et fidéliser après la visite',
                    ],
                ],
                'en' => [
                    'subtitle' => '<p>Keep bookings, reminders, no-show handling, and loyalty connected in one smoother experience for the team and the client.</p>',
                    'overview_title' => 'A smoother experience for salons and beauty businesses',
                    'overview_body' => '<p>For salons and beauty businesses, booking, attendance, reception, and loyalty are part of the same revenue flow. The product has to stay simple, fast, and premium.</p>',
                    'overview_items' => [
                        'Online booking with live availability',
                        'Reminders, cancellations, and no-show fees',
                        'Customer follow-up, loyalty, and VIP flows',
                        'Reception, check-in, and queue handling when needed',
                    ],
                    'workflow_title' => 'A typical workflow for salon and beauty teams',
                    'workflow_body' => '<p>The customer books, the team prepares the day, reception stays smooth, and loyalty takes over after the visit.</p>',
                    'workflow_items' => [
                        '1. Open booking slots',
                        '2. Confirm and remind appointments',
                        '3. Manage arrival and service delivery',
                        '4. Follow up and build retention',
                    ],
                ],
            ],
            'restaurant' => [
                'slug' => 'industry-restaurant',
                'title' => 'Restaurant',
                'image_url' => $this->stockImage('industry-restaurant', 'fr')['image_url'],
                'image_alt_fr' => $this->stockImage('industry-restaurant', 'fr')['image_alt'],
                'image_alt_en' => $this->stockImage('industry-restaurant', 'en')['image_alt'],
                'fr' => [
                    'subtitle' => '<p>Gardez réservations, file d\'attente, check-in, et expérience client reliés dans un même parcours plus fluide pour l\'équipe d\'accueil.</p>',
                    'overview_title' => 'Gérez le parcours restaurant avec plus de fluidité avant et pendant le service',
                    'overview_body' => '<p>Le revenu ne dépend pas seulement des tables réservées, mais aussi de la fluidité d\'accueil, de l\'attente, des dépôts, et de la relation client après la visite.</p>',
                    'overview_items' => [
                        'Réservations et disponibilités en temps réel',
                        'Gestion de la file et check-in client',
                        'Dépôts et règles d\'annulation',
                        'Communication avant et après la visite',
                    ],
                    'workflow_title' => 'Le parcours type pour un restaurant',
                    'workflow_body' => '<p>Les clients réservent, l\'équipe confirme, la salle gère l\'arrivée et l\'attente, puis les relances permettent de faire revenir les meilleurs clients.</p>',
                    'workflow_items' => [
                        '1. Ouvrir les disponibilités',
                        '2. Confirmer les réservations et les dépôts',
                        '3. Gérer l\'attente et l\'arrivée en salle',
                        '4. Relancer après la visite',
                    ],
                ],
                'en' => [
                    'subtitle' => '<p>Keep bookings, waitlist flow, check-in, and guest experience connected in one smoother journey for the front-of-house team.</p>',
                    'overview_title' => 'Manage the restaurant journey with more flow before and during service',
                    'overview_body' => '<p>Revenue depends not only on booked tables, but also on front-of-house flow, waiting time, deposits, and guest follow-up after the visit.</p>',
                    'overview_items' => [
                        'Bookings and live availability',
                        'Queue handling and customer check-in',
                        'Deposits and cancellation rules',
                        'Communication before and after the visit',
                    ],
                    'workflow_title' => 'A typical workflow for a restaurant',
                    'workflow_body' => '<p>Guests book, the team confirms, the dining room manages arrivals and waiting flow, and follow-up helps bring the best customers back.</p>',
                    'workflow_items' => [
                        '1. Open availability',
                        '2. Confirm bookings and deposits',
                        '3. Manage waiting and front-of-house arrival',
                        '4. Follow up after the visit',
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function solutionCatalog(): array
    {
        return [
            'field-services' => [
                'slug' => 'solution-field-services',
                'title' => 'Services terrain',
                'image_url' => $this->stockImage('solution-field-services', 'fr')['image_url'],
                'image_alt_fr' => $this->stockImage('solution-field-services', 'fr')['image_alt'],
                'image_alt_en' => $this->stockImage('solution-field-services', 'en')['image_alt'],
                'fr' => [
                    'subtitle' => '<p>Coordonnez planification, dispatch, exécution terrain, et preuve de travail dans un même flux opérationnel pensé pour garder les équipes alignées de l\'affectation à la clôture.</p>',
                    'overview_title' => 'Pilotez le terrain avec une coordination plus claire de la planification jusqu\'à la preuve',
                    'overview_body' => '<p>Services terrain réunit planning, dispatch, jobs, suivi des tâches, et preuve de travail pour aider les équipes à livrer plus régulièrement sans reconstruire le contexte à chaque étape.</p>',
                    'overview_items' => [
                        'Planning équipe et dispatch centralisés',
                        'Jobs terrain avec preuves, notes, et statuts',
                        'Tâches internes visibles par rôle ou équipe',
                        'Suivi opérationnel sans perte de contexte',
                    ],
                    'workflow_title' => 'Un parcours terrain clair de l\'affectation à la clôture',
                    'workflow_body' => '<p>Les responsables planifient et affectent le travail, les équipes exécutent avec le bon contexte en main, puis les jobs reviennent avec preuves, notes, et validation prêtes à être revues.</p>',
                    'workflow_items' => [
                        '1. Planifier les ressources et les plages de travail',
                        '2. Assigner les jobs et interventions',
                        '3. Suivre l\'avancement sur le terrain',
                        '4. Clôturer avec preuve de travail et validation',
                    ],
                    'modules_title' => 'Les espaces terrain qui gardent les équipes alignées pendant l\'exécution',
                    'modules_body' => '<p>La solution active les pages opérationnelles sur lesquelles les équipes s\'appuient pour planifier, affecter, exécuter, documenter, et clôturer le travail avec moins d\'écart entre le bureau et le terrain.</p>',
                    'modules_items' => [
                        'Planning',
                        'Jobs',
                        'Tâches',
                        'Présence',
                        'Équipe',
                        'Preuves de travail',
                    ],
                ],
                'en' => [
                    'title' => 'Field services',
                    'subtitle' => '<p>Coordinate scheduling, dispatch, field execution, and proof of work in one operating workflow built to keep teams aligned from assignment to completion.</p>',
                    'overview_title' => 'Run field work with clearer coordination from planning to proof',
                    'overview_body' => '<p>Field services brings scheduling, dispatch, jobs, task follow-through, and proof of work together so teams can deliver more consistently without rebuilding context at every step.</p>',
                    'overview_items' => [
                        'Centralized team scheduling and dispatch',
                        'Field jobs with proof, notes, and statuses',
                        'Internal tasks visible by role or team',
                        'Operational follow-up without context loss',
                    ],
                    'workflow_title' => 'A clear field workflow from assignment to completion',
                    'workflow_body' => '<p>Managers plan and assign the work, teams execute with the right context in hand, and completed jobs come back with proof, notes, and validation ready to review.</p>',
                    'workflow_items' => [
                        '1. Plan resources and work windows',
                        '2. Assign jobs and field visits',
                        '3. Track execution on the ground',
                        '4. Close the work with proof and validation',
                    ],
                    'modules_title' => 'The field workspaces that keep teams aligned through execution',
                    'modules_body' => '<p>The solution activates the operational pages teams rely on to schedule, assign, execute, document, and close work with fewer gaps between the office and the field.</p>',
                    'modules_items' => [
                        'Planning',
                        'Jobs',
                        'Tasks',
                        'Presence',
                        'Team',
                        'Proofs',
                    ],
                ],
            ],
            'reservations-queues' => [
                'slug' => 'solution-reservations-queues',
                'title' => 'Réservations & files',
                'image_url' => $this->stockImage('solution-reservations-queues', 'fr')['image_url'],
                'image_alt_fr' => $this->stockImage('solution-reservations-queues', 'fr')['image_alt'],
                'image_alt_en' => $this->stockImage('solution-reservations-queues', 'en')['image_alt'],
                'fr' => [
                    'subtitle' => '<p>Transformez réservation, confirmations, accueil, et gestion de la file en un parcours client plus fluide de la première réservation jusqu\'à l\'arrivée sur site.</p>',
                    'overview_title' => 'Gérez le flux client plus clairement avant et pendant la visite',
                    'overview_body' => '<p>Réservations & files réunit disponibilités, confirmations, check-in, et gestion de la file pour que les clients avancent plus fluidement dans la visite et que l\'équipe garde un meilleur contrôle sur place.</p>',
                    'overview_items' => [
                        'Réservations en ligne avec disponibilité en direct',
                        'Rappels et confirmations automatisés',
                        'Check-in sur site et kiosques en libre-service',
                        'Vue temps réel sur la file et les arrivées',
                    ],
                    'workflow_title' => 'Un parcours de visite clair de la réservation jusqu\'à l\'arrivée',
                    'workflow_body' => '<p>Le client réserve, l\'équipe confirme, la visite est préparée puis gérée sur place, et chaque changement reste visible pour que le flux d\'accueil soit plus simple à piloter.</p>',
                    'workflow_items' => [
                        '1. Publier les disponibilités et les ressources',
                        '2. Accepter les réservations en libre-service',
                        '3. Gérer confirmations, retards, et annulations',
                        '4. Suivre la file et le check-in en temps réel',
                    ],
                    'modules_title' => 'Les espaces qui gardent réservation, accueil, et file dans un même flux',
                    'modules_body' => '<p>La solution relie les pages qui structurent la réservation avant visite, l\'arrivée sur site, et la visibilité d\'accueil en temps réel.</p>',
                    'modules_items' => [
                        'Agenda',
                        'Disponibilités',
                        'Réservations client',
                        'Kiosque client',
                        'Kiosque public',
                        'File d\'attente',
                    ],
                ],
                'en' => [
                    'title' => 'Reservations & queues',
                    'subtitle' => '<p>Turn booking, confirmations, arrival handling, and queue visibility into one smoother customer journey from first reservation to on-site reception.</p>',
                    'overview_title' => 'Manage customer flow more clearly before and during the visit',
                    'overview_body' => '<p>Reservations & queues brings availability, confirmations, check-in, and queue handling together so customers move through the visit more smoothly and teams keep better control on site.</p>',
                    'overview_items' => [
                        'Online booking with live availability',
                        'Automated reminders and confirmations',
                        'On-site check-in and self-service kiosks',
                        'Real-time view of queues and arrivals',
                    ],
                    'workflow_title' => 'A clear visit workflow from booking to arrival',
                    'workflow_body' => '<p>The customer books, the team confirms, the visit is prepared and handled on site, and every change stays visible so the front-desk flow remains easier to manage.</p>',
                    'workflow_items' => [
                        '1. Publish availability and resources',
                        '2. Accept self-service bookings',
                        '3. Handle confirmations, delays, and cancellations',
                        '4. Track queue and check-in activity live',
                    ],
                    'modules_title' => 'The spaces that keep booking, reception, and queue flow connected',
                    'modules_body' => '<p>The solution brings together the pages that structure booking before the visit, on-site arrival, and front-desk visibility in real time.</p>',
                    'modules_items' => [
                        'Calendar',
                        'Availability',
                        'Customer bookings',
                        'Client kiosk',
                        'Public kiosk',
                        'Queue board',
                    ],
                ],
            ],
            'sales-quoting' => [
                'slug' => 'solution-sales-quoting',
                'title' => 'Vente & devis',
                'image_url' => $this->stockImage('solution-sales-quoting', 'fr')['image_url'],
                'image_alt_fr' => $this->stockImage('solution-sales-quoting', 'fr')['image_alt'],
                'image_alt_en' => $this->stockImage('solution-sales-quoting', 'en')['image_alt'],
                'fr' => [
                    'subtitle' => '<p>Captez les demandes entrantes, qualifiez les opportunités, envoyez des devis plus propres, et gardez la relance visible jusqu\'à l\'approbation du travail.</p>',
                    'overview_title' => 'Passez de la première demande au devis approuvé sans friction',
                    'overview_body' => '<p>Vente & devis relie la demande entrante, la qualification, le contexte client, le devis, et la relance pour aider l\'équipe à convertir plus vite sans reconstruire les mêmes informations à chaque étape.</p>',
                    'overview_items' => [
                        'Demandes centralisées depuis le web ou l\'équipe',
                        'Fiches clients partagées avec historique visible',
                        'Devis suivis par statut, relance, et approbation',
                        'Pipeline commercial plus clair pour prioriser les opportunités',
                    ],
                    'workflow_title' => 'Un parcours clair pour les équipes commerciales',
                    'workflow_body' => '<p>L\'équipe capte la demande, qualifie l\'opportunité, prépare le devis, relance avec le bon contexte, puis fait avancer la décision sans sauter entre des outils déconnectés.</p>',
                    'workflow_items' => [
                        '1. Capturer le lead entrant',
                        '2. Qualifier et enrichir le dossier client',
                        '3. Préparer puis envoyer le devis',
                        '4. Relancer et convertir en vente ou intervention',
                    ],
                    'modules_title' => 'Les espaces commerciaux qui font avancer devis et conversion',
                    'modules_body' => '<p>La solution réunit les pages sur lesquelles l\'équipe s\'appuie pour capter la demande, structurer les devis, suivre le mouvement des opportunités, et garder le contexte client disponible de la première demande jusqu\'à l\'approbation.</p>',
                    'modules_items' => [
                        'Dashboard commercial',
                        'Demandes',
                        'Devis',
                        'Clients',
                        'Pipeline',
                        'Scan de plans',
                    ],
                ],
                'en' => [
                    'title' => 'Sales & quoting',
                    'subtitle' => '<p>Capture inbound demand, qualify opportunities, send cleaner quotes, and keep follow-up visible until the work is approved.</p>',
                    'overview_title' => 'Move from first request to approved quote without friction',
                    'overview_body' => '<p>Sales & quoting connects inbound demand, qualification, customer context, quoting, and follow-up so the team can convert faster without rebuilding the same information at every step.</p>',
                    'overview_items' => [
                        'Centralized requests from the web or the team',
                        'Shared customer records with visible history',
                        'Quotes tracked through status, follow-up, and approval',
                        'A clearer sales pipeline to prioritize opportunities',
                    ],
                    'workflow_title' => 'A clear operating flow for sales teams',
                    'workflow_body' => '<p>The team captures the request, qualifies the opportunity, prepares the quote, follows up with the right context, and moves the work forward without jumping between disconnected tools.</p>',
                    'workflow_items' => [
                        '1. Capture inbound leads',
                        '2. Qualify and enrich the customer record',
                        '3. Prepare and send the quote',
                        '4. Follow up and convert into work or revenue',
                    ],
                    'modules_title' => 'The commercial spaces that keep quoting and conversion moving',
                    'modules_body' => '<p>The solution brings together the pages teams rely on to capture demand, structure quotes, track opportunity movement, and keep customer context available from first request to approval.</p>',
                    'modules_items' => [
                        'Sales dashboard',
                        'Requests',
                        'Quotes',
                        'Customers',
                        'Pipeline',
                        'Plan scan',
                    ],
                ],
            ],
            'commerce-catalog' => [
                'slug' => 'solution-commerce-catalog',
                'title' => 'Commerce & catalogue',
                'image_url' => $this->stockImage('solution-commerce-catalog', 'fr')['image_url'],
                'image_alt_fr' => $this->stockImage('solution-commerce-catalog', 'fr')['image_alt'],
                'image_alt_en' => $this->stockImage('solution-commerce-catalog', 'en')['image_alt'],
                'fr' => [
                    'subtitle' => '<p>Publiez votre offre, guidez la commande, facturez proprement, et encaissez dans un même parcours commercial connecté.</p>',
                    'overview_title' => 'Structurez l\'offre, la commande, et le revenu dans un même système commercial',
                    'overview_body' => '<p>Commerce & catalogue relie catalogue, boutique, commande, facture, et paiement pour que la vente reste lisible du premier choix client jusqu\'au revenu encaissé.</p>',
                    'overview_items' => [
                        'Catalogue produits et services clair',
                        'Parcours de commande guidé et cohérent',
                        'Facturation reliée à la transaction',
                        'Paiements et suivi du revenu au même endroit',
                    ],
                    'workflow_title' => 'Passez de l\'offre publiée au revenu encaissé sans casser le flux',
                    'workflow_body' => '<p>L\'équipe structure l\'offre, ouvre la vente, génère la commande ou la facture, puis suit l\'encaissement sans ressaisir le même contexte à chaque étape.</p>',
                    'workflow_items' => [
                        '1. Structurer produits, services, et tarifs',
                        '2. Ouvrir la vente via boutique ou équipe',
                        '3. Générer commande ou facture',
                        '4. Suivre paiement et revenu encaissé',
                    ],
                    'modules_title' => 'Les espaces commerciaux qui gardent vente, facture, et paiement reliés',
                    'modules_body' => '<p>La solution réunit les pages qui permettent de présenter l\'offre, encaisser plus proprement, et garder une lecture plus claire du revenu.</p>',
                    'modules_items' => [
                        'Produits',
                        'Services',
                        'Boutique',
                        'Commandes',
                        'Factures',
                        'Paiements',
                    ],
                ],
                'en' => [
                    'title' => 'Commerce & catalog',
                    'subtitle' => '<p>Publish your offer, guide the order, invoice cleanly, and collect payment inside one connected commercial journey.</p>',
                    'overview_title' => 'Structure the offer, the order, and the revenue inside one commercial system',
                    'overview_body' => '<p>Commerce & catalog keeps the catalog, storefront, order flow, invoicing, and payment connected so selling stays clear from first selection to collected revenue.</p>',
                    'overview_items' => [
                        'Clear product and service catalog',
                        'Guided and consistent order flow',
                        'Invoicing tied to the transaction',
                        'Payments and revenue follow-through in one place',
                    ],
                    'workflow_title' => 'Move from published offer to collected revenue without breaking the flow',
                    'workflow_body' => '<p>The team structures the offer, opens the sale, generates the order or invoice, and follows payment without re-entering the same context at each step.</p>',
                    'workflow_items' => [
                        '1. Structure products, services, and pricing',
                        '2. Open the sale through the storefront or the team',
                        '3. Generate the order or invoice',
                        '4. Track payment and collected revenue',
                    ],
                    'modules_title' => 'The commercial spaces that keep selling, invoicing, and payment connected',
                    'modules_body' => '<p>The solution brings together the pages that present the offer, support cleaner collection, and keep revenue easier to read.</p>',
                    'modules_items' => [
                        'Products',
                        'Services',
                        'Storefront',
                        'Orders',
                        'Invoices',
                        'Payments',
                    ],
                ],
            ],
            'marketing-loyalty' => [
                'slug' => 'solution-marketing-loyalty',
                'title' => 'Marketing & fidélisation',
                'image_url' => $this->stockImage('solution-marketing-loyalty', 'fr')['image_url'],
                'image_alt_fr' => $this->stockImage('solution-marketing-loyalty', 'fr')['image_alt'],
                'image_alt_en' => $this->stockImage('solution-marketing-loyalty', 'en')['image_alt'],
                'fr' => [
                    'subtitle' => '<p>Transformez signaux client, campagnes ciblées, et parcours de fidélisation en une façon plus pertinente de faire revenir les clients et de développer le revenu récurrent.</p>',
                    'overview_title' => 'Réactivez les clients avec plus de pertinence et moins d\'approximation',
                    'overview_body' => '<p>Marketing & fidélisation relie signaux d\'audience, campagnes, logique VIP, et parcours de relance pour aider l\'équipe à agir avec un meilleur timing et à garder le revenu récurrent plus visible.</p>',
                    'overview_items' => [
                        'Campagnes email et SMS plus pertinentes',
                        'Segments construits à partir du comportement réel',
                        'Programmes VIP et avantages fidélité',
                        'Relances utiles au bon moment',
                    ],
                    'workflow_title' => 'Passez du signal client au revenu récurrent avec un flux de rétention plus clair',
                    'workflow_body' => '<p>L\'équipe identifie la bonne audience, prépare le message ou le parcours, active le bon canal, puis mesure le retour avec le même contexte client que le reste de la plateforme.</p>',
                    'workflow_items' => [
                        '1. Repérer les bons signaux client',
                        '2. Construire segments et logique VIP',
                        '3. Activer campagnes et scénarios au bon moment',
                        '4. Suivre réactivation, fidélité, et valeur',
                    ],
                    'modules_title' => 'Les espaces qui relient signaux, campagnes, et fidélisation',
                    'modules_body' => '<p>La solution rassemble les modules qui permettent d\'agir au bon moment, avec le bon contexte, et de suivre l\'effet sur le revenu récurrent.</p>',
                    'modules_items' => [
                        'Campagnes',
                        'Mailing lists',
                        'Audience segments',
                        'Loyalty',
                        'VIP tiers',
                        'Prospect providers',
                    ],
                ],
                'en' => [
                    'title' => 'Marketing & loyalty',
                    'subtitle' => '<p>Turn customer signals, targeted campaigns, and loyalty journeys into a more relevant way to bring people back and grow repeat revenue.</p>',
                    'overview_title' => 'Re-engage customers with more relevance and less guesswork',
                    'overview_body' => '<p>Marketing & loyalty connects audience signals, campaigns, VIP logic, and follow-up journeys so teams can act with better timing and keep repeat revenue more visible.</p>',
                    'overview_items' => [
                        'More relevant email and SMS campaigns',
                        'Segments built from real behavior',
                        'VIP programs and loyalty benefits',
                        'Useful follow-up triggered at the right time',
                    ],
                    'workflow_title' => 'Move from customer signal to repeat revenue with a clearer retention flow',
                    'workflow_body' => '<p>The team identifies the right audience, prepares the message or journey, activates delivery on the right channel, and measures return using the same customer context as the rest of the platform.</p>',
                    'workflow_items' => [
                        '1. Spot the right customer signals',
                        '2. Build segments and VIP logic',
                        '3. Activate campaigns and journeys at the right time',
                        '4. Track reactivation, loyalty, and value',
                    ],
                    'modules_title' => 'The spaces that connect signals, campaigns, and loyalty',
                    'modules_body' => '<p>The solution brings together the modules that help teams act at the right moment, with the right context, and measure the effect on repeat revenue.</p>',
                    'modules_items' => [
                        'Campaigns',
                        'Mailing lists',
                        'Audience segments',
                        'Loyalty',
                        'VIP tiers',
                        'Prospect providers',
                    ],
                ],
            ],
            'multi-entity-oversight' => [
                'slug' => 'solution-multi-entity-oversight',
                'title' => 'Pilotage multi-entreprise',
                'image_url' => $this->stockImage('solution-multi-entity-oversight', 'fr')['image_url'],
                'image_alt_fr' => $this->stockImage('solution-multi-entity-oversight', 'fr')['image_alt'],
                'image_alt_en' => $this->stockImage('solution-multi-entity-oversight', 'en')['image_alt'],
                'fr' => [
                    'subtitle' => '<p>Obtenez une vue dirigeante partagée sur les entités, la performance, et les priorités pour comparer plus vite, décider plus clairement, et agir sans perdre le contexte local.</p>',
                    'overview_title' => 'Pilotez plusieurs entités avec plus de visibilité et un meilleur alignement',
                    'overview_body' => '<p>Pilotage multi-entreprise relie indicateurs transverses, vues partagées, et logique de comparaison pour aider la direction à lire la performance plus clairement et à coordonner l\'action sans casser le contexte.</p>',
                    'overview_items' => [
                        'Vue consolidée sur plusieurs entités ou unités d\'activité',
                        'Indicateurs partagés entre les modules clés',
                        'Priorités et points d\'attention visibles rapidement',
                        'Pilotage direction et responsables dans un même espace',
                    ],
                    'workflow_title' => 'Passez de la visibilité partagée à l\'action coordonnée sans perdre la lecture locale',
                    'workflow_body' => '<p>La direction lit les signaux globaux, identifie les écarts qui comptent, descend au bon niveau équipe ou entité, puis transforme les priorités en action via le bon module ou le bon responsable.</p>',
                    'workflow_items' => [
                        '1. Lire les indicateurs transverses',
                        '2. Comparer entités, équipes, ou périodes',
                        '3. Isoler les écarts qui demandent une décision',
                        '4. Basculer vers le bon module ou responsable pour agir',
                    ],
                    'modules_title' => 'Les espaces de pilotage qui relient vision globale et action locale',
                    'modules_body' => '<p>La solution s\'appuie sur des vues consolidées et des espaces de commandement qui aident la direction à prioriser sans perdre la lecture terrain.</p>',
                    'modules_items' => [
                        'Dashboard global',
                        'Vue revenu',
                        'Vue opérations',
                        'Vue équipe',
                        'Alertes',
                        'Rapports partagés',
                    ],
                ],
                'en' => [
                    'title' => 'Multi-entity oversight',
                    'subtitle' => '<p>Get one shared leadership view across entities, performance, and priorities so teams can compare faster, decide more clearly, and act without losing local context.</p>',
                    'overview_title' => 'Lead multiple entities with clearer visibility and stronger alignment',
                    'overview_body' => '<p>Multi-entity oversight connects cross-functional indicators, shared leadership views, and comparison logic so teams can read performance more clearly and coordinate action without breaking context.</p>',
                    'overview_items' => [
                        'Consolidated view across entities or business units',
                        'Shared indicators across core modules',
                        'Attention points surfaced quickly',
                        'Leadership and managers working from the same view',
                    ],
                    'workflow_title' => 'From top-level visibility to action in the field',
                    'workflow_body' => '<p>Leadership reviews the top-level signals, identifies the gaps that matter, drills into the right team or entity, and turns priorities into action through the right module or owner.</p>',
                    'workflow_items' => [
                        '1. Review top-level indicators',
                        '2. Identify gaps or opportunities',
                        '3. Drill into detail by team or entity',
                        '4. Coordinate next actions',
                    ],
                    'modules_title' => 'The oversight spaces that connect global visibility and local action',
                    'modules_body' => '<p>The solution relies on consolidated views and shared command spaces that help leadership prioritize without losing operational context.</p>',
                    'modules_items' => [
                        'Global dashboard',
                        'Revenue view',
                        'Operations view',
                        'Team view',
                        'Alerts',
                        'Shared reports',
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function productCatalog(): array
    {
        return [
            'sales-crm' => [
                'slug' => 'sales-crm',
                'title' => 'Sales & CRM',
                'pricing_href' => '/pricing#sales-crm',
                'image_url' => $this->stockImage('sales-crm', 'fr')['image_url'],
                'image_alt_fr' => $this->stockImage('sales-crm', 'fr')['image_alt'],
                'image_alt_en' => $this->stockImage('sales-crm', 'en')['image_alt'],
                'fr' => [
                    'subtitle' => '<p>Centralisez les demandes, les devis, et le suivi client dans un même espace commercial.</p>',
                    'overview_title' => 'Passez de la demande au devis sans rupture',
                    'overview_body' => '<p>Sales & CRM réunit la capture de leads, la qualification, les devis et l\'historique client pour accélérer chaque opportunité.</p>',
                    'overview_items' => [
                        'Demandes web et formulaires centralisés',
                        'Devis rapides avec suivi de statut',
                        'Fiches clients partagées par toute l\'équipe',
                        'Pipeline visible pour chaque opportunité',
                    ],
                    'workflow_title' => 'Un parcours clair pour les équipes commerciales',
                    'workflow_body' => '<p>Les équipes captent la demande, enrichissent le dossier client, préparent le devis puis suivent la conversion sans sortir de la plateforme.</p>',
                    'workflow_items' => [
                        '1. Capturer la demande entrante',
                        '2. Qualifier le besoin et affecter un responsable',
                        '3. Envoyer le devis et suivre la relance',
                        '4. Transformer l\'opportunité en prestation ou en vente',
                    ],
                    'pages_title' => 'Pages et espaces inclus dans la suite',
                    'pages_body' => '<p>Le produit couvre tout le cycle commercial, de l\'acquisition jusqu\'au suivi client.</p>',
                    'pages_items' => [
                        'Dashboard commercial',
                        'Demandes',
                        'Devis',
                        'Clients',
                        'Pipeline',
                        'Scan de plans',
                    ],
                ],
                'en' => [
                    'subtitle' => '<p>Centralize demand, quotes, and customer follow-up in one sales workspace.</p>',
                    'overview_title' => 'Move from first request to approved quote without friction',
                    'overview_body' => '<p>Sales & CRM brings lead capture, qualification, quoting, and customer history together so every opportunity moves faster.</p>',
                    'overview_items' => [
                        'Centralized web requests and forms',
                        'Fast quotes with live status tracking',
                        'Shared customer records across the team',
                        'A visible pipeline for every opportunity',
                    ],
                    'workflow_title' => 'A clear operating flow for sales teams',
                    'workflow_body' => '<p>Teams capture demand, enrich the customer record, prepare the quote, and track conversion without leaving the platform.</p>',
                    'workflow_items' => [
                        '1. Capture inbound demand',
                        '2. Qualify the request and assign an owner',
                        '3. Send the quote and follow up',
                        '4. Turn the opportunity into work or revenue',
                    ],
                    'pages_title' => 'Pages and workspaces included',
                    'pages_body' => '<p>This suite covers the full sales cycle, from acquisition to customer follow-up.</p>',
                    'pages_items' => [
                        'Sales dashboard',
                        'Requests',
                        'Quotes',
                        'Customers',
                        'Pipeline',
                        'Plan scan',
                    ],
                ],
            ],
            'reservations' => [
                'slug' => 'reservations',
                'title' => 'Reservations',
                'pricing_href' => '/pricing#reservations',
                'image_url' => $this->stockImage('reservations', 'fr')['image_url'],
                'image_alt_fr' => $this->stockImage('reservations', 'fr')['image_alt'],
                'image_alt_en' => $this->stockImage('reservations', 'en')['image_alt'],
                'fr' => [
                    'subtitle' => '<p>Offrez la réservation en libre-service sans perdre le contrôle de la visite.</p>',
                    'overview_title' => 'La prise de rendez-vous devient un vrai canal de vente',
                    'overview_body' => '<p>Reservations connecte les agendas, les confirmations client, les kiosques et la disponibilité en temps réel dans une seule expérience.</p>',
                    'overview_items' => [
                        'Réservations en ligne avec disponibilité temps réel',
                        'Confirmation et rappel automatiques',
                        'Gestion de file et check-in sur site',
                        'Kiosques client et public pour accélérer l\'accueil',
                    ],
                    'workflow_title' => 'Un flux simple pour les équipes d\'accueil et d\'opération',
                    'workflow_body' => '<p>Le client réserve, l\'équipe confirme, le point de service gère l\'arrivée et le planning reste synchronisé sans double saisie.</p>',
                    'workflow_items' => [
                        '1. Publier les plages et ressources disponibles',
                        '2. Permettre la réservation depuis le web ou un kiosque',
                        '3. Confirmer, replanifier ou annuler rapidement',
                        '4. Suivre les arrivées et la file en temps réel',
                    ],
                    'pages_title' => 'Pages et espaces inclus dans la suite',
                    'pages_body' => '<p>Le produit couvre la réservation avant visite, la gestion sur place et les réglages d\'exploitation.</p>',
                    'pages_items' => [
                        'Agenda',
                        'Disponibilités',
                        'Réservations client',
                        'Kiosque client',
                        'Kiosque public',
                        'File d\'attente',
                    ],
                ],
                'en' => [
                    'subtitle' => '<p>Offer self-service booking without losing control over the visit.</p>',
                    'overview_title' => 'Turn booking into a real acquisition channel',
                    'overview_body' => '<p>Reservations connects calendars, confirmations, kiosks, and live availability into one customer experience.</p>',
                    'overview_items' => [
                        'Online booking with live availability',
                        'Automatic confirmations and reminders',
                        'Queue management and on-site check-in',
                        'Client and public kiosks for faster reception',
                    ],
                    'workflow_title' => 'A simple flow for reception and operations teams',
                    'workflow_body' => '<p>The customer books, the team confirms, the location handles arrival, and the schedule stays aligned without duplicate work.</p>',
                    'workflow_items' => [
                        '1. Publish slots and resource availability',
                        '2. Accept bookings from the web or a kiosk',
                        '3. Confirm, reschedule, or cancel quickly',
                        '4. Track arrivals and queue activity live',
                    ],
                    'pages_title' => 'Pages and workspaces included',
                    'pages_body' => '<p>The suite covers pre-visit booking, on-site handling, and operating settings.</p>',
                    'pages_items' => [
                        'Calendar',
                        'Availability',
                        'Customer bookings',
                        'Client kiosk',
                        'Public kiosk',
                        'Queue board',
                    ],
                ],
            ],
            'operations' => [
                'slug' => 'operations',
                'title' => 'Operations',
                'pricing_href' => '/pricing#operations',
                'image_url' => $this->stockImage('operations', 'fr')['image_url'],
                'image_alt_fr' => $this->stockImage('operations', 'fr')['image_alt'],
                'image_alt_en' => $this->stockImage('operations', 'en')['image_alt'],
                'fr' => [
                    'subtitle' => '<p>Pilotez planning, dispatch, exécution terrain, et preuve de travail depuis un même espace opérationnel.</p>',
                    'overview_title' => 'Une vue opérationnelle complète pour mieux livrer',
                    'overview_body' => '<p>Operations aligne planning, dispatch, interventions et suivi quotidien pour que les équipes terrain travaillent avec le bon contexte.</p>',
                    'overview_items' => [
                        'Planning centralisé avec affectation rapide',
                        'Jobs et interventions suivis en direct',
                        'Tâches internes visibles par équipe',
                        'Preuves de travail, notes et complétions au même endroit',
                    ],
                    'workflow_title' => 'Un cycle de travail adapté aux opérations de terrain',
                    'workflow_body' => '<p>Les managers planifient, assignent les jobs, suivent l\'exécution puis valident la fin des travaux avec toutes les preuves nécessaires.</p>',
                    'workflow_items' => [
                        '1. Planifier les ressources et les plages de travail',
                        '2. Assigner les jobs et tâches aux bonnes équipes',
                        '3. Suivre l\'avancement et les blocages',
                        '4. Clôturer avec notes, photos ou signatures',
                    ],
                    'pages_title' => 'Pages et espaces inclus dans la suite',
                    'pages_body' => '<p>Le produit couvre la planification, l\'exécution et le suivi de la performance terrain.</p>',
                    'pages_items' => [
                        'Planning',
                        'Jobs',
                        'Tâches',
                        'Présence',
                        'Équipe',
                        'Suivi journalier',
                    ],
                ],
                'en' => [
                    'subtitle' => '<p>Run planning, dispatch, field execution, and proof of work from one operational workspace.</p>',
                    'overview_title' => 'A complete operational view to deliver work better',
                    'overview_body' => '<p>Operations aligns planning, dispatch, jobs, and daily follow-up so field teams always work with the right context.</p>',
                    'overview_items' => [
                        'Centralized scheduling with fast assignment',
                        'Live tracking for jobs and interventions',
                        'Internal tasks visible by team',
                        'Proof of work, notes, and completion in one place',
                    ],
                    'workflow_title' => 'A work cycle designed for field operations',
                    'workflow_body' => '<p>Managers plan resources, assign jobs, monitor execution, and validate completion with all required proof.</p>',
                    'workflow_items' => [
                        '1. Plan resources and work windows',
                        '2. Assign jobs and tasks to the right team',
                        '3. Track progress and blockers',
                        '4. Close the work with notes, photos, or signatures',
                    ],
                    'pages_title' => 'Pages and workspaces included',
                    'pages_body' => '<p>The suite covers planning, execution, and field performance follow-up.</p>',
                    'pages_items' => [
                        'Planning',
                        'Jobs',
                        'Tasks',
                        'Presence',
                        'Team',
                        'Daily follow-up',
                    ],
                ],
            ],
            'commerce' => [
                'slug' => 'commerce',
                'title' => 'Commerce',
                'pricing_href' => '/pricing#commerce',
                'image_url' => $this->stockImage('commerce', 'fr')['image_url'],
                'image_alt_fr' => $this->stockImage('commerce', 'fr')['image_alt'],
                'image_alt_en' => $this->stockImage('commerce', 'en')['image_alt'],
                'fr' => [
                    'subtitle' => '<p>Vendez produits et services, émettez les factures et encaissez dans un même parcours client connecté.</p>',
                    'overview_title' => 'Reliez catalogue, vente, facturation et paiement',
                    'overview_body' => '<p>Commerce unifie les produits, les commandes, les factures et le paiement pour que chaque transaction reste simple à piloter.</p>',
                    'overview_items' => [
                        'Catalogue produits et services centralisé',
                        'Boutique et parcours de commande cohérents',
                        'Facturation et suivi de paiement intégrés',
                        'Paiements en ligne et sur place dans le même système',
                    ],
                    'workflow_title' => 'Une chaîne complète du panier à l\'encaissement',
                    'workflow_body' => '<p>Les équipes publient l\'offre, vendent, facturent puis encaissent dans le même environnement, sans ressaisie entre les étapes.</p>',
                    'workflow_items' => [
                        '1. Configurer les produits, services et prix',
                        '2. Ouvrir la vente via la boutique ou l\'equipe interne',
                        '3. Générer la commande ou la facture',
                        '4. Suivre le paiement et la finalisation',
                    ],
                    'pages_title' => 'Pages et espaces inclus dans la suite',
                    'pages_body' => '<p>Le produit regroupe toute la chaîne de monétisation, du catalogue jusqu\'à l\'encaissement.</p>',
                    'pages_items' => [
                        'Produits',
                        'Services',
                        'Boutique',
                        'Commandes',
                        'Factures',
                        'Paiements',
                    ],
                ],
                'en' => [
                    'subtitle' => '<p>Sell products and services, issue invoices, and collect payments in one connected customer journey.</p>',
                    'overview_title' => 'Connect catalog, selling, invoicing, and payment',
                    'overview_body' => '<p>Commerce unifies products, orders, invoices, and payment collection so every transaction stays easy to manage.</p>',
                    'overview_items' => [
                        'Centralized product and service catalog',
                        'Consistent storefront and ordering flow',
                        'Integrated invoicing and payment follow-up',
                        'Online and in-person payments in one system',
                    ],
                    'workflow_title' => 'A full chain from cart to collection',
                    'workflow_body' => '<p>Teams publish the offer, sell, invoice, and collect in the same environment without re-entering data between steps.</p>',
                    'workflow_items' => [
                        '1. Configure products, services, and prices',
                        '2. Sell through the storefront or internal team',
                        '3. Generate the order or invoice',
                        '4. Track payment and fulfillment',
                    ],
                    'pages_title' => 'Pages and workspaces included',
                    'pages_body' => '<p>The suite covers the full revenue chain, from catalog to payment collection.</p>',
                    'pages_items' => [
                        'Products',
                        'Services',
                        'Storefront',
                        'Orders',
                        'Invoices',
                        'Payments',
                    ],
                ],
            ],
            'marketing-loyalty' => [
                'slug' => 'marketing-loyalty',
                'title' => 'Marketing & Loyalty',
                'pricing_href' => '/pricing#marketing',
                'image_url' => $this->stockImage('marketing-loyalty', 'fr')['image_url'],
                'image_alt_fr' => $this->stockImage('marketing-loyalty', 'fr')['image_alt'],
                'image_alt_en' => $this->stockImage('marketing-loyalty', 'en')['image_alt'],
                'fr' => [
                    'subtitle' => '<p>Lancez des campagnes, créez des segments plus intelligents, et ramenez les clients avec des parcours de fidélisation reliés à leur activité réelle plutôt qu\'à des envois génériques.</p>',
                    'overview_title' => 'Une base de rétention connectée à vos opérations',
                    'overview_body' => '<p>Marketing & Loyalty connecte campagnes, listes, segments et programmes VIP au même contexte client que les ventes et les opérations.</p>',
                    'overview_items' => [
                        'Campagnes email et SMS plus ciblées',
                        'Segmentation basée sur le comportement client',
                        'Programmes de fidélité et avantages VIP',
                        'Relances et parcours de rétention automatisées',
                    ],
                    'workflow_title' => 'Une exécution marketing branchée sur les bons signaux',
                    'workflow_body' => '<p>Les équipes sélectionnent l\'audience, définissent le message, lancent la campagne puis mesurent les réactions sans sortir du produit.</p>',
                    'workflow_items' => [
                        '1. Construire les segments selon la valeur ou l\'activité',
                        '2. Préparer la campagne ou le scénario de relance',
                        '3. Activer la diffusion sur les bons canaux',
                        '4. Suivre la réactivation et la fidélisation',
                    ],
                    'pages_title' => 'Pages et espaces inclus dans la suite',
                    'pages_body' => '<p>Le produit couvre l\'activation marketing, la base d\'audience et les programmes de rétention.</p>',
                    'pages_items' => [
                        'Campagnes',
                        'Mailing lists',
                        'Audience segments',
                        'Loyalty',
                        'VIP tiers',
                        'Prospect providers',
                    ],
                ],
                'en' => [
                    'subtitle' => '<p>Launch campaigns, build segments, and activate loyalty journeys tied to real customer activity.</p>',
                    'overview_title' => 'A retention engine connected to your operations',
                    'overview_body' => '<p>Marketing & Loyalty connects campaigns, lists, segments, and VIP programs to the same customer context used by sales and operations.</p>',
                    'overview_items' => [
                        'More targeted email and SMS campaigns',
                        'Segmentation based on customer behavior',
                        'Loyalty programs and VIP benefits',
                        'Automated follow-up and retention journeys',
                    ],
                    'workflow_title' => 'Marketing execution driven by the right signals',
                    'workflow_body' => '<p>Teams select the audience, define the message, launch the campaign, and measure response without leaving the product.</p>',
                    'workflow_items' => [
                        '1. Build segments from value or activity',
                        '2. Prepare the campaign or follow-up scenario',
                        '3. Activate delivery on the right channels',
                        '4. Track reactivation and loyalty impact',
                    ],
                    'pages_title' => 'Pages and workspaces included',
                    'pages_body' => '<p>The suite covers campaign activation, audience management, and retention programs.</p>',
                    'pages_items' => [
                        'Campaigns',
                        'Mailing lists',
                        'Audience segments',
                        'Loyalty',
                        'VIP tiers',
                        'Prospect providers',
                    ],
                ],
            ],
            'ai-automation' => [
                'slug' => 'ai-automation',
                'title' => 'AI & Automation',
                'pricing_href' => '/pricing#ai-automation',
                'image_url' => $this->stockImage('ai-automation', 'fr')['image_url'],
                'image_alt_fr' => $this->stockImage('ai-automation', 'fr')['image_alt'],
                'image_alt_en' => $this->stockImage('ai-automation', 'en')['image_alt'],
                'fr' => [
                    'subtitle' => '<p>Utilisez assistants, brouillons, résumés, et automatisations dans le travail que votre équipe fait déjà, avec une aide qui reste utile, contextualisée, et révisable.</p>',
                    'overview_title' => 'L\'IA intégrée à la plateforme, pas à côté',
                    'overview_body' => '<p>AI & Automation apporte les résumés, les suggestions et l\'assistant conversationnel dans les modules que les équipes utilisent déjà.</p>',
                    'overview_items' => [
                        'Assistant disponible dans les parcours métier',
                        'Brouillons intelligents pour messages et offres',
                        'Résumés rapides des historiques complexes',
                        'Suggestions d\'actions et automatisations utiles',
                    ],
                    'workflow_title' => 'Un usage concret pour gagner du temps chaque jour',
                    'workflow_body' => '<p>Les équipes interrogent l\'assistant, génèrent un contenu, résument un dossier ou déclenchent une action recommandée sans changer d\'outil.</p>',
                    'workflow_items' => [
                        '1. Ouvrir l\'assistant dans le bon contexte',
                        '2. Générer un brouillon ou un résumé utile',
                        '3. Valider ou ajuster la proposition',
                        '4. Transformer la suggestion en action réelle',
                    ],
                    'pages_title' => 'Pages et espaces inclus dans la suite',
                    'pages_body' => '<p>Le produit couvre l\'assistant, les contenus générés et les aides à la décision.</p>',
                    'pages_items' => [
                        'AI assistant',
                        'Smart drafts',
                        'Summaries',
                        'Suggested tasks',
                        'Plan scan',
                        'AI images',
                    ],
                ],
                'en' => [
                    'subtitle' => '<p>Use the assistant, smart drafts, and suggested actions directly inside existing workflows.</p>',
                    'overview_title' => 'AI built into the platform, not beside it',
                    'overview_body' => '<p>AI & Automation brings summaries, suggestions, and conversational assistance into the modules teams already use.</p>',
                    'overview_items' => [
                        'Assistant available inside business flows',
                        'Smart drafts for messages and offers',
                        'Fast summaries of long histories',
                        'Useful suggestions and workflow automation',
                    ],
                    'workflow_title' => 'A practical way to save time every day',
                    'workflow_body' => '<p>Teams open the assistant in context, generate content, summarize a record, or trigger a recommended action without changing tools.</p>',
                    'workflow_items' => [
                        '1. Open the assistant in the right context',
                        '2. Generate a useful draft or summary',
                        '3. Review and adjust the output',
                        '4. Turn the suggestion into real execution',
                    ],
                    'pages_title' => 'Pages and workspaces included',
                    'pages_body' => '<p>The suite covers the assistant, generated content, and decision support.</p>',
                    'pages_items' => [
                        'AI assistant',
                        'Smart drafts',
                        'Summaries',
                        'Suggested tasks',
                        'Plan scan',
                        'AI images',
                    ],
                ],
            ],
            'command-center' => [
                'slug' => 'command-center',
                'title' => 'Command Center',
                'pricing_href' => '/pricing#platform',
                'image_url' => $this->stockImage('command-center', 'fr')['image_url'],
                'image_alt_fr' => $this->stockImage('command-center', 'fr')['image_alt'],
                'image_alt_en' => $this->stockImage('command-center', 'en')['image_alt'],
                'fr' => [
                    'subtitle' => '<p>Obtenez une couche de pilotage partagée sur le revenu, les opérations, et l\'activité client pour voir les priorités plus vite et orienter l\'action avec plus de clarté.</p>',
                    'overview_title' => 'Un poste de pilotage pour la direction et les opérations',
                    'overview_body' => '<p>Command Center consolide les signaux clés de plusieurs modules pour donner une lecture claire de la performance globale.</p>',
                    'overview_items' => [
                        'Vue consolidée sur les indicateurs clés',
                        'Suivi croisé des ventes, opérations et marketing',
                        'Pilotage multi-entité ou multi-activité',
                        'Points d\'attention et priorités visibles rapidement',
                    ],
                    'workflow_title' => 'Un centre de décision partagé par les responsables',
                    'workflow_body' => '<p>Les responsables suivent les indicateurs, identifient les priorités puis basculent vers le bon module pour agir sans perdre le contexte.</p>',
                    'workflow_items' => [
                        '1. Surveiller les indicateurs globaux',
                        '2. Détecter les blocages ou opportunités',
                        '3. Explorer le détail par module ou équipe',
                        '4. Coordonner les prochaines actions',
                    ],
                    'pages_title' => 'Pages et espaces inclus dans la suite',
                    'pages_body' => '<p>Le produit couvre la gouvernance, la visibilité transverse et les vues de commandement.</p>',
                    'pages_items' => [
                        'Dashboard global',
                        'Vue revenu',
                        'Vue opérations',
                        'Vue équipe',
                        'Alertes',
                        'Rapports partagés',
                    ],
                ],
                'en' => [
                    'subtitle' => '<p>Get a shared cross-module view of revenue, operations, and customer activity with one command center.</p>',
                    'overview_title' => 'A control tower for leadership and operations',
                    'overview_body' => '<p>Command Center consolidates signals from multiple modules to give teams a clear view of overall business performance.</p>',
                    'overview_items' => [
                        'A consolidated view of key indicators',
                        'Cross-functional tracking for sales, ops, and marketing',
                        'Multi-entity or multi-activity leadership visibility',
                        'Priority signals and attention points surfaced quickly',
                    ],
                    'workflow_title' => 'A shared decision space for leadership teams',
                    'workflow_body' => '<p>Leaders monitor key indicators, identify priorities, and jump into the right module to act without losing context.</p>',
                    'workflow_items' => [
                        '1. Monitor high-level indicators',
                        '2. Detect blockers or opportunities',
                        '3. Drill into detail by module or team',
                        '4. Coordinate next actions',
                    ],
                    'pages_title' => 'Pages and workspaces included',
                    'pages_body' => '<p>The suite covers governance, cross-functional visibility, and command views.</p>',
                    'pages_items' => [
                        'Global dashboard',
                        'Revenue view',
                        'Operations view',
                        'Team view',
                        'Alerts',
                        'Shared reports',
                    ],
                ],
            ],
        ];
    }
}
