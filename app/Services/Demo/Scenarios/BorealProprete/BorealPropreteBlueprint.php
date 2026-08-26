<?php

namespace App\Services\Demo\Scenarios\BorealProprete;

use App\Enums\DemoDataVolume;
use InvalidArgumentException;

final class BorealPropreteBlueprint
{
    public const KEY = 'boreal_proprete_services';

    public const DEFAULT_VOLUME = 'medium';

    public const HISTORY_MONTHS = 12;

    public const FUTURE_WEEKS = 4;

    /**
     * @return array<string, mixed>
     */
    public static function definition(): array
    {
        return [
            'key' => self::KEY,
            'version' => 1,
            'default_volume' => self::DEFAULT_VOLUME,
            'history_months' => self::HISTORY_MONTHS,
            'future_weeks' => self::FUTURE_WEEKS,
            'identity' => self::identity(),
            'employees' => self::employees(),
            'service_categories' => self::serviceCategories(),
            'services' => self::services(),
            'suppliers' => self::suppliers(),
            'products' => self::products(),
            'offer_packages' => self::offerPackages(),
            'employee_service_matrix' => self::employeeServiceMatrix(),
            'client_stories' => self::clientStories(),
            'quality_protocols' => self::qualityProtocols(),
            'expense_templates' => self::expenseTemplates(),
            'seasonality' => self::seasonality(),
            'payment_methods' => self::paymentMethods(),
        ];
    }

    /**
     * @return array<string, int>
     */
    public static function targetsForVolume(DemoDataVolume|string $volume): array
    {
        $normalized = $volume instanceof DemoDataVolume
            ? $volume
            : DemoDataVolume::normalize($volume);

        return match ($normalized) {
            DemoDataVolume::Small => [
                'employees' => 7,
                'services' => 18,
                'products' => 18,
                'offer_packages' => 6,
                'customers' => 24,
                'properties' => 30,
                'prospects' => 16,
                'service_requests' => 16,
                'quotes' => 16,
                'works' => 150,
                'tasks' => 480,
                'work_checklist_items' => 600,
                'work_media' => 80,
                'task_materials' => 400,
                'task_status_histories' => 700,
                'invoices' => 80,
                'payments' => 90,
                'expenses' => 60,
                'inventory_movements' => 350,
                'work_ratings' => 24,
                'activity_logs' => 150,
                'team_attendances' => 280,
                'campaigns' => 3,
                'campaign_runs' => 1,
                'campaign_recipients' => 24,
                'campaign_events' => 108,
                'notifications' => 12,
            ],
            DemoDataVolume::Medium => [
                'employees' => 7,
                'services' => 18,
                'products' => 18,
                'offer_packages' => 6,
                'customers' => 90,
                'properties' => 118,
                'prospects' => 45,
                'service_requests' => 45,
                'quotes' => 65,
                'works' => 720,
                'tasks' => 2400,
                'work_checklist_items' => 3200,
                'work_media' => 420,
                'task_materials' => 2100,
                'task_status_histories' => 3000,
                'invoices' => 360,
                'payments' => 420,
                'expenses' => 144,
                'inventory_movements' => 1800,
                'work_ratings' => 140,
                'activity_logs' => 900,
                'team_attendances' => 1260,
                'campaigns' => 6,
                'campaign_runs' => 2,
                'campaign_recipients' => 80,
                'campaign_events' => 360,
                'notifications' => 36,
            ],
            DemoDataVolume::Large => [
                'employees' => 7,
                'services' => 18,
                'products' => 18,
                'offer_packages' => 6,
                'customers' => 240,
                'properties' => 320,
                'prospects' => 140,
                'service_requests' => 140,
                'quotes' => 180,
                'works' => 2400,
                'tasks' => 8000,
                'work_checklist_items' => 11000,
                'work_media' => 1400,
                'task_materials' => 7000,
                'task_status_histories' => 10000,
                'invoices' => 1200,
                'payments' => 1400,
                'expenses' => 360,
                'inventory_movements' => 6000,
                'work_ratings' => 420,
                'activity_logs' => 3000,
                'team_attendances' => 3500,
                'campaigns' => 9,
                'campaign_runs' => 3,
                'campaign_recipients' => 240,
                'campaign_events' => 1080,
                'notifications' => 100,
            ],
            default => throw new InvalidArgumentException('Unsupported Boréal Propreté data volume.'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function identity(): array
    {
        return [
            'name' => 'Boréal Propreté Services',
            'legal_name' => 'Boréal Propreté Services inc.',
            'owner_name' => 'Amélie Gagnon',
            'category_key' => 'cleaning_company',
            'category_label' => 'Entreprise de nettoyage',
            'company_type' => 'services',
            'company_sector' => 'nettoyage',
            'business_preset' => 'service_general',
            'primary_locale' => 'fr_CA',
            'currency_code' => 'CAD',
            'timezone' => 'America/Toronto',
            'operating_history_months' => self::HISTORY_MONTHS,
            'future_planning_weeks' => self::FUTURE_WEEKS,
            'tax_profile' => 'quebec_gst_qst',
            'taxes' => [
                ['key' => 'gst', 'label' => 'TPS', 'rate' => 5.000],
                ['key' => 'qst', 'label' => 'TVQ', 'rate' => 9.975],
            ],
            'address' => [
                'line_1' => '482, rue des Érables',
                'city' => 'Longueuil',
                'province' => 'QC',
                'postal_code' => 'J4K 2V1',
                'country_code' => 'CA',
            ],
            'service_area' => ['Longueuil', 'Montréal', 'Brossard', 'Saint-Lambert', 'Laval'],
            'phone' => '+1 438 555 0196',
            'email' => 'bonjour@boreal-proprete.example',
            'description' => 'Entreprise québécoise de nettoyage résidentiel et commercial, spécialisée dans les sites récurrents, la preuve de passage et le contrôle qualité.',
            'logo' => [
                'strategy' => 'generated_placeholder',
                'initials' => 'BP',
                'background_color' => '#0F766E',
                'foreground_color' => '#F0FDFA',
            ],
            'business_hours' => [
                1 => ['opens_at' => '06:00', 'closes_at' => '23:00'],
                2 => ['opens_at' => '06:00', 'closes_at' => '23:00'],
                3 => ['opens_at' => '06:00', 'closes_at' => '23:00'],
                4 => ['opens_at' => '06:00', 'closes_at' => '23:00'],
                5 => ['opens_at' => '06:00', 'closes_at' => '23:00'],
                6 => ['opens_at' => '07:00', 'closes_at' => '17:00'],
                7 => null,
            ],
            'operating_model' => [
                'primary_record' => 'work',
                'planning_focus' => 'recurring_sites',
                'proof_strategy' => 'checklists_and_media',
                'billing_focus' => 'per_visit_and_monthly_grouping',
                'queue_enabled' => false,
                'tips_enabled' => false,
            ],
            'applicable_modules' => [
                'customers',
                'requests',
                'quotes',
                'services',
                'jobs',
                'tasks',
                'planning',
                'presence',
                'invoices',
                'payments',
                'expenses',
                'accounting',
                'team_members',
                'performance',
                'products',
                'inventory',
                'campaigns',
                'notifications',
                'assistant',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function employees(): array
    {
        return [
            self::employee(
                'amelie_gagnon',
                'Amélie Gagnon',
                'owner_operations_director',
                'Fondatrice et directrice des opérations',
                ['gestion', 'devis_commerciaux', 'supervision_qualite'],
                ['jobs.view', 'jobs.edit', 'tasks.view', 'tasks.create', 'tasks.edit', 'quotes.view', 'quotes.create', 'quotes.edit', 'quotes.send', 'invoices.view', 'invoices.create', 'invoices.edit', 'invoices.approve', 'expenses.view', 'expenses.approve', 'reports.view'],
                [1 => ['starts_at' => '08:00', 'ends_at' => '16:30'], 2 => ['starts_at' => '08:00', 'ends_at' => '16:30'], 3 => ['starts_at' => '08:00', 'ends_at' => '16:30'], 4 => ['starts_at' => '08:00', 'ends_at' => '16:30'], 5 => ['starts_at' => '08:00', 'ends_at' => '15:00']],
                ['weekly_capacity_hours' => 38, 'on_time_target' => 0.97, 'quality_target' => 4.80],
                [['kind' => 'vacation', 'duration_days' => 5, 'preferred_month' => 7], ['kind' => 'training', 'duration_days' => 1, 'preferred_month' => 11]],
                'manager',
            ),
            self::employee(
                'mariam_diallo',
                'Mariam Diallo',
                'customer_quality_coordinator',
                'Coordonnatrice clientèle et responsable qualité',
                ['relation_client', 'inspection_qualite', 'gestion_incidents'],
                ['jobs.view', 'jobs.edit', 'tasks.view', 'tasks.create', 'tasks.edit', 'quotes.view', 'quotes.edit', 'campaigns.view', 'campaigns.manage', 'reservations.view'],
                [1 => ['starts_at' => '07:30', 'ends_at' => '16:00'], 2 => ['starts_at' => '07:30', 'ends_at' => '16:00'], 3 => ['starts_at' => '10:30', 'ends_at' => '19:00'], 4 => ['starts_at' => '07:30', 'ends_at' => '16:00'], 5 => ['starts_at' => '07:30', 'ends_at' => '16:00']],
                ['weekly_capacity_hours' => 38, 'on_time_target' => 0.96, 'quality_target' => 4.85],
                [['kind' => 'personal_leave', 'duration_days' => 2, 'preferred_month' => 2]],
                'front_desk',
            ),
            self::employee(
                'jose_alvarez',
                'José Alvarez',
                'commercial_team_lead',
                'Chef d’équipe commercial',
                ['bureaux', 'aires_communes', 'controle_fermeture'],
                ['jobs.view', 'jobs.edit', 'tasks.view', 'tasks.create', 'tasks.edit', 'presence.view'],
                [1 => ['starts_at' => '15:00', 'ends_at' => '23:00'], 2 => ['starts_at' => '15:00', 'ends_at' => '23:00'], 3 => ['starts_at' => '15:00', 'ends_at' => '23:00'], 4 => ['starts_at' => '15:00', 'ends_at' => '23:00'], 5 => ['starts_at' => '14:00', 'ends_at' => '22:00']],
                ['weekly_capacity_hours' => 40, 'on_time_target' => 0.96, 'quality_target' => 4.75],
                [['kind' => 'vacation', 'duration_days' => 5, 'preferred_month' => 8]],
                'staff',
            ),
            self::employee(
                'fatou_ndiaye',
                'Fatou Ndiaye',
                'residential_team_lead',
                'Cheffe d’équipe résidentiel',
                ['residentiel', 'grand_menage', 'formation_terrain'],
                ['jobs.view', 'jobs.edit', 'tasks.view', 'tasks.create', 'tasks.edit', 'presence.view'],
                [1 => ['starts_at' => '07:00', 'ends_at' => '15:30'], 2 => ['starts_at' => '07:00', 'ends_at' => '15:30'], 3 => ['starts_at' => '07:00', 'ends_at' => '15:30'], 4 => ['starts_at' => '07:00', 'ends_at' => '15:30'], 5 => ['starts_at' => '07:00', 'ends_at' => '15:30']],
                ['weekly_capacity_hours' => 40, 'on_time_target' => 0.97, 'quality_target' => 4.80],
                [['kind' => 'training', 'duration_days' => 2, 'preferred_month' => 4]],
                'staff',
            ),
            self::employee(
                'alexandre_nguyen',
                'Alexandre Nguyen',
                'specialized_cleaning_technician',
                'Spécialiste vitres, tapis et planchers',
                ['vitres', 'extraction_tapis', 'entretien_planchers'],
                ['jobs.view', 'tasks.view', 'tasks.edit', 'products.view', 'presence.view'],
                [1 => ['starts_at' => '06:30', 'ends_at' => '15:00'], 2 => ['starts_at' => '06:30', 'ends_at' => '15:00'], 3 => ['starts_at' => '06:30', 'ends_at' => '15:00'], 4 => ['starts_at' => '06:30', 'ends_at' => '15:00'], 6 => ['starts_at' => '07:00', 'ends_at' => '15:00']],
                ['weekly_capacity_hours' => 40, 'on_time_target' => 0.94, 'quality_target' => 4.70],
                [['kind' => 'certification', 'duration_days' => 1, 'preferred_month' => 3]],
                'staff',
            ),
            self::employee(
                'naomi_saint_pierre',
                'Naomi Saint-Pierre',
                'residential_cleaner',
                'Préposée résidentiel et locations courte durée',
                ['residentiel', 'locations_courte_duree', 'demenagement'],
                ['jobs.view', 'tasks.view', 'tasks.edit', 'presence.view'],
                [2 => ['starts_at' => '08:00', 'ends_at' => '16:30'], 3 => ['starts_at' => '08:00', 'ends_at' => '16:30'], 4 => ['starts_at' => '08:00', 'ends_at' => '16:30'], 5 => ['starts_at' => '08:00', 'ends_at' => '16:30'], 6 => ['starts_at' => '08:00', 'ends_at' => '16:30']],
                ['weekly_capacity_hours' => 40, 'on_time_target' => 0.95, 'quality_target' => 4.70],
                [['kind' => 'personal_leave', 'duration_days' => 1, 'preferred_month' => 7]],
                'staff',
            ),
            self::employee(
                'samuel_roy',
                'Samuel Roy',
                'mobile_relief_cleaner',
                'Préposé mobile, remplacements et approvisionnement',
                ['remplacements', 'urgences', 'approvisionnement'],
                ['jobs.view', 'tasks.view', 'tasks.edit', 'products.view', 'presence.view'],
                [1 => ['starts_at' => '11:00', 'ends_at' => '19:30'], 2 => ['starts_at' => '11:00', 'ends_at' => '19:30'], 4 => ['starts_at' => '11:00', 'ends_at' => '19:30'], 5 => ['starts_at' => '11:00', 'ends_at' => '19:30'], 6 => ['starts_at' => '07:00', 'ends_at' => '15:30']],
                ['weekly_capacity_hours' => 40, 'on_time_target' => 0.93, 'quality_target' => 4.65],
                [['kind' => 'vacation', 'duration_days' => 4, 'preferred_month' => 1]],
                'staff',
            ),
        ];
    }

    /**
     * @return list<array{key:string,name:string,calendar_color:string}>
     */
    public static function serviceCategories(): array
    {
        return [
            ['key' => 'assessment', 'name' => 'Évaluation', 'calendar_color' => '#64748B'],
            ['key' => 'residential', 'name' => 'Nettoyage résidentiel', 'calendar_color' => '#0D9488'],
            ['key' => 'commercial', 'name' => 'Nettoyage commercial', 'calendar_color' => '#2563EB'],
            ['key' => 'specialized', 'name' => 'Travaux spécialisés', 'calendar_color' => '#7C3AED'],
            ['key' => 'urgent', 'name' => 'Remise en état urgente', 'calendar_color' => '#DC2626'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function services(): array
    {
        return [
            self::service('site_assessment', 'assessment', 'Visite d’évaluation de site', 'Relevé des accès, surfaces, contraintes, fréquences et attentes qualité.', 45, 0.00, 1, 'assessment', ['evaluation', 'devis'], '#64748B', [], metadata: ['demand_profile' => 'lead_generation']),
            self::service('recurring_home_cleaning', 'residential', 'Entretien résidentiel régulier', 'Entretien complet des pièces de vie, cuisine, salles de bain et planchers.', 150, 165.00, 2, 'per_visit', ['residentiel', 'recurrent'], '#0D9488', ['neutral_floor_cleaner' => 0.08, 'microfiber_cloths' => 4, 'nitrile_gloves' => 4, 'garbage_bags_medium' => 2], metadata: ['demand_profile' => 'high']),
            self::service('deep_home_cleaning', 'residential', 'Grand ménage', 'Nettoyage approfondi des surfaces, plinthes, portes, luminaires et zones difficiles.', 300, 395.00, 2, 'fixed', ['residentiel', 'saisonnier', 'profondeur'], '#14B8A6', ['neutral_floor_cleaner' => 0.15, 'bathroom_descaler' => 0.12, 'melamine_sponges' => 4, 'microfiber_cloths' => 8], metadata: ['seasonal' => true, 'demand_profile' => 'spring_peak']),
            self::service('move_in_out_cleaning', 'residential', 'Nettoyage emménagement ou déménagement', 'Remise à zéro d’un logement vide avant remise des clés ou arrivée des occupants.', 360, 525.00, 3, 'fixed', ['residentiel', 'demenagement'], '#0F766E', ['degreaser' => 0.15, 'bathroom_descaler' => 0.12, 'glass_cleaner' => 0.12, 'melamine_sponges' => 6], metadata: ['demand_profile' => 'seasonal', 'seasonal' => true]),
            self::service('short_term_rental_turnover', 'residential', 'Rotation location courte durée', 'Nettoyage entre voyageurs, contrôle visuel, déchets et signalement des anomalies.', 180, 195.00, 2, 'per_visit', ['location_courte_duree', 'rotation'], '#059669', ['neutral_floor_cleaner' => 0.08, 'disinfectant_concentrate' => 0.06, 'microfiber_cloths' => 5, 'garbage_bags_medium' => 3], metadata: ['demand_profile' => 'summer_peak']),
            self::service('oven_fridge_addon', 'residential', 'Nettoyage du four et du réfrigérateur', 'Dégraissage intérieur des appareils et finition sans résidu.', 90, 95.00, 1, 'addon', ['residentiel', 'extra'], '#2DD4BF', ['degreaser' => 0.18, 'melamine_sponges' => 3, 'nitrile_gloves' => 2], metadata: ['bundle' => true]),
            self::service('recurring_office_cleaning', 'commercial', 'Entretien de bureaux récurrent', 'Entretien hors heures d’ouverture, postes partagés, salles de réunion et sanitaires.', 180, 315.00, 3, 'per_visit', ['commercial', 'bureaux', 'recurrent'], '#2563EB', ['neutral_floor_cleaner' => 0.14, 'disinfectant_concentrate' => 0.10, 'garbage_bags_large' => 5, 'microfiber_cloths' => 8], metadata: ['demand_profile' => 'high']),
            self::service('retail_space_cleaning', 'commercial', 'Entretien de commerce', 'Surfaces de vente, vitrines intérieures, comptoirs, sanitaires et planchers.', 180, 285.00, 2, 'per_visit', ['commercial', 'commerce'], '#3B82F6', ['neutral_floor_cleaner' => 0.12, 'glass_cleaner' => 0.10, 'garbage_bags_large' => 3, 'microfiber_cloths' => 6]),
            self::service('building_common_areas', 'commercial', 'Aires communes d’immeuble', 'Entrées, corridors, escaliers, ascenseurs et gestion des traces hivernales.', 240, 360.00, 3, 'per_visit', ['commercial', 'immeuble', 'recurrent'], '#1D4ED8', ['neutral_floor_cleaner' => 0.20, 'disinfectant_concentrate' => 0.08, 'mop_heads' => 1, 'garbage_bags_large' => 5], metadata: ['demand_profile' => 'winter_peak']),
            self::service('high_touch_disinfection', 'commercial', 'Désinfection des points de contact', 'Protocole ciblé sur poignées, interrupteurs, comptoirs et surfaces partagées.', 120, 220.00, 2, 'per_visit', ['commercial', 'desinfection'], '#60A5FA', ['disinfectant_concentrate' => 0.16, 'nitrile_gloves' => 6, 'microfiber_cloths' => 8, 'shoe_covers' => 4]),
            self::service('post_event_cleaning', 'commercial', 'Nettoyage après événement', 'Ramassage, tri, sanitaires, surfaces et remise en état rapide du lieu.', 300, 475.00, 4, 'fixed', ['commercial', 'evenement'], '#1E40AF', ['neutral_floor_cleaner' => 0.20, 'degreaser' => 0.12, 'garbage_bags_large' => 12, 'nitrile_gloves' => 8], metadata: ['seasonal' => true, 'demand_profile' => 'holiday_peak']),
            self::service('light_post_construction', 'specialized', 'Nettoyage post-chantier léger', 'Dépoussiérage fin, surfaces, sanitaires et planchers avant livraison.', 480, 1250.00, 4, 'fixed', ['post_chantier', 'specialise'], '#7C3AED', ['neutral_floor_cleaner' => 0.28, 'hepa_vacuum_bags' => 2, 'scraper_blades' => 4, 'nitrile_gloves' => 12], metadata: ['demand_profile' => 'project']),
            self::service('complete_post_construction', 'specialized', 'Nettoyage post-chantier complet', 'Remise à neuf complète en plusieurs zones avec contrôle qualité et preuves photo.', 720, 2350.00, 6, 'fixed', ['post_chantier', 'specialise', 'premium'], '#6D28D9', ['neutral_floor_cleaner' => 0.45, 'hepa_vacuum_bags' => 4, 'scraper_blades' => 8, 'nitrile_gloves' => 18, 'shoe_covers' => 12], metadata: ['demand_profile' => 'project', 'requires_site_assessment' => true]),
            self::service('residential_windows', 'specialized', 'Vitres résidentielles', 'Lavage intérieur et extérieur accessible, cadres et rebords.', 240, 350.00, 2, 'fixed', ['vitres', 'residentiel'], '#8B5CF6', ['glass_cleaner' => 0.24, 'microfiber_cloths' => 8, 'scraper_blades' => 3], metadata: ['seasonal' => true, 'demand_profile' => 'spring_peak']),
            self::service('commercial_windows', 'specialized', 'Vitres commerciales', 'Vitrines et surfaces vitrées commerciales avec préparation sécurisée.', 360, 725.00, 3, 'fixed', ['vitres', 'commercial'], '#9333EA', ['glass_cleaner' => 0.40, 'microfiber_cloths' => 12, 'scraper_blades' => 6], metadata: ['requires_site_assessment' => true]),
            self::service('carpet_extraction', 'specialized', 'Extraction de tapis', 'Prétraitement, extraction et contrôle d’humidité des tapis commerciaux ou résidentiels.', 300, 595.00, 2, 'fixed', ['tapis', 'specialise'], '#A855F7', ['carpet_extraction_solution' => 0.35, 'degreaser' => 0.05, 'nitrile_gloves' => 4]),
            self::service('floor_strip_wax', 'specialized', 'Décapage et cirage de planchers', 'Décapage, neutralisation et application contrôlée du fini à plancher.', 480, 1450.00, 4, 'fixed', ['planchers', 'specialise', 'premium'], '#581C87', ['floor_stripper' => 0.80, 'floor_finish' => 1.20, 'mop_heads' => 3, 'nitrile_gloves' => 10], metadata: ['requires_site_assessment' => true, 'demand_profile' => 'project']),
            self::service('urgent_recovery_cleaning', 'urgent', 'Remise en état urgente', 'Intervention prioritaire après incident, absence ou insatisfaction documentée.', 240, 480.00, 3, 'fixed', ['urgence', 'reprise_qualite'], '#DC2626', ['absorbent_granules' => 0.50, 'disinfectant_concentrate' => 0.16, 'nitrile_gloves' => 8, 'garbage_bags_large' => 6], metadata: ['demand_profile' => 'low', 'quality_recovery' => true]),
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    public static function suppliers(): array
    {
        return [
            ['key' => 'hygiene_rive_sud', 'name' => 'Hygiène Rive-Sud', 'email' => 'commandes@hygiene-rive-sud.example', 'phone' => '+1 450 555 0134', 'city' => 'Longueuil'],
            ['key' => 'ecopro_distribution', 'name' => 'ÉcoPro Distribution', 'email' => 'ventes@ecopro-distribution.example', 'phone' => '+1 450 555 0172', 'city' => 'Laval'],
            ['key' => 'solutions_planchers_metropole', 'name' => 'Solutions Planchers Métropole', 'email' => 'service@planchers-metropole.example', 'phone' => '+1 514 555 0128', 'city' => 'Montréal'],
            ['key' => 'securite_entretien_quebec', 'name' => 'Sécurité Entretien Québec', 'email' => 'pro@securite-entretien.example', 'phone' => '+1 438 555 0165', 'city' => 'Brossard'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function products(): array
    {
        return [
            self::product('neutral_floor_cleaner', 'chemicals', 'Nettoyant neutre pour planchers 4 L', 'hygiene_rive_sud', 18.00, 32.00, 36, 12, 'bidon'),
            self::product('degreaser', 'chemicals', 'Dégraissant cuisine 4 L', 'hygiene_rive_sud', 22.00, 39.00, 24, 8, 'bidon'),
            self::product('disinfectant_concentrate', 'chemicals', 'Désinfectant concentré 4 L', 'hygiene_rive_sud', 26.00, 46.00, 20, 8, 'bidon'),
            self::product('glass_cleaner', 'chemicals', 'Nettoyant pour vitres 4 L', 'hygiene_rive_sud', 16.00, 29.00, 28, 10, 'bidon'),
            self::product('bathroom_descaler', 'chemicals', 'Détartrant pour salles de bain 4 L', 'hygiene_rive_sud', 21.00, 38.00, 18, 7, 'bidon'),
            self::product('carpet_extraction_solution', 'specialty_chemicals', 'Solution d’extraction pour tapis 4 L', 'ecopro_distribution', 29.00, 52.00, 11, 5, 'bidon'),
            self::product('floor_stripper', 'floor_care', 'Décapant pour planchers 4 L', 'solutions_planchers_metropole', 31.00, 58.00, 9, 4, 'bidon'),
            self::product('floor_finish', 'floor_care', 'Fini à plancher 4 L', 'solutions_planchers_metropole', 38.00, 69.00, 4, 6, 'bidon', metadata: ['stock_state' => 'low']),
            self::product('microfiber_cloths', 'reusable_supplies', 'Linges microfibres · paquet de 24', 'ecopro_distribution', 28.00, 49.00, 20, 8, 'paquet'),
            self::product('mop_heads', 'reusable_supplies', 'Tête de vadrouille professionnelle', 'ecopro_distribution', 9.00, 18.00, 16, 6, 'unite'),
            self::product('nitrile_gloves', 'protective_supplies', 'Gants de nitrile · boîte de 100', 'securite_entretien_quebec', 12.00, 24.00, 42, 16, 'boite'),
            self::product('garbage_bags_medium', 'disposable_supplies', 'Sacs à déchets 26 × 36 · boîte', 'ecopro_distribution', 18.00, 32.00, 30, 12, 'boite'),
            self::product('garbage_bags_large', 'disposable_supplies', 'Sacs à déchets 35 × 50 · boîte', 'ecopro_distribution', 27.00, 46.00, 18, 8, 'boite'),
            self::product('hepa_vacuum_bags', 'equipment_supplies', 'Sacs HEPA pour aspirateur · paquet', 'ecopro_distribution', 18.00, 34.00, 3, 5, 'paquet', metadata: ['stock_state' => 'low']),
            self::product('melamine_sponges', 'disposable_supplies', 'Éponges de mélamine · paquet', 'ecopro_distribution', 9.00, 18.00, 22, 8, 'paquet'),
            self::product('scraper_blades', 'equipment_supplies', 'Lames de grattoir pour vitres · paquet', 'securite_entretien_quebec', 11.00, 21.00, 12, 5, 'paquet'),
            self::product('shoe_covers', 'protective_supplies', 'Couvre-chaussures · boîte de 100', 'securite_entretien_quebec', 15.00, 28.00, 25, 10, 'boite'),
            self::product('absorbent_granules', 'incident_supplies', 'Absorbant d’incident · sac', 'securite_entretien_quebec', 20.00, 36.00, 8, 4, 'sac'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function offerPackages(): array
    {
        return [
            self::offerPackage('maison_sereine_2', 'Maison Sereine · 2 passages par mois', 'forfait', 'Deux entretiens résidentiels par mois avec renouvellement et suivi des préférences.', 319.00, [['service_key' => 'recurring_home_cleaning', 'quantity' => 2]], 31, 2, 'visit', true, 'monthly'),
            self::offerPackage('maison_sereine_4', 'Maison Sereine Plus · 4 passages par mois', 'forfait', 'Un entretien résidentiel hebdomadaire à tarif contractuel.', 599.00, [['service_key' => 'recurring_home_cleaning', 'quantity' => 4]], 31, 4, 'visit', true, 'monthly'),
            self::offerPackage('passeport_location_5', 'Passeport Location · 5 rotations', 'forfait', 'Cinq rotations flexibles pour propriétaires de locations courte durée.', 825.00, [['service_key' => 'short_term_rental_turnover', 'quantity' => 5]], 90, 5, 'visit'),
            self::offerPackage('grand_depart', 'Grand Départ sans stress', 'pack', 'Nettoyage de déménagement, appareils et vitres réunis avant la remise des clés.', 875.00, [['service_key' => 'move_in_out_cleaning', 'quantity' => 1], ['service_key' => 'oven_fridge_addon', 'quantity' => 1], ['service_key' => 'residential_windows', 'quantity' => 1]]),
            self::offerPackage('printemps_impeccable', 'Printemps impeccable', 'pack', 'Grand ménage, vitres et extraction ciblée pour relancer la saison.', 1125.00, [['service_key' => 'deep_home_cleaning', 'quantity' => 1], ['service_key' => 'residential_windows', 'quantity' => 1], ['service_key' => 'carpet_extraction', 'quantity' => 1]]),
            self::offerPackage('bureau_constant_12', 'Bureau Constant · 12 passages par mois', 'forfait', 'Douze passages commerciaux regroupés dans une facturation mensuelle prévisible.', 3480.00, [['service_key' => 'recurring_office_cleaning', 'quantity' => 12]], 31, 12, 'visit', true, 'monthly'),
        ];
    }

    /**
     * @return array<string, array{lead_service_keys:list<string>,support_service_keys:list<string>}>
     */
    public static function employeeServiceMatrix(): array
    {
        return [
            'amelie_gagnon' => [
                'lead_service_keys' => ['site_assessment', 'light_post_construction', 'complete_post_construction', 'commercial_windows', 'floor_strip_wax', 'urgent_recovery_cleaning'],
                'support_service_keys' => ['recurring_office_cleaning', 'building_common_areas', 'post_event_cleaning'],
            ],
            'mariam_diallo' => [
                'lead_service_keys' => ['site_assessment', 'high_touch_disinfection', 'urgent_recovery_cleaning'],
                'support_service_keys' => ['deep_home_cleaning', 'move_in_out_cleaning', 'complete_post_construction'],
            ],
            'jose_alvarez' => [
                'lead_service_keys' => ['recurring_office_cleaning', 'retail_space_cleaning', 'building_common_areas', 'high_touch_disinfection', 'post_event_cleaning', 'light_post_construction'],
                'support_service_keys' => ['complete_post_construction', 'commercial_windows', 'floor_strip_wax', 'urgent_recovery_cleaning'],
            ],
            'fatou_ndiaye' => [
                'lead_service_keys' => ['recurring_home_cleaning', 'deep_home_cleaning', 'move_in_out_cleaning', 'short_term_rental_turnover', 'oven_fridge_addon'],
                'support_service_keys' => ['residential_windows', 'carpet_extraction', 'urgent_recovery_cleaning'],
            ],
            'alexandre_nguyen' => [
                'lead_service_keys' => ['residential_windows', 'commercial_windows', 'carpet_extraction', 'floor_strip_wax'],
                'support_service_keys' => ['light_post_construction', 'complete_post_construction', 'urgent_recovery_cleaning'],
            ],
            'naomi_saint_pierre' => [
                'lead_service_keys' => ['recurring_home_cleaning', 'short_term_rental_turnover', 'oven_fridge_addon'],
                'support_service_keys' => ['deep_home_cleaning', 'move_in_out_cleaning', 'residential_windows'],
            ],
            'samuel_roy' => [
                'lead_service_keys' => ['retail_space_cleaning', 'post_event_cleaning', 'urgent_recovery_cleaning'],
                'support_service_keys' => ['recurring_office_cleaning', 'building_common_areas', 'light_post_construction', 'complete_post_construction', 'commercial_windows', 'carpet_extraction', 'floor_strip_wax'],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function clientStories(): array
    {
        return [
            [
                'key' => 'groupe_lavoie_immeubles',
                'name' => 'Groupe Lavoie Immeubles',
                'archetype' => 'retained_multi_site_commercial_account',
                'lifecycle_state' => 'active_customer',
                'profile' => [
                    'client_type' => 'company',
                    'contact_name' => 'Jean-Philippe Lavoie',
                    'customer_since_months' => 12,
                    'preferred_employee_key' => 'jose_alvarez',
                    'service_keys' => ['building_common_areas', 'urgent_recovery_cleaning'],
                    'property_count' => 3,
                    'billing' => ['mode' => 'per_task', 'cycle' => 'monthly', 'grouping' => 'periodic', 'delay_days' => 30],
                    'tags' => ['commercial', 'multi_site', 'mensuel', 'client_retenu'],
                    'internal_note' => 'Portes coupe-feu à maintenir fermées; insister sur les traces de sel dans les vestibules en hiver.',
                    'marketing_consent' => true,
                ],
                'expected_records' => ['properties' => 3, 'completed_works' => 92, 'paid_invoices' => 11, 'partial_invoices' => 1, 'resolved_disputes' => 1, 'work_ratings' => 2],
                'timeline' => [
                    ['event' => 'service_request_received', 'offset_days' => -363],
                    ['event' => 'site_assessment_completed', 'offset_days' => -356],
                    ['event' => 'quote_accepted', 'offset_days' => -345],
                    ['event' => 'recurring_work_started', 'offset_days' => -338],
                    ['event' => 'winter_quality_claim_received', 'offset_days' => -220],
                    ['event' => 'work_marked_dispute', 'offset_days' => -220],
                    ['event' => 'urgent_recovery_completed', 'offset_days' => -219],
                    ['event' => 'client_retention_confirmed', 'offset_days' => -215],
                    ['event' => 'third_property_added', 'offset_days' => -90],
                ],
            ],
            [
                'key' => 'clinique_du_parc',
                'name' => 'Clinique du Parc',
                'archetype' => 'stable_evening_commercial_contract',
                'lifecycle_state' => 'active_customer',
                'profile' => [
                    'client_type' => 'company',
                    'contact_name' => 'Sophie Beaulieu',
                    'customer_since_months' => 10,
                    'preferred_employee_key' => 'jose_alvarez',
                    'service_keys' => ['recurring_office_cleaning', 'high_touch_disinfection'],
                    'property_count' => 1,
                    'billing' => ['mode' => 'per_task', 'cycle' => 'monthly', 'grouping' => 'periodic', 'delay_days' => 30],
                    'tags' => ['commercial', 'soir', 'net_30', 'controle_qualite'],
                    'internal_note' => 'Intervenir après 18 h; ne jamais déplacer les dossiers et confirmer l’armement du système avant le départ.',
                    'marketing_consent' => true,
                ],
                'expected_records' => ['completed_works' => 116, 'paid_invoices' => 10, 'sent_invoices' => 1, 'quality_inspections' => 10, 'work_ratings' => 1],
                'timeline' => [
                    ['event' => 'service_request_received', 'offset_days' => -330],
                    ['event' => 'quote_sent', 'offset_days' => -321],
                    ['event' => 'quote_accepted', 'offset_days' => -316],
                    ['event' => 'evening_contract_started', 'offset_days' => -308],
                    ['event' => 'first_quality_inspection_passed', 'offset_days' => -280],
                    ['event' => 'annual_renewal_discussion_opened', 'offset_days' => -12],
                ],
            ],
            [
                'key' => 'camille_fortin',
                'name' => 'Camille Fortin',
                'archetype' => 'loyal_fragrance_sensitive_residential_client',
                'lifecycle_state' => 'active_customer',
                'profile' => [
                    'client_type' => 'individual',
                    'customer_since_months' => 11,
                    'preferred_employee_key' => 'fatou_ndiaye',
                    'service_keys' => ['recurring_home_cleaning', 'deep_home_cleaning'],
                    'property_count' => 1,
                    'offer_package_key' => 'maison_sereine_2',
                    'billing' => ['mode' => 'per_task', 'cycle' => 'monthly', 'grouping' => 'periodic', 'delay_days' => 0],
                    'tags' => ['residentiel', 'fidele', 'sans_parfum', 'reference_client'],
                    'internal_note' => 'Allergie aux parfums; employer uniquement les produits neutres identifiés au dossier.',
                    'marketing_consent' => true,
                ],
                'expected_records' => ['completed_works' => 21, 'paid_invoices' => 11, 'package_renewals' => 10, 'referrals' => 1, 'work_ratings' => 2],
                'timeline' => [
                    ['event' => 'customer_created', 'offset_days' => -320],
                    ['event' => 'first_home_cleaning_completed', 'offset_days' => -312],
                    ['event' => 'fragrance_preference_recorded', 'offset_days' => -311],
                    ['event' => 'recurring_package_started', 'offset_days' => -300],
                    ['event' => 'five_star_rating_received', 'offset_days' => -120],
                    ['event' => 'referred_customer_converted', 'offset_days' => -42],
                    ['event' => 'next_visit_scheduled', 'offset_days' => 9],
                ],
            ],
            [
                'key' => 'gestion_loft_514',
                'name' => 'Gestion Loft 514',
                'archetype' => 'high_velocity_short_term_rental_account',
                'lifecycle_state' => 'active_customer',
                'profile' => [
                    'client_type' => 'company',
                    'contact_name' => 'Mélanie Côté',
                    'customer_since_months' => 9,
                    'preferred_employee_key' => 'naomi_saint_pierre',
                    'service_keys' => ['short_term_rental_turnover', 'urgent_recovery_cleaning'],
                    'property_count' => 3,
                    'offer_package_key' => 'passeport_location_5',
                    'billing' => ['mode' => 'per_task', 'cycle' => 'biweekly', 'grouping' => 'periodic', 'delay_days' => 7],
                    'tags' => ['commercial', 'location_courte_duree', 'multi_site', 'prioritaire'],
                    'internal_note' => 'Codes d’accès renouvelés chaque mois; signaler immédiatement tout dommage ou article manquant.',
                    'marketing_consent' => true,
                ],
                'expected_records' => ['properties' => 3, 'completed_works' => 58, 'reassigned_tasks' => 1, 'client_notifications' => 1, 'paid_invoices' => 18],
                'timeline' => [
                    ['event' => 'customer_created', 'offset_days' => -280],
                    ['event' => 'first_turnover_completed', 'offset_days' => -274],
                    ['event' => 'third_property_added', 'offset_days' => -132],
                    ['event' => 'employee_absence_detected', 'offset_days' => -36],
                    ['event' => 'urgent_task_reassigned', 'offset_days' => -36],
                    ['event' => 'client_notified_of_delay', 'offset_days' => -36],
                    ['event' => 'turnover_completed_before_guest_arrival', 'offset_days' => -36],
                ],
            ],
            [
                'key' => 'construction_horizon',
                'name' => 'Construction Horizon',
                'archetype' => 'post_construction_project_with_change_order',
                'lifecycle_state' => 'active_customer',
                'profile' => [
                    'client_type' => 'company',
                    'contact_name' => 'Karim Ouellet',
                    'customer_since_months' => 4,
                    'preferred_employee_key' => 'amelie_gagnon',
                    'service_keys' => ['site_assessment', 'complete_post_construction', 'commercial_windows'],
                    'property_count' => 1,
                    'billing' => ['mode' => 'end_of_job', 'cycle' => null, 'grouping' => 'single', 'delay_days' => 15],
                    'tags' => ['commercial', 'post_chantier', 'depot_recu', 'portee_modifiee'],
                    'internal_note' => 'Coordonner l’accès avec le surintendant; conserver toutes les preuves photo avant la remise des clés.',
                    'marketing_consent' => true,
                ],
                'expected_records' => ['accepted_quotes' => 1, 'completed_deposits' => 1, 'completed_works' => 1, 'completed_tasks' => 12, 'work_media' => 26, 'partial_invoices' => 1],
                'timeline' => [
                    ['event' => 'service_request_received', 'offset_days' => -106],
                    ['event' => 'site_assessment_completed', 'offset_days' => -101],
                    ['event' => 'quote_sent', 'offset_days' => -95, 'amount' => 7820.00],
                    ['event' => 'quote_accepted', 'offset_days' => -88],
                    ['event' => 'deposit_paid', 'offset_days' => -87, 'share_of_quote' => 0.30],
                    ['event' => 'post_construction_work_started', 'offset_days' => -70],
                    ['event' => 'window_scope_added', 'offset_days' => -64],
                    ['event' => 'quality_review_validated', 'offset_days' => -60],
                    ['event' => 'final_invoice_partially_paid', 'offset_days' => -40],
                ],
            ],
            [
                'key' => 'elodie_nguyen',
                'name' => 'Élodie Nguyen',
                'archetype' => 'quality_recovery_after_move_out_claim',
                'lifecycle_state' => 'active_customer',
                'profile' => [
                    'client_type' => 'individual',
                    'customer_since_months' => 2,
                    'preferred_employee_key' => 'fatou_ndiaye',
                    'service_keys' => ['move_in_out_cleaning', 'oven_fridge_addon', 'urgent_recovery_cleaning'],
                    'property_count' => 1,
                    'billing' => ['mode' => 'end_of_job', 'cycle' => null, 'grouping' => 'single', 'delay_days' => 0],
                    'tags' => ['residentiel', 'demenagement', 'incident_resolu', 'recuperation_qualite'],
                    'internal_note' => 'Le four avait été omis lors du premier passage; vérifier les appareils sur toute intervention future.',
                    'marketing_consent' => true,
                ],
                'expected_records' => ['accepted_quotes' => 1, 'disputed_works' => 1, 'recovery_works' => 1, 'credit_adjustments' => 1, 'work_ratings' => 2],
                'timeline' => [
                    ['event' => 'service_request_received', 'offset_days' => -48],
                    ['event' => 'quote_accepted', 'offset_days' => -44],
                    ['event' => 'move_out_work_completed', 'offset_days' => -35],
                    ['event' => 'missed_oven_claim_received', 'offset_days' => -34],
                    ['event' => 'work_marked_dispute', 'offset_days' => -34],
                    ['event' => 'recovery_work_completed', 'offset_days' => -33],
                    ['event' => 'commercial_credit_applied', 'offset_days' => -32],
                    ['event' => 'positive_follow_up_rating_received', 'offset_days' => -29],
                ],
            ],
            [
                'key' => 'atelier_mile_end',
                'name' => 'Atelier Mile End',
                'archetype' => 'current_qualified_commercial_opportunity',
                'lifecycle_state' => 'qualified_prospect',
                'profile' => [
                    'client_type' => 'company',
                    'contact_name' => 'Laurence Bérubé',
                    'customer_since_months' => 0,
                    'preferred_employee_key' => 'mariam_diallo',
                    'service_keys' => ['site_assessment', 'recurring_office_cleaning', 'commercial_windows'],
                    'property_count' => 1,
                    'billing' => ['mode' => 'per_task', 'cycle' => 'monthly', 'grouping' => 'periodic', 'delay_days' => 15],
                    'tags' => ['prospect', 'commercial', 'devis_en_attente', 'relance_planifiee'],
                    'internal_note' => 'Atelier partagé occupé en journée; proposer les passages du lundi, mercredi et vendredi après 18 h.',
                    'marketing_consent' => false,
                ],
                'expected_records' => ['service_requests' => 1, 'qualified_prospects' => 1, 'completed_assessments' => 1, 'sent_quotes' => 1, 'open_follow_up_tasks' => 1],
                'timeline' => [
                    ['event' => 'service_request_received', 'offset_days' => -7],
                    ['event' => 'prospect_qualified', 'offset_days' => -5],
                    ['event' => 'site_assessment_completed', 'offset_days' => -3],
                    ['event' => 'quote_sent', 'offset_days' => -1],
                    ['event' => 'follow_up_due', 'offset_days' => 2],
                ],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function qualityProtocols(): array
    {
        return [
            'residential_standard' => [
                'name' => 'Contrôle résidentiel standard',
                'service_keys' => ['recurring_home_cleaning', 'deep_home_cleaning', 'move_in_out_cleaning', 'short_term_rental_turnover'],
                'checklist' => ['Accès et préférences confirmés', 'Cuisine contrôlée', 'Salles de bain contrôlées', 'Surfaces et points de contact complétés', 'Planchers complétés', 'Déchets retirés', 'Photo finale ajoutée'],
                'proof_types' => ['after'],
            ],
            'commercial_close' => [
                'name' => 'Fermeture de site commercial',
                'service_keys' => ['recurring_office_cleaning', 'retail_space_cleaning', 'building_common_areas', 'high_touch_disinfection'],
                'checklist' => ['Accès enregistré', 'Zones prioritaires complétées', 'Sanitaires contrôlés', 'Déchets sortis', 'Anomalies consignées', 'Alarme et verrouillage confirmés'],
                'proof_types' => ['execution', 'after'],
            ],
            'post_construction_handoff' => [
                'name' => 'Livraison post-chantier',
                'service_keys' => ['light_post_construction', 'complete_post_construction'],
                'checklist' => ['Débris retirés', 'Poussière haute retirée', 'Poussière basse retirée', 'Vitrage contrôlé', 'Appareils et sanitaires contrôlés', 'Planchers contrôlés', 'Photos avant et après ajoutées', 'Validation superviseur obtenue'],
                'proof_types' => ['before', 'execution', 'after'],
            ],
            'incident_recovery' => [
                'name' => 'Récupération après incident qualité',
                'service_keys' => ['urgent_recovery_cleaning'],
                'checklist' => ['Réclamation reconnue', 'Client informé', 'Inspection superviseur réalisée', 'Correction exécutée', 'Preuves avant et après ajoutées', 'Suivi client complété', 'Dossier clôturé'],
                'proof_types' => ['before', 'after'],
                'status_path' => ['dispute', 'in_progress', 'pending_review', 'validated', 'closed'],
                'target_response_hours' => 2,
                'target_resolution_hours' => 24,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function expenseTemplates(): array
    {
        return [
            ['key' => 'vehicle_lease', 'name' => 'Location des véhicules de service', 'category' => 'fleet', 'frequency' => 'monthly', 'amount_range' => [1180.00, 1180.00], 'payment_method' => 'bank_transfer', 'due_day' => 1],
            ['key' => 'fuel', 'name' => 'Carburant de la flotte', 'category' => 'fuel', 'frequency' => 'weekly', 'amount_range' => [190.00, 340.00], 'payment_method' => 'card'],
            ['key' => 'chemicals', 'name' => 'Approvisionnement en produits de nettoyage', 'category' => 'inventory', 'frequency' => 'biweekly', 'amount_range' => [420.00, 980.00], 'payment_method' => 'card'],
            ['key' => 'insurance', 'name' => 'Assurance responsabilité et véhicules', 'category' => 'insurance', 'frequency' => 'monthly', 'amount_range' => [465.00, 465.00], 'payment_method' => 'bank_transfer', 'due_day' => 5],
            ['key' => 'equipment_rental', 'name' => 'Location d’équipement spécialisé', 'category' => 'equipment', 'frequency' => 'monthly', 'amount_range' => [180.00, 850.00], 'payment_method' => 'card'],
            ['key' => 'equipment_repair', 'name' => 'Entretien et réparation d’équipement', 'category' => 'maintenance', 'frequency' => 'quarterly', 'amount_range' => [225.00, 1250.00], 'payment_method' => 'card'],
            ['key' => 'uniforms', 'name' => 'Uniformes et équipement de protection', 'category' => 'workwear', 'frequency' => 'quarterly', 'amount_range' => [310.00, 720.00], 'payment_method' => 'card'],
            ['key' => 'laundry', 'name' => 'Buanderie commerciale', 'category' => 'supplies', 'frequency' => 'weekly', 'amount_range' => [85.00, 145.00], 'payment_method' => 'card'],
            ['key' => 'mobile_phones', 'name' => 'Téléphonie mobile de l’équipe', 'category' => 'utilities', 'frequency' => 'monthly', 'amount_range' => [235.00, 265.00], 'payment_method' => 'card', 'due_day' => 12],
            ['key' => 'operations_software', 'name' => 'Logiciels d’opérations', 'category' => 'software', 'frequency' => 'monthly', 'amount_range' => [185.00, 235.00], 'payment_method' => 'card', 'due_day' => 20],
            ['key' => 'local_marketing', 'name' => 'Publicité locale et campagnes', 'category' => 'marketing', 'frequency' => 'monthly', 'amount_range' => [280.00, 920.00], 'payment_method' => 'card'],
            ['key' => 'staff_reimbursement', 'name' => 'Remboursement terrain employé', 'category' => 'reimbursement', 'frequency' => 'monthly', 'amount_range' => [35.00, 165.00], 'payment_method' => 'interac', 'reimbursable' => true],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function seasonality(): array
    {
        return [
            'monthly_demand_multipliers' => [
                1 => 1.18,
                2 => 1.12,
                3 => 1.28,
                4 => 1.22,
                5 => 1.18,
                6 => 1.25,
                7 => 1.12,
                8 => 1.05,
                9 => 1.15,
                10 => 1.04,
                11 => 1.10,
                12 => 1.32,
            ],
            'weekday_demand_weights' => [
                1 => 1.08,
                2 => 1.00,
                3 => 1.00,
                4 => 1.00,
                5 => 1.12,
                6 => 0.78,
                7 => 0.15,
            ],
            'daypart_weights' => [
                'residential_day' => 0.58,
                'commercial_evening' => 0.34,
                'weekend_and_urgent' => 0.08,
            ],
            'customer_growth_monthly_rate' => 0.022,
            'events' => [
                ['key' => 'winter_salt', 'months' => [1, 2], 'service_tags' => ['immeuble', 'planchers'], 'demand_multiplier' => 1.18],
                ['key' => 'spring_cleaning', 'months' => [3, 4, 5], 'service_tags' => ['saisonnier', 'vitres', 'profondeur'], 'demand_multiplier' => 1.28],
                ['key' => 'moving_and_construction', 'months' => [5, 6, 7], 'service_tags' => ['demenagement', 'post_chantier'], 'demand_multiplier' => 1.24],
                ['key' => 'short_term_rental_peak', 'months' => [6, 7, 8], 'service_tags' => ['location_courte_duree'], 'demand_multiplier' => 1.20],
                ['key' => 'office_return', 'months' => [9], 'service_tags' => ['bureaux', 'commercial'], 'demand_multiplier' => 1.15],
                ['key' => 'holiday_rush', 'months' => [11, 12], 'service_tags' => ['evenement', 'residentiel'], 'demand_multiplier' => 1.26],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function paymentMethods(): array
    {
        return [
            ['key' => 'bank_transfer', 'label' => 'Virement bancaire', 'record_type' => 'payment', 'internal_method' => 'bank_transfer', 'weight' => 0.40],
            ['key' => 'credit_card', 'label' => 'Carte de crédit', 'record_type' => 'payment', 'internal_method' => 'card', 'weight' => 0.25],
            ['key' => 'interac_transfer', 'label' => 'Virement Interac', 'record_type' => 'payment', 'internal_method' => 'bank_transfer', 'weight' => 0.17],
            ['key' => 'online_card', 'label' => 'Paiement en ligne', 'record_type' => 'payment', 'internal_method' => 'card', 'weight' => 0.08],
            ['key' => 'cheque', 'label' => 'Chèque', 'record_type' => 'payment', 'internal_method' => 'cheque', 'weight' => 0.07],
            ['key' => 'cash', 'label' => 'Espèces', 'record_type' => 'payment', 'internal_method' => 'cash', 'weight' => 0.03],
        ];
    }

    /**
     * @param  list<string>  $specialties
     * @param  list<string>  $permissions
     * @param  array<int, array{starts_at:string,ends_at:string}>  $schedule
     * @param  array<string, int|float>  $performanceProfile
     * @param  list<array<string, int|string>>  $absenceTemplates
     * @return array<string, mixed>
     */
    private static function employee(
        string $key,
        string $name,
        string $roleKey,
        string $title,
        array $specialties,
        array $permissions,
        array $schedule,
        array $performanceProfile,
        array $absenceTemplates,
        string $demoAccessRole,
    ): array {
        return [
            'key' => $key,
            'name' => $name,
            'role_key' => $roleKey,
            'title' => $title,
            'specialties' => $specialties,
            'permissions' => $permissions,
            'schedule' => $schedule,
            'performance_profile' => $performanceProfile,
            'absence_templates' => $absenceTemplates,
            'demo_access_role' => $demoAccessRole,
        ];
    }

    /**
     * @param  list<string>  $tags
     * @param  array<string, float|int>  $materials
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private static function service(
        string $key,
        string $categoryKey,
        string $name,
        string $description,
        int $durationMinutes,
        float $price,
        int $crewSize,
        string $pricingModel,
        array $tags,
        string $calendarColor,
        array $materials,
        bool $active = true,
        array $metadata = [],
    ): array {
        return [
            'key' => $key,
            'category_key' => $categoryKey,
            'name' => $name,
            'description' => $description,
            'duration_minutes' => $durationMinutes,
            'crew_size' => $crewSize,
            'pricing_model' => $pricingModel,
            'price' => $price,
            'currency_code' => 'CAD',
            'unit_type' => 'service',
            'tags' => $tags,
            'calendar_color' => $calendarColor,
            'active' => $active,
            'materials' => collect($materials)
                ->map(fn (float|int $quantity, string $productKey): array => [
                    'product_key' => $productKey,
                    'quantity' => $quantity,
                    'billable' => false,
                ])
                ->values()
                ->all(),
            'metadata' => array_merge([
                'demand_profile' => 'standard',
                'seasonal' => false,
                'bundle' => false,
                'requires_site_assessment' => false,
                'quality_recovery' => false,
                'price_history' => [],
            ], $metadata),
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
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
        bool $billable = false,
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
            'tracking_type' => 'stock',
            'billable' => $billable,
            'retail' => false,
            'active' => $active,
            'metadata' => array_merge([
                'stock_state' => $stockOnHand === 0
                    ? 'out_of_stock'
                    : ($stockOnHand <= $reorderThreshold ? 'low' : 'healthy'),
            ], $metadata),
        ];
    }

    /**
     * @param  list<array{service_key:string,quantity:int|float}>  $items
     * @return array<string, mixed>
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
        bool $recurring = false,
        ?string $recurrenceFrequency = null,
    ): array {
        return [
            'key' => $key,
            'name' => $name,
            'type' => $type,
            'status' => 'active',
            'description' => $description,
            'pricing_mode' => 'fixed',
            'price' => $price,
            'currency_code' => 'CAD',
            'validity_days' => $validityDays,
            'included_quantity' => $includedQuantity,
            'unit_type' => $unitType,
            'is_public' => true,
            'is_recurring' => $recurring,
            'recurrence_frequency' => $recurrenceFrequency,
            'items' => $items,
            'metadata' => [
                'scenario_key' => self::KEY,
                'sales_channel' => $type === 'forfait' ? 'quote_and_portal' : 'quote',
            ],
        ];
    }
}
