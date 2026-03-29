<?php

namespace App\Services\Demo;

use Illuminate\Support\Arr;

class DemoWorkspaceCatalog
{
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
                    'team' => 4,
                ],
            ],
        ];
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
        return [
            ['value' => 'fr', 'label' => 'French'],
            ['value' => 'en', 'label' => 'English'],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function modules(): array
    {
        return [
            'requests' => [
                'key' => 'requests',
                'label' => 'Lead intake',
                'description' => 'Incoming requests, qualification, and next follow-ups.',
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
            ? ['products', 'sales', 'invoices', 'campaigns', 'loyalty', 'performance']
            : ['requests', 'quotes', 'services', 'jobs', 'tasks', 'invoices', 'team_members', 'performance'];

        if ($companyType === 'services' && in_array($sector, ['salon', 'wellness', 'restaurant'], true)) {
            $base = array_merge($base, ['reservations', 'planning', 'presence']);
        }

        if ($companyType === 'services') {
            $base[] = 'products';
        }

        return array_values(array_unique($base));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function presets(): array
    {
        return [
            [
                'key' => 'service_ops',
                'label' => 'Service ops',
                'description' => 'Leads, quotes, jobs, invoicing, and team delivery.',
                'company_type' => 'services',
                'company_sector' => 'field_services',
                'modules' => $this->defaultModules('services', 'field_services'),
            ],
            [
                'key' => 'salon_queue',
                'label' => 'Salon queue',
                'description' => 'Appointments, queue flow, team presence, and service delivery.',
                'company_type' => 'services',
                'company_sector' => 'salon',
                'modules' => $this->defaultModules('services', 'salon'),
            ],
            [
                'key' => 'commerce',
                'label' => 'Commerce',
                'description' => 'Catalog, sales, invoices, campaigns, and loyalty.',
                'company_type' => 'products',
                'company_sector' => 'retail',
                'modules' => $this->defaultModules('products', 'retail'),
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

        return [
            'prospect_name' => '',
            'prospect_email' => '',
            'prospect_company' => '',
            'company_name' => '',
            'company_type' => $companyType,
            'company_sector' => $sector,
            'locale' => 'fr',
            'timezone' => 'America/Toronto',
            'desired_outcome' => '',
            'internal_notes' => '',
            'seed_profile' => 'standard',
            'team_size' => 3,
            'selected_modules' => $this->defaultModules($companyType, $sector),
            'expires_at' => now()->addDays(14)->toDateString(),
        ];
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
            'team' => 3,
        ]);
    }

    public function moduleLabel(string $key): string
    {
        return (string) Arr::get($this->modules(), $key.'.label', $key);
    }
}
