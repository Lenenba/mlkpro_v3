<?php

namespace App\Support;

class PublicPageStockImages
{
    /**
     * @return array{image_alt:string,image_url:string}
     */
    public static function page(string $key, ?string $locale = 'fr'): array
    {
        return self::slot($key, 'header', $locale);
    }

    /**
     * @return array{image_alt:string,image_url:string}
     */
    public static function slot(string $key, string $slot, ?string $locale = 'fr'): array
    {
        $locale = self::normalizeLocale($locale);
        $slot = strtolower(trim($slot)) ?: 'header';
        $visualKey = self::PAGE_SLOTS[$key][$slot]
            ?? self::PAGE_SLOTS[$key]['header']
            ?? self::PAGE_SLOTS['sales-crm']['header'];
        $visual = self::VISUALS[$visualKey] ?? self::VISUALS['desk-phone-laptop'];

        return [
            'image_url' => $visual['image_url'],
            'image_alt' => $locale === 'fr' ? $visual['alt_fr'] : $visual['alt_en'],
        ];
    }

    public static function normalizeLocale(?string $locale): string
    {
        return str_starts_with(strtolower((string) $locale), 'fr') ? 'fr' : 'en';
    }

    /**
     * @return array<int, string>
     */
    public static function managedPageSlugs(): array
    {
        return array_keys(self::PAGE_SLOTS);
    }

    /**
     * @return array<int, string>
     */
    public static function legacyIllustrationUrls(): array
    {
        return [
            '/images/landing/mobile-field.svg',
            '/images/landing/workflow-board.svg',
            '/images/landing/hero-dashboard.svg',
            '/images/mega-menu/ai-automation-suite.svg',
            '/images/mega-menu/commerce-suite.svg',
            '/images/mega-menu/contact-map.svg',
            '/images/mega-menu/marketing-loyalty-suite.svg',
            '/images/mega-menu/operations-suite.svg',
            '/images/mega-menu/platform-command-center.svg',
            '/images/mega-menu/reservations-suite.svg',
            '/images/mega-menu/sales-crm-suite.svg',
        ];
    }

    private const VISUALS = [
        'collab-laptop-desk' => [
            'image_url' => '/images/landing/stock/collab-laptop-desk.jpg',
            'alt_fr' => 'Deux collegues collaborent autour d un ordinateur portable',
            'alt_en' => 'Two teammates collaborating around a laptop',
        ],
        'desk-phone-laptop' => [
            'image_url' => '/images/landing/stock/desk-phone-laptop.jpg',
            'alt_fr' => 'Responsable commerciale avec telephone et ordinateur sur son bureau',
            'alt_en' => 'Sales lead working from a desk with phone and laptop',
        ],
        'field-checklist' => [
            'image_url' => '/images/landing/stock/field-checklist.jpg',
            'alt_fr' => 'Technicien terrain avec checklist avant intervention',
            'alt_en' => 'Field technician reviewing a checklist before work',
        ],
        'hero-tablet' => [
            'image_url' => '/images/landing/stock/hero-tablet.jpg',
            'alt_fr' => 'Equipe qui consulte une tablette pour organiser la suite',
            'alt_en' => 'Team reviewing a tablet to organize the next step',
        ],
        'hero-team' => [
            'image_url' => '/images/landing/stock/hero-team.jpg',
            'alt_fr' => 'Equipe en coordination pour garder un service fluide',
            'alt_en' => 'Team coordinating to keep service flowing smoothly',
        ],
        'marketing-desk' => [
            'image_url' => '/images/landing/stock/marketing-desk.jpg',
            'alt_fr' => 'Professionnelle qui gere messages et campagnes depuis son poste',
            'alt_en' => 'Professional managing campaigns and messages from a desk',
        ],
        'meeting-room-laptops' => [
            'image_url' => '/images/landing/stock/meeting-room-laptops.jpg',
            'alt_fr' => 'Equipe en reunion autour de plusieurs ordinateurs',
            'alt_en' => 'Team in a meeting around multiple laptops',
        ],
        'payments-terminal' => [
            'image_url' => '/images/landing/stock/payments-terminal.jpg',
            'alt_fr' => 'Terminal de paiement dans un contexte de vente',
            'alt_en' => 'Payment terminal in a retail context',
        ],
        'service-install' => [
            'image_url' => '/images/landing/stock/service-install.jpg',
            'alt_fr' => 'Technicien en intervention sur une installation interieure',
            'alt_en' => 'Technician performing an indoor installation',
        ],
        'service-tablet' => [
            'image_url' => '/images/landing/stock/service-tablet.jpg',
            'alt_fr' => 'Equipe qui coordonne les rendez-vous sur tablette',
            'alt_en' => 'Team coordinating appointments on a tablet',
        ],
        'service-team' => [
            'image_url' => '/images/landing/stock/service-team.jpg',
            'alt_fr' => 'Equipe service en coordination avant une intervention',
            'alt_en' => 'Service team coordinating before field work',
        ],
        'store-boxes' => [
            'image_url' => '/images/landing/stock/store-boxes.jpg',
            'alt_fr' => 'Preparation de colis et de stock dans un espace de vente',
            'alt_en' => 'Boxes and inventory being prepared in a commerce setting',
        ],
        'store-payment' => [
            'image_url' => '/images/landing/stock/store-payment.jpg',
            'alt_fr' => 'Paiement sur terminal dans un contexte de vente',
            'alt_en' => 'Card payment on a terminal in a selling context',
        ],
        'store-worker' => [
            'image_url' => '/images/landing/stock/store-worker.jpg',
            'alt_fr' => 'Collaborateur logistique dans un espace de preparation',
            'alt_en' => 'Fulfillment team member inside a preparation space',
        ],
        'team-laptop-window' => [
            'image_url' => '/images/landing/stock/team-laptop-window.jpg',
            'alt_fr' => 'Equipe qui prepare l accueil client et la planification',
            'alt_en' => 'Team preparing customer scheduling and reception',
        ],
        'warehouse-worker' => [
            'image_url' => '/images/landing/stock/warehouse-worker.jpg',
            'alt_fr' => 'Preparateur qui gere articles et disponibilites',
            'alt_en' => 'Warehouse operator managing items and availability',
        ],
        'workflow-plan' => [
            'image_url' => '/images/landing/stock/workflow-plan.jpg',
            'alt_fr' => 'Professionnels qui relisent un plan avant execution',
            'alt_en' => 'Professionals reviewing a plan before execution',
        ],
    ];

    private const PAGE_SLOTS = [
        'sales-crm' => [
            'header' => 'desk-phone-laptop',
            'overview' => 'marketing-desk',
            'workflow' => 'collab-laptop-desk',
            'pages' => 'meeting-room-laptops',
        ],
        'reservations' => [
            'header' => 'service-tablet',
            'overview' => 'hero-tablet',
            'workflow' => 'team-laptop-window',
            'pages' => 'marketing-desk',
        ],
        'operations' => [
            'header' => 'service-team',
            'overview' => 'workflow-plan',
            'workflow' => 'field-checklist',
            'pages' => 'meeting-room-laptops',
        ],
        'commerce' => [
            'header' => 'store-worker',
            'overview' => 'store-payment',
            'workflow' => 'warehouse-worker',
            'pages' => 'store-boxes',
        ],
        'marketing-loyalty' => [
            'header' => 'marketing-desk',
            'overview' => 'desk-phone-laptop',
            'workflow' => 'team-laptop-window',
            'pages' => 'collab-laptop-desk',
        ],
        'ai-automation' => [
            'header' => 'collab-laptop-desk',
            'overview' => 'meeting-room-laptops',
            'workflow' => 'hero-tablet',
            'pages' => 'workflow-plan',
        ],
        'command-center' => [
            'header' => 'meeting-room-laptops',
            'overview' => 'service-team',
            'workflow' => 'desk-phone-laptop',
            'pages' => 'team-laptop-window',
        ],
        'solution-field-services' => [
            'header' => 'service-install',
            'overview' => 'field-checklist',
            'workflow' => 'service-team',
            'modules' => 'workflow-plan',
        ],
        'solution-reservations-queues' => [
            'header' => 'service-tablet',
            'overview' => 'hero-tablet',
            'workflow' => 'team-laptop-window',
            'modules' => 'marketing-desk',
        ],
        'solution-sales-quoting' => [
            'header' => 'desk-phone-laptop',
            'overview' => 'marketing-desk',
            'workflow' => 'collab-laptop-desk',
            'modules' => 'hero-tablet',
        ],
        'solution-commerce-catalog' => [
            'header' => 'store-worker',
            'overview' => 'warehouse-worker',
            'workflow' => 'store-boxes',
            'modules' => 'store-payment',
        ],
        'solution-marketing-loyalty' => [
            'header' => 'marketing-desk',
            'overview' => 'team-laptop-window',
            'workflow' => 'desk-phone-laptop',
            'modules' => 'collab-laptop-desk',
        ],
        'solution-multi-entity-oversight' => [
            'header' => 'meeting-room-laptops',
            'overview' => 'workflow-plan',
            'workflow' => 'service-team',
            'modules' => 'collab-laptop-desk',
        ],
        'industry-plumbing' => [
            'header' => 'workflow-plan',
            'overview' => 'service-install',
            'workflow' => 'field-checklist',
        ],
        'industry-hvac' => [
            'header' => 'service-install',
            'overview' => 'service-team',
            'workflow' => 'field-checklist',
        ],
        'industry-electrical' => [
            'header' => 'field-checklist',
            'overview' => 'workflow-plan',
            'workflow' => 'service-install',
        ],
        'industry-cleaning' => [
            'header' => 'service-team',
            'overview' => 'team-laptop-window',
            'workflow' => 'workflow-plan',
        ],
        'industry-salon-beauty' => [
            'header' => 'team-laptop-window',
            'overview' => 'hero-tablet',
            'workflow' => 'marketing-desk',
        ],
        'industry-restaurant' => [
            'header' => 'hero-team',
            'overview' => 'service-team',
            'workflow' => 'meeting-room-laptops',
        ],
        'contact-us' => [
            'header' => 'team-laptop-window',
            'overview' => 'collab-laptop-desk',
            'details' => 'desk-phone-laptop',
        ],
        'partners' => [
            'header' => 'collab-laptop-desk',
            'overview' => 'team-laptop-window',
        ],
    ];
}
