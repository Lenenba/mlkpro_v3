<?php

namespace App\Support;

class WelcomeEditorialSections
{
    private const SECTION_COPY = [
        'proof_feature_pairs' => [
            'fr' => [
                'kicker' => 'Des moments operationnels concrets',
                'title' => 'La plateforme se comprend mieux quand on voit comment le travail avance vraiment',
                'body' => '<p>Malikia Pro est pense pour les moments qui comptent dans l experience client: suivi commercial, coordination, planning, execution et paiement.</p>',
                'items' => [
                    'Gardez ventes, operations et facturation connectees.',
                    'Donnez a chaque equipe une prochaine etape plus claire.',
                    'Reduisez la friction entre les passages de relais.',
                ],
                'aside_kicker' => 'Pourquoi c est important',
                'aside_title' => 'Un vrai contexte operationnel inspire plus vite confiance qu une promesse abstraite',
                'aside_body' => '<p>Quand la page montre de vraies situations metier, les visiteurs comprennent plus vite comment la plateforme peut s integrer a leur equipe avant meme de comparer les logiciels.</p>',
                'aside_items' => [
                    'Plus facile de comprendre ce qui se passe apres le devis.',
                    'Plus facile de voir le lien entre bureau et execution.',
                    'Plus facile de faire confiance a la plateforme comme systeme de pilotage.',
                ],
                'aside_link_label' => 'Voir les tarifs',
                'primary_label' => 'Voir la solution terrain',
                'secondary_label' => 'Voir Command Center',
            ],
            'es' => [
                'kicker' => 'Momentos operativos reales',
                'title' => 'La plataforma tiene mas sentido cuando se ve como avanza realmente el trabajo',
                'body' => '<p>Malikia Pro esta pensado para los momentos que definen la experiencia del cliente: seguimiento, coordinacion, planificacion, ejecucion y pago.</p>',
                'items' => [
                    'Mantiene conectadas ventas, operaciones y facturacion.',
                    'Da a cada equipo un siguiente paso mas claro.',
                    'Reduce la friccion en los traspasos del flujo.',
                ],
                'aside_kicker' => 'Por que importa',
                'aside_title' => 'El contexto operativo genera confianza mas rapido que las promesas abstractas',
                'aside_body' => '<p>Cuando la pagina muestra situaciones reales del negocio, los prospectos entienden mas rapido como la plataforma puede integrarse en su equipo antes incluso de comparar software.</p>',
                'aside_items' => [
                    'Es mas facil entender que pasa despues de la cotizacion.',
                    'Es mas facil ver el vinculo entre oficina y ejecucion.',
                    'Es mas facil confiar en la plataforma como un sistema serio de gestion.',
                ],
                'aside_link_label' => 'Ver precios',
                'primary_label' => 'Ver servicios de campo',
                'secondary_label' => 'Ver Command Center',
            ],
            'en' => [
                'kicker' => 'Real operating moments',
                'title' => 'The platform makes more sense when people can see how work actually moves',
                'body' => '<p>Malikia Pro is built for the moments that shape the customer experience: follow-up, coordination, scheduling, execution, and payment.</p>',
                'items' => [
                    'Keep sales, operations, and billing connected.',
                    'Give every team a clearer next step.',
                    'Reduce handoff friction across the workflow.',
                ],
                'aside_kicker' => 'Why it matters',
                'aside_title' => 'Operational context builds trust faster than abstract feature claims',
                'aside_body' => '<p>When the page shows real business situations, prospects can picture how the platform fits their team before they ever compare software.</p>',
                'aside_items' => [
                    'Easier to understand what happens after the quote.',
                    'Easier to see the link between office and execution.',
                    'Easier to trust the product as a serious operating system.',
                ],
                'aside_link_label' => 'View pricing',
                'primary_label' => 'See Field Services',
                'secondary_label' => 'See Command Center',
            ],
        ],
        'editorial_showcase' => [
            'fr' => [
                'title' => 'Commencez simplement aujourd hui, puis evoluez a mesure que l activite grandit',
                'body' => '<p>Explorez la plateforme avec une prochaine etape claire, que vous vouliez demarrer un essai, comparer les offres ou demander une demonstration.</p>',
                'primary_label' => 'Demarrer l essai',
                'secondary_label' => 'Voir les tarifs',
                'aside_link_label' => 'Demander une demo',
            ],
            'es' => [
                'title' => 'Empieza de forma simple hoy y amplia a medida que crece tu operacion',
                'body' => '<p>Explora la plataforma con un siguiente paso mas claro, tanto si quieres iniciar una prueba, comparar planes o solicitar una demostracion mas guiada.</p>',
                'primary_label' => 'Empezar prueba',
                'secondary_label' => 'Ver precios',
                'aside_link_label' => 'Solicitar una demo',
            ],
            'en' => [
                'title' => 'Start simple now, then expand as your operation grows',
                'body' => '<p>Explore the platform with a clearer next step, whether you want to start a trial, compare plans, or book a closer walkthrough.</p>',
                'primary_label' => 'Start trial',
                'secondary_label' => 'View pricing',
                'aside_link_label' => 'Book a demo',
            ],
        ],
        'industry_grid' => [
            'fr' => [
                'title' => 'Concu pour des entreprises de services avec des realites operationnelles variees.',
                'industry_cards' => [
                    ['id' => 'industry-arborists', 'label' => 'Arboristes', 'href' => '', 'icon' => 'tree-pine'],
                    ['id' => 'industry-commercial-cleaning', 'label' => 'Nettoyage commercial', 'href' => '/pages/industry-cleaning', 'icon' => 'brush-cleaning'],
                    ['id' => 'industry-construction', 'label' => 'Construction & entrepreneurs', 'href' => '', 'icon' => 'construction'],
                    ['id' => 'industry-electrical', 'label' => 'Entrepreneur electrique', 'href' => '/pages/industry-electrical', 'icon' => 'plug-zap'],
                    ['id' => 'industry-hvac', 'label' => 'HVAC', 'href' => '/pages/industry-hvac', 'icon' => 'fan'],
                    ['id' => 'industry-handyman', 'label' => 'Homme a tout faire', 'href' => '', 'icon' => 'wrench'],
                    ['id' => 'industry-landscaping', 'label' => 'Amenagement paysager', 'href' => '', 'icon' => 'shovel'],
                    ['id' => 'industry-lawn-care', 'label' => 'Entretien de pelouse', 'href' => '', 'icon' => 'leaf'],
                    ['id' => 'industry-painting', 'label' => 'Peinture', 'href' => '', 'icon' => 'paint-roller'],
                    ['id' => 'industry-plumbing', 'label' => 'Plomberie', 'href' => '/pages/industry-plumbing', 'icon' => 'shower-head'],
                    ['id' => 'industry-residential-cleaning', 'label' => 'Nettoyage residentiel', 'href' => '/pages/industry-cleaning', 'icon' => 'sparkles'],
                    ['id' => 'industry-roofing', 'label' => 'Toiture', 'href' => '', 'icon' => 'house'],
                ],
            ],
            'es' => [
                'title' => 'Disenado para negocios de servicios con realidades operativas distintas.',
                'industry_cards' => [
                    ['id' => 'industry-arborists', 'label' => 'Arboristas', 'href' => '', 'icon' => 'tree-pine'],
                    ['id' => 'industry-commercial-cleaning', 'label' => 'Limpieza comercial', 'href' => '/pages/industry-cleaning', 'icon' => 'brush-cleaning'],
                    ['id' => 'industry-construction', 'label' => 'Construccion y contratistas', 'href' => '', 'icon' => 'construction'],
                    ['id' => 'industry-electrical', 'label' => 'Contratista electrico', 'href' => '/pages/industry-electrical', 'icon' => 'plug-zap'],
                    ['id' => 'industry-hvac', 'label' => 'HVAC', 'href' => '/pages/industry-hvac', 'icon' => 'fan'],
                    ['id' => 'industry-handyman', 'label' => 'Manitas', 'href' => '', 'icon' => 'wrench'],
                    ['id' => 'industry-landscaping', 'label' => 'Paisajismo', 'href' => '', 'icon' => 'shovel'],
                    ['id' => 'industry-lawn-care', 'label' => 'Cuidado del cesped', 'href' => '', 'icon' => 'leaf'],
                    ['id' => 'industry-painting', 'label' => 'Pintura', 'href' => '', 'icon' => 'paint-roller'],
                    ['id' => 'industry-plumbing', 'label' => 'Fontaneria', 'href' => '/pages/industry-plumbing', 'icon' => 'shower-head'],
                    ['id' => 'industry-residential-cleaning', 'label' => 'Limpieza residencial', 'href' => '/pages/industry-cleaning', 'icon' => 'sparkles'],
                    ['id' => 'industry-roofing', 'label' => 'Techado', 'href' => '', 'icon' => 'house'],
                ],
            ],
            'en' => [
                'title' => 'Designed for service businesses with different operating realities.',
                'industry_cards' => [
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
            ],
        ],
    ];

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
        $copy = self::sectionCopy('proof_feature_pairs', $locale);
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
            'kicker' => $copy['kicker'],
            'title' => $copy['title'],
            'body' => $copy['body'],
            'items' => $copy['items'],
            'image_url' => $image['image_url'],
            'image_alt' => $image['image_alt'],
            'aside_kicker' => $copy['aside_kicker'],
            'aside_title' => $copy['aside_title'],
            'aside_body' => $copy['aside_body'],
            'aside_items' => $copy['aside_items'],
            'aside_link_label' => $copy['aside_link_label'],
            'aside_link_href' => '/pricing',
            'aside_image_url' => $asideImage['image_url'],
            'aside_image_alt' => $asideImage['image_alt'],
            'primary_label' => $copy['primary_label'],
            'primary_href' => '/pages/solution-field-services',
            'secondary_label' => $copy['secondary_label'],
            'secondary_href' => '/pages/command-center',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function editorialShowcase(string $locale): array
    {
        $copy = self::sectionCopy('editorial_showcase', $locale);
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
            'title' => $copy['title'],
            'body' => $copy['body'],
            'image_url' => $image['image_url'],
            'image_alt' => $image['image_alt'],
            'primary_label' => $copy['primary_label'],
            'primary_href' => '/onboarding',
            'secondary_label' => $copy['secondary_label'],
            'secondary_href' => '/pricing',
            'aside_link_label' => $copy['aside_link_label'],
            'aside_link_href' => '/demo',
            'showcase_divider_style' => 'notch',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function industryGrid(string $locale): array
    {
        $copy = self::sectionCopy('industry_grid', $locale);

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
            'title' => $copy['title'],
            'industry_cards' => $copy['industry_cards'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function sectionCopy(string $key, string $locale): array
    {
        $resolvedLocale = PublicPageStockImages::normalizeLocale($locale);
        $copySet = self::SECTION_COPY[$key] ?? [];

        return $copySet[$resolvedLocale] ?? $copySet['en'] ?? [];
    }
}
