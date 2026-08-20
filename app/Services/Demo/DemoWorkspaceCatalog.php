<?php

namespace App\Services\Demo;

use App\Enums\DemoDataVolume;
use App\Models\MarketingSetting;
use App\Services\Demo\Scenarios\StudioNaya\StudioNayaBlueprint;
use App\Support\LocalePreference;
use Illuminate\Support\Arr;

class DemoWorkspaceCatalog
{
    /**
     * Modules that do not belong in the default salon experience.
     *
     * @var array<int, string>
     */
    private const SALON_DEFAULT_DISABLED_MODULES = [
        'requests',
        'quotes',
        'plan_scans',
        'jobs',
        'tasks',
        'products',
        'sales_crm',
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function companyTypes(): array
    {
        return [
            ['value' => 'services', 'label' => 'Services'],
            ['value' => 'products', 'label' => 'Commerce'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function sectors(): array
    {
        return [
            ['value' => 'salon', 'label' => 'Salon / beauty'],
            ['value' => 'wellness', 'label' => 'Wellness / spa'],
            ['value' => 'restaurant', 'label' => 'Restaurant / hospitality'],
            ['value' => 'field_services', 'label' => 'Field services'],
            ['value' => 'professional_services', 'label' => 'Professional services'],
            ['value' => 'retail', 'label' => 'Retail'],
            ['value' => 'other', 'label' => 'Other'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function seedProfiles(): array
    {
        return [
            [
                'value' => 'light',
                'label' => 'Light',
                'description' => 'Fast setup with a compact sample dataset.',
                'counts' => [
                    'customers' => 6,
                    'catalog' => 6,
                    'quotes' => 2,
                    'works' => 2,
                    'tasks' => 4,
                    'reservations' => 5,
                    'queue' => 3,
                    'sales' => 2,
                    'expenses' => 4,
                    'team' => 2,
                ],
            ],
            [
                'value' => 'standard',
                'label' => 'Standard',
                'description' => 'Balanced environment for most prospect demos.',
                'counts' => [
                    'customers' => 12,
                    'catalog' => 10,
                    'quotes' => 4,
                    'works' => 4,
                    'tasks' => 8,
                    'reservations' => 9,
                    'queue' => 4,
                    'sales' => 4,
                    'expenses' => 7,
                    'team' => 3,
                ],
            ],
            [
                'value' => 'immersive',
                'label' => 'Immersive',
                'description' => 'Denser environment for deeper, role-based demos.',
                'counts' => [
                    'customers' => 20,
                    'catalog' => 16,
                    'quotes' => 7,
                    'works' => 6,
                    'tasks' => 12,
                    'reservations' => 14,
                    'queue' => 6,
                    'sales' => 6,
                    'expenses' => 10,
                    'team' => 4,
                ],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function dataVolumes(): array
    {
        return [
            [
                'value' => DemoDataVolume::Small->value,
                'label' => 'Small',
                'description' => 'A fast, compact dataset for smoke tests and short walkthroughs.',
                'counts' => (array) config('demo_scenarios.volumes.small', []),
            ],
            [
                'value' => DemoDataVolume::Medium->value,
                'label' => 'Medium',
                'description' => 'The standard narrative dataset with enough history for reporting.',
                'counts' => (array) config('demo_scenarios.volumes.medium', []),
            ],
            [
                'value' => DemoDataVolume::Large->value,
                'label' => 'Large',
                'description' => 'A high-volume dataset intended for advanced demos and performance checks.',
                'counts' => (array) config('demo_scenarios.volumes.large', []),
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function dataVolumeKeys(): array
    {
        return array_column($this->dataVolumes(), 'value');
    }

    /**
     * Scenario engines are intentionally distinct from the lightweight walkthrough packs.
     *
     * @return array<int, array<string, mixed>>
     */
    public function scenarioDefinitions(): array
    {
        return collect((array) config('demo_scenarios.scenarios', []))
            ->map(function (mixed $configuration, string $key): ?array {
                $configuration = is_array($configuration) ? $configuration : [];
                $blueprintClass = $configuration['blueprint'] ?? null;

                if (! is_string($blueprintClass)
                    || ! class_exists($blueprintClass)
                    || ! method_exists($blueprintClass, 'definition')) {
                    return null;
                }

                $definition = (array) $blueprintClass::definition();
                $identity = (array) ($definition['identity'] ?? []);
                $scenarioKey = strtolower(trim((string) ($definition['key'] ?? $key)));

                if ($scenarioKey === '') {
                    return null;
                }

                return [
                    'key' => $scenarioKey,
                    'label' => (string) ($configuration['label'] ?? $identity['name'] ?? $scenarioKey),
                    'description' => (string) ($configuration['description']
                        ?? ($scenarioKey === StudioNayaBlueprint::KEY
                            ? 'An 18-month Montreal salon story with linked appointments, billing, inventory, expenses, and notifications.'
                            : 'A deterministic, resettable business narrative.')),
                    'version' => (int) ($definition['version'] ?? 1),
                    'default_volume' => (string) ($configuration['default_volume'] ?? $definition['default_volume'] ?? DemoDataVolume::Medium->value),
                    'available_volumes' => array_values((array) ($configuration['available_volumes'] ?? $this->dataVolumeKeys())),
                    'required_modules' => array_values(array_unique(array_map(
                        'strval',
                        (array) ($configuration['required_modules'] ?? $definition['required_modules'] ?? [])
                    ))),
                    'reference_timezone' => (string) ($configuration['reference_timezone'] ?? $identity['timezone'] ?? 'UTC'),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function scenarioKeys(): array
    {
        return array_column($this->scenarioDefinitions(), 'key');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function scenarioDefinition(?string $key): ?array
    {
        $key = strtolower(trim((string) $key));

        if ($key === '') {
            return null;
        }

        return collect($this->scenarioDefinitions())->firstWhere('key', $key);
    }

    /**
     * @return array<int, string>
     */
    public function requiredModulesForScenario(?string $key): array
    {
        $definition = $this->scenarioDefinition($key);

        return array_values(array_intersect(
            array_map('strval', (array) ($definition['required_modules'] ?? [])),
            $this->moduleKeys(),
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function timezones(): array
    {
        return [
            ['value' => 'America/Toronto', 'label' => 'America/Toronto'],
            ['value' => 'America/New_York', 'label' => 'America/New_York'],
            ['value' => 'Europe/Paris', 'label' => 'Europe/Paris'],
            ['value' => 'Europe/London', 'label' => 'Europe/London'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function locales(): array
    {
        return array_map(
            fn (string $locale) => [
                'value' => $locale,
                'label' => match ($locale) {
                    'fr' => 'French',
                    'es' => 'Spanish',
                    default => 'English',
                },
            ],
            LocalePreference::supported()
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function modules(): array
    {
        return [
            'requests' => [
                'key' => 'requests',
                'label' => 'Prospects & requests',
                'description' => 'Prospect qualification plus service request intake and next follow-ups.',
                'category' => 'Pipeline',
                'company_types' => ['services'],
            ],
            'quotes' => [
                'key' => 'quotes',
                'label' => 'Quotes',
                'description' => 'Commercial proposals linked to prospect or customer work.',
                'category' => 'Pipeline',
                'company_types' => ['services'],
            ],
            'plan_scans' => [
                'key' => 'plan_scans',
                'label' => 'Plan scans',
                'description' => 'Show the module as available for technical estimation demos.',
                'category' => 'Pipeline',
                'company_types' => ['services'],
            ],
            'jobs' => [
                'key' => 'jobs',
                'label' => 'Jobs',
                'description' => 'Operational work orders with statuses and ownership.',
                'category' => 'Operations',
                'company_types' => ['services'],
            ],
            'tasks' => [
                'key' => 'tasks',
                'label' => 'Tasks',
                'description' => 'Internal execution, follow-up, and team handoffs.',
                'category' => 'Operations',
                'company_types' => ['services'],
            ],
            'services' => [
                'key' => 'services',
                'label' => 'Service catalog',
                'description' => 'Services ready for quoting, scheduling, and delivery.',
                'category' => 'Catalog',
                'company_types' => ['services'],
            ],
            'products' => [
                'key' => 'products',
                'label' => 'Product catalog',
                'description' => 'Products with prices and stock-ready data.',
                'category' => 'Catalog',
                'company_types' => ['services', 'products'],
            ],
            'invoices' => [
                'key' => 'invoices',
                'label' => 'Invoices',
                'description' => 'Bills, line items, and example payment states.',
                'category' => 'Revenue',
                'company_types' => ['services', 'products'],
            ],
            'sales' => [
                'key' => 'sales',
                'label' => 'Sales',
                'description' => 'POS and order history for commerce demos.',
                'category' => 'Revenue',
                'company_types' => ['services', 'products'],
            ],
            'sales_crm' => [
                'key' => 'sales_crm',
                'label' => 'Advanced sales CRM',
                'description' => 'Next actions, sales inbox, and manager pipeline dashboard.',
                'category' => 'Pipeline',
                'company_types' => ['services'],
            ],
            'promotions' => [
                'key' => 'promotions',
                'label' => 'Promotions',
                'description' => 'Promo codes, seasonal discounts, and targeted commercial offers.',
                'category' => 'Revenue',
                'company_types' => ['services', 'products'],
            ],
            'expenses' => [
                'key' => 'expenses',
                'label' => 'Expenses',
                'description' => 'Operational spend tracking with receipts, statuses, and finance follow-up.',
                'category' => 'Finance',
                'company_types' => ['services', 'products'],
            ],
            'accounting' => [
                'key' => 'accounting',
                'label' => 'Accounting',
                'description' => 'Accounting journal, taxes, review workflow, periods, and mobile-ready finance supervision.',
                'category' => 'Finance',
                'company_types' => ['services', 'products'],
            ],
            'reservations' => [
                'key' => 'reservations',
                'label' => 'Reservations & queue',
                'description' => 'Booking, waitlist, kiosk, and queue scenarios.',
                'category' => 'Operations',
                'company_types' => ['services'],
            ],
            'planning' => [
                'key' => 'planning',
                'label' => 'Planning',
                'description' => 'Team schedules, working hours, and shift visibility.',
                'category' => 'Operations',
                'company_types' => ['services'],
            ],
            'team_members' => [
                'key' => 'team_members',
                'label' => 'Team members',
                'description' => 'Staff accounts and role-based views inside the demo.',
                'category' => 'Operations',
                'company_types' => ['services', 'products'],
            ],
            'campaigns' => [
                'key' => 'campaigns',
                'label' => 'Campaigns',
                'description' => 'Marketing workspace with lists and draft campaigns.',
                'category' => 'Growth',
                'company_types' => ['services', 'products'],
            ],
            'social' => [
                'key' => 'social',
                'label' => 'Social',
                'description' => 'Social content planning, approval, and publishing workflows.',
                'category' => 'Growth',
                'company_types' => ['services', 'products'],
            ],
            'loyalty' => [
                'key' => 'loyalty',
                'label' => 'Loyalty',
                'description' => 'VIP tiers and points-based retention setup.',
                'category' => 'Growth',
                'company_types' => ['services', 'products'],
            ],
            'assistant' => [
                'key' => 'assistant',
                'label' => 'Assistant',
                'description' => 'Enable the assistant in the workspace configuration.',
                'category' => 'Growth',
                'company_types' => ['services', 'products'],
            ],
            'performance' => [
                'key' => 'performance',
                'label' => 'Performance',
                'description' => 'Enable KPI views for management walkthroughs.',
                'category' => 'Insights',
                'company_types' => ['services', 'products'],
            ],
            'presence' => [
                'key' => 'presence',
                'label' => 'Presence',
                'description' => 'Useful for salon or appointment-heavy team visibility.',
                'category' => 'Insights',
                'company_types' => ['services'],
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function moduleKeys(): array
    {
        return array_keys($this->modules());
    }

    /**
     * @return array<string, bool>
     */
    public function featureMap(array $selectedModules): array
    {
        $selectedLookup = array_fill_keys($selectedModules, true);
        $features = [];

        foreach ($this->moduleKeys() as $moduleKey) {
            $features[$moduleKey] = (bool) ($selectedLookup[$moduleKey] ?? false);
        }

        return $features;
    }

    /**
     * @return array<int, string>
     */
    public function defaultModules(string $companyType, ?string $sector = null): array
    {
        $base = $companyType === 'products'
            ? ['products', 'sales', 'promotions', 'invoices', 'expenses', 'campaigns', 'loyalty', 'performance']
            : ['requests', 'quotes', 'services', 'jobs', 'tasks', 'invoices', 'expenses', 'team_members', 'performance'];

        if ($companyType === 'services' && in_array($sector, ['salon', 'wellness', 'restaurant'], true)) {
            $base = array_merge($base, ['reservations', 'planning', 'presence']);
        }

        if ($companyType === 'services') {
            $base[] = 'products';
        }

        if ($companyType === 'services' && $sector === 'salon') {
            $base = array_diff($base, self::SALON_DEFAULT_DISABLED_MODULES);
        }

        return array_values(array_unique($base));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function presets(): array
    {
        $serviceOpsModules = $this->defaultModules('services', 'field_services');
        $serviceOpsScenarios = $this->defaultScenarioPacks('services', 'field_services', $serviceOpsModules);
        $salonModules = $this->defaultModules('services', 'salon');
        $salonScenarios = $this->defaultScenarioPacks('services', 'salon', $salonModules);
        $salonEclatModules = [
            'services',
            'reservations',
            'planning',
            'presence',
            'invoices',
            'expenses',
            'accounting',
            'team_members',
            'performance',
            'products',
            'sales',
            'promotions',
            'loyalty',
            'campaigns',
            'assistant',
            'social',
        ];
        $salonEclatScenarios = [
            'salon_queue',
            'reservation_to_service',
            'salon_eclat_complete',
        ];
        $studioNayaModules = array_values(array_unique([
            ...$this->requiredModulesForScenario(StudioNayaBlueprint::KEY),
            'promotions',
            'assistant',
            'social',
        ]));
        $studioNayaScenarios = [
            'salon_queue',
            'reservation_to_service',
            'studio_naya_complete',
        ];
        $commerceModules = $this->defaultModules('products', 'retail');
        $commerceScenarios = $this->defaultScenarioPacks('products', 'retail', $commerceModules);

        return [
            [
                'key' => 'service_ops',
                'label' => 'Service ops',
                'description' => 'Leads, quotes, jobs, invoicing, and team delivery.',
                'company_type' => 'services',
                'company_sector' => 'field_services',
                'modules' => $serviceOpsModules,
                'scenario_packs' => $serviceOpsScenarios,
                'branding_profile' => $this->brandingProfileDefaults('services', 'field_services'),
                'extra_access_roles' => $this->defaultExtraAccessRoles('services', 'field_services'),
                'suggested_flow' => $this->suggestedFlowFromScenarioPacks($serviceOpsScenarios)
                    ?: $this->suggestedFlow('services', 'field_services', $serviceOpsModules),
            ],
            [
                'key' => 'salon_queue',
                'label' => 'Salon queue',
                'description' => 'Appointments, queue flow, team presence, and service delivery.',
                'company_type' => 'services',
                'company_sector' => 'salon',
                'modules' => $salonModules,
                'scenario_packs' => $salonScenarios,
                'branding_profile' => $this->brandingProfileDefaults('services', 'salon'),
                'extra_access_roles' => $this->defaultExtraAccessRoles('services', 'salon'),
                'suggested_flow' => $this->suggestedFlowFromScenarioPacks($salonScenarios)
                    ?: $this->suggestedFlow('services', 'salon', $salonModules),
            ],
            [
                'key' => 'salon_eclat_complete',
                'label' => 'Salon Éclat - immersive',
                'description' => 'A complete salon day from booking and service to checkout, retail, loyalty, and marketing.',
                'company_type' => 'services',
                'company_sector' => 'salon',
                'company_name' => 'Salon Éclat',
                'prospect_name' => 'Amina Diallo',
                'prospect_email' => 'amina.diallo@example.test',
                'prospect_company' => 'Salon Éclat',
                'seed_profile' => 'immersive',
                'team_size' => 3,
                'locale' => 'fr',
                'timezone' => 'America/Toronto',
                'desired_outcome' => 'Présenter une journée complète au salon, de la réservation au reçu, puis à la fidélisation.',
                'modules' => $salonEclatModules,
                'scenario_packs' => $salonEclatScenarios,
                'branding_profile' => $this->brandingProfileDefaults('services', 'salon', 'Salon Éclat'),
                'extra_access_roles' => ['front_desk', 'staff'],
                'suggested_flow' => $this->suggestedFlowFromScenarioPacks($salonEclatScenarios),
            ],
            [
                'key' => 'studio_naya_coiffure',
                'label' => 'Studio Naya - narrative scenario',
                'description' => 'A deterministic, resettable salon workspace with 18 months of connected operating history.',
                'company_type' => 'services',
                'company_sector' => 'salon',
                'company_name' => 'Studio Naya Coiffure',
                'prospect_name' => 'Maya Koné',
                'prospect_email' => 'maya.kone@studio-naya.example.test',
                'prospect_company' => 'Studio Naya Coiffure',
                'seed_profile' => 'immersive',
                'scenario_key' => StudioNayaBlueprint::KEY,
                'data_volume' => StudioNayaBlueprint::DEFAULT_VOLUME,
                'reference_date' => now('America/Toronto')->toDateString(),
                'random_seed' => (int) config('demo_scenarios.default_seed', 26082026),
                'scenario_version' => 1,
                'team_size' => 5,
                'locale' => 'fr',
                'timezone' => 'America/Toronto',
                'desired_outcome' => 'Présenter une entreprise de coiffure crédible à travers ses opérations, ses finances et ses relations clientes sur 18 mois.',
                'modules' => $studioNayaModules,
                'scenario_packs' => $studioNayaScenarios,
                'branding_profile' => array_replace(
                    $this->brandingProfileDefaults('services', 'salon', 'Studio Naya Coiffure'),
                    [
                        'name' => 'Studio Naya Coiffure',
                        'tagline' => 'Textures, couleurs et soins sur mesure.',
                        'description' => 'Salon de quartier montréalais spécialisé en cheveux texturés, coloration et soins.',
                        'contact_email' => 'bonjour@studio-naya.example',
                        'phone' => '+1 514 555 0148',
                        'address_line_1' => '4827, rue de l’Aurore',
                        'city' => 'Montréal',
                        'province' => 'QC',
                        'country' => 'Canada',
                        'postal_code' => 'H2T 2M4',
                        'primary_color' => '#5B3A70',
                        'secondary_color' => '#2F2535',
                        'accent_color' => '#D89B6B',
                        'surface_color' => '#FFF8F0',
                    ],
                ),
                'extra_access_roles' => ['manager', 'front_desk', 'staff'],
                'suggested_flow' => $this->suggestedFlowFromScenarioPacks($studioNayaScenarios),
            ],
            [
                'key' => 'commerce',
                'label' => 'Commerce',
                'description' => 'Catalog, sales, invoices, campaigns, and loyalty.',
                'company_type' => 'products',
                'company_sector' => 'retail',
                'modules' => $commerceModules,
                'scenario_packs' => $commerceScenarios,
                'branding_profile' => $this->brandingProfileDefaults('products', 'retail'),
                'extra_access_roles' => $this->defaultExtraAccessRoles('products', 'retail'),
                'suggested_flow' => $this->suggestedFlowFromScenarioPacks($commerceScenarios)
                    ?: $this->suggestedFlow('products', 'retail', $commerceModules),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        $companyType = 'services';
        $sector = 'salon';
        $selectedModules = $this->defaultModules($companyType, $sector);
        $scenarioPacks = $this->defaultScenarioPacks($companyType, $sector, $selectedModules);
        $extraAccessRoles = $this->defaultExtraAccessRoles($companyType, $sector);

        return [
            'prospect_name' => '',
            'prospect_email' => '',
            'prospect_company' => '',
            'company_name' => '',
            'demo_workspace_template_id' => null,
            'company_type' => $companyType,
            'company_sector' => $sector,
            'locale' => 'fr',
            'timezone' => 'America/Toronto',
            'desired_outcome' => '',
            'internal_notes' => '',
            'suggested_flow' => $this->suggestedFlowFromScenarioPacks($scenarioPacks)
                ?: $this->suggestedFlow($companyType, $sector, $selectedModules),
            'seed_profile' => 'standard',
            'scenario_key' => null,
            'data_volume' => DemoDataVolume::Medium->value,
            'reference_date' => now('America/Toronto')->toDateString(),
            'random_seed' => (int) config('demo_scenarios.default_seed', 26082026),
            'scenario_version' => null,
            'team_size' => 3,
            'selected_modules' => $selectedModules,
            'scenario_packs' => $scenarioPacks,
            'branding_profile' => $this->brandingProfileDefaults($companyType, $sector),
            'extra_access_roles' => $extraAccessRoles,
            'extra_access_credentials' => [],
            'prefill_source' => '',
            'prefill_payload' => [],
            'expires_at' => now()->addDays(14)->toDateString(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function extraAccessRoles(): array
    {
        return [
            [
                'key' => 'manager',
                'label' => 'Manager view',
                'description' => 'Operational or store manager account for oversight and KPI walkthroughs.',
            ],
            [
                'key' => 'front_desk',
                'label' => 'Front desk view',
                'description' => 'Reception or front-desk login to demonstrate check-in and queue handling.',
            ],
            [
                'key' => 'staff',
                'label' => 'Staff view',
                'description' => 'Team member login to showcase day-to-day execution screens.',
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function extraAccessRoleKeys(): array
    {
        return collect($this->extraAccessRoles())
            ->pluck('key')
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function defaultExtraAccessRoles(string $companyType, ?string $sector = null): array
    {
        if ($companyType === 'products') {
            return ['manager', 'staff'];
        }

        if (in_array($sector, ['salon', 'wellness', 'restaurant'], true)) {
            return ['manager', 'front_desk', 'staff'];
        }

        return ['manager', 'staff'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function scenarioPacks(): array
    {
        return [
            [
                'key' => 'salon_queue',
                'label' => 'Salon queue walkthrough',
                'description' => 'Check in a client, move them in queue, and finish service handoff.',
                'business_objective' => 'Show the prospect how the front desk, queue, and stylist flow stay in sync.',
                'ordered_actions' => [
                    'Open the live reservation screen and review the current queue.',
                    'Check in an upcoming or walk-in client from the reservation flow.',
                    'Move the client from checked-in to called, then into service.',
                    'Review the visit outcome and confirm the next step for the customer.',
                ],
                'expected_results' => [
                    'Queue statuses update instantly across the demo.',
                    'The active chair and upcoming client cards reflect the change.',
                    'Staff can see who is next without leaving the service workflow.',
                ],
                'key_screens' => [
                    'Reservations board',
                    'Queue live screen',
                    'Client history',
                ],
                'company_types' => ['services'],
                'sectors' => ['salon', 'wellness'],
                'required_modules' => ['reservations', 'planning'],
            ],
            [
                'key' => 'reservation_to_service',
                'label' => 'Reservation to in-service',
                'description' => 'Walk from booking creation to the active in-service state.',
                'business_objective' => 'Demonstrate how booked demand becomes operational work for the team.',
                'ordered_actions' => [
                    'Create or open a seeded reservation for a service.',
                    'Assign the right staff member and confirm the booking slot.',
                    'Move the reservation into the live queue and start the service.',
                    'Review the service notes and follow-up options.',
                ],
                'expected_results' => [
                    'Booking data and team allocation remain connected.',
                    'The prospect sees the path from future booking to live execution.',
                ],
                'key_screens' => [
                    'Reservation form',
                    'Planning board',
                    'Live queue',
                ],
                'company_types' => ['services'],
                'sectors' => ['salon', 'wellness', 'restaurant'],
                'required_modules' => ['reservations'],
            ],
            [
                'key' => 'salon_eclat_complete',
                'label' => 'Salon Éclat complete day',
                'description' => 'Run an immersive salon journey from booking to checkout and customer retention.',
                'business_objective' => 'Show how Salon Éclat connects front desk, stylists, checkout, retail, loyalty, and marketing in one customer history.',
                'ordered_actions' => [
                    'Open Amina Diallo\'s dashboard and review the Salon Éclat day plan.',
                    'Confirm a reservation, check the client in, and move the visit through the live queue.',
                    'Start and complete the assigned service with the stylist.',
                    'Complete the service checkout with taxes and a tip, then open the generated invoice and receipt.',
                    'Review a separate retail sale and the promotion prepared for the salon.',
                    'Review loyalty progress, then open the retention campaign and social content prepared for the salon.',
                ],
                'expected_results' => [
                    'The reservation, service, payment, invoice, taxes, tip, and receipt form one traceable journey.',
                    'Product sales and promotions remain visible in the customer and revenue history.',
                    'Loyalty, campaign, assistant, and social screens provide a credible next action after the visit.',
                ],
                'key_screens' => [
                    'Dashboard',
                    'Reservations board and live queue',
                    'Service checkout',
                    'Invoices and payment history',
                    'Accounting journal and tax summary',
                    'Point of sale',
                    'Loyalty',
                    'Campaigns',
                    'Social',
                ],
                'company_types' => ['services'],
                'sectors' => ['salon'],
                'required_modules' => [
                    'services',
                    'reservations',
                    'invoices',
                    'products',
                    'sales',
                    'promotions',
                    'loyalty',
                    'campaigns',
                    'assistant',
                    'social',
                ],
            ],
            [
                'key' => 'studio_naya_complete',
                'label' => 'Studio Naya complete story',
                'description' => 'Explore 18 months of connected salon activity and several named customer journeys.',
                'business_objective' => 'Demonstrate that daily salon operations, customer history, revenue, stock, and management signals tell one coherent story.',
                'ordered_actions' => [
                    'Review the twelve-month dashboard and current salon alerts.',
                    'Open a named customer and follow appointments, invoices, payments, notes, and preferences.',
                    'Move to the reservation calendar and compare team schedules and service demand.',
                    'Review retail stock movements, operating expenses, and accounting results.',
                    'Reset the workspace and verify that the same reference scenario is restored.',
                ],
                'expected_results' => [
                    'Operational and financial screens are populated from linked records.',
                    'Named customer journeys remain coherent across the application.',
                    'The saved seed, volume, and reference date reproduce the same business story.',
                ],
                'key_screens' => [
                    'Dashboard',
                    'Customer profile',
                    'Reservation calendar',
                    'Invoices and payments',
                    'Inventory and expenses',
                ],
                'company_types' => ['services'],
                'sectors' => ['salon'],
                'required_modules' => [
                    'services',
                    'reservations',
                    'planning',
                    'invoices',
                    'products',
                    'sales',
                    'expenses',
                    'team_members',
                    'performance',
                ],
            ],
            [
                'key' => 'service_quote_to_invoice',
                'label' => 'Service quote to invoice',
                'description' => 'Start from a lead, turn it into work, then finish on billing.',
                'business_objective' => 'Show the commercial and operational continuity for service businesses.',
                'ordered_actions' => [
                    'Open an incoming prospect.',
                    'Convert the need into a quote and review pricing.',
                    'Turn the quote into work and assign a team member.',
                    'Finish on invoice visibility and payment status.',
                ],
                'expected_results' => [
                    'The prospect sees a complete service pipeline in one environment.',
                    'Commercial records stay linked to execution and billing.',
                ],
                'key_screens' => [
                    'Prospects workspace',
                    'Quotes',
                    'Jobs or works',
                    'Invoices',
                ],
                'company_types' => ['services'],
                'sectors' => ['field_services', 'professional_services', 'other'],
                'required_modules' => ['quotes', 'jobs', 'invoices'],
            ],
            [
                'key' => 'retail_checkout',
                'label' => 'Retail checkout walkthrough',
                'description' => 'Move from catalog browsing to POS sale and retention follow-up.',
                'business_objective' => 'Demonstrate the commerce loop from stock to sale to repeat revenue.',
                'ordered_actions' => [
                    'Review the seeded retail catalog and stock levels.',
                    'Create a sale from the commerce flow.',
                    'Show invoice continuity and payment status.',
                    'Finish with loyalty or campaign follow-up for the customer.',
                ],
                'expected_results' => [
                    'The prospect sees a realistic product catalog and order flow.',
                    'Sales, invoices, and customer retention stay linked in one workspace.',
                ],
                'key_screens' => [
                    'Catalog',
                    'Point of sale',
                    'Invoices',
                    'Campaigns or loyalty',
                ],
                'company_types' => ['products'],
                'sectors' => ['retail', 'other'],
                'required_modules' => ['products', 'sales', 'invoices'],
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function scenarioPackKeys(): array
    {
        return collect($this->scenarioPacks())
            ->pluck('key')
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $selectedModules
     * @return array<int, string>
     */
    public function defaultScenarioPacks(string $companyType, ?string $sector = null, array $selectedModules = []): array
    {
        $selectedModules = $selectedModules !== []
            ? $selectedModules
            : $this->defaultModules($companyType, $sector);

        $matches = collect($this->scenarioPacks())
            ->filter(function (array $pack) use ($companyType, $sector, $selectedModules) {
                if (! in_array($companyType, $pack['company_types'] ?? [], true)) {
                    return false;
                }

                $sectors = $pack['sectors'] ?? [];
                if ($sectors !== [] && ! in_array($sector, $sectors, true)) {
                    return false;
                }

                return collect($pack['required_modules'] ?? [])
                    ->every(fn (string $moduleKey) => in_array($moduleKey, $selectedModules, true));
            })
            ->pluck('key')
            ->values()
            ->all();

        if ($matches !== []) {
            return $matches;
        }

        return $companyType === 'products'
            ? ['retail_checkout']
            : ['service_quote_to_invoice'];
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<int, array<string, mixed>>
     */
    public function scenarioPackDetails(array $keys): array
    {
        $selected = array_fill_keys($keys, true);

        return collect($this->scenarioPacks())
            ->filter(fn (array $pack) => isset($selected[$pack['key']]))
            ->values()
            ->all();
    }

    public function scenarioPackLabel(string $key): string
    {
        $pack = collect($this->scenarioPacks())->firstWhere('key', $key);

        return is_array($pack)
            ? (string) ($pack['label'] ?? $key)
            : $key;
    }

    /**
     * @param  array<int, string>  $keys
     */
    public function suggestedFlowFromScenarioPacks(array $keys): string
    {
        $actions = collect($this->scenarioPackDetails($keys))
            ->flatMap(fn (array $pack) => $pack['ordered_actions'] ?? [])
            ->filter(fn ($action) => is_string($action) && trim($action) !== '')
            ->unique()
            ->values();

        if ($actions->isEmpty()) {
            return '';
        }

        return $actions
            ->values()
            ->map(fn (string $action, int $index) => ($index + 1).'. '.$action)
            ->implode("\n");
    }

    /**
     * @return array<string, mixed>
     */
    public function brandingProfileDefaults(string $companyType, ?string $sector = null, ?string $companyName = null): array
    {
        $defaults = Arr::get(MarketingSetting::defaults(), 'templates.brand_profile', []);
        $palette = match (true) {
            $companyType === 'products' => [
                'primary_color' => '#B45309',
                'secondary_color' => '#111827',
                'accent_color' => '#F59E0B',
                'surface_color' => '#FFF7ED',
            ],
            in_array($sector, ['salon', 'wellness'], true) => [
                'primary_color' => '#059669',
                'secondary_color' => '#0F172A',
                'accent_color' => '#F59E0B',
                'surface_color' => '#ECFDF5',
            ],
            default => [
                'primary_color' => '#0F766E',
                'secondary_color' => '#0F172A',
                'accent_color' => '#2563EB',
                'surface_color' => '#F0FDFA',
            ],
        };

        return array_replace($defaults, $palette, [
            'name' => $companyName,
            'logo_url' => $this->defaultLogoUrl($companyType, $sector),
            'tagline' => match (true) {
                $companyType === 'products' => 'Retail demo experience',
                in_array($sector, ['salon', 'wellness'], true) => 'Appointments, queue, and service flow',
                default => 'Operational demo environment',
            },
            'description' => match (true) {
                $companyType === 'products' => 'A polished commerce demo with catalog, checkout, loyalty, and campaigns.',
                in_array($sector, ['salon', 'wellness'], true) => 'A branded service demo focused on reservations, live queue, and customer follow-up.',
                default => 'A realistic business demo prepared for discovery, operations, and revenue walkthroughs.',
            },
        ]);
    }

    private function defaultLogoUrl(string $companyType, ?string $sector = null): string
    {
        return match (true) {
            $companyType === 'products',
            $sector === 'retail' => '/images/presets/company-4.svg',
            $sector === 'restaurant' => '/images/presets/company-2.svg',
            in_array($sector, ['field_services', 'professional_services'], true) => '/images/presets/company-3.svg',
            default => '/images/presets/company-1.svg',
        };
    }

    /**
     * @param  array<int, string>  $selectedModules
     */
    public function suggestedFlow(string $companyType, ?string $sector, array $selectedModules): string
    {
        $isSalonLike = $companyType === 'services' && in_array($sector, ['salon', 'wellness'], true);
        $hasReservations = in_array('reservations', $selectedModules, true);
        $hasSales = in_array('sales', $selectedModules, true);
        $hasQuotes = in_array('quotes', $selectedModules, true);
        $hasInvoices = in_array('invoices', $selectedModules, true);

        if ($isSalonLike && $hasReservations) {
            return implode("\n", [
                '1. Open the reservations screen and review the day queue.',
                '2. Check in a walk-in or upcoming client.',
                '3. Move the client from checked-in to in-service.',
                '4. Review the service history and finish with payment or follow-up.',
            ]);
        }

        if ($companyType === 'products' && $hasSales) {
            return implode("\n", [
                '1. Review the featured catalog and available stock.',
                '2. Create a sale from the commerce flow.',
                '3. Show payment status and invoice continuity.',
                '4. Finish with loyalty or campaign follow-up.',
            ]);
        }

        if ($companyType === 'services' && $hasQuotes) {
            return implode("\n", [
                '1. Start from an incoming lead or request.',
                '2. Convert the need into a quote and review pricing.',
                '3. Turn the quote into work and assign the team.',
                '4. End on invoicing and payment visibility.',
            ]);
        }

        if ($hasInvoices) {
            return implode("\n", [
                '1. Open the main operational dashboard for the tenant.',
                '2. Review one or two seeded records in the enabled modules.',
                '3. Finish with invoices, payments, and next actions for the prospect.',
            ]);
        }

        return implode("\n", [
            '1. Open the main dashboard and review the enabled modules.',
            '2. Walk through the most relevant workflow for the prospect.',
            '3. End with the value summary and next operational step.',
        ]);
    }

    /**
     * @return array<string, int>
     */
    public function seedCounts(string $profile): array
    {
        $matched = collect($this->seedProfiles())
            ->firstWhere('value', $profile);

        return Arr::get($matched, 'counts', [
            'customers' => 12,
            'catalog' => 10,
            'quotes' => 4,
            'works' => 4,
            'tasks' => 8,
            'reservations' => 9,
            'queue' => 4,
            'sales' => 4,
            'expenses' => 7,
            'team' => 3,
        ]);
    }

    public function moduleLabel(string $key): string
    {
        return (string) Arr::get($this->modules(), $key.'.label', $key);
    }
}
