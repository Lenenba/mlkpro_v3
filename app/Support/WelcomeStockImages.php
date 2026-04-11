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

        if ($locale === 'es') {
            return [
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

        if ($locale === 'es') {
            return [
                'image_url' => self::WORKFLOW_PLAN,
                'image_alt' => 'Profesionales revisando planos en una obra',
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

        if ($locale === 'es') {
            return [
                'image_url' => self::FIELD_CHECKLIST,
                'image_alt' => 'Tecnico de campo con una lista de control en el lugar',
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
                : ($locale === 'es'
                    ? [
                        'image_url' => self::MARKETING_DESK,
                        'image_alt' => 'Profesional gestionando mensajes y solicitudes desde su escritorio',
                    ]
                    : [
                        'image_url' => self::MARKETING_DESK,
                        'image_alt' => 'Professional handling messages and requests from a desk',
                    ]),
            'win_jobs' => $locale === 'fr'
                ? [
                    'image_url' => self::WORKFLOW_PLAN,
                    'image_alt' => 'Deux professionnels qui valident des plans de chantier',
                ]
                : ($locale === 'es'
                    ? [
                        'image_url' => self::WORKFLOW_PLAN,
                        'image_alt' => 'Dos profesionales validando juntos planos de trabajo',
                    ]
                    : [
                        'image_url' => self::WORKFLOW_PLAN,
                        'image_alt' => 'Two professionals validating job plans together',
                    ]),
            'work_smarter' => $locale === 'fr'
                ? [
                    'image_url' => self::FIELD_CHECKLIST,
                    'image_alt' => 'Technicien avec checklist pret pour l intervention',
                ]
                : ($locale === 'es'
                    ? [
                        'image_url' => self::FIELD_CHECKLIST,
                        'image_alt' => 'Tecnico con una lista de control antes de una visita en el lugar',
                    ]
                    : [
                        'image_url' => self::FIELD_CHECKLIST,
                        'image_alt' => 'Technician holding a checklist before an on-site visit',
                    ]),
            'boost_profits' => $locale === 'fr'
                ? [
                    'image_url' => self::PAYMENTS_TERMINAL,
                    'image_alt' => 'Paiement par carte sur terminal au milieu des outils d intervention',
                ]
                : ($locale === 'es'
                    ? [
                        'image_url' => self::PAYMENTS_TERMINAL,
                        'image_alt' => 'Pago con tarjeta en un terminal junto a herramientas de servicio',
                    ]
                    : [
                        'image_url' => self::PAYMENTS_TERMINAL,
                        'image_alt' => 'Card payment on a terminal beside service tools',
                    ]),
            default => $locale === 'fr'
                ? [
                    'image_url' => self::HERO_TEAM,
                    'image_alt' => 'Equipe terrain sur chantier',
                ]
                : ($locale === 'es'
                    ? [
                        'image_url' => self::HERO_TEAM,
                        'image_alt' => 'Equipo de campo en el sitio',
                    ]
                    : [
                        'image_url' => self::HERO_TEAM,
                        'image_alt' => 'Field team on site',
                    ]),
        };
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
}
