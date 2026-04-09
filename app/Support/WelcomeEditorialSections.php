<?php

namespace App\Support;

class WelcomeEditorialSections
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function genericSections(string $locale): array
    {
        return [
            self::proofFeaturePairs($locale),
            self::editorialShowcase($locale),
            self::industryGrid($locale),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function forId(string $id, string $locale): ?array
    {
        return match (trim($id)) {
            'welcome-proof-feature-pairs' => self::proofFeaturePairs($locale),
            'welcome-editorial-showcase' => self::editorialShowcase($locale),
            'welcome-industries-grid' => self::industryGrid($locale),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function proofFeaturePairs(string $locale): array
    {
        $isFrench = self::isFrench($locale);
        $image = PublicPageStockImages::visual('service-tablet', $locale);
        $asideImage = PublicPageStockImages::visual('office-collaboration', $locale);

        return [
            'id' => 'welcome-proof-feature-pairs',
            'enabled' => true,
            'source_id' => null,
            'use_source' => false,
            'background_color' => '#ffffff',
            'layout' => 'feature_pairs',
            'image_position' => 'left',
            'alignment' => 'left',
            'density' => 'normal',
            'tone' => 'default',
            'kicker' => $isFrench ? 'Des moments opérationnels concrets' : 'Real operating moments',
            'title' => $isFrench
                ? 'La plateforme se comprend mieux quand on voit comment le travail avance vraiment'
                : 'The platform makes more sense when people can see how work actually moves',
            'body' => $isFrench
                ? '<p>Malikia Pro est pensé pour les moments qui comptent dans l’expérience client: suivi commercial, coordination, planning, exécution et paiement.</p>'
                : '<p>Malikia Pro is built for the moments that shape the customer experience: follow-up, coordination, scheduling, execution, and payment.</p>',
            'items' => $isFrench
                ? [
                    'Gardez ventes, opérations et facturation connectées.',
                    'Donnez à chaque équipe une prochaine étape plus claire.',
                    'Réduisez la friction entre les passages de relais.',
                ]
                : [
                    'Keep sales, operations, and billing connected.',
                    'Give every team a clearer next step.',
                    'Reduce handoff friction across the workflow.',
                ],
            'image_url' => $image['image_url'],
            'image_alt' => $image['image_alt'],
            'aside_kicker' => $isFrench ? "Pourquoi c'est important" : 'Why it matters',
            'aside_title' => $isFrench
                ? 'Un vrai contexte opérationnel inspire plus vite confiance qu’une promesse abstraite'
                : 'Operational context builds trust faster than abstract feature claims',
            'aside_body' => $isFrench
                ? '<p>Quand la page montre de vraies situations métier, les visiteurs comprennent plus vite comment la plateforme peut s’intégrer à leur équipe avant même de comparer les logiciels.</p>'
                : '<p>When the page shows real business situations, prospects can picture how the platform fits their team before they ever compare software.</p>',
            'aside_items' => $isFrench
                ? [
                    'Plus facile de comprendre ce qui se passe après le devis.',
                    'Plus facile de voir le lien entre bureau et exécution.',
                    'Plus facile de faire confiance à la plateforme comme système de pilotage.',
                ]
                : [
                    'Easier to understand what happens after the quote.',
                    'Easier to see the link between office and execution.',
                    'Easier to trust the product as a serious operating system.',
                ],
            'aside_link_label' => $isFrench ? 'Voir les tarifs' : 'View pricing',
            'aside_link_href' => '/pricing',
            'aside_image_url' => $asideImage['image_url'],
            'aside_image_alt' => $asideImage['image_alt'],
            'primary_label' => $isFrench ? 'Voir la solution terrain' : 'See Field Services',
            'primary_href' => '/pages/solution-field-services',
            'secondary_label' => $isFrench ? 'Voir Command Center' : 'See Command Center',
            'secondary_href' => '/pages/command-center',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function editorialShowcase(string $locale): array
    {
        $isFrench = self::isFrench($locale);
        $image = PublicPageStockImages::visual('warehouse-worker', $locale);

        return [
            'id' => 'welcome-editorial-showcase',
            'enabled' => true,
            'source_id' => null,
            'use_source' => false,
            'background_preset' => 'welcome-hero',
            'layout' => 'showcase_cta',
            'image_position' => 'right',
            'alignment' => 'left',
            'density' => 'normal',
            'tone' => 'contrast',
            'title' => $isFrench
                ? "Commencez simplement aujourd'hui, puis évoluez à mesure que l'activité grandit"
                : 'Start simple now, then expand as your operation grows',
            'body' => $isFrench
                ? '<p>Explorez la plateforme avec une prochaine étape claire, que vous vouliez commencer gratuitement, comparer les offres ou demander une démonstration.</p>'
                : '<p>Explore the platform with a clearer next step, whether you want to start free, compare plans, or book a closer walkthrough.</p>',
            'image_url' => $image['image_url'],
            'image_alt' => $image['image_alt'],
            'primary_label' => $isFrench ? 'Commencer gratuitement' : 'Start free',
            'primary_href' => '/onboarding',
            'secondary_label' => $isFrench ? 'Voir les tarifs' : 'View pricing',
            'secondary_href' => '/pricing',
            'aside_link_label' => $isFrench ? 'Demander une démo' : 'Book a demo',
            'aside_link_href' => '/demo',
            'showcase_divider_style' => 'notch',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function industryGrid(string $locale): array
    {
        $isFrench = self::isFrench($locale);

        return [
            'id' => 'welcome-industries-grid',
            'enabled' => true,
            'source_id' => null,
            'use_source' => false,
            'background_color' => '#f7f2e8',
            'layout' => 'industry_grid',
            'alignment' => 'center',
            'density' => 'normal',
            'tone' => 'default',
            'title' => $isFrench
                ? 'Conçu pour des entreprises de services avec des réalités opérationnelles variées.'
                : 'Designed for service businesses with different operating realities.',
            'industry_cards' => $isFrench
                ? [
                    ['id' => 'industry-arborists', 'label' => 'Arboristes', 'href' => '', 'icon' => 'tree-pine'],
                    ['id' => 'industry-commercial-cleaning', 'label' => 'Nettoyage commercial', 'href' => '/pages/industry-cleaning', 'icon' => 'brush-cleaning'],
                    ['id' => 'industry-construction', 'label' => 'Construction & entrepreneurs', 'href' => '', 'icon' => 'construction'],
                    ['id' => 'industry-electrical', 'label' => 'Entrepreneur électrique', 'href' => '/pages/industry-electrical', 'icon' => 'plug-zap'],
                    ['id' => 'industry-hvac', 'label' => 'HVAC', 'href' => '/pages/industry-hvac', 'icon' => 'fan'],
                    ['id' => 'industry-handyman', 'label' => 'Homme à tout faire', 'href' => '', 'icon' => 'wrench'],
                    ['id' => 'industry-landscaping', 'label' => 'Aménagement paysager', 'href' => '', 'icon' => 'shovel'],
                    ['id' => 'industry-lawn-care', 'label' => 'Entretien de pelouse', 'href' => '', 'icon' => 'leaf'],
                    ['id' => 'industry-painting', 'label' => 'Peinture', 'href' => '', 'icon' => 'paint-roller'],
                    ['id' => 'industry-plumbing', 'label' => 'Plomberie', 'href' => '/pages/industry-plumbing', 'icon' => 'shower-head'],
                    ['id' => 'industry-residential-cleaning', 'label' => 'Nettoyage résidentiel', 'href' => '/pages/industry-cleaning', 'icon' => 'sparkles'],
                    ['id' => 'industry-roofing', 'label' => 'Toiture', 'href' => '', 'icon' => 'house'],
                ]
                : [
                    ['id' => 'industry-arborists', 'label' => 'Arborists', 'href' => '', 'icon' => 'tree-pine'],
                    ['id' => 'industry-commercial-cleaning', 'label' => 'Commercial Cleaning', 'href' => '/pages/industry-cleaning', 'icon' => 'brush-cleaning'],
                    ['id' => 'industry-construction', 'label' => 'Construction & Contractors', 'href' => '', 'icon' => 'construction'],
                    ['id' => 'industry-electrical', 'label' => 'Electrical Contractor', 'href' => '/pages/industry-electrical', 'icon' => 'plug-zap'],
                    ['id' => 'industry-hvac', 'label' => 'HVAC', 'href' => '/pages/industry-hvac', 'icon' => 'fan'],
                    ['id' => 'industry-handyman', 'label' => 'Handyman', 'href' => '', 'icon' => 'wrench'],
                    ['id' => 'industry-landscaping', 'label' => 'Landscaping', 'href' => '', 'icon' => 'shovel'],
                    ['id' => 'industry-lawn-care', 'label' => 'Lawn Care', 'href' => '', 'icon' => 'leaf'],
                    ['id' => 'industry-painting', 'label' => 'Painting', 'href' => '', 'icon' => 'paint-roller'],
                    ['id' => 'industry-plumbing', 'label' => 'Plumbing', 'href' => '/pages/industry-plumbing', 'icon' => 'shower-head'],
                    ['id' => 'industry-residential-cleaning', 'label' => 'Residential Cleaning', 'href' => '/pages/industry-cleaning', 'icon' => 'sparkles'],
                    ['id' => 'industry-roofing', 'label' => 'Roofing', 'href' => '', 'icon' => 'house'],
                ],
        ];
    }

    private static function isFrench(string $locale): bool
    {
        return str_starts_with(strtolower(trim($locale)), 'fr');
    }
}
