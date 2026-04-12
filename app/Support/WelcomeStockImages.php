<?php

namespace App\Support;

class WelcomeStockImages
{
    public const HERO_TEAM = '/images/landing/stock/hero-team.jpg';

    public const HERO_TABLET = '/images/landing/stock/hero-tablet.jpg';

    public const WORKFLOW_PLAN = '/images/landing/stock/workflow-plan.jpg';

    public const FIELD_CHECKLIST = '/images/landing/stock/field-checklist.jpg';

    public const MARKETING_DESK = '/images/landing/stock/marketing-desk.jpg';

    public const PAYMENTS_TERMINAL = '/images/landing/stock/payments-terminal.jpg';

    private const HERO_SLIDES = [
        'fr' => [
            [
                'image_url' => self::HERO_TEAM,
                'image_alt' => 'Equipe de chantier en coordination sur site',
            ],
            [
                'image_url' => self::HERO_TABLET,
                'image_alt' => 'Responsable de chantier consultant une tablette sur site',
            ],
            [
                'image_url' => self::WORKFLOW_PLAN,
                'image_alt' => 'Deux professionnels relisent des plans dans un chantier interieur',
            ],
        ],
        'es' => [
            [
                'image_url' => self::HERO_TEAM,
                'image_alt' => 'Equipo de campo coordinandose en el sitio',
            ],
            [
                'image_url' => self::HERO_TABLET,
                'image_alt' => 'Responsable en obra revisando una tableta en el lugar',
            ],
            [
                'image_url' => self::WORKFLOW_PLAN,
                'image_alt' => 'Dos profesionales revisando planos dentro de una obra',
            ],
        ],
        'en' => [
            [
                'image_url' => self::HERO_TEAM,
                'image_alt' => 'Field crew coordinating on site',
            ],
            [
                'image_url' => self::HERO_TABLET,
                'image_alt' => 'Site lead reviewing a tablet on location',
            ],
            [
                'image_url' => self::WORKFLOW_PLAN,
                'image_alt' => 'Two professionals reviewing plans inside a job site',
            ],
        ],
    ];

    private const IMAGE_DEFINITIONS = [
        'workflow' => [
            'image_url' => self::WORKFLOW_PLAN,
            'alt' => [
                'fr' => 'Professionnels qui relisent des plans sur un chantier',
                'es' => 'Profesionales revisando planos en una obra',
                'en' => 'Professionals reviewing plans on a job site',
            ],
        ],
        'field' => [
            'image_url' => self::FIELD_CHECKLIST,
            'alt' => [
                'fr' => 'Technicien terrain avec checklist sur place',
                'es' => 'Tecnico de campo con una lista de control en el lugar',
                'en' => 'Field technician holding an on-site checklist',
            ],
        ],
        'get_noticed' => [
            'image_url' => self::MARKETING_DESK,
            'alt' => [
                'fr' => 'Professionnelle gerant messages et demandes depuis son poste de travail',
                'es' => 'Profesional gestionando mensajes y solicitudes desde su escritorio',
                'en' => 'Professional handling messages and requests from a desk',
            ],
        ],
        'win_jobs' => [
            'image_url' => self::WORKFLOW_PLAN,
            'alt' => [
                'fr' => 'Deux professionnels qui valident des plans de chantier',
                'es' => 'Dos profesionales validando juntos planos de trabajo',
                'en' => 'Two professionals validating job plans together',
            ],
        ],
        'work_smarter' => [
            'image_url' => self::FIELD_CHECKLIST,
            'alt' => [
                'fr' => 'Technicien avec checklist pret pour l intervention',
                'es' => 'Tecnico con una lista de control antes de una visita en el lugar',
                'en' => 'Technician holding a checklist before an on-site visit',
            ],
        ],
        'boost_profits' => [
            'image_url' => self::PAYMENTS_TERMINAL,
            'alt' => [
                'fr' => 'Paiement par carte sur terminal au milieu des outils d intervention',
                'es' => 'Pago con tarjeta en un terminal junto a herramientas de servicio',
                'en' => 'Card payment on a terminal beside service tools',
            ],
        ],
        'default' => [
            'image_url' => self::HERO_TEAM,
            'alt' => [
                'fr' => 'Equipe terrain sur chantier',
                'es' => 'Equipo de campo en el sitio',
                'en' => 'Field team on site',
            ],
        ],
    ];

    public static function normalizeLocale(?string $locale): string
    {
        $value = strtolower((string) $locale);

        if (str_starts_with($value, 'fr')) {
            return 'fr';
        }

        if (str_starts_with($value, 'es')) {
            return 'es';
        }

        return 'en';
    }

    public static function heroSlides(?string $locale = 'fr'): array
    {
        $resolvedLocale = self::normalizeLocale($locale);

        return self::HERO_SLIDES[$resolvedLocale] ?? self::HERO_SLIDES['en'];
    }

    public static function heroImage(?string $locale = 'fr'): array
    {
        return self::heroSlides($locale)[0];
    }

    public static function workflowImage(?string $locale = 'fr'): array
    {
        return self::localizedImage(self::IMAGE_DEFINITIONS['workflow'], $locale);
    }

    public static function fieldImage(?string $locale = 'fr'): array
    {
        return self::localizedImage(self::IMAGE_DEFINITIONS['field'], $locale);
    }

    public static function showcaseImage(string $key, ?string $locale = 'fr'): array
    {
        $definition = self::IMAGE_DEFINITIONS[$key] ?? self::IMAGE_DEFINITIONS['default'];

        return self::localizedImage($definition, $locale);
    }

    /**
     * @return array<int, string>
     */
    public static function libraryImageUrls(): array
    {
        return array_values(array_unique([
            self::HERO_TEAM,
            self::HERO_TABLET,
            self::WORKFLOW_PLAN,
            self::FIELD_CHECKLIST,
            self::MARKETING_DESK,
            self::PAYMENTS_TERMINAL,
        ]));
    }

    /**
     * @param  array{alt:array<string, string>,image_url:string}  $definition
     * @return array{image_alt:string,image_url:string}
     */
    private static function localizedImage(array $definition, ?string $locale = 'fr'): array
    {
        $resolvedLocale = self::normalizeLocale($locale);
        $alts = $definition['alt'] ?? [];

        return [
            'image_url' => $definition['image_url'],
            'image_alt' => $alts[$resolvedLocale] ?? $alts['en'] ?? '',
        ];
    }
}
