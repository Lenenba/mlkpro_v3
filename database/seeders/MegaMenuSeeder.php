<?php

namespace Database\Seeders;

use App\Models\MegaMenu;
use App\Models\PlatformPage;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\MegaMenus\MegaMenuManagerService;
use App\Services\PublicLeadFormUrlService;
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
        $industryPages = $this->syncIndustryPages($userId);
        $solutionPages = $this->syncSolutionPages($userId);
        $contactPage = $this->upsertPage(
            slug: 'contact-us',
            title: 'Contact us',
            content: $this->contactPageContent(),
            userId: $userId
        );
        $manager = app(MegaMenuManagerService::class);

        foreach ($this->menus($productPages, $industryPages, $solutionPages, $contactPage) as $payload) {
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
     * @param  array<string, PlatformPage>  $industryPages
     * @param  array<string, PlatformPage>  $solutionPages
     * @return array<int, array<string, mixed>>
     */
    private function menus(array $productPages, array $industryPages, array $solutionPages, PlatformPage $contactPage): array
    {
        return [
            $this->mainHeaderMenu($productPages, $industryPages, $solutionPages, $contactPage),
            $this->footerMenu(),
        ];
    }

    /**
     * @param  array<string, PlatformPage>  $productPages
     * @param  array<string, PlatformPage>  $industryPages
     * @param  array<string, PlatformPage>  $solutionPages
     * @return array<string, mixed>
     */
    private function mainHeaderMenu(array $productPages, array $industryPages, array $solutionPages, PlatformPage $contactPage): array
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
                $this->solutionsItem($solutionPages),
                $this->pricingItem(),
                $this->industriesItem($industryPages),
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
                    'label' => 'Legal',
                    'link_type' => 'none',
                    'link_target' => '_self',
                    'panel_type' => 'classic',
                    'is_visible' => true,
                    'children' => [
                        $this->classicLink('Terms', '/terms'),
                        $this->classicLink('Privacy', '/privacy'),
                        $this->classicLink('Refund', '/refund'),
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
                'workflow_kicker' => 'Comment ca fonctionne',
                'pages_kicker' => 'Pages incluses',
                'primary_label' => 'Voir les tarifs',
                'secondary_label' => 'Voir la demo',
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
                'workflow_kicker' => 'Mode operatoire',
                'modules_kicker' => 'Modules et pages',
                'primary_label' => 'Voir les tarifs',
                'secondary_label' => 'Contact us',
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
                    'page_subtitle' => '<p>Explorez l\'ecosysteme de partenaires et d\'integrations autour de la plateforme.</p>',
                    'header' => $this->pageHeader('partners', 'fr'),
                    'sections' => [
                        $this->pageSection(
                            id: 'partners-overview',
                            kicker: 'Partenariats',
                            title: 'Un ecosysteme pour deployer plus vite',
                            body: '<p>Retrouvez les partenaires technologiques, operationnels et commerciaux qui gravitent autour de la plateforme.</p>',
                            items: [
                                'Partenaires de mise en oeuvre',
                                'Integrations metier et passerelles de paiement',
                                'Partenaires de croissance et d\'acquisition',
                            ],
                            imageUrl: $partnersFr['image_url'],
                            imageAlt: $partnersFr['image_alt'],
                            primaryLabel: 'Voir les tarifs',
                            primaryHref: '/pricing',
                            secondaryLabel: 'Voir la demo',
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
        $industryFrOverview = $this->stockImage($industry['slug'], 'fr', 'overview');
        $industryFrWorkflow = $this->stockImage($industry['slug'], 'fr', 'workflow');
        $industryEnOverview = $this->stockImage($industry['slug'], 'en', 'overview');
        $industryEnWorkflow = $this->stockImage($industry['slug'], 'en', 'workflow');

        return [
            'locales' => [
                'fr' => [
                    'page_title' => $industry['title'],
                    'page_subtitle' => $industry['fr']['subtitle'],
                    'header' => $this->pageHeader($industry['slug'], 'fr'),
                    'sections' => [
                        $this->pageSection(
                            id: 'industry-overview',
                            kicker: 'Industrie',
                            title: $industry['fr']['overview_title'],
                            body: $industry['fr']['overview_body'],
                            items: $industry['fr']['overview_items'],
                            imageUrl: $industryFrOverview['image_url'],
                            imageAlt: $industryFrOverview['image_alt'],
                            primaryLabel: 'Voir les produits',
                            primaryHref: '/pricing',
                            secondaryLabel: 'Contact us',
                            secondaryHref: '/pages/contact-us',
                            backgroundColor: ''
                        ),
                        $this->pageSection(
                            id: 'industry-workflow',
                            kicker: 'Parcours type',
                            title: $industry['fr']['workflow_title'],
                            body: $industry['fr']['workflow_body'],
                            items: $industry['fr']['workflow_items'],
                            imageUrl: $industryFrWorkflow['image_url'],
                            imageAlt: $industryFrWorkflow['image_alt'],
                            primaryLabel: '',
                            primaryHref: '',
                            secondaryLabel: '',
                            secondaryHref: '',
                            backgroundColor: '#f8fafc'
                        ),
                    ],
                ],
                'en' => [
                    'page_title' => $industry['title'],
                    'page_subtitle' => $industry['en']['subtitle'],
                    'header' => $this->pageHeader($industry['slug'], 'en'),
                    'sections' => [
                        $this->pageSection(
                            id: 'industry-overview',
                            kicker: 'Industry',
                            title: $industry['en']['overview_title'],
                            body: $industry['en']['overview_body'],
                            items: $industry['en']['overview_items'],
                            imageUrl: $industryEnOverview['image_url'],
                            imageAlt: $industryEnOverview['image_alt'],
                            primaryLabel: 'View products',
                            primaryHref: '/pricing',
                            secondaryLabel: 'Contact us',
                            secondaryHref: '/pages/contact-us',
                            backgroundColor: ''
                        ),
                        $this->pageSection(
                            id: 'industry-workflow',
                            kicker: 'Typical workflow',
                            title: $industry['en']['workflow_title'],
                            body: $industry['en']['workflow_body'],
                            items: $industry['en']['workflow_items'],
                            imageUrl: $industryEnWorkflow['image_url'],
                            imageAlt: $industryEnWorkflow['image_alt'],
                            primaryLabel: '',
                            primaryHref: '',
                            secondaryLabel: '',
                            secondaryHref: '',
                            backgroundColor: '#f8fafc'
                        ),
                    ],
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
                    'page_title' => 'Contact us',
                    'page_subtitle' => '<p>Parlez-nous de votre contexte, de votre equipe et de votre mode operatoire. Le formulaire integre reste modifiable depuis l\'admin Pages.</p>',
                    'header' => $this->pageHeader('contact-us', 'fr'),
                    'sections' => [
                        $this->pageSection(
                            id: 'contact-overview',
                            kicker: 'Contact',
                            title: 'Expliquez-nous votre besoin',
                            body: '<p>Utilisez cette page comme point d\'entree public pour vos demandes commerciales. Depuis l\'admin Pages, vous pouvez remplacer ou reconfigurer le formulaire embarque a tout moment.</p>',
                            items: [
                                'Formulaire integre directement dans la page',
                                'URL du formulaire editable dans l\'admin Pages',
                                'Possibilite d\'adapter le message par langue',
                                'Page publique coherente avec le reste du site',
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
                            kicker: 'Support',
                            title: 'Discutez avec nous',
                            body: '<p>Ajoutez ici un point de contact rapide pour les prospects qui veulent parler a une personne avant de remplir un formulaire complet.</p>',
                            items: [
                                'Accompagnement avant vente',
                                'Reponse sous un jour ouvrable',
                                'Bloc complet editable depuis l\'admin Pages',
                            ],
                            imageUrl: $contactFrDetail['image_url'],
                            imageAlt: $contactFrDetail['image_alt'],
                            primaryLabel: $hasEmbeddedForm ? 'Ouvrir le formulaire' : '',
                            primaryHref: $formUrl,
                            secondaryLabel: '',
                            secondaryHref: '',
                            backgroundColor: '#ffffff',
                            extra: [
                                'layout' => 'contact',
                                'aside_kicker' => 'Bureau',
                                'aside_title' => 'Equipe commerciale',
                                'aside_body' => '<p>Support commercial et accompagnement au deploiement.<br>Remplacez cette colonne par vos vraies coordonnees depuis l\'admin Pages.</p><p><strong>Disponibilite</strong><br>Lun - ven · 8h00 - 18h00 EST</p>',
                                'aside_items' => [
                                    'Canada et Etats-Unis',
                                    'Reponse prioritaire pour les demandes qualifiees',
                                ],
                                'aside_link_label' => 'Nous contacter',
                                'aside_link_href' => $formUrl,
                            ]
                        ),
                    ],
                ],
                'en' => [
                    'page_title' => 'Contact us',
                    'page_subtitle' => '<p>Tell us about your team, your workflow, and your business model. The embedded form stays editable from the Pages admin.</p>',
                    'header' => $this->pageHeader('contact-us', 'en'),
                    'sections' => [
                        $this->pageSection(
                            id: 'contact-overview',
                            kicker: 'Contact',
                            title: 'Tell us what you need',
                            body: '<p>Use this page as the public entry point for commercial inquiries. From the Pages admin, you can replace or reconfigure the embedded form at any time.</p>',
                            items: [
                                'Form embedded directly on the page',
                                'Editable form URL from the Pages admin',
                                'Localized messaging per language',
                                'A public page aligned with the rest of the site',
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
                            kicker: 'Support',
                            title: 'Chat with us',
                            body: '<p>Add a fast contact point here for prospects who want to speak with someone before submitting the full form.</p>',
                            items: [
                                'Pre-sales guidance',
                                'Reply within one business day',
                                'Fully editable block from the Pages admin',
                            ],
                            imageUrl: $contactEnDetail['image_url'],
                            imageAlt: $contactEnDetail['image_alt'],
                            primaryLabel: $hasEmbeddedForm ? 'Open the form' : '',
                            primaryHref: $formUrl,
                            secondaryLabel: '',
                            secondaryHref: '',
                            backgroundColor: '#ffffff',
                            extra: [
                                'layout' => 'contact',
                                'aside_kicker' => 'Office',
                                'aside_title' => 'Sales desk',
                                'aside_body' => '<p>Sales support and rollout guidance.<br>Replace this column with your real office details from the Pages admin.</p><p><strong>Availability</strong><br>Mon - Fri · 8:00 AM - 6:00 PM EST</p>',
                                'aside_items' => [
                                    'Canada and United States',
                                    'Priority follow-up for qualified requests',
                                ],
                                'aside_link_label' => 'Contact our team',
                                'aside_link_href' => $formUrl,
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
            'description' => 'Explorez tout le catalogue plateforme depuis un point d\'entree unique.',
            'link_type' => 'none',
            'link_target' => '_self',
            'panel_type' => 'mega',
            'icon' => 'grid-2x2',
            'badge_text' => '',
            'badge_variant' => null,
            'is_visible' => true,
            'settings' => [
                'eyebrow' => 'Modules',
                'note' => 'Explorez tout le catalogue plateforme depuis un point d\'entree unique.',
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
                        'Survolez un produit pour voir l\'interface et cliquez pour ouvrir sa page detaillee.',
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
    private function solutionsItem(array $solutionPages): array
    {
        return [
            'label' => 'Solutions',
            'description' => 'Choisissez un parcours selon le mode operatoire de votre equipe.',
            'link_type' => 'none',
            'link_target' => '_self',
            'panel_type' => 'mega',
            'icon' => 'blocks',
            'is_visible' => true,
            'settings' => [
                'eyebrow' => 'Parcours',
                'note' => 'Choisissez un parcours selon le mode operatoire de votre equipe.',
                'highlight_color' => '#0f766e',
                'translations' => [
                    'en' => [
                        'label' => 'Solutions',
                        'description' => 'Choose a path based on how your team operates.',
                        'eyebrow' => 'Journeys',
                        'note' => 'Choose a path based on how your team operates.',
                    ],
                ],
            ],
            'columns' => [
                $this->column('', '1fr', [
                    $this->productShowcaseBlock(
                        'Solutions',
                        'Solutions',
                        'Survolez une solution pour voir son angle metier puis ouvrez la page correspondante.',
                        'Hover a solution to preview its business angle and open the matching page.',
                        [
                            $this->showcaseProduct(
                                'Services terrain',
                                $this->pagePath($solutionPages['field-services']),
                                'Planification, interventions, preuves et suivi terrain.',
                                'Structure the full field-service journey from planning to proof of work and operational follow-up.',
                                $this->stockImage('solution-field-services', 'en')['image_url'],
                                $this->stockImage('solution-field-services', 'en')['image_alt'],
                                'Field services',
                                'Core'
                            ),
                            $this->showcaseProduct(
                                'Reservations & files',
                                $this->pagePath($solutionPages['reservations-queues']),
                                'Prise de rendez-vous, disponibilite, kiosque et check-in.',
                                'Show how booking, availability, kiosk flows, and queue handling fit together in one customer journey.',
                                $this->stockImage('solution-reservations-queues', 'en')['image_url'],
                                $this->stockImage('solution-reservations-queues', 'en')['image_alt'],
                                'Reservations & queues'
                            ),
                            $this->showcaseProduct(
                                'Vente & devis',
                                $this->pagePath($solutionPages['sales-quoting']),
                                'Demandes, devis, clients et pipeline commercial.',
                                'Connect inbound demand, qualification, quotes, and conversion into one sales motion.',
                                $this->stockImage('solution-sales-quoting', 'en')['image_url'],
                                $this->stockImage('solution-sales-quoting', 'en')['image_alt'],
                                'Sales & quoting',
                                'Popular'
                            ),
                            $this->showcaseProduct(
                                'Commerce & catalogue',
                                $this->pagePath($solutionPages['commerce-catalog']),
                                'Produits, services, boutique et commandes.',
                                'Tie catalog, storefront, ordering, invoicing, and payment collection into one revenue chain.',
                                $this->stockImage('solution-commerce-catalog', 'en')['image_url'],
                                $this->stockImage('solution-commerce-catalog', 'en')['image_alt'],
                                'Commerce & catalog'
                            ),
                            $this->showcaseProduct(
                                'Marketing & fidelisation',
                                $this->pagePath($solutionPages['marketing-loyalty']),
                                'Campagnes, segments, VIP et automatisations.',
                                'Build retention, reactivation, and loyalty journeys from live customer context instead of disconnected campaigns.',
                                $this->stockImage('solution-marketing-loyalty', 'en')['image_url'],
                                $this->stockImage('solution-marketing-loyalty', 'en')['image_alt'],
                                'Marketing & loyalty',
                                'Growth'
                            ),
                            $this->showcaseProduct(
                                'Pilotage multi-entreprise',
                                $this->pagePath($solutionPages['multi-entity-oversight']),
                                'Vue globale sur plusieurs entites et modules.',
                                'Give leadership one place to compare entities, priorities, and cross-functional signals.',
                                $this->stockImage('solution-multi-entity-oversight', 'en')['image_url'],
                                $this->stockImage('solution-multi-entity-oversight', 'en')['image_alt'],
                                'Multi-entity oversight'
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
    private function industriesItem(array $industryPages): array
    {
        return [
            'label' => 'Industries',
            'description' => 'Decouvrez des parcours adaptes a chaque metier.',
            'link_type' => 'none',
            'link_target' => '_self',
            'panel_type' => 'mega',
            'icon' => 'briefcase-business',
            'is_visible' => true,
            'settings' => [
                'eyebrow' => 'Metiers',
                'note' => 'Decouvrez des parcours adaptes a chaque metier.',
                'highlight_color' => '#0f766e',
                'translations' => [
                    'en' => [
                        'label' => 'Industries',
                        'description' => 'Explore workflows tailored to each trade.',
                        'eyebrow' => 'Industries',
                        'note' => 'Explore workflows tailored to each trade.',
                    ],
                ],
            ],
            'columns' => [
                $this->column('', '1fr', [
                    $this->productShowcaseBlock(
                        'Industries',
                        'Industries',
                        'Survolez un metier pour voir le parcours type avant d ouvrir sa page dediee.',
                        'Hover an industry to preview the typical workflow before opening its dedicated page.',
                        [
                            $this->showcaseProduct(
                                'Plomberie',
                                $this->pagePath($industryPages['plumbing']),
                                'De la demande au paiement pour les equipes de plomberie.',
                                'Show how plumbing teams can move from request intake to field work and payment with one connected operating system.',
                                $this->stockImage('industry-plumbing', 'en')['image_url'],
                                $this->stockImage('industry-plumbing', 'en')['image_alt'],
                                'Plumbing',
                                'Trades'
                            ),
                            $this->showcaseProduct(
                                'HVAC / Climatisation',
                                $this->pagePath($industryPages['hvac']),
                                'Planning, jobs terrain et facturation pour les equipes HVAC.',
                                'Give HVAC teams one clearer flow for urgent calls, scheduled maintenance, field execution, and billing.',
                                $this->stockImage('industry-hvac', 'en')['image_url'],
                                $this->stockImage('industry-hvac', 'en')['image_alt'],
                                'HVAC / Cooling'
                            ),
                            $this->showcaseProduct(
                                'Electricite',
                                $this->pagePath($industryPages['electrical']),
                                'Interventions, devis et preuve de travail pour les electriciens.',
                                'Frame quoting, field interventions, and proof of work around the real day-to-day needs of electrical teams.',
                                $this->stockImage('industry-electrical', 'en')['image_url'],
                                $this->stockImage('industry-electrical', 'en')['image_alt'],
                                'Electrical'
                            ),
                            $this->showcaseProduct(
                                'Nettoyage',
                                $this->pagePath($industryPages['cleaning']),
                                'Tournees recurrents, equipe et satisfaction client.',
                                'Organize recurring routes, team coordination, and quality follow-up in a workflow built for cleaning businesses.',
                                $this->stockImage('industry-cleaning', 'en')['image_url'],
                                $this->stockImage('industry-cleaning', 'en')['image_alt'],
                                'Cleaning'
                            ),
                            $this->showcaseProduct(
                                'Salon & beaute',
                                $this->pagePath($industryPages['salon-beauty']),
                                'Reservations, no-show fees, fidelite et experience VIP.',
                                'Connect appointments, no-show protection, loyalty, and premium customer experience for salon teams.',
                                $this->stockImage('industry-salon-beauty', 'en')['image_url'],
                                $this->stockImage('industry-salon-beauty', 'en')['image_alt'],
                                'Salon & beauty',
                                'Hospitality'
                            ),
                            $this->showcaseProduct(
                                'Restaurant',
                                $this->pagePath($industryPages['restaurant']),
                                'Reservations, file d\'attente, check-in et experience salle.',
                                'Clarify how reservations, waitlist flow, check-in, and front-of-house coordination fit together for restaurants.',
                                $this->stockImage('industry-restaurant', 'en')['image_url'],
                                $this->stockImage('industry-restaurant', 'en')['image_alt'],
                                'Restaurant'
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
    private function contactItem(PlatformPage $contactPage): array
    {
        return [
            'label' => 'Contact us',
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
    ): array
    {
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
                    'subtitle' => '<p>Gerez demandes, devis, interventions et paiements dans un flux adapte aux equipes de plomberie.</p>',
                    'overview_title' => 'Un systeme simple pour la plomberie residentielle et commerciale',
                    'overview_body' => '<p>MLKIA aide les equipes de plomberie a capter la demande, planifier les interventions et encaisser plus vite sans fragmenter les outils.</p>',
                    'overview_items' => [
                        'Demandes entrantes et devis rapides',
                        'Planning terrain et jobs assignes',
                        'Photos, notes et preuve de travail',
                        'Facturation et suivi client centralises',
                    ],
                    'workflow_title' => 'Le parcours type pour une equipe plomberie',
                    'workflow_body' => '<p>Le lead arrive, le devis est valide, l\'intervention est planifiee puis l\'equipe terrain cloture avec toutes les informations utiles.</p>',
                    'workflow_items' => [
                        '1. Recevoir et qualifier la demande',
                        '2. Preparer et envoyer le devis',
                        '3. Planifier l\'intervention',
                        '4. Facturer et relancer le paiement',
                    ],
                ],
                'en' => [
                    'subtitle' => '<p>Manage requests, quotes, jobs, and payment in a workflow built for plumbing teams.</p>',
                    'overview_title' => 'A simple system for residential and commercial plumbing',
                    'overview_body' => '<p>MLKIA helps plumbing teams capture demand, schedule work, and collect payment faster without fragmented tools.</p>',
                    'overview_items' => [
                        'Inbound requests and fast quoting',
                        'Field planning and assigned jobs',
                        'Photos, notes, and proof of work',
                        'Centralized invoicing and customer follow-up',
                    ],
                    'workflow_title' => 'A typical workflow for plumbing teams',
                    'workflow_body' => '<p>The lead comes in, the quote is approved, the job is scheduled, and the field team closes the work with full context.</p>',
                    'workflow_items' => [
                        '1. Receive and qualify the request',
                        '2. Prepare and send the quote',
                        '3. Schedule the intervention',
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
                    'subtitle' => '<p>Coordonnez demandes, maintenance, interventions et facturation pour les equipes HVAC.</p>',
                    'overview_title' => 'Un flux operationnel pour les equipes climatisation et chauffage',
                    'overview_body' => '<p>Entre les demandes urgentes, les maintenances planifiees et les suivis client, les equipes HVAC ont besoin d\'un cockpit tres lisible.</p>',
                    'overview_items' => [
                        'Demandes de service et maintenance recurrente',
                        'Planification des techniciens et des plages',
                        'Suivi des jobs et compte rendu de visite',
                        'Facturation et paiements au meme endroit',
                    ],
                    'workflow_title' => 'Le parcours type pour HVAC',
                    'workflow_body' => '<p>Les gestionnaires planifient les visites, les techniciens executent avec tout le contexte client, puis la facturation suit sans friction.</p>',
                    'workflow_items' => [
                        '1. Organiser les demandes et contrats',
                        '2. Assigner les techniciens',
                        '3. Documenter la visite et le travail realise',
                        '4. Generer la facture et assurer le suivi',
                    ],
                ],
                'en' => [
                    'subtitle' => '<p>Coordinate requests, maintenance, field work, and billing for HVAC teams.</p>',
                    'overview_title' => 'An operating flow for heating and cooling teams',
                    'overview_body' => '<p>Between urgent calls, scheduled maintenance, and customer follow-up, HVAC teams need a highly visible operating cockpit.</p>',
                    'overview_items' => [
                        'Service requests and recurring maintenance',
                        'Technician scheduling and time windows',
                        'Job tracking and visit reports',
                        'Billing and payments in one place',
                    ],
                    'workflow_title' => 'A typical workflow for HVAC',
                    'workflow_body' => '<p>Managers schedule visits, technicians work with full customer context, and billing follows without friction.</p>',
                    'workflow_items' => [
                        '1. Organize requests and service contracts',
                        '2. Assign technicians',
                        '3. Document the visit and work completed',
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
                    'subtitle' => '<p>Suivez devis, interventions et execution terrain avec une vue claire sur chaque dossier electrique.</p>',
                    'overview_title' => 'Une meilleure coordination pour les equipes electriques',
                    'overview_body' => '<p>MLKIA donne une vue commune au bureau et au terrain pour piloter les demandes, les chantiers et la cloture des travaux.</p>',
                    'overview_items' => [
                        'Demandes et qualification commerciale',
                        'Devis et planification de l\'execution',
                        'Suivi chantier ou intervention terrain',
                        'Relance client et encaissement',
                    ],
                    'workflow_title' => 'Le parcours type pour electricite',
                    'workflow_body' => '<p>Les equipes commerciales et operationnelles partagent le meme flux, du premier besoin jusqu\'a la facture finale.</p>',
                    'workflow_items' => [
                        '1. Qualifier la demande electrique',
                        '2. Construire le devis et valider le scope',
                        '3. Planifier les techniciens',
                        '4. Cloturer et facturer',
                    ],
                ],
                'en' => [
                    'subtitle' => '<p>Track quotes, field jobs, and execution with a clear view of every electrical job.</p>',
                    'overview_title' => 'Better coordination for electrical teams',
                    'overview_body' => '<p>MLKIA gives office and field teams a shared view to manage requests, projects, and final closeout.</p>',
                    'overview_items' => [
                        'Commercial request intake and qualification',
                        'Quoting and execution planning',
                        'Project or field intervention tracking',
                        'Customer follow-up and collection',
                    ],
                    'workflow_title' => 'A typical workflow for electrical teams',
                    'workflow_body' => '<p>Commercial and operational teams share the same flow from first request to the final invoice.</p>',
                    'workflow_items' => [
                        '1. Qualify the electrical request',
                        '2. Build the quote and confirm scope',
                        '3. Schedule technicians',
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
                    'subtitle' => '<p>Organisez les equipes, les jobs recurrents et la relation client pour les entreprises de nettoyage.</p>',
                    'overview_title' => 'Un meilleur pilotage des operations recurrentes',
                    'overview_body' => '<p>Les entreprises de nettoyage ont besoin d\'un outil fiable pour planifier, suivre la presence et maintenir la qualite percue.</p>',
                    'overview_items' => [
                        'Taches et jobs recurrents par site',
                        'Equipe, presence et suivi journalier',
                        'Notes terrain et preuve de passage',
                        'Fidelisation et relances clients',
                    ],
                    'workflow_title' => 'Le parcours type pour le nettoyage',
                    'workflow_body' => '<p>Le travail est planifie par site, assigne par equipe puis controle avec un suivi operationnel et commercial continu.</p>',
                    'workflow_items' => [
                        '1. Structurer les sites et la recurrence',
                        '2. Planifier les equipes et la presence',
                        '3. Suivre la qualite et les incidents',
                        '4. Facturer et conserver les clients',
                    ],
                ],
                'en' => [
                    'subtitle' => '<p>Organize teams, recurring jobs, and customer follow-up for cleaning businesses.</p>',
                    'overview_title' => 'Better control over recurring operations',
                    'overview_body' => '<p>Cleaning businesses need a reliable system to schedule work, track attendance, and maintain service quality.</p>',
                    'overview_items' => [
                        'Recurring tasks and jobs by site',
                        'Team, attendance, and daily follow-up',
                        'Field notes and proof of visit',
                        'Retention and customer follow-up',
                    ],
                    'workflow_title' => 'A typical workflow for cleaning teams',
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
                    'subtitle' => '<p>Reservations, rappels, no-show fees et fidelisation dans une experience pensee pour les salons.</p>',
                    'overview_title' => 'Une experience fluide pour les businesses a rendez-vous',
                    'overview_body' => '<p>Pour les salons et activites beaute, la reservation et la relation client sont au coeur du revenu. Le produit doit etre rapide, clair et mobile.</p>',
                    'overview_items' => [
                        'Reservation en ligne et disponibilite temps reel',
                        'Rappels, annulations et no-show fees',
                        'Suivi client, fidelite et VIP',
                        'Accueil, check-in et file d\'attente si besoin',
                    ],
                    'workflow_title' => 'Le parcours type pour salon & beaute',
                    'workflow_body' => '<p>Le client reserve, l\'equipe prepare la journee, l\'accueil reste fluide puis la fidelisation prend le relais.</p>',
                    'workflow_items' => [
                        '1. Ouvrir les plages de reservation',
                        '2. Confirmer et rappeler les rendez-vous',
                        '3. Gerer l\'arrivee et le service',
                        '4. Relancer et fideliser apres la visite',
                    ],
                ],
                'en' => [
                    'subtitle' => '<p>Bookings, reminders, no-show fees, and loyalty in an experience designed for salons.</p>',
                    'overview_title' => 'A smoother experience for appointment-led businesses',
                    'overview_body' => '<p>For salons and beauty businesses, booking and customer follow-up sit at the center of revenue. The product has to be fast, clear, and mobile-friendly.</p>',
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
                    'subtitle' => '<p>Reservations, file d\'attente, check-in et experience salle dans un parcours plus fluide.</p>',
                    'overview_title' => 'Une meilleure orchestration pour les restaurants',
                    'overview_body' => '<p>Le revenu ne depend pas seulement des tables reservees, mais aussi de la fluidite en salle, de l\'attente et des confirmations client.</p>',
                    'overview_items' => [
                        'Reservations et disponibilite en temps reel',
                        'Gestion de la file et check-in client',
                        'Depots et regles d\'annulation',
                        'Communication avant et apres la visite',
                    ],
                    'workflow_title' => 'Le parcours type pour restaurant',
                    'workflow_body' => '<p>Les clients reservent, l\'equipe confirme, la salle gere l\'arrivee puis les relances permettent de faire revenir les meilleurs clients.</p>',
                    'workflow_items' => [
                        '1. Ouvrir les disponibilites',
                        '2. Confirmer les reservations et depots',
                        '3. Gerer l\'attente et l\'arrivee en salle',
                        '4. Relancer apres la visite',
                    ],
                ],
                'en' => [
                    'subtitle' => '<p>Bookings, queue flow, check-in, and front-of-house experience in one smoother journey.</p>',
                    'overview_title' => 'Better orchestration for restaurants',
                    'overview_body' => '<p>Revenue depends not only on booked tables, but also on front-of-house flow, waiting time, and confirmation handling.</p>',
                    'overview_items' => [
                        'Bookings and live availability',
                        'Queue handling and customer check-in',
                        'Deposits and cancellation rules',
                        'Communication before and after the visit',
                    ],
                    'workflow_title' => 'A typical workflow for restaurants',
                    'workflow_body' => '<p>Guests book, the team confirms, the dining room manages arrivals, and follow-up helps bring the best customers back.</p>',
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
                    'subtitle' => '<p>Coordonnez la planification, l\'execution et la preuve de travail dans une seule solution pour les equipes terrain.</p>',
                    'overview_title' => 'Une solution complete pour les operations terrain',
                    'overview_body' => '<p>Cette solution rassemble la planification, les interventions, les taches et le suivi quotidien pour donner a chaque equipe le bon contexte au bon moment.</p>',
                    'overview_items' => [
                        'Planning equipe et dispatch centralises',
                        'Jobs terrain avec preuves, notes et statuts',
                        'Taches internes visibles par role ou equipe',
                        'Suivi operationnel sans perte de contexte',
                    ],
                    'workflow_title' => 'Le parcours type de l\'intervention a la cloture',
                    'workflow_body' => '<p>Les responsables planifient, assignent, suivent l\'execution puis valident la fin des travaux avec tout le contexte utile a disposition.</p>',
                    'workflow_items' => [
                        '1. Planifier les ressources et les plages de travail',
                        '2. Assigner les jobs et interventions',
                        '3. Suivre l\'avancement sur le terrain',
                        '4. Cloturer avec preuve de travail et validation',
                    ],
                    'modules_title' => 'Modules et pages cles de la solution',
                    'modules_body' => '<p>La solution active les espaces essentiels pour piloter une equipe terrain du debut a la fin.</p>',
                    'modules_items' => [
                        'Planning',
                        'Jobs',
                        'Taches',
                        'Presence',
                        'Equipe',
                        'Preuves de travail',
                    ],
                ],
                'en' => [
                    'title' => 'Field services',
                    'subtitle' => '<p>Coordinate scheduling, execution, and proof of work in one operating solution for field teams.</p>',
                    'overview_title' => 'A complete solution for field operations',
                    'overview_body' => '<p>This solution brings scheduling, jobs, tasks, and daily follow-up together so each team works with the right context at the right time.</p>',
                    'overview_items' => [
                        'Centralized team scheduling and dispatch',
                        'Field jobs with proof, notes, and statuses',
                        'Internal tasks visible by role or team',
                        'Operational follow-up without context loss',
                    ],
                    'workflow_title' => 'The standard flow from dispatch to completion',
                    'workflow_body' => '<p>Managers plan, assign, monitor execution, and validate completed work with all supporting context in one place.</p>',
                    'workflow_items' => [
                        '1. Plan resources and work windows',
                        '2. Assign jobs and field visits',
                        '3. Track execution on the ground',
                        '4. Close the work with proof and validation',
                    ],
                    'modules_title' => 'Key modules and pages in the solution',
                    'modules_body' => '<p>The solution activates the core spaces required to run a field team end to end.</p>',
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
                'title' => 'Reservations & files',
                'image_url' => $this->stockImage('solution-reservations-queues', 'fr')['image_url'],
                'image_alt_fr' => $this->stockImage('solution-reservations-queues', 'fr')['image_alt'],
                'image_alt_en' => $this->stockImage('solution-reservations-queues', 'en')['image_alt'],
                'fr' => [
                    'subtitle' => '<p>Transformez la reservation, l\'accueil et la file d\'attente en une experience fluide et visible en temps reel.</p>',
                    'overview_title' => 'Une solution pour gerer le flux client avant et pendant la visite',
                    'overview_body' => '<p>Reservations & files aligne disponibilites, confirmations, check-in et file d\'attente dans un seul parcours client.</p>',
                    'overview_items' => [
                        'Reservations en ligne avec disponibilite en direct',
                        'Rappels et confirmations automatises',
                        'Check-in sur site et kiosques en libre-service',
                        'Vue temps reel sur la file et les arrivees',
                    ],
                    'workflow_title' => 'Du rendez-vous a l\'arrivee sans rupture',
                    'workflow_body' => '<p>Le client reserve, l\'equipe confirme, la visite est geree sur place et les changements restent visibles pour tous les intervenants.</p>',
                    'workflow_items' => [
                        '1. Publier les plages et les ressources',
                        '2. Accepter les reservations en self-service',
                        '3. Gerer confirmations, retards et annulations',
                        '4. Suivre la file et le check-in en direct',
                    ],
                    'modules_title' => 'Modules et pages cles de la solution',
                    'modules_body' => '<p>La solution couvre la reservation avant visite et la gestion d\'accueil sur place.</p>',
                    'modules_items' => [
                        'Agenda',
                        'Disponibilites',
                        'Reservations client',
                        'Kiosque client',
                        'Kiosque public',
                        'File d\'attente',
                    ],
                ],
                'en' => [
                    'title' => 'Reservations & queues',
                    'subtitle' => '<p>Turn booking, reception, and queue handling into one smooth experience with live visibility.</p>',
                    'overview_title' => 'A solution to manage customer flow before and during the visit',
                    'overview_body' => '<p>Reservations & queues aligns availability, confirmations, check-in, and waitlist handling into one customer journey.</p>',
                    'overview_items' => [
                        'Online booking with live availability',
                        'Automated reminders and confirmations',
                        'On-site check-in and self-service kiosks',
                        'Real-time view of queues and arrivals',
                    ],
                    'workflow_title' => 'From booking to arrival without friction',
                    'workflow_body' => '<p>The customer books, the team confirms, the visit is handled on site, and every change stays visible to the whole team.</p>',
                    'workflow_items' => [
                        '1. Publish slots and resources',
                        '2. Accept self-service bookings',
                        '3. Handle confirmations, delays, and cancellations',
                        '4. Track queue and check-in activity live',
                    ],
                    'modules_title' => 'Key modules and pages in the solution',
                    'modules_body' => '<p>The solution covers pre-visit booking and on-site reception handling.</p>',
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
                    'subtitle' => '<p>Captez les demandes, preparez les devis et suivez les opportunites dans un seul flux commercial.</p>',
                    'overview_title' => 'Une solution pour structurer la conversion commerciale',
                    'overview_body' => '<p>Vente & devis relie la capture de demande, la qualification, la preparation du devis et le suivi des opportunites jusqu\'a la signature.</p>',
                    'overview_items' => [
                        'Demandes centralisees depuis le web ou l\'equipe',
                        'Fiches clients partagees et historique visible',
                        'Devis avec statut, relance et approbation',
                        'Pipeline commercial pour prioriser les opportunites',
                    ],
                    'workflow_title' => 'Du lead a la vente dans un seul parcours',
                    'workflow_body' => '<p>L\'equipe capte la demande, qualifie le besoin, envoie le devis puis suit la decision sans sortir du meme espace de travail.</p>',
                    'workflow_items' => [
                        '1. Capturer le lead entrant',
                        '2. Qualifier et enrichir le dossier client',
                        '3. Preparer puis envoyer le devis',
                        '4. Relancer et convertir en vente ou intervention',
                    ],
                    'modules_title' => 'Modules et pages cles de la solution',
                    'modules_body' => '<p>La solution couvre les espaces commerciaux qui servent a convertir plus vite et mieux suivre.</p>',
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
                    'subtitle' => '<p>Capture inbound demand, prepare quotes, and track opportunities inside one revenue workflow.</p>',
                    'overview_title' => 'A solution to structure commercial conversion',
                    'overview_body' => '<p>Sales & quoting connects demand capture, qualification, quote preparation, and opportunity follow-up through to signature.</p>',
                    'overview_items' => [
                        'Requests centralized from the web or team intake',
                        'Shared customer records and visible history',
                        'Quotes with status, follow-up, and approval',
                        'A sales pipeline to prioritize opportunities',
                    ],
                    'workflow_title' => 'From lead to revenue in one path',
                    'workflow_body' => '<p>The team captures demand, qualifies the request, sends the quote, and tracks the decision without leaving the same workspace.</p>',
                    'workflow_items' => [
                        '1. Capture inbound leads',
                        '2. Qualify and enrich the customer record',
                        '3. Prepare and send the quote',
                        '4. Follow up and convert into work or revenue',
                    ],
                    'modules_title' => 'Key modules and pages in the solution',
                    'modules_body' => '<p>The solution covers the commercial spaces used to convert faster and keep opportunity follow-up visible.</p>',
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
                    'subtitle' => '<p>Publiez votre offre, vendez, facturez et encaissez dans un meme environnement commercial.</p>',
                    'overview_title' => 'Une solution pour monetiser sans fragmenter le parcours',
                    'overview_body' => '<p>Commerce & catalogue relie le catalogue, la boutique, les commandes, les factures et les paiements dans un seul parcours de monetisation.</p>',
                    'overview_items' => [
                        'Catalogue produits et services unifie',
                        'Boutique et parcours de commande coherents',
                        'Facturation et suivi de paiement integres',
                        'Paiements relies aux ventes et aux commandes',
                    ],
                    'workflow_title' => 'De l\'offre a l\'encaissement',
                    'workflow_body' => '<p>Les equipes definissent l\'offre, vendent via la boutique ou en interne, generent la commande puis suivent le paiement sans ressaisie.</p>',
                    'workflow_items' => [
                        '1. Configurer produits, services et prix',
                        '2. Ouvrir la vente via la boutique ou l\'equipe',
                        '3. Generer commandes ou factures',
                        '4. Suivre l\'encaissement et la finalisation',
                    ],
                    'modules_title' => 'Modules et pages cles de la solution',
                    'modules_body' => '<p>La solution rassemble les espaces necessaires pour vendre et encaisser dans le meme cadre.</p>',
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
                    'subtitle' => '<p>Publish your offer, sell, invoice, and collect payments inside one commercial environment.</p>',
                    'overview_title' => 'A solution to monetize without fragmenting the journey',
                    'overview_body' => '<p>Commerce & catalog connects the catalog, storefront, orders, invoices, and payments inside one monetization flow.</p>',
                    'overview_items' => [
                        'Unified product and service catalog',
                        'Consistent storefront and ordering flow',
                        'Integrated invoicing and payment tracking',
                        'Payments connected to orders and sales',
                    ],
                    'workflow_title' => 'From offer to collection',
                    'workflow_body' => '<p>Teams define the offer, sell through the storefront or internally, generate the order, and track collection without re-entering data.</p>',
                    'workflow_items' => [
                        '1. Configure products, services, and pricing',
                        '2. Sell through the storefront or the internal team',
                        '3. Generate orders or invoices',
                        '4. Track collection and completion',
                    ],
                    'modules_title' => 'Key modules and pages in the solution',
                    'modules_body' => '<p>The solution brings together the spaces required to sell and collect within one operating frame.</p>',
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
                'title' => 'Marketing & fidelisation',
                'image_url' => $this->stockImage('solution-marketing-loyalty', 'fr')['image_url'],
                'image_alt_fr' => $this->stockImage('solution-marketing-loyalty', 'fr')['image_alt'],
                'image_alt_en' => $this->stockImage('solution-marketing-loyalty', 'en')['image_alt'],
                'fr' => [
                    'subtitle' => '<p>Activez la retention et les campagnes clients a partir des bons signaux, pas de listes statiques.</p>',
                    'overview_title' => 'Une solution pour relancer, segmenter et fideliser',
                    'overview_body' => '<p>Marketing & fidelisation relie l\'audience, les campagnes, les programmes VIP et les scenarios de relance aux donnees reelles du client.</p>',
                    'overview_items' => [
                        'Campagnes email et SMS plus pertinentes',
                        'Segments bases sur l\'historique et le comportement',
                        'Programmes VIP et avantages de fidelite',
                        'Automatisations pour relancer au bon moment',
                    ],
                    'workflow_title' => 'Une execution marketing branchee sur la realite client',
                    'workflow_body' => '<p>Les equipes choisissent l\'audience, preparent le message, lancent la campagne puis mesurent les retours avec le meme contexte client que le reste de la plateforme.</p>',
                    'workflow_items' => [
                        '1. Construire les segments utiles',
                        '2. Creer le message ou le scenario',
                        '3. Activer la diffusion sur les bons canaux',
                        '4. Suivre reactivation, fidelite et valeur',
                    ],
                    'modules_title' => 'Modules et pages cles de la solution',
                    'modules_body' => '<p>La solution regroupe l\'activation marketing et les programmes de retention dans un seul ensemble coherent.</p>',
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
                    'subtitle' => '<p>Activate retention and customer campaigns from real operating signals, not static lists.</p>',
                    'overview_title' => 'A solution to re-engage, segment, and retain',
                    'overview_body' => '<p>Marketing & loyalty connects audience building, campaigns, VIP programs, and follow-up scenarios to real customer data.</p>',
                    'overview_items' => [
                        'More relevant email and SMS campaigns',
                        'Segments based on history and behavior',
                        'VIP programs and loyalty benefits',
                        'Automations to re-engage at the right time',
                    ],
                    'workflow_title' => 'Marketing execution driven by real customer context',
                    'workflow_body' => '<p>Teams choose the audience, prepare the message, launch the campaign, and measure results with the same customer context used across the platform.</p>',
                    'workflow_items' => [
                        '1. Build the right segments',
                        '2. Create the message or scenario',
                        '3. Activate delivery on the right channels',
                        '4. Track reactivation, loyalty, and value',
                    ],
                    'modules_title' => 'Key modules and pages in the solution',
                    'modules_body' => '<p>The solution groups campaign activation and retention programs into one coherent operating set.</p>',
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
                    'subtitle' => '<p>Pilotez plusieurs entites, activites ou equipes avec une vue partagee sur les indicateurs et les priorites.</p>',
                    'overview_title' => 'Une solution de pilotage pour les structures complexes',
                    'overview_body' => '<p>Pilotage multi-entreprise offre une vue transverse sur les ventes, les operations et l\'activite client pour coordonner plusieurs entites sans perdre la lecture globale.</p>',
                    'overview_items' => [
                        'Vue consolidee sur plusieurs entites ou business units',
                        'Indicateurs partages entre les modules cles',
                        'Priorites et points d\'attention visibles rapidement',
                        'Pilotage direction et responsables dans le meme espace',
                    ],
                    'workflow_title' => 'De la vision globale a l\'action terrain',
                    'workflow_body' => '<p>Les responsables surveillent les indicateurs, detectent les priorites puis basculent vers le bon module ou la bonne equipe pour agir sans rupture.</p>',
                    'workflow_items' => [
                        '1. Lire les indicateurs globaux',
                        '2. Identifier les ecarts ou opportunites',
                        '3. Explorer le detail par equipe ou entite',
                        '4. Coordonner les prochaines actions',
                    ],
                    'modules_title' => 'Modules et pages cles de la solution',
                    'modules_body' => '<p>La solution s\'appuie sur des vues transverses et des espaces de commandement partages.</p>',
                    'modules_items' => [
                        'Dashboard global',
                        'Vue revenu',
                        'Vue operations',
                        'Vue equipe',
                        'Alertes',
                        'Rapports partages',
                    ],
                ],
                'en' => [
                    'title' => 'Multi-entity oversight',
                    'subtitle' => '<p>Lead multiple entities, business units, or teams with a shared view of indicators and priorities.</p>',
                    'overview_title' => 'A leadership solution for more complex structures',
                    'overview_body' => '<p>Multi-entity oversight gives leadership a cross-functional view of sales, operations, and customer activity so several entities can be coordinated without losing the big picture.</p>',
                    'overview_items' => [
                        'Consolidated view across entities or business units',
                        'Shared indicators across core modules',
                        'Attention points surfaced quickly',
                        'Leadership and managers working from the same view',
                    ],
                    'workflow_title' => 'From top-level visibility to action in the field',
                    'workflow_body' => '<p>Leaders monitor indicators, detect priorities, and jump into the right module or team to act without breaking context.</p>',
                    'workflow_items' => [
                        '1. Review top-level indicators',
                        '2. Identify gaps or opportunities',
                        '3. Drill into detail by team or entity',
                        '4. Coordinate next actions',
                    ],
                    'modules_title' => 'Key modules and pages in the solution',
                    'modules_body' => '<p>The solution relies on shared command views and cross-functional operating spaces.</p>',
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
                    'subtitle' => '<p>Centralisez les demandes, les devis et le suivi client dans un seul espace commercial.</p>',
                    'overview_title' => 'Passez de la demande au devis sans rupture',
                    'overview_body' => '<p>Sales & CRM reunit la capture de leads, la qualification, les devis et l\'historique client pour accelerer chaque opportunite.</p>',
                    'overview_items' => [
                        'Demandes web et formulaires centralises',
                        'Devis rapides avec suivi de statut',
                        'Fiches clients partagees par toute l\'equipe',
                        'Pipeline visible pour chaque opportunite',
                    ],
                    'workflow_title' => 'Un parcours clair pour les equipes commerciales',
                    'workflow_body' => '<p>Les equipes captent la demande, enrichissent le dossier client, preparent le devis puis suivent la conversion sans sortir de la plateforme.</p>',
                    'workflow_items' => [
                        '1. Capturer la demande entrante',
                        '2. Qualifier le besoin et affecter un responsable',
                        '3. Envoyer le devis et suivre la relance',
                        '4. Transformer l\'opportunite en prestation ou en vente',
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
                    'subtitle' => '<p>Centralize inbound requests, quotes, and customer follow-up in a single revenue workspace.</p>',
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
                    'subtitle' => '<p>Offrez la reservation en libre-service tout en gardant la maitrise des disponibilites, des files et des confirmations.</p>',
                    'overview_title' => 'La prise de rendez-vous devient un vrai canal de vente',
                    'overview_body' => '<p>Reservations connecte les agendas, les confirmations client, les kiosques et la disponibilite en temps reel dans une seule experience.</p>',
                    'overview_items' => [
                        'Reservations en ligne avec disponibilite temps reel',
                        'Confirmation et rappel automatiques',
                        'Gestion de file et check-in sur site',
                        'Kiosques client et public pour accelerer l\'accueil',
                    ],
                    'workflow_title' => 'Un flux simple pour les equipes d\'accueil et d\'operation',
                    'workflow_body' => '<p>Le client reserve, l\'equipe confirme, le point de service gere l\'arrivee et le planning reste synchronise sans double saisie.</p>',
                    'workflow_items' => [
                        '1. Publier les plages et ressources disponibles',
                        '2. Permettre la reservation depuis le web ou un kiosque',
                        '3. Confirmer, replanifier ou annuler rapidement',
                        '4. Suivre les arrivees et la file en temps reel',
                    ],
                    'pages_title' => 'Pages et espaces inclus dans la suite',
                    'pages_body' => '<p>Le produit couvre la reservation avant visite, la gestion sur place et les reglages d\'exploitation.</p>',
                    'pages_items' => [
                        'Agenda',
                        'Disponibilites',
                        'Reservations client',
                        'Kiosque client',
                        'Kiosque public',
                        'File d\'attente',
                    ],
                ],
                'en' => [
                    'subtitle' => '<p>Offer self-service booking while keeping full control over availability, queues, and confirmations.</p>',
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
                    'subtitle' => '<p>Pilotez la planification, les interventions, les taches et l\'execution terrain depuis un cockpit unique.</p>',
                    'overview_title' => 'Une vue operationnelle complete pour mieux livrer',
                    'overview_body' => '<p>Operations aligne planning, dispatch, interventions et suivi quotidien pour que les equipes terrain travaillent avec le bon contexte.</p>',
                    'overview_items' => [
                        'Planning centralise avec affectation rapide',
                        'Jobs et interventions suivis en direct',
                        'Taches internes visibles par equipe',
                        'Preuves de travail, notes et completions au meme endroit',
                    ],
                    'workflow_title' => 'Un cycle de travail adapte aux operations de terrain',
                    'workflow_body' => '<p>Les managers planifient, assignent les jobs, suivent l\'execution puis valident la fin des travaux avec toutes les preuves necessaires.</p>',
                    'workflow_items' => [
                        '1. Planifier les ressources et les plages de travail',
                        '2. Assigner les jobs et taches aux bonnes equipes',
                        '3. Suivre l\'avancement et les blocages',
                        '4. Cloturer avec notes, photos ou signatures',
                    ],
                    'pages_title' => 'Pages et espaces inclus dans la suite',
                    'pages_body' => '<p>Le produit couvre la planification, l\'execution et le suivi de la performance terrain.</p>',
                    'pages_items' => [
                        'Planning',
                        'Jobs',
                        'Taches',
                        'Presence',
                        'Equipe',
                        'Suivi journalier',
                    ],
                ],
                'en' => [
                    'subtitle' => '<p>Run scheduling, jobs, tasks, and field execution from a single operating cockpit.</p>',
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
                    'subtitle' => '<p>Vendez produits et services, emettez les factures et encaissez sans casser le parcours client.</p>',
                    'overview_title' => 'Reliez catalogue, vente, facturation et paiement',
                    'overview_body' => '<p>Commerce unifie les produits, les commandes, les factures et le paiement pour que chaque transaction reste simple a piloter.</p>',
                    'overview_items' => [
                        'Catalogue produits et services centralise',
                        'Boutique et parcours de commande coherents',
                        'Facturation et suivi de paiement integres',
                        'Paiements en ligne et sur place dans le meme systeme',
                    ],
                    'workflow_title' => 'Une chaine complete du panier a l\'encaissement',
                    'workflow_body' => '<p>Les equipes publient l\'offre, vendent, facturent puis encaissent dans le meme environnement, sans ressaisie entre les etapes.</p>',
                    'workflow_items' => [
                        '1. Configurer les produits, services et prix',
                        '2. Ouvrir la vente via la boutique ou l\'equipe interne',
                        '3. Generer la commande ou la facture',
                        '4. Suivre le paiement et la finalisation',
                    ],
                    'pages_title' => 'Pages et espaces inclus dans la suite',
                    'pages_body' => '<p>Le produit regroupe toute la chaine de monetisation, du catalogue jusqu\'a l\'encaissement.</p>',
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
                    'subtitle' => '<p>Sell products and services, issue invoices, and collect payment without breaking the customer journey.</p>',
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
                    'subtitle' => '<p>Lancez des campagnes, creez des segments et activez des parcours de fidelisation relies a l\'activite reelle des clients.</p>',
                    'overview_title' => 'Une base de retention connectee a vos operations',
                    'overview_body' => '<p>Marketing & Loyalty connecte campagnes, listes, segments et programmes VIP au meme contexte client que les ventes et les operations.</p>',
                    'overview_items' => [
                        'Campagnes email et SMS plus ciblees',
                        'Segmentation basee sur le comportement client',
                        'Programmes de fidelite et avantages VIP',
                        'Relances et parcours de retention automatisees',
                    ],
                    'workflow_title' => 'Une execution marketing branchee sur les bons signaux',
                    'workflow_body' => '<p>Les equipes selectionnent l\'audience, definissent le message, lancent la campagne puis mesurent les reactions sans sortir du produit.</p>',
                    'workflow_items' => [
                        '1. Construire les segments selon la valeur ou l\'activite',
                        '2. Preparer la campagne ou le scenario de relance',
                        '3. Activer la diffusion sur les bons canaux',
                        '4. Suivre la reactivation et la fidelisation',
                    ],
                    'pages_title' => 'Pages et espaces inclus dans la suite',
                    'pages_body' => '<p>Le produit couvre l\'activation marketing, la base d\'audience et les programmes de retention.</p>',
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
                    'subtitle' => '<p>Utilisez l\'assistant, les brouillons intelligents et les suggestions d\'actions directement dans les flux de travail existants.</p>',
                    'overview_title' => 'L\'IA integree a la plateforme, pas a cote',
                    'overview_body' => '<p>AI & Automation apporte les resumes, les suggestions et l\'assistant conversationnel dans les modules que les equipes utilisent deja.</p>',
                    'overview_items' => [
                        'Assistant disponible dans les parcours metier',
                        'Brouillons intelligents pour messages et offres',
                        'Resumes rapides des historiques complexes',
                        'Suggestions d\'actions et automatisations utiles',
                    ],
                    'workflow_title' => 'Un usage concret pour gagner du temps chaque jour',
                    'workflow_body' => '<p>Les equipes interrogent l\'assistant, generent un contenu, resumant un dossier ou declenchent une action recommandee sans changer d\'outil.</p>',
                    'workflow_items' => [
                        '1. Ouvrir l\'assistant dans le bon contexte',
                        '2. Generer un brouillon ou un resume utile',
                        '3. Valider ou ajuster la proposition',
                        '4. Transformer la suggestion en action reelle',
                    ],
                    'pages_title' => 'Pages et espaces inclus dans la suite',
                    'pages_body' => '<p>Le produit couvre l\'assistant, les contenus generes et les aides a la decision.</p>',
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
                    'subtitle' => '<p>Obtenez une vue transversale sur le revenu, les operations et l\'activite client avec un centre de pilotage partage.</p>',
                    'overview_title' => 'Un poste de pilotage pour la direction et les operations',
                    'overview_body' => '<p>Command Center consolide les signaux business de plusieurs modules pour donner une lecture claire de la performance globale.</p>',
                    'overview_items' => [
                        'Vue consolidee sur les indicateurs cle',
                        'Suivi croise des ventes, operations et marketing',
                        'Pilotage multi-entite ou multi-activite',
                        'Points d\'attention et priorites visibles rapidement',
                    ],
                    'workflow_title' => 'Un centre de decision partage par les responsables',
                    'workflow_body' => '<p>Les responsables suivent les indicateurs, identifient les priorites puis basculent vers le bon module pour agir sans perdre le contexte.</p>',
                    'workflow_items' => [
                        '1. Surveiller les indicateurs globaux',
                        '2. Detecter les blocages ou opportunites',
                        '3. Explorer le detail par module ou equipe',
                        '4. Coordonner les prochaines actions',
                    ],
                    'pages_title' => 'Pages et espaces inclus dans la suite',
                    'pages_body' => '<p>Le produit couvre la gouvernance, la visibilite transverse et les vues de commandement.</p>',
                    'pages_items' => [
                        'Dashboard global',
                        'Vue revenu',
                        'Vue operations',
                        'Vue equipe',
                        'Alertes',
                        'Rapports partages',
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
