<?php

namespace Database\Seeders;

use App\Models\MegaMenu;
use App\Models\PlatformPage;
use App\Models\User;
use App\Services\MegaMenus\MegaMenuManagerService;
use Illuminate\Database\Seeder;

class MegaMenuSeeder extends Seeder
{
    public function run(): void
    {
        $userId = User::query()->where('email', 'superadmin@example.com')->value('id');

        $partnersPage = PlatformPage::query()->firstOrCreate(
            ['slug' => 'partners'],
            [
                'title' => 'Partners',
                'is_active' => true,
                'content' => [
                    'en' => [
                        'page_title' => 'Partners',
                        'page_subtitle' => '<p>Explore our partner ecosystem.</p>',
                        'sections' => [],
                    ],
                ],
                'updated_by' => $userId,
            ]
        );

        $manager = app(MegaMenuManagerService::class);

        foreach ($this->menus($partnersPage) as $payload) {
            $existing = MegaMenu::query()->where('slug', $payload['slug'])->first();

            if ($existing) {
                $manager->update($existing, $payload, $userId);
                continue;
            }

            $manager->create($payload, $userId);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function menus(PlatformPage $partnersPage): array
    {
        return [
            $this->mainHeaderMenu($partnersPage),
            $this->footerMenu(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mainHeaderMenu(PlatformPage $partnersPage): array
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
                $this->productsAndServicesItem(),
                $this->solutionsItem(),
                $this->pricingItem(),
                $this->demoItem(),
                $this->resourcesItem($partnersPage),
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
     * @return array<string, mixed>
     */
    private function resourcesItem(PlatformPage $partnersPage): array
    {
        return [
            'label' => 'Ressources',
            'description' => 'Pages utiles, informations publiques et contenus de référence.',
            'link_type' => 'none',
            'link_target' => '_self',
            'panel_type' => 'classic',
            'is_visible' => true,
            'children' => [
                $this->classicLink('Tarifs', '/pricing', 'Plans, modules et options de déploiement.'),
                $this->classicLink('Partenaires', "/pages/{$partnersPage->slug}", 'Explorez l’écosystème autour de la plateforme.'),
                $this->classicLink('Conditions', '/terms', 'Conditions d’utilisation de la plateforme.'),
                $this->classicLink('Confidentialité', '/privacy', 'Sécurité, données et conformité.'),
                $this->classicLink('Remboursement', '/refund', 'Politique de remboursement et facturation.'),
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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function demoItem(): array
    {
        return [
            'label' => 'Démo',
            'description' => 'Explorez la plateforme avec des données de démonstration.',
            'link_type' => 'internal_page',
            'link_value' => '/demo',
            'link_target' => '_self',
            'panel_type' => 'link',
            'is_visible' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function solutionsItem(): array
    {
        return [
            'label' => 'Solutions',
            'description' => 'Choisissez un parcours selon le mode opératoire de votre équipe.',
            'link_type' => 'none',
            'link_target' => '_self',
            'panel_type' => 'classic',
            'is_visible' => true,
            'children' => [
                $this->classicLink('Services terrain', '/pricing#operations', 'Planification, interventions, preuves et suivi terrain.'),
                $this->classicLink('Réservations & files', '/pricing#reservations', 'Prise de rendez-vous, disponibilité, kiosque et check-in.'),
                $this->classicLink('Vente & devis', '/pricing#sales-crm', 'Demandes, devis, clients et pipeline commercial.'),
                $this->classicLink('Commerce & catalogue', '/pricing#commerce', 'Produits, services, boutique et commandes.'),
                $this->classicLink('Marketing & fidélisation', '/pricing#marketing', 'Campagnes, segments, VIP et automatisations.'),
                $this->classicLink('Pilotage multi-entreprise', '/pricing#platform', 'Vue globale sur plusieurs entités et modules.'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function productsAndServicesItem(): array
    {
        $item = $this->megaItem(
            'Produits & Services',
            'Explorez tout le catalogue plateforme depuis un point d’entrée unique.',
            '/pricing',
            'grid-2x2',
            '#0f766e',
            [
                $this->column('', '1fr', [
                    $this->productShowcaseBlock(
                        'Produits & Services',
                        'Survolez un produit pour voir l’interface et comprendre ce qu’il débloque pour votre équipe.',
                        [
                            $this->showcaseProduct(
                                'Sales & CRM',
                                '/pricing#sales-crm',
                                'Requests, quotes, customers, and pipelines.',
                                'Capture demand, qualify opportunities, and move faster from first request to approved quote.',
                                '/images/mega-menu/sales-crm-suite.svg',
                                'Sales and CRM suite illustration',
                                'Sales and CRM suite',
                                'Popular'
                            ),
                            $this->showcaseProduct(
                                'Reservations',
                                '/pricing#reservations',
                                'Bookings, availability, and self-service scheduling.',
                                'Let customers book online while teams keep live control over availability, queues, and confirmations.',
                                '/images/mega-menu/reservations-suite.svg',
                                'Reservations suite illustration',
                                'Reservations suite',
                                'Core'
                            ),
                            $this->showcaseProduct(
                                'Operations',
                                '/pricing#operations',
                                'Scheduling, jobs, tasks, and dispatch.',
                                'Coordinate field execution, assignments, proof of work, and daily follow-up from one operational cockpit.',
                                '/images/mega-menu/operations-suite.svg',
                                'Operations suite dashboard illustration',
                                'Operations suite dashboard'
                            ),
                            $this->showcaseProduct(
                                'Commerce',
                                '/pricing#commerce',
                                'Catalog, storefront, invoices, and payments.',
                                'Sell products and services, invoice customers, and collect payments without fragmenting the journey.',
                                '/images/mega-menu/commerce-suite.svg',
                                'Commerce suite hero illustration',
                                'Commerce suite hero'
                            ),
                            $this->showcaseProduct(
                                'Marketing & Loyalty',
                                '/pricing#marketing',
                                'Campaigns, segments, loyalty, and VIP journeys.',
                                'Build retention programs and targeted follow-up using the same customer context as sales and operations.',
                                '/images/mega-menu/marketing-loyalty-suite.svg',
                                'Growth automation illustration',
                                'Growth automation',
                                'Growth'
                            ),
                            $this->showcaseProduct(
                                'AI & Automation',
                                '/pricing#ai-automation',
                                'Assistant, drafts, summaries, and suggested actions.',
                                'Embed AI into the workflow your teams already use instead of adding another disconnected tool.',
                                '/images/mega-menu/ai-automation-suite.svg',
                                'AI automation hero illustration',
                                'AI automation hero',
                                'AI'
                            ),
                            $this->showcaseProduct(
                                'Command Center',
                                '/pricing#platform',
                                'Cross-module visibility and leadership overview.',
                                'Get one executive-level view across revenue, operations, and customer activity with a shared command center.',
                                '/images/mega-menu/platform-command-center.svg',
                                'Platform command center illustration',
                                'Platform command center'
                            ),
                        ]
                    ),
                ]),
            ]
        );

        $item['link_type'] = 'none';
        $item['link_value'] = null;

        return $item;
    }

    /**
     * @return array<string, mixed>
     */
    private function salesAndCrmItem(): array
    {
        return $this->megaItem(
            'Sales & CRM',
            'Capture demand, qualify opportunities, and convert faster.',
            '/pricing#sales-crm',
            'briefcase-business',
            '#0284c7',
            [
                $this->column('Convert demand', '1.1fr', [
                    $this->navigationGroupBlock(
                        'Front office modules',
                        'Unify lead capture, qualification, quoting, and client context.',
                        [
                            $this->suiteLink('Requests', '/pricing#requests', 'Capture inbound demand from web, chat, or forms.', 'Hot'),
                            $this->suiteLink('Quotes', '/pricing#quotes', 'Build proposals and convert them into paid work.'),
                            $this->suiteLink('Customers', '/pricing#customers', 'Keep every contact, note, and company profile in one place.'),
                            $this->suiteLink('Pipelines', '/pricing#pipelines', 'Track opportunity stages and next best actions.'),
                        ],
                        'contrast'
                    ),
                ]),
                $this->column('Discover', '0.9fr', [
                    $this->navigationGroupBlock(
                        'Discover',
                        'Surface the right modules for acquisition, qualification, and conversion.',
                        [
                            $this->suiteLink('Lead capture', '/pricing#requests', 'Multi-entry acquisition workflows.'),
                            $this->suiteLink('Proposal builder', '/pricing#quotes', 'Fast quote composition and approval.'),
                            $this->suiteLink('Client timeline', '/pricing#customers', 'Notes, history, and relationship context.'),
                            $this->suiteLink('AI follow-up', '/pricing#ai-automation', 'Suggested replies and next actions.', 'AI'),
                        ]
                    ),
                ]),
                $this->column('Featured', '1.05fr', [
                    $this->promoBannerBlock(
                        'Revenue',
                        'One workspace for every opportunity',
                        '<p>Sales teams move from first contact to approved quote with a single customer timeline.</p>',
                        'Explore revenue modules',
                        '/pricing#sales-crm',
                        '/images/mega-menu/sales-crm-suite.svg',
                        'Sales and CRM suite illustration',
                        'Sales and CRM suite'
                    ),
                ]),
                $this->column('Popular shortcuts', '1fr', [
                    $this->quickLinksBlock('Popular shortcuts', [
                        $this->quickLink('Requests', '/pricing#requests'),
                        $this->quickLink('Quotes', '/pricing#quotes'),
                        $this->quickLink('Pipelines', '/pricing#pipelines'),
                        $this->quickLink('AI follow-up', '/pricing#ai-automation'),
                    ]),
                ], ['row' => 'footer']),
            ],
            'Popular',
            'featured'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function operationsItem(): array
    {
        return $this->megaItem(
            'Operations',
            'Plan, schedule, dispatch, and deliver work with live visibility.',
            '/pricing#operations',
            'calendar-range',
            '#0f766e',
            [
                $this->column('Plan & dispatch', '1.1fr', [
                    $this->navigationGroupBlock(
                        'Operational modules',
                        'Organize teams, bookings, schedules, and day-to-day execution.',
                        [
                            $this->suiteLink('Reservations', '/pricing#reservations', 'Online booking, queues, availability, and self-service.', 'Core'),
                            $this->suiteLink('Scheduling', '/pricing#scheduling', 'Assign the right work to the right team at the right time.'),
                            $this->suiteLink('Jobs', '/pricing#jobs', 'Manage work orders, progress, proof, and completion.'),
                            $this->suiteLink('Tasks', '/pricing#tasks', 'Keep internal follow-up and task ownership visible.'),
                            $this->suiteLink('Team access', '/pricing#team', 'Give every staff member the tools and permissions they need.'),
                        ],
                        'contrast'
                    ),
                ]),
                $this->column('Need help?', '0.95fr', [
                    $this->navigationGroupBlock(
                        'Support',
                        'Guide visitors from discovery to service execution.',
                        [
                            $this->suiteLink('Online booking', '/pricing#reservations', 'Self-serve availability and appointments.'),
                            $this->suiteLink('Resource planning', '/pricing#scheduling', 'Distribute work across teams and calendars.'),
                            $this->suiteLink('Proof of work', '/pricing#jobs', 'Photos, notes, signatures, and completion.'),
                            $this->suiteLink('Internal follow-up', '/pricing#tasks', 'Tasks and escalations that stay visible.'),
                        ]
                    ),
                ]),
                $this->column('Featured', '1.05fr', [
                    $this->promoBannerBlock(
                        'Operations',
                        'Dispatch snapshot',
                        '<p>Bookings, assignments, and service execution stay aligned in one operational cockpit.</p>',
                        'See operations modules',
                        '/pricing#operations',
                        '/images/mega-menu/operations-suite.svg',
                        'Operations suite dashboard illustration',
                        'Operations suite dashboard'
                    ),
                ]),
                $this->column('Quick links', '1fr', [
                    $this->quickLinksBlock('Quick links', [
                        $this->quickLink('Reservations', '/pricing#reservations'),
                        $this->quickLink('Scheduling', '/pricing#scheduling'),
                        $this->quickLink('Jobs', '/pricing#jobs'),
                        $this->quickLink('Tasks', '/pricing#tasks'),
                    ]),
                ], ['row' => 'footer']),
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function commerceItem(): array
    {
        return $this->megaItem(
            'Commerce',
            'Sell products, issue invoices, and collect payment without fragmentation.',
            '/pricing#commerce',
            'wallet',
            '#b45309',
            [
                $this->column('Sell & get paid', '1.1fr', [
                    $this->navigationGroupBlock(
                        'Revenue operations',
                        'Connect catalog, storefront, billing, and payment collection.',
                        [
                            $this->suiteLink('Products', '/pricing#products', 'Manage catalog, pricing, and merchandising.'),
                            $this->suiteLink('Storefront', '/pricing#store', 'Launch a branded online store without extra tooling.'),
                            $this->suiteLink('Invoices', '/pricing#invoices', 'Generate, send, and monitor invoice status end to end.'),
                            $this->suiteLink('Payments', '/pricing#payments', 'Collect card, online, and in-person payments faster.', 'Hot'),
                            $this->suiteLink('Tips & add-ons', '/pricing#tips', 'Increase basket value during checkout and billing.'),
                        ],
                        'contrast'
                    ),
                ]),
                $this->column('Discover', '0.95fr', [
                    $this->navigationGroupBlock(
                        'Discover',
                        'Show the full revenue journey from catalog to paid invoice.',
                        [
                            $this->suiteLink('Product catalog', '/pricing#products', 'Centralized merchandising and pricing.'),
                            $this->suiteLink('Online store', '/pricing#store', 'Branded storefront with checkout.'),
                            $this->suiteLink('Invoice lifecycle', '/pricing#invoices', 'Status, reminders, and collection.'),
                            $this->suiteLink('Payment options', '/pricing#payments', 'Online and in-person collection tools.'),
                        ]
                    ),
                ]),
                $this->column('Featured', '1.05fr', [
                    $this->promoBannerBlock(
                        'Revenue',
                        'Monetize every interaction',
                        '<p>Bundle products, services, invoices, and online checkout into a single customer journey.</p>',
                        'See revenue modules',
                        '/pricing#commerce',
                        '/images/mega-menu/commerce-suite.svg',
                        'Commerce suite hero illustration',
                        'Commerce suite hero'
                    ),
                ]),
                $this->column('Popular shortcuts', '1fr', [
                    $this->quickLinksBlock('Popular shortcuts', [
                        $this->quickLink('Products', '/pricing#products'),
                        $this->quickLink('Storefront', '/pricing#store'),
                        $this->quickLink('Invoices', '/pricing#invoices'),
                        $this->quickLink('Payments', '/pricing#payments'),
                    ]),
                ], ['row' => 'footer']),
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function marketingItem(): array
    {
        return $this->megaItem(
            'Marketing & Loyalty',
            'Grow retention with campaigns, segments, VIP tiers, and repeat-buy journeys.',
            '/pricing#marketing',
            'megaphone',
            '#7c3aed',
            [
                $this->column('Retention engine', '1.1fr', [
                    $this->navigationGroupBlock(
                        'Growth modules',
                        'Launch campaigns and personalize follow-up for every audience segment.',
                        [
                            $this->suiteLink('Campaigns', '/pricing#campaigns', 'Email, SMS, and audience-led automation.'),
                            $this->suiteLink('Mailing lists', '/pricing#mailing-lists', 'Keep clean, reusable marketing audiences.'),
                            $this->suiteLink('Audience segments', '/pricing#audience-segments', 'Target customers by behavior, recency, or value.'),
                            $this->suiteLink('Loyalty', '/pricing#loyalty', 'Reward repeat activity and drive return visits.'),
                            $this->suiteLink('VIP tiers', '/pricing#vip', 'Create premium experiences for top customers.'),
                        ],
                        'contrast'
                    ),
                ]),
                $this->column('Discover', '0.95fr', [
                    $this->navigationGroupBlock(
                        'Discover',
                        'Connect communication, segmentation, loyalty, and retention in one stack.',
                        [
                            $this->suiteLink('Campaign journeys', '/pricing#campaigns', 'Send the right follow-up at the right time.'),
                            $this->suiteLink('Behavioral segments', '/pricing#audience-segments', 'Build audiences from customer signals.'),
                            $this->suiteLink('Win-back flows', '/pricing#loyalty', 'Bring lapsed customers back with incentives.'),
                            $this->suiteLink('VIP experiences', '/pricing#vip', 'Treat top customers differently and deliberately.'),
                        ]
                    ),
                ]),
                $this->column('Featured', '1.05fr', [
                    $this->promoBannerBlock(
                        'Growth',
                        'Campaigns linked to real customer activity',
                        '<p>Send the right follow-up at the right moment using the same data that powers operations and billing.</p>',
                        'Explore marketing modules',
                        '/pricing#marketing',
                        '/images/mega-menu/marketing-loyalty-suite.svg',
                        'Growth automation illustration',
                        'Growth automation'
                    ),
                ]),
                $this->column('Popular shortcuts', '1fr', [
                    $this->quickLinksBlock('Popular shortcuts', [
                        $this->quickLink('Campaigns', '/pricing#campaigns'),
                        $this->quickLink('Segments', '/pricing#audience-segments'),
                        $this->quickLink('Loyalty', '/pricing#loyalty'),
                        $this->quickLink('VIP', '/pricing#vip'),
                    ]),
                ], ['row' => 'footer']),
            ],
            'Growth',
            'new'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function aiAutomationItem(): array
    {
        return $this->megaItem(
            'AI & Automation',
            'Accelerate work with assisted drafting, summaries, and guided next actions.',
            '/pricing#ai-automation',
            'sparkles',
            '#dc2626',
            [
                $this->column('Assistant capabilities', '1.05fr', [
                    $this->navigationGroupBlock(
                        'AI-powered work',
                        'Use the assistant to move faster across sales, operations, and service delivery.',
                        [
                            $this->suiteLink('AI assistant', '/pricing#assistant', 'Ask, generate, summarize, and decide from one command space.', 'AI'),
                            $this->suiteLink('Smart drafts', '/pricing#smart-drafts', 'Create faster replies, offers, and follow-up messages.'),
                            $this->suiteLink('Auto summaries', '/pricing#summaries', 'Condense long histories into immediately useful context.'),
                            $this->suiteLink('Suggested tasks', '/pricing#suggested-tasks', 'Turn signals into next actions for teams and managers.'),
                        ],
                        'contrast'
                    ),
                ]),
                $this->column('Discover', '0.95fr', [
                    $this->navigationGroupBlock(
                        'Discover',
                        'Bring AI into the existing workflow instead of adding another disconnected tool.',
                        [
                            $this->suiteLink('Assisted replies', '/pricing#smart-drafts', 'Draft responses faster and keep the right tone.'),
                            $this->suiteLink('Summaries', '/pricing#summaries', 'Compress context into usable next steps.'),
                            $this->suiteLink('Suggested tasks', '/pricing#suggested-tasks', 'Push recommendations directly into execution.'),
                            $this->suiteLink('Cross-module context', '/pricing#assistant', 'Use the same assistant across sales and operations.'),
                        ]
                    ),
                ]),
                $this->column('Featured', '1.05fr', [
                    $this->promoBannerBlock(
                        'Assistant',
                        'Build a faster operating system',
                        '<p>The assistant is not a side tool. It is embedded across the modules your teams already use.</p>',
                        'See AI capabilities',
                        '/pricing#ai-automation',
                        '/images/mega-menu/ai-automation-suite.svg',
                        'AI automation hero illustration',
                        'AI automation hero'
                    ),
                ]),
                $this->column('Quick links', '1fr', [
                    $this->quickLinksBlock('Quick links', [
                        $this->quickLink('AI assistant', '/pricing#assistant'),
                        $this->quickLink('Smart drafts', '/pricing#smart-drafts'),
                        $this->quickLink('Summaries', '/pricing#summaries'),
                        $this->quickLink('Suggested tasks', '/pricing#suggested-tasks'),
                    ]),
                ], ['row' => 'footer']),
            ],
            'AI',
            'hot'
        );
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
     * @param  array<int, array<string, mixed>>  $links
     * @return array<string, mixed>
     */
    private function navigationGroupBlock(string $title, string $description, array $links, string $tone = 'default'): array
    {
        return [
            'type' => 'navigation_group',
            'title' => $title,
            'settings' => [
                'tone' => $tone,
                'show_border' => false,
            ],
            'payload' => [
                'title' => $title,
                'description' => $description,
                'links' => $links,
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function productShowcaseBlock(string $title, string $description, array $items): array
    {
        return [
            'type' => 'product_showcase',
            'title' => $title,
            'payload' => [
                'title' => $title,
                'description' => $description,
                'items' => $items,
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $links
     * @return array<string, mixed>
     */
    private function quickLinksBlock(string $title, array $links): array
    {
        return [
            'type' => 'quick_links',
            'title' => $title,
            'payload' => [
                'title' => $title,
                'links' => $links,
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $cards
     * @return array<string, mixed>
     */
    private function cardsBlock(string $title, array $cards): array
    {
        return [
            'type' => 'cards',
            'title' => $title,
            'payload' => [
                'title' => $title,
                'cards' => $cards,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function featuredBlock(
        string $eyebrow,
        string $title,
        string $body,
        string $ctaLabel,
        string $ctaHref,
        string $imageUrl,
        string $imageAlt,
        string $imageTitle
    ): array {
        return [
            'type' => 'featured_content',
            'title' => $title,
            'payload' => [
                'eyebrow' => $eyebrow,
                'title' => $title,
                'body' => $body,
                'cta_label' => $ctaLabel,
                'cta_href' => $ctaHref,
                'image_url' => $imageUrl,
                'image_alt' => $imageAlt,
                'image_title' => $imageTitle,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function promoBannerBlock(
        string $badge,
        string $title,
        string $body,
        string $ctaLabel,
        string $ctaHref,
        string $imageUrl,
        string $imageAlt,
        string $imageTitle
    ): array {
        return [
            'type' => 'promo_banner',
            'title' => $title,
            'payload' => [
                'badge' => $badge,
                'title' => $title,
                'body' => $body,
                'cta_label' => $ctaLabel,
                'cta_href' => $ctaHref,
                'image_url' => $imageUrl,
                'image_alt' => $imageAlt,
                'image_title' => $imageTitle,
            ],
        ];
    }

    /**
     * @param  array<int, array<string, string>>  $metrics
     * @return array<string, mixed>
     */
    private function demoPreviewBlock(
        string $title,
        string $body,
        string $imageUrl,
        string $imageAlt,
        string $imageTitle,
        array $metrics
    ): array {
        return [
            'type' => 'demo_preview',
            'title' => $title,
            'payload' => [
                'title' => $title,
                'body' => $body,
                'preview_image_url' => $imageUrl,
                'preview_image_alt' => $imageAlt,
                'preview_image_title' => $imageTitle,
                'metrics' => $metrics,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function imageBlock(
        string $imageUrl,
        string $imageAlt,
        string $imageTitle,
        string $caption,
        string $href
    ): array {
        return [
            'type' => 'image',
            'title' => $imageTitle,
            'payload' => [
                'image_url' => $imageUrl,
                'image_alt' => $imageAlt,
                'image_title' => $imageTitle,
                'caption' => $caption,
                'href' => $href,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function suiteLink(string $label, string $href, string $note, string $badge = ''): array
    {
        return [
            'label' => $label,
            'href' => $href,
            'note' => $note,
            'badge' => $badge,
            'target' => '_self',
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
    private function quickLink(string $label, string $href): array
    {
        return [
            'label' => $label,
            'href' => $href,
            'target' => '_self',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function card(
        string $title,
        string $body,
        string $href,
        string $badge,
        string $imageUrl,
        string $imageAlt,
        string $imageTitle
    ): array {
        return [
            'title' => $title,
            'body' => $body,
            'href' => $href,
            'badge' => $badge,
            'image_url' => $imageUrl,
            'image_alt' => $imageAlt,
            'image_title' => $imageTitle,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $columns
     * @return array<string, mixed>
     */
    private function megaItem(
        string $label,
        string $description,
        string $linkValue,
        string $icon,
        string $highlightColor,
        array $columns,
        ?string $badgeText = null,
        ?string $badgeVariant = null
    ): array {
        return [
            'label' => $label,
            'description' => $description,
            'link_type' => 'internal_page',
            'link_value' => $linkValue,
            'link_target' => '_self',
            'panel_type' => 'mega',
            'icon' => $icon,
            'badge_text' => $badgeText,
            'badge_variant' => $badgeVariant,
            'is_visible' => true,
            'settings' => [
                'eyebrow' => 'Modules',
                'note' => $description,
                'featured' => $badgeText !== null,
                'highlight_color' => $highlightColor,
            ],
            'columns' => $columns,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function classicLink(string $label, string $linkValue, string $description = ''): array
    {
        return [
            'label' => $label,
            'description' => $description,
            'link_type' => 'internal_page',
            'link_value' => $linkValue,
            'link_target' => '_self',
            'panel_type' => 'link',
            'is_visible' => true,
        ];
    }
}
