<?php

namespace App\Services\Demo\Scenarios\StudioNaya;

final class StudioNayaBlueprint
{
    public const KEY = 'studio_naya_coiffure';

    public const DEFAULT_VOLUME = 'medium';

    /**
     * Targets introduced with the immersive offers and customer-package story.
     *
     * @var list<string>
     */
    public const IMMERSIVE_TARGET_KEYS = [
        'offer_packages',
        'offer_package_items',
        'pack_invoice_lines',
        'customer_packages',
        'customer_package_usages',
        'package_behavior_events',
        'loyalty_story_events',
    ];

    /**
     * Keep these version-one additions available to a long-running worker that
     * booted before its configuration cache contained the new target keys.
     *
     * @return array<string, int>
     */
    public static function immersiveTargetsForVolume(string $volume): array
    {
        return match ($volume) {
            'small' => [
                'offer_packages' => 7,
                'offer_package_items' => 20,
                'pack_invoice_lines' => 6,
                'customer_packages' => 12,
                'customer_package_usages' => 40,
                'package_behavior_events' => 18,
                'loyalty_story_events' => 3,
            ],
            'medium' => [
                'offer_packages' => 7,
                'offer_package_items' => 20,
                'pack_invoice_lines' => 18,
                'customer_packages' => 36,
                'customer_package_usages' => 118,
                'package_behavior_events' => 54,
                'loyalty_story_events' => 3,
            ],
            'large' => [
                'offer_packages' => 7,
                'offer_package_items' => 20,
                'pack_invoice_lines' => 45,
                'customer_packages' => 88,
                'customer_package_usages' => 287,
                'package_behavior_events' => 132,
                'loyalty_story_events' => 3,
            ],
            default => throw new \InvalidArgumentException("Unsupported Studio Naya data volume [{$volume}]."),
        };
    }

    public static function definition(): array
    {
        return [
            'key' => self::KEY,
            'version' => 1,
            'default_volume' => self::DEFAULT_VOLUME,
            'identity' => self::identity(),
            'employees' => self::employees(),
            'service_categories' => self::serviceCategories(),
            'services' => self::services(),
            'suppliers' => self::suppliers(),
            'products' => self::products(),
            'offer_packages' => self::offerPackages(),
            'employee_service_matrix' => self::employeeServiceMatrix(),
            'client_stories' => self::clientStories(),
            'expense_templates' => self::expenseTemplates(),
            'seasonality' => self::seasonality(),
            'payment_methods' => self::paymentMethods(),
        ];
    }

    public static function identity(): array
    {
        return [
            'name' => 'Studio Naya Coiffure',
            'legal_name' => 'Studio Naya Coiffure inc.',
            'category_key' => 'hair_salon',
            'category_label' => 'Salon de coiffure',
            'primary_locale' => 'fr_CA',
            'currency_code' => 'CAD',
            'timezone' => 'America/Toronto',
            'operating_history_months' => 18,
            'tax_profile' => 'tenant_configured',
            'address' => [
                'line_1' => '4827, rue de l’Aurore',
                'city' => 'Montréal',
                'province' => 'QC',
                'postal_code' => 'H2T 2M4',
                'country_code' => 'CA',
            ],
            'phone' => '+1 514 555 0148',
            'email' => 'bonjour@studio-naya.example',
            'logo' => [
                'strategy' => 'generated_placeholder',
                'initials' => 'SN',
                'background_color' => '#5B3A70',
                'foreground_color' => '#FFF8F0',
            ],
            'business_hours' => [
                1 => null,
                2 => ['opens_at' => '09:00', 'closes_at' => '18:00'],
                3 => ['opens_at' => '09:00', 'closes_at' => '18:00'],
                4 => ['opens_at' => '09:00', 'closes_at' => '20:00'],
                5 => ['opens_at' => '09:00', 'closes_at' => '20:00'],
                6 => ['opens_at' => '08:00', 'closes_at' => '17:00'],
                7 => null,
            ],
            'applicable_modules' => [
                'customers',
                'services',
                'reservations',
                'planning',
                'presence',
                'invoices',
                'payments',
                'quotes',
                'products',
                'inventory',
                'sales',
                'expenses',
                'accounting',
                'team',
                'performance',
                'loyalty',
                'campaigns',
                'notifications',
            ],
        ];
    }

    public static function employees(): array
    {
        return [
            [
                'key' => 'maya_kone',
                'name' => 'Maya Koné',
                'role_key' => 'owner_senior_stylist',
                'demo_access_role' => 'manager',
                'title' => 'Propriétaire et coiffeuse senior',
                'specialties' => ['coloration', 'transformation', 'consultation'],
                'permissions' => [
                    'reservations.view',
                    'reservations.queue',
                    'reservations.manage',
                    'sales.manage',
                    'invoices.view',
                    'invoices.create',
                    'invoices.edit',
                    'invoices.approve',
                    'reports.view',
                ],
                'performance_profile' => ['occupancy_target' => 0.86, 'average_ticket_target' => 146.00, 'demand_weight' => 1.22],
                'schedule' => [
                    2 => ['starts_at' => '09:00', 'ends_at' => '17:00'],
                    3 => ['starts_at' => '10:00', 'ends_at' => '18:00'],
                    4 => ['starts_at' => '11:00', 'ends_at' => '20:00'],
                    5 => ['starts_at' => '10:00', 'ends_at' => '20:00'],
                    6 => ['starts_at' => '08:00', 'ends_at' => '16:00'],
                ],
                'absence_templates' => [
                    ['kind' => 'vacation', 'duration_days' => 5, 'preferred_month' => 2],
                    ['kind' => 'training', 'duration_days' => 1, 'preferred_month' => 10],
                ],
            ],
            [
                'key' => 'sarah_mbaye',
                'name' => 'Sarah Mbaye',
                'role_key' => 'protective_style_specialist',
                'title' => 'Coiffeuse spécialiste en coiffures protectrices',
                'specialties' => ['tresses', 'nattes', 'coiffures_protectrices'],
                'permissions' => ['reservations.view', 'reservations.queue', 'reservations.manage', 'sales.pos'],
                'performance_profile' => ['occupancy_target' => 0.91, 'average_ticket_target' => 132.00, 'demand_weight' => 1.30],
                'schedule' => [
                    2 => ['starts_at' => '10:00', 'ends_at' => '18:00'],
                    3 => ['starts_at' => '10:00', 'ends_at' => '18:00'],
                    4 => ['starts_at' => '12:00', 'ends_at' => '20:00'],
                    5 => ['starts_at' => '11:00', 'ends_at' => '20:00'],
                    6 => ['starts_at' => '08:00', 'ends_at' => '17:00'],
                ],
                'absence_templates' => [
                    ['kind' => 'personal_leave', 'duration_days' => 2, 'preferred_month' => 7],
                ],
            ],
            [
                'key' => 'alicia_tremblay',
                'name' => 'Alicia Tremblay',
                'role_key' => 'stylist',
                'title' => 'Coiffeuse',
                'specialties' => ['coupes', 'brushings', 'soins_capillaires'],
                'permissions' => ['reservations.view', 'reservations.queue', 'reservations.manage', 'sales.pos'],
                'performance_profile' => ['occupancy_target' => 0.75, 'average_ticket_target' => 86.00, 'demand_weight' => 0.96],
                'schedule' => [
                    2 => ['starts_at' => '09:00', 'ends_at' => '17:00'],
                    3 => ['starts_at' => '09:00', 'ends_at' => '17:00'],
                    4 => ['starts_at' => '10:00', 'ends_at' => '19:00'],
                    5 => ['starts_at' => '09:00', 'ends_at' => '18:00'],
                    6 => ['starts_at' => '09:00', 'ends_at' => '14:00'],
                ],
                'absence_templates' => [
                    ['kind' => 'vacation', 'duration_days' => 4, 'preferred_month' => 8],
                ],
            ],
            [
                'key' => 'kevin_diallo',
                'name' => 'Kevin Diallo',
                'role_key' => 'barber',
                'demo_access_role' => 'staff',
                'title' => 'Barbier',
                'specialties' => ['coupes_homme', 'degrades', 'barbe'],
                'permissions' => ['reservations.view', 'reservations.queue', 'reservations.manage', 'sales.pos'],
                'performance_profile' => ['occupancy_target' => 0.82, 'average_ticket_target' => 58.00, 'demand_weight' => 1.12],
                'schedule' => [
                    2 => ['starts_at' => '11:00', 'ends_at' => '19:00'],
                    3 => ['starts_at' => '10:00', 'ends_at' => '18:00'],
                    4 => ['starts_at' => '12:00', 'ends_at' => '20:00'],
                    5 => ['starts_at' => '11:00', 'ends_at' => '20:00'],
                    6 => ['starts_at' => '08:00', 'ends_at' => '17:00'],
                ],
                'absence_templates' => [
                    ['kind' => 'training', 'duration_days' => 2, 'preferred_month' => 4],
                ],
            ],
            [
                'key' => 'emma_roy',
                'name' => 'Emma Roy',
                'role_key' => 'assistant_apprentice',
                'demo_access_role' => 'front_desk',
                'title' => 'Assistante et apprentie',
                'specialties' => ['shampoings', 'preparation', 'soins_assistes'],
                'permissions' => ['reservations.view', 'reservations.queue'],
                'performance_profile' => ['occupancy_target' => 0.62, 'average_ticket_target' => 48.00, 'demand_weight' => 0.68],
                'schedule' => [
                    2 => ['starts_at' => '09:00', 'ends_at' => '17:00'],
                    3 => ['starts_at' => '09:00', 'ends_at' => '17:00'],
                    4 => ['starts_at' => '11:00', 'ends_at' => '19:00'],
                    5 => ['starts_at' => '10:00', 'ends_at' => '18:00'],
                    6 => ['starts_at' => '08:00', 'ends_at' => '16:00'],
                ],
                'absence_templates' => [
                    ['kind' => 'school', 'duration_days' => 1, 'preferred_month' => 9],
                ],
            ],
        ];
    }

    public static function serviceCategories(): array
    {
        return [
            ['key' => 'cuts_styling', 'name' => 'Coupes et coiffage', 'calendar_color' => '#4F46E5'],
            ['key' => 'protective_styles', 'name' => 'Tresses et coiffures protectrices', 'calendar_color' => '#9333EA'],
            ['key' => 'color', 'name' => 'Coloration', 'calendar_color' => '#DB2777'],
            ['key' => 'hair_care', 'name' => 'Soins capillaires', 'calendar_color' => '#0D9488'],
            ['key' => 'barber', 'name' => 'Barbier', 'calendar_color' => '#D97706'],
        ];
    }

    public static function services(): array
    {
        return [
            self::service('hair_consultation', 'cuts_styling', 'Consultation capillaire', 'Diagnostic, objectifs et plan de service personnalisé.', 30, 35.00, tags: ['consultation', 'diagnostic'], color: '#6366F1', cleanup: 0, metadata: ['demand_profile' => 'standard']),
            self::service('women_cut', 'cuts_styling', 'Coupe femme', 'Consultation courte, coupe et finition.', 60, 62.00, tags: ['coupe', 'populaire'], color: '#4F46E5', metadata: ['demand_profile' => 'popular', 'price_history' => [['offset_days' => -180, 'price' => 55.00], ['offset_days' => 0, 'price' => 62.00]]]),
            self::service('child_cut', 'cuts_styling', 'Coupe enfant', 'Coupe pour enfant de douze ans ou moins.', 35, 35.00, tags: ['coupe', 'famille'], color: '#818CF8'),
            self::service('short_hair_blowout', 'cuts_styling', 'Brushing cheveux courts', 'Shampoing et mise en forme sur cheveux courts.', 45, 48.00, tags: ['brushing', 'coiffage'], color: '#6366F1', preparation: 5),
            self::service('long_hair_blowout', 'cuts_styling', 'Brushing cheveux longs', 'Shampoing et mise en forme sur cheveux longs.', 75, 72.00, tags: ['brushing', 'coiffage'], color: '#6366F1', preparation: 5, cleanup: 10),
            self::service('roller_set', 'cuts_styling', 'Mise en plis classique', 'Ancien service conservé pour l’historique client.', 75, 58.00, tags: ['coiffage', 'ancien'], color: '#A5B4FC', active: false, metadata: ['demand_profile' => 'legacy']),
            self::service('event_updo', 'cuts_styling', 'Coiffure événementielle', 'Coiffure structurée pour mariage, gala ou séance photo.', 120, 145.00, tags: ['evenement', 'saisonnier'], color: '#4338CA', preparation: 15, cleanup: 10, bufferAfter: 15, metadata: ['demand_profile' => 'low', 'seasonal' => true]),
            self::service('cornrows', 'protective_styles', 'Nattes collées', 'Nattes collées avec motif simple ou intermédiaire.', 150, 125.00, tags: ['tresses', 'protecteur'], color: '#9333EA', preparation: 10, cleanup: 10, bufferAfter: 15),
            self::service('short_box_braids', 'protective_styles', 'Box braids courtes', 'Pose de box braids longueur épaules.', 240, 210.00, tags: ['tresses', 'protecteur'], color: '#7E22CE', preparation: 20, cleanup: 15, bufferAfter: 20, metadata: ['demand_profile' => 'popular']),
            self::service('long_box_braids', 'protective_styles', 'Box braids longues', 'Pose de box braids longues avec finition.', 330, 285.00, tags: ['tresses', 'longue_duree'], color: '#6B21A8', preparation: 25, cleanup: 20, bufferAfter: 20, metadata: ['demand_profile' => 'high_value']),
            self::service('twists', 'protective_styles', 'Vanilles et twists', 'Coiffure protectrice en vanilles ou twists.', 210, 185.00, tags: ['twists', 'protecteur'], color: '#A855F7', preparation: 15, cleanup: 15, bufferAfter: 15),
            self::service('wig_install', 'protective_styles', 'Pose de perruque', 'Préparation, pose sécurisée et finition naturelle.', 150, 165.00, tags: ['perruque', 'transformation'], color: '#C026D3', preparation: 20, cleanup: 15, bufferAfter: 15),
            self::service('braid_removal', 'protective_styles', 'Retrait de tresses', 'Retrait soigneux, démêlage et préparation au shampoing.', 90, 75.00, tags: ['tresses', 'entretien'], color: '#D8B4FE', cleanup: 10),
            self::service('color_consultation', 'color', 'Consultation avant coloration', 'Diagnostic couleur, test de mèche et estimation.', 45, 45.00, tags: ['coloration', 'consultation'], color: '#F472B6', preparation: 5, cleanup: 5),
            self::service('full_color', 'color', 'Coloration complète', 'Coloration uniforme avec diagnostic et finition.', 150, 165.00, tags: ['coloration', 'transformation'], color: '#DB2777', preparation: 15, cleanup: 20, bufferAfter: 15, metadata: ['demand_profile' => 'popular']),
            self::service('root_touch_up', 'color', 'Retouche des racines', 'Retouche ciblée des repousses et finition.', 105, 112.00, tags: ['coloration', 'entretien'], color: '#EC4899', preparation: 10, cleanup: 15, bufferAfter: 10),
            self::service('balayage', 'color', 'Balayage', 'Éclaircissement sur mesure, toner et finition.', 240, 245.00, tags: ['coloration', 'premium'], color: '#BE185D', preparation: 20, cleanup: 20, bufferAfter: 20, metadata: ['demand_profile' => 'high_value', 'price_history' => [['offset_days' => -240, 'price' => 225.00], ['offset_days' => 0, 'price' => 245.00]]]),
            self::service('toner', 'color', 'Toner', 'Neutralisation ou rafraîchissement de la nuance.', 60, 78.00, tags: ['coloration', 'entretien'], color: '#F9A8D4', preparation: 10, cleanup: 10),
            self::service('color_correction', 'color', 'Correction de couleur', 'Correction technique avec consultation préalable obligatoire.', 300, 360.00, tags: ['coloration', 'correction', 'premium'], color: '#9D174D', preparation: 30, cleanup: 25, bufferBefore: 15, bufferAfter: 30, metadata: ['demand_profile' => 'low', 'requires_consultation' => true]),
            self::service('hydrating_shampoo_care', 'hair_care', 'Shampoing et soin hydratant', 'Nettoyage doux, hydratation et finition simple.', 45, 52.00, tags: ['soin', 'hydratation'], color: '#14B8A6', preparation: 5, cleanup: 10),
            self::service('deep_conditioning', 'hair_care', 'Soin profond', 'Masque profond adapté à la porosité du cheveu.', 60, 68.00, tags: ['soin', 'masque'], color: '#0D9488', preparation: 5, cleanup: 10),
            self::service('repair_treatment', 'hair_care', 'Traitement réparateur', 'Protocole réparateur pour cheveux fragilisés.', 75, 92.00, tags: ['soin', 'reparation'], color: '#0F766E', preparation: 10, cleanup: 10),
            self::service('scalp_steam_treatment', 'hair_care', 'Soin du cuir chevelu à la vapeur', 'Exfoliation douce et traitement vapeur apaisant.', 75, 88.00, tags: ['soin', 'cuir_chevelu', 'vapeur'], color: '#2DD4BF', preparation: 10, cleanup: 15),
            self::service('men_cut', 'barber', 'Coupe homme', 'Coupe classique ou contemporaine avec finition.', 40, 42.00, tags: ['barbier', 'coupe'], color: '#D97706', metadata: ['demand_profile' => 'popular']),
            self::service('fade', 'barber', 'Dégradé', 'Dégradé précis avec contours.', 50, 49.00, tags: ['barbier', 'degrade'], color: '#B45309', cleanup: 10),
            self::service('line_up', 'barber', 'Contour', 'Rafraîchissement des contours et finition.', 20, 24.00, tags: ['barbier', 'entretien'], color: '#F59E0B', cleanup: 5),
            self::service('beard_trim', 'barber', 'Taille de barbe', 'Taille, contours et huile de finition.', 30, 32.00, tags: ['barbier', 'barbe'], color: '#FBBF24', cleanup: 5),
            self::service('haircut_and_beard', 'barber', 'Coupe et barbe', 'Forfait coupe, taille de barbe et finition.', 75, 68.00, tags: ['barbier', 'forfait', 'populaire'], color: '#92400E', cleanup: 10, bufferAfter: 10, metadata: ['bundle' => true, 'demand_profile' => 'popular']),
        ];
    }

    public static function suppliers(): array
    {
        return [
            ['key' => 'naya_pro_distribution', 'name' => 'Naya Pro Distribution', 'email' => 'commandes@naya-pro.example', 'phone' => '+1 438 555 0112', 'city' => 'Montréal'],
            ['key' => 'chromatique_montreal', 'name' => 'Chromatique Montréal', 'email' => 'service@chromatique.example', 'phone' => '+1 514 555 0184', 'city' => 'Montréal'],
            ['key' => 'texture_tress_supply', 'name' => 'Texture & Tress Supply', 'email' => 'ventes@texture-tress.example', 'phone' => '+1 450 555 0127', 'city' => 'Laval'],
            ['key' => 'atelier_barbe_nord', 'name' => 'Atelier Barbe Nord', 'email' => 'pro@barbe-nord.example', 'phone' => '+1 514 555 0161', 'city' => 'Montréal'],
            ['key' => 'ecosalon_fournitures', 'name' => 'ÉcoSalon Fournitures', 'email' => 'bonjour@ecosalon.example', 'phone' => '+1 450 555 0198', 'city' => 'Longueuil'],
        ];
    }

    public static function products(): array
    {
        return [
            self::product('hydrating_shampoo', 'hair_care', 'Shampoing hydratant professionnel', 'naya_pro_distribution', 14.50, 29.00, 18, 8, 'bottle', retail: true),
            self::product('repair_conditioner', 'hair_care', 'Revitalisant réparateur', 'naya_pro_distribution', 16.00, 32.00, 15, 7, 'bottle', retail: true),
            self::product('deep_mask', 'hair_care', 'Masque hydratant profond', 'naya_pro_distribution', 19.00, 39.00, 9, 5, 'jar', retail: true),
            self::product('color_cream', 'color', 'Crème colorante professionnelle', 'chromatique_montreal', 9.25, 19.00, 34, 16, 'tube'),
            self::product('toner_bottle', 'color', 'Toner neutralisant', 'chromatique_montreal', 10.75, 22.00, 4, 8, 'bottle', metadata: ['stock_state' => 'low']),
            self::product('oxidant_20', 'color', 'Oxydant 20 volumes', 'chromatique_montreal', 12.50, 25.00, 13, 6, 'bottle'),
            self::product('oxidant_30', 'color', 'Oxydant 30 volumes', 'chromatique_montreal', 13.25, 27.00, 10, 5, 'bottle'),
            self::product('styling_gel', 'styling', 'Gel de coiffage tenue souple', 'texture_tress_supply', 8.50, 20.00, 12, 6, 'jar', retail: true),
            self::product('hair_oil', 'hair_care', 'Huile capillaire nourrissante', 'texture_tress_supply', 11.00, 28.00, 21, 8, 'bottle', retail: true),
            self::product('braid_mousse', 'protective_styles', 'Mousse de finition pour tresses', 'texture_tress_supply', 8.75, 21.00, 14, 6, 'bottle', retail: true),
            self::product('wig_adhesive', 'wigs', 'Adhésif professionnel pour perruque', 'texture_tress_supply', 15.00, 34.00, 0, 3, 'bottle', metadata: ['stock_state' => 'out_of_stock']),
            self::product('adhesive_remover', 'wigs', 'Dissolvant doux pour adhésif', 'texture_tress_supply', 10.00, 24.00, 7, 3, 'bottle'),
            self::product('beard_oil', 'barber', 'Huile à barbe boréale', 'atelier_barbe_nord', 9.50, 24.00, 17, 7, 'bottle', retail: true),
            self::product('beard_balm', 'barber', 'Baume à barbe fixation légère', 'atelier_barbe_nord', 10.25, 26.00, 11, 5, 'tin', retail: true),
            self::product('synthetic_extensions', 'protective_styles', 'Mèches synthétiques premium', 'texture_tress_supply', 4.75, 10.00, 72, 30, 'pack'),
            self::product('nitrile_gloves', 'disposables', 'Gants de nitrile sans poudre', 'ecosalon_fournitures', 0.16, 0.00, 240, 100, 'unit'),
            self::product('disposable_towels', 'disposables', 'Serviettes jetables biodégradables', 'ecosalon_fournitures', 0.22, 0.00, 160, 80, 'unit'),
            self::product('satin_bonnet', 'retail', 'Bonnet de satin réversible', 'texture_tress_supply', 8.00, 22.00, 19, 8, 'unit', retail: true),
        ];
    }

    public static function offerPackages(): array
    {
        return [
            self::offerPackage(
                'entretien_protecteur_6',
                'Entretien protecteur · 6 visites',
                'forfait',
                'Six visites flexibles pour entretenir tresses, twists et coiffures protectrices.',
                525.00,
                [
                    self::offerItem('service', 'cornrows'),
                    self::offerItem('service', 'short_box_braids'),
                    self::offerItem('service', 'braid_removal'),
                ],
                validityDays: 180,
                includedQuantity: 6,
                unitType: 'visit',
                imagePath: '/images/landing/stock/beauty-treatment.jpg',
                metadata: ['badge' => 'Favori', 'savings_amount' => 90.00],
            ),
            self::offerPackage(
                'club_barbe_mensuel',
                'Club Barbe mensuel',
                'forfait',
                'Deux rendez-vous barbier par mois, avec renouvellement et rappels automatiques.',
                89.00,
                [
                    self::offerItem('service', 'men_cut'),
                    self::offerItem('service', 'fade'),
                    self::offerItem('service', 'beard_trim'),
                ],
                validityDays: 31,
                includedQuantity: 2,
                unitType: 'visit',
                imagePath: '/images/landing/stock/salon-front-desk.jpg',
                recurring: true,
                recurrenceFrequency: 'monthly',
                renewalNoticeDays: 7,
                metadata: [
                    'badge' => 'Mensuel',
                    'recurrence' => [
                        'carry_over_unused_balance' => true,
                        'payment_grace_days' => 7,
                        'payment_reminder_days' => [0, 3, 6],
                    ],
                ],
            ),
            self::offerPackage(
                'passeport_brushing_5',
                'Passeport Brushing · 5 séances',
                'forfait',
                'Cinq brushings au choix pour garder une mise en forme impeccable toute la saison.',
                275.00,
                [
                    self::offerItem('service', 'short_hair_blowout'),
                    self::offerItem('service', 'long_hair_blowout'),
                ],
                validityDays: 120,
                includedQuantity: 5,
                unitType: 'session',
                imagePath: '/images/landing/stock/salon-front-desk.jpg',
                metadata: ['badge' => 'Flexible', 'savings_amount' => 45.00],
            ),
            self::offerPackage(
                'cure_reparation_4',
                'Cure Réparation · 4 soins',
                'forfait',
                'Un protocole de quatre soins progressifs pour cheveux fragilisés ou colorés.',
                298.00,
                [
                    self::offerItem('service', 'hydrating_shampoo_care'),
                    self::offerItem('service', 'deep_conditioning'),
                    self::offerItem('service', 'repair_treatment'),
                ],
                validityDays: 90,
                includedQuantity: 4,
                unitType: 'session',
                imagePath: '/images/landing/stock/beauty-treatment.jpg',
                metadata: ['badge' => 'Résultats visibles', 'savings_amount' => 42.00],
            ),
            self::offerPackage(
                'rituel_hydratation',
                'Rituel Hydratation',
                'pack',
                'Un soin hydratant en salon accompagné de deux essentiels à emporter.',
                99.00,
                [
                    self::offerItem('service', 'hydrating_shampoo_care'),
                    self::offerItem('product', 'deep_mask'),
                    self::offerItem('product', 'hair_oil'),
                ],
                imagePath: '/images/landing/stock/beauty-treatment.jpg',
                metadata: ['badge' => 'Découverte', 'savings_amount' => 20.00],
            ),
            self::offerPackage(
                'duo_coupe_soin',
                'Duo Coupe & Soin',
                'pack',
                'Coupe, soin profond et revitalisant réparateur réunis dans une offre simple.',
                139.00,
                [
                    self::offerItem('service', 'women_cut'),
                    self::offerItem('service', 'deep_conditioning'),
                    self::offerItem('product', 'repair_conditioner'),
                ],
                imagePath: '/images/landing/stock/salon-front-desk.jpg',
                metadata: ['badge' => 'Essentiel', 'savings_amount' => 23.00],
            ),
            self::offerPackage(
                'mariage_serenite',
                'Mariage Sérénité',
                'pack',
                'Consultation, essai et coiffure du jour J réunis avec un suivi personnalisé.',
                323.00,
                [
                    self::offerItem('service', 'hair_consultation'),
                    self::offerItem('service', 'event_updo', 2),
                    self::offerItem('product', 'hair_oil'),
                ],
                imagePath: '/images/landing/stock/beauty-treatment.jpg',
                metadata: ['badge' => 'Événement', 'savings_amount' => 30.00],
            ),
        ];
    }

    public static function employeeServiceMatrix(): array
    {
        return [
            'maya_kone' => [
                'bookable_service_keys' => [
                    'hair_consultation', 'women_cut', 'short_hair_blowout', 'long_hair_blowout', 'event_updo',
                    'color_consultation', 'full_color', 'root_touch_up', 'balayage', 'toner', 'color_correction',
                    'hydrating_shampoo_care', 'deep_conditioning', 'repair_treatment', 'scalp_steam_treatment',
                ],
                'assist_only_service_keys' => [],
            ],
            'sarah_mbaye' => [
                'bookable_service_keys' => [
                    'hair_consultation', 'child_cut', 'event_updo', 'cornrows', 'short_box_braids', 'long_box_braids',
                    'twists', 'wig_install', 'braid_removal', 'hydrating_shampoo_care', 'deep_conditioning',
                    'repair_treatment', 'scalp_steam_treatment',
                ],
                'assist_only_service_keys' => ['full_color', 'balayage'],
            ],
            'alicia_tremblay' => [
                'bookable_service_keys' => [
                    'hair_consultation', 'women_cut', 'child_cut', 'short_hair_blowout', 'long_hair_blowout',
                    'roller_set', 'event_updo', 'root_touch_up', 'toner', 'hydrating_shampoo_care',
                    'deep_conditioning', 'repair_treatment', 'scalp_steam_treatment',
                ],
                'assist_only_service_keys' => ['full_color', 'balayage', 'color_correction'],
            ],
            'kevin_diallo' => [
                'bookable_service_keys' => ['men_cut', 'fade', 'line_up', 'beard_trim', 'haircut_and_beard'],
                'assist_only_service_keys' => [],
            ],
            'emma_roy' => [
                'bookable_service_keys' => ['braid_removal', 'hydrating_shampoo_care', 'deep_conditioning'],
                'assist_only_service_keys' => [
                    'short_hair_blowout', 'long_hair_blowout', 'cornrows', 'short_box_braids', 'long_box_braids',
                    'twists', 'wig_install', 'full_color', 'root_touch_up', 'balayage', 'toner', 'color_correction',
                    'repair_treatment', 'scalp_steam_treatment',
                ],
            ],
        ];
    }

    public static function clientStories(): array
    {
        return [
            [
                'key' => 'aicha_martin',
                'name' => 'Aïcha Martin',
                'archetype' => 'loyal_high_value_client',
                'profile' => [
                    'customer_since_months' => 15,
                    'preferred_employee_key' => 'sarah_mbaye',
                    'favorite_service_keys' => ['short_box_braids', 'cornrows'],
                    'tags' => ['vip', 'fidele', 'rappels_actifs'],
                    'internal_note' => 'Cuir chevelu sensible; éviter les produits fortement parfumés.',
                    'marketing_consent' => true,
                ],
                'expected_records' => [
                    'completed_reservations' => 12,
                    'rescheduled_reservations' => 1,
                    'paid_invoices' => 12,
                    'product_sales' => 3,
                    'future_confirmed_reservations' => 1,
                ],
                'timeline' => [
                    ['event' => 'customer_created', 'offset_days' => -456],
                    ['event' => 'first_completed_reservation', 'offset_days' => -441],
                    ['event' => 'reservation_rescheduled', 'offset_days' => -126],
                    ['event' => 'retail_purchase', 'offset_days' => -42, 'product_key' => 'hair_oil'],
                    ['event' => 'next_reservation_confirmed', 'offset_days' => 18, 'service_key' => 'short_box_braids'],
                ],
            ],
            [
                'key' => 'samantha_joseph',
                'name' => 'Samantha Joseph',
                'archetype' => 'wedding_package',
                'profile' => [
                    'customer_since_months' => 7,
                    'preferred_employee_key' => 'maya_kone',
                    'favorite_service_keys' => ['event_updo', 'color_consultation'],
                    'tags' => ['mariage', 'depot_recu', 'suivi_requis'],
                    'internal_note' => 'Chignon bas texturé, accessoires dorés et voile à fixer après la coiffure.',
                    'marketing_consent' => true,
                ],
                'expected_records' => [
                    'accepted_quotes' => 1,
                    'completed_deposits' => 1,
                    'planned_reservations' => 3,
                    'partially_paid_invoices' => 1,
                    'open_follow_up_tasks' => 1,
                ],
                'timeline' => [
                    ['event' => 'customer_created', 'offset_days' => -211],
                    ['event' => 'quote_sent', 'offset_days' => -84, 'template' => 'wedding_package'],
                    ['event' => 'quote_accepted', 'offset_days' => -78],
                    ['event' => 'deposit_paid', 'offset_days' => -77, 'share_of_quote' => 0.30],
                    ['event' => 'consultation_reservation', 'offset_days' => -28, 'service_key' => 'hair_consultation'],
                    ['event' => 'trial_reservation', 'offset_days' => 12, 'service_key' => 'event_updo'],
                    ['event' => 'wedding_reservation', 'offset_days' => 47, 'service_key' => 'event_updo'],
                    ['event' => 'final_invoice_due', 'offset_days' => 40],
                ],
            ],
            [
                'key' => 'nadia_pierre',
                'name' => 'Nadia Pierre',
                'archetype' => 'no_show_deposit_required',
                'profile' => [
                    'customer_since_months' => 10,
                    'preferred_employee_key' => 'alicia_tremblay',
                    'favorite_service_keys' => ['long_hair_blowout'],
                    'tags' => ['absence', 'depot_obligatoire', 'suivi_requis'],
                    'internal_note' => 'Exiger un dépôt avant toute nouvelle confirmation à la suite de l’absence.',
                    'marketing_consent' => false,
                ],
                'expected_records' => [
                    'no_show_reservations' => 1,
                    'no_show_fee_invoices' => 1,
                    'partially_paid_invoices' => 1,
                    'future_pending_reservations' => 1,
                    'actionable_notifications' => 1,
                ],
                'timeline' => [
                    ['event' => 'customer_created', 'offset_days' => -304],
                    ['event' => 'reservation_no_show', 'offset_days' => -24, 'service_key' => 'long_hair_blowout'],
                    ['event' => 'no_show_fee_invoiced', 'offset_days' => -23],
                    ['event' => 'partial_payment_received', 'offset_days' => -19, 'share_of_invoice' => 0.50],
                    ['event' => 'follow_up_notification_created', 'offset_days' => -2],
                    ['event' => 'new_reservation_pending', 'offset_days' => 15, 'service_key' => 'deep_conditioning'],
                ],
            ],
            [
                'key' => 'marc_andre_beaulieu',
                'name' => 'Marc-André Beaulieu',
                'archetype' => 'recurring_barber_client',
                'profile' => [
                    'customer_since_months' => 17,
                    'preferred_employee_key' => 'kevin_diallo',
                    'favorite_service_keys' => ['haircut_and_beard'],
                    'tags' => ['fidele', 'recurrence_3_semaines', 'paiement_immediat'],
                    'internal_note' => 'Conserver un dégradé bas et une barbe courte avec finition mate.',
                    'marketing_consent' => true,
                ],
                'expected_records' => [
                    'completed_reservations' => 21,
                    'paid_invoices' => 21,
                    'payments_with_tip' => 8,
                    'product_sales' => 2,
                    'future_confirmed_reservations' => 1,
                ],
                'timeline' => [
                    ['event' => 'customer_created', 'offset_days' => -515],
                    ['event' => 'recurring_series_started', 'offset_days' => -497, 'interval_days' => 21],
                    ['event' => 'retail_purchase', 'offset_days' => -63, 'product_key' => 'beard_oil'],
                    ['event' => 'payment_with_tip', 'offset_days' => -21, 'tip_percent' => 18],
                    ['event' => 'next_reservation_confirmed', 'offset_days' => 14, 'service_key' => 'haircut_and_beard'],
                ],
            ],
            [
                'key' => 'chloe_nguyen',
                'name' => 'Chloé Nguyen',
                'archetype' => 'color_correction_claim',
                'profile' => [
                    'customer_since_months' => 8,
                    'preferred_employee_key' => 'maya_kone',
                    'favorite_service_keys' => ['balayage', 'toner'],
                    'tags' => ['correction', 'remboursement_partiel', 'suivi_qualite'],
                    'internal_note' => 'Tonalité trop chaude signalée; correction froide convenue avec validation de Maya.',
                    'marketing_consent' => true,
                ],
                'expected_records' => [
                    'completed_color_reservations' => 1,
                    'discounted_correction_reservations' => 1,
                    'partial_refunds' => 1,
                    'quality_follow_up_notes' => 2,
                    'activity_timeline_events' => 7,
                ],
                'timeline' => [
                    ['event' => 'customer_created', 'offset_days' => -244],
                    ['event' => 'color_reservation_completed', 'offset_days' => -35, 'service_key' => 'balayage'],
                    ['event' => 'invoice_paid', 'offset_days' => -35],
                    ['event' => 'client_claim_received', 'offset_days' => -32],
                    ['event' => 'quality_note_added', 'offset_days' => -32],
                    ['event' => 'discounted_correction_completed', 'offset_days' => -27, 'service_key' => 'toner'],
                    ['event' => 'partial_refund_recorded', 'offset_days' => -26, 'share_of_payment' => 0.25],
                    ['event' => 'quality_follow_up_completed', 'offset_days' => -20],
                ],
            ],
        ];
    }

    public static function expenseTemplates(): array
    {
        return [
            ['key' => 'rent', 'name' => 'Loyer commercial', 'category' => 'occupancy', 'frequency' => 'monthly', 'amount_range' => [3850.00, 3850.00], 'payment_method' => 'bank_transfer', 'due_day' => 1],
            ['key' => 'internet', 'name' => 'Internet affaires', 'category' => 'utilities', 'frequency' => 'monthly', 'amount_range' => [105.00, 125.00], 'payment_method' => 'card', 'due_day' => 8],
            ['key' => 'phone', 'name' => 'Téléphonie', 'category' => 'utilities', 'frequency' => 'monthly', 'amount_range' => [82.00, 98.00], 'payment_method' => 'card', 'due_day' => 12],
            ['key' => 'electricity', 'name' => 'Électricité', 'category' => 'utilities', 'frequency' => 'monthly', 'amount_range' => [210.00, 390.00], 'payment_method' => 'bank_transfer', 'due_day' => 18],
            ['key' => 'insurance', 'name' => 'Assurance commerciale', 'category' => 'insurance', 'frequency' => 'monthly', 'amount_range' => [188.00, 188.00], 'payment_method' => 'bank_transfer', 'due_day' => 5],
            ['key' => 'hair_products', 'name' => 'Approvisionnement produits capillaires', 'category' => 'inventory', 'frequency' => 'biweekly', 'amount_range' => [480.00, 1350.00], 'payment_method' => 'card'],
            ['key' => 'supplies', 'name' => 'Fournitures jetables', 'category' => 'supplies', 'frequency' => 'monthly', 'amount_range' => [180.00, 420.00], 'payment_method' => 'card'],
            ['key' => 'marketing', 'name' => 'Publicité locale et contenu', 'category' => 'marketing', 'frequency' => 'monthly', 'amount_range' => [250.00, 780.00], 'payment_method' => 'card'],
            ['key' => 'maintenance', 'name' => 'Entretien du local', 'category' => 'maintenance', 'frequency' => 'monthly', 'amount_range' => [160.00, 310.00], 'payment_method' => 'bank_transfer'],
            ['key' => 'equipment_repair', 'name' => 'Réparation d’équipement', 'category' => 'maintenance', 'frequency' => 'quarterly', 'amount_range' => [175.00, 950.00], 'payment_method' => 'card'],
            ['key' => 'training', 'name' => 'Formation professionnelle', 'category' => 'training', 'frequency' => 'quarterly', 'amount_range' => [295.00, 1200.00], 'payment_method' => 'card'],
            ['key' => 'platform_fees', 'name' => 'Frais de plateformes', 'category' => 'software', 'frequency' => 'monthly', 'amount_range' => [145.00, 225.00], 'payment_method' => 'card', 'due_day' => 20],
            ['key' => 'bank_fees', 'name' => 'Frais bancaires', 'category' => 'banking', 'frequency' => 'monthly', 'amount_range' => [42.00, 86.00], 'payment_method' => 'bank_transfer', 'due_day' => 28],
        ];
    }

    public static function seasonality(): array
    {
        return [
            'monthly_demand_multipliers' => [
                1 => 0.74,
                2 => 0.82,
                3 => 0.94,
                4 => 1.02,
                5 => 1.13,
                6 => 1.21,
                7 => 1.16,
                8 => 1.08,
                9 => 1.02,
                10 => 1.07,
                11 => 1.15,
                12 => 1.34,
            ],
            'weekday_demand_weights' => [
                1 => 0.00,
                2 => 0.78,
                3 => 0.88,
                4 => 1.08,
                5 => 1.26,
                6 => 1.34,
                7 => 0.00,
            ],
            'customer_growth_monthly_rate' => 0.018,
            'events' => [
                ['key' => 'wedding_season', 'months' => [5, 6, 7, 8], 'service_tags' => ['evenement', 'coloration'], 'demand_multiplier' => 1.18],
                ['key' => 'back_to_school', 'months' => [8, 9], 'service_tags' => ['famille', 'tresses'], 'demand_multiplier' => 1.12],
                ['key' => 'holiday_rush', 'months' => [11, 12], 'service_tags' => ['coiffage', 'coloration', 'barbier'], 'demand_multiplier' => 1.25],
                ['key' => 'post_holiday_slowdown', 'months' => [1], 'service_tags' => [], 'demand_multiplier' => 0.78],
            ],
        ];
    }

    public static function paymentMethods(): array
    {
        return [
            ['key' => 'credit_card', 'label' => 'Carte de crédit', 'record_type' => 'payment', 'internal_method' => 'card', 'weight' => 0.31],
            ['key' => 'debit_card', 'label' => 'Carte de débit', 'record_type' => 'payment', 'internal_method' => 'card', 'weight' => 0.27],
            ['key' => 'cash', 'label' => 'Espèces', 'record_type' => 'payment', 'internal_method' => 'cash', 'weight' => 0.17],
            ['key' => 'interac_transfer', 'label' => 'Virement Interac', 'record_type' => 'payment', 'internal_method' => 'bank_transfer', 'weight' => 0.08],
            ['key' => 'online_card', 'label' => 'Paiement en ligne', 'record_type' => 'payment', 'internal_method' => 'card', 'weight' => 0.11],
            ['key' => 'gift_card', 'label' => 'Carte-cadeau', 'record_type' => 'payment', 'internal_method' => null, 'weight' => 0.02, 'implementation_state' => 'blueprint_only'],
            ['key' => 'deposit', 'label' => 'Dépôt', 'record_type' => 'transaction', 'internal_method' => 'card', 'weight' => 0.04],
        ];
    }

    private static function service(
        string $key,
        string $categoryKey,
        string $name,
        string $description,
        int $duration,
        float $price,
        array $tags,
        string $color,
        int $preparation = 0,
        int $cleanup = 5,
        int $bufferBefore = 0,
        int $bufferAfter = 5,
        bool $active = true,
        array $metadata = [],
    ): array {
        return [
            'key' => $key,
            'category_key' => $categoryKey,
            'name' => $name,
            'description' => $description,
            'duration_minutes' => $duration,
            'preparation_minutes' => $preparation,
            'cleanup_minutes' => $cleanup,
            'buffer_before_minutes' => $bufferBefore,
            'buffer_after_minutes' => $bufferAfter,
            'price' => $price,
            'currency_code' => 'CAD',
            'tags' => $tags,
            'calendar_color' => $color,
            'active' => $active,
            'metadata' => array_merge([
                'demand_profile' => 'standard',
                'seasonal' => false,
                'bundle' => false,
                'price_history' => [],
                'consumables' => self::serviceConsumables($key),
            ], $metadata),
        ];
    }

    private static function product(
        string $key,
        string $categoryKey,
        string $name,
        string $supplierKey,
        float $cost,
        float $price,
        int $stockOnHand,
        int $reorderThreshold,
        string $unit,
        bool $retail = false,
        bool $active = true,
        array $metadata = [],
    ): array {
        return [
            'key' => $key,
            'category_key' => $categoryKey,
            'name' => $name,
            'supplier_key' => $supplierKey,
            'cost' => $cost,
            'price' => $price,
            'currency_code' => 'CAD',
            'stock_on_hand' => $stockOnHand,
            'reorder_threshold' => $reorderThreshold,
            'unit' => $unit,
            'tracking_type' => 'simple',
            'retail' => $retail,
            'active' => $active,
            'metadata' => array_merge([
                'stock_state' => $stockOnHand === 0
                    ? 'out_of_stock'
                    : ($stockOnHand <= $reorderThreshold ? 'low' : 'healthy'),
            ], $metadata),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $metadata
     */
    private static function offerPackage(
        string $key,
        string $name,
        string $type,
        string $description,
        float $price,
        array $items,
        ?int $validityDays = null,
        ?int $includedQuantity = null,
        ?string $unitType = null,
        ?string $imagePath = null,
        bool $recurring = false,
        ?string $recurrenceFrequency = null,
        ?int $renewalNoticeDays = null,
        array $metadata = [],
    ): array {
        return [
            'key' => $key,
            'name' => $name,
            'type' => $type,
            'status' => 'active',
            'description' => $description,
            'image_path' => $imagePath,
            'pricing_mode' => 'fixed',
            'price' => $price,
            'currency_code' => 'CAD',
            'validity_days' => $validityDays,
            'included_quantity' => $includedQuantity,
            'unit_type' => $unitType,
            'is_public' => true,
            'is_recurring' => $recurring,
            'recurrence_frequency' => $recurrenceFrequency,
            'renewal_notice_days' => $renewalNoticeDays,
            'items' => $items,
            'metadata' => array_merge([
                'scenario_key' => self::KEY,
                'merchandising' => [
                    'featured' => true,
                    'channel' => 'salon_and_portal',
                ],
            ], $metadata),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function offerItem(
        string $catalog,
        string $key,
        float $quantity = 1,
    ): array {
        return [
            'catalog' => $catalog,
            'key' => $key,
            'quantity' => $quantity,
            'included' => true,
            'is_optional' => false,
        ];
    }

    private static function serviceConsumables(string $serviceKey): array
    {
        return match ($serviceKey) {
            'short_hair_blowout', 'long_hair_blowout', 'hydrating_shampoo_care' => [
                ['product_key' => 'hydrating_shampoo', 'quantity' => 0.04],
                ['product_key' => 'repair_conditioner', 'quantity' => 0.03],
            ],
            'cornrows', 'short_box_braids', 'long_box_braids', 'twists' => [
                ['product_key' => 'braid_mousse', 'quantity' => 0.08],
                ['product_key' => 'styling_gel', 'quantity' => 0.05],
                ['product_key' => 'synthetic_extensions', 'quantity' => $serviceKey === 'long_box_braids' ? 7 : 4],
            ],
            'wig_install' => [
                ['product_key' => 'wig_adhesive', 'quantity' => 0.08],
                ['product_key' => 'adhesive_remover', 'quantity' => 0.03],
            ],
            'full_color', 'root_touch_up', 'balayage', 'color_correction' => [
                ['product_key' => 'color_cream', 'quantity' => 1],
                ['product_key' => 'oxidant_20', 'quantity' => 0.10],
                ['product_key' => 'nitrile_gloves', 'quantity' => 2],
            ],
            'toner' => [
                ['product_key' => 'toner_bottle', 'quantity' => 0.12],
                ['product_key' => 'oxidant_20', 'quantity' => 0.05],
                ['product_key' => 'nitrile_gloves', 'quantity' => 2],
            ],
            'deep_conditioning', 'repair_treatment', 'scalp_steam_treatment' => [
                ['product_key' => 'deep_mask', 'quantity' => 0.06],
                ['product_key' => 'hair_oil', 'quantity' => 0.02],
            ],
            'beard_trim', 'haircut_and_beard' => [
                ['product_key' => 'beard_oil', 'quantity' => 0.02],
                ['product_key' => 'beard_balm', 'quantity' => 0.01],
            ],
            default => [],
        };
    }
}
