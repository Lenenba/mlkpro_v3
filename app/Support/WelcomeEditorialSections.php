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
            'kicker' => $isFrench ? 'Preuves concretes' : 'Visible proof',
            'title' => $isFrench
                ? 'Montrez des situations metier concretes avant meme d arriver aux tarifs'
                : 'Put concrete business situations in front of visitors before they ever reach pricing',
            'body' => $isFrench
                ? '<p>La page d accueil montre des moments de bureau, de coordination et de terrain qui racontent mieux la plateforme qu une pile de cartes abstraites.</p>'
                : '<p>The homepage now shows office, coordination, and field moments that fit the platform story better than another abstract card stack.</p>',
            'items' => $isFrench
                ? [
                    'Les modules arrivent avec un vrai contexte d usage.',
                    'Chaque bloc pousse vers une prochaine etape claire.',
                    'Les images aident a comprendre le parcours sans surcharger la page.',
                ]
                : [
                    'Modules land with real operating context instead of abstract copy.',
                    'Each block pushes toward a clear next step.',
                    'Distinct images make the journey easier to scan without clutter.',
                ],
            'image_url' => $image['image_url'],
            'image_alt' => $image['image_alt'],
            'aside_kicker' => $isFrench ? 'Un contexte plus clair' : 'Clearer context',
            'aside_title' => $isFrench
                ? 'L histoire de la plateforme passe mieux quand chaque image porte une etape differente'
                : 'The platform story lands better when each image carries a different step',
            'aside_body' => $isFrench
                ? '<p>Les visiteurs voient la presence commerciale, la coordination interne et l execution terrain dans des blocs distincts, ce qui rend le prochain clic plus evident.</p>'
                : '<p>Visitors see sales presence, internal coordination, and field execution in separate moments, which makes the next click feel more obvious.</p>',
            'aside_items' => $isFrench
                ? [
                    'Le commerce et la monetisation apparaissent avec un vrai contexte.',
                    'Les modules ne donnent plus l impression d une liste uniforme.',
                    'Le lien entre equipes, planning et supervision devient plus clair.',
                ]
                : [
                    'Commerce and monetization appear inside a real business moment.',
                    'Modules no longer feel like one uniform list.',
                    'The link between teams, scheduling, and oversight becomes easier to read.',
                ],
            'aside_link_label' => $isFrench ? 'Voir Command Center' : 'See Command Center',
            'aside_link_href' => '/pages/command-center',
            'aside_image_url' => $asideImage['image_url'],
            'aside_image_alt' => $asideImage['image_alt'],
            'primary_label' => $isFrench ? 'Voir les solutions' : 'See solutions',
            'primary_href' => '/pages/solution-field-services',
            'secondary_label' => $isFrench ? 'Voir les modules' : 'See modules',
            'secondary_href' => '/pricing',
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
                ? 'Essayez gratuitement. Voyez comment ca s adapte a votre operation.'
                : 'Try it free. See how it fits your operation.',
            'body' => $isFrench
                ? '<p>Decouvrez la plateforme, les modules et le parcours public dans un bloc simple qui pousse vers une prochaine action claire.</p>'
                : '<p>Explore the platform, the modules, and the public journey inside one simple block that drives toward a clear next action.</p>',
            'image_url' => $image['image_url'],
            'image_alt' => $image['image_alt'],
            'primary_label' => $isFrench ? 'Creer un compte' : 'Get started free',
            'primary_href' => '/onboarding',
            'secondary_label' => $isFrench ? 'Voir les modules' : 'Watch product tour',
            'secondary_href' => '/pricing',
            'aside_link_label' => $isFrench ? 'Voir la demo' : 'See the demo',
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
                ? 'Fier partenaire des pros du service dans plus de 50 industries.'
                : 'Proud partner to service pros in over 50 industries.',
            'industry_cards' => $isFrench
                ? [
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
