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

    public static function normalizeLocale(?string $locale): string
    {
        return str_starts_with(strtolower((string) $locale), 'fr') ? 'fr' : 'en';
    }

    public static function heroSlides(?string $locale = 'fr'): array
    {
        $locale = self::normalizeLocale($locale);

        if ($locale === 'fr') {
            return [
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
            ];
        }

        return [
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
        ];
    }

    public static function heroImage(?string $locale = 'fr'): array
    {
        return self::heroSlides($locale)[0];
    }

    public static function workflowImage(?string $locale = 'fr'): array
    {
        $locale = self::normalizeLocale($locale);

        if ($locale === 'fr') {
            return [
                'image_url' => self::WORKFLOW_PLAN,
                'image_alt' => 'Professionnels qui relisent des plans sur un chantier',
            ];
        }

        return [
            'image_url' => self::WORKFLOW_PLAN,
            'image_alt' => 'Professionals reviewing plans on a job site',
        ];
    }

    public static function fieldImage(?string $locale = 'fr'): array
    {
        $locale = self::normalizeLocale($locale);

        if ($locale === 'fr') {
            return [
                'image_url' => self::FIELD_CHECKLIST,
                'image_alt' => 'Technicien terrain avec checklist sur place',
            ];
        }

        return [
            'image_url' => self::FIELD_CHECKLIST,
            'image_alt' => 'Field technician holding an on-site checklist',
        ];
    }

    public static function showcaseImage(string $key, ?string $locale = 'fr'): array
    {
        $locale = self::normalizeLocale($locale);

        return match ($key) {
            'get_noticed' => $locale === 'fr'
                ? [
                    'image_url' => self::MARKETING_DESK,
                    'image_alt' => 'Professionnelle gerant messages et demandes depuis son poste de travail',
                ]
                : [
                    'image_url' => self::MARKETING_DESK,
                    'image_alt' => 'Professional handling messages and requests from a desk',
                ],
            'win_jobs' => $locale === 'fr'
                ? [
                    'image_url' => self::WORKFLOW_PLAN,
                    'image_alt' => 'Deux professionnels qui valident des plans de chantier',
                ]
                : [
                    'image_url' => self::WORKFLOW_PLAN,
                    'image_alt' => 'Two professionals validating job plans together',
                ],
            'work_smarter' => $locale === 'fr'
                ? [
                    'image_url' => self::FIELD_CHECKLIST,
                    'image_alt' => 'Technicien avec checklist pret pour l intervention',
                ]
                : [
                    'image_url' => self::FIELD_CHECKLIST,
                    'image_alt' => 'Technician holding a checklist before an on-site visit',
                ],
            'boost_profits' => $locale === 'fr'
                ? [
                    'image_url' => self::PAYMENTS_TERMINAL,
                    'image_alt' => 'Paiement par carte sur terminal au milieu des outils d intervention',
                ]
                : [
                    'image_url' => self::PAYMENTS_TERMINAL,
                    'image_alt' => 'Card payment on a terminal beside service tools',
                ],
            default => $locale === 'fr'
                ? [
                    'image_url' => self::HERO_TEAM,
                    'image_alt' => 'Equipe terrain sur chantier',
                ]
                : [
                    'image_url' => self::HERO_TEAM,
                    'image_alt' => 'Field team on site',
                ],
        };
    }
}
