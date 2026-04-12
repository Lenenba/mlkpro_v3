<?php

namespace App\Services;

use App\Enums\CurrencyCode;

class PublicPricingCatalogService
{
    public function __construct(
        private readonly BillingPlanService $billingPlanService,
    ) {}

    public function webPayload(CurrencyCode|string|null $currencyCode = null, ?string $requestedAudience = null): array
    {
        $currency = CurrencyCode::tryFromMixed($currencyCode) ?? CurrencyCode::default();
        $catalogs = $this->catalogsForCurrency($currency, true);
        $defaultAudience = $this->resolveDefaultAudience($catalogs, $requestedAudience ?? '');
        $activeCatalog = $this->activeCatalog($catalogs, $defaultAudience);

        return [
            'pricingCatalogs' => $catalogs,
            'defaultAudience' => $defaultAudience,
            'pricingPlans' => $activeCatalog['plans'],
            'highlightedPlanKey' => $activeCatalog['highlightedPlanKey'],
            'comparisonSections' => $activeCatalog['comparisonSections'],
        ];
    }

    public function apiPayload(
        CurrencyCode|string|null $currencyCode = null,
        ?string $requestedAudience = null,
        bool $includeComparisonSections = false,
    ): array {
        $currency = CurrencyCode::tryFromMixed($currencyCode) ?? CurrencyCode::default();
        $catalogs = $this->catalogsForCurrency($currency, $includeComparisonSections);
        $defaultAudience = $this->resolveDefaultAudience($catalogs, $requestedAudience ?? '');
        $activeCatalog = $this->activeCatalog($catalogs, $defaultAudience);

        return [
            'currency_code' => $currency->value,
            'default_audience' => $defaultAudience,
            'available_audiences' => array_values(array_keys($catalogs)),
            'audience' => $defaultAudience,
            'highlighted_plan_key' => $activeCatalog['highlightedPlanKey'],
            'plans' => $activeCatalog['plans'],
            'comparison_sections' => $activeCatalog['comparisonSections'],
        ];
    }

    public function audienceExists(CurrencyCode|string|null $currencyCode, string $audience): bool
    {
        if ($audience === '') {
            return false;
        }

        return array_key_exists($audience, $this->catalogsForCurrency($currencyCode, false));
    }

    public function catalogsForCurrency(CurrencyCode|string|null $currencyCode = null, bool $includeComparisonSections = true): array
    {
        $currency = CurrencyCode::tryFromMixed($currencyCode) ?? CurrencyCode::default();
        $plansByKey = collect($this->billingPlanService->plansForCurrency($currency))
            ->keyBy('key')
            ->all();

        return $this->resolvePublicPlanCatalogs($plansByKey, $includeComparisonSections);
    }

    private function resolvePublicPlanCatalogs(array $plansByKey, bool $includeComparisonSections): array
    {
        $configuredCatalogs = config('billing.public_catalogs', []);

        if (! is_array($configuredCatalogs) || $configuredCatalogs === []) {
            $order = array_keys($plansByKey);

            return [
                'team' => [
                    'plans' => $this->buildPublicPlans($order, $plansByKey),
                    'highlightedPlanKey' => in_array('growth', $order, true) ? 'growth' : ($order[1] ?? ($order[0] ?? null)),
                    'comparisonSections' => $includeComparisonSections
                        ? $this->resolveComparisonSections($order, 'team')
                        : [],
                ],
            ];
        }

        return collect($configuredCatalogs)
            ->mapWithKeys(function (mixed $catalogConfig, mixed $audience) use ($plansByKey, $includeComparisonSections) {
                if (! is_string($audience) || ! is_array($catalogConfig)) {
                    return [];
                }

                $order = collect($catalogConfig['order'] ?? [])
                    ->filter(fn ($key) => is_string($key) && isset($plansByKey[$key]))
                    ->values()
                    ->all();

                if ($order === []) {
                    return [];
                }

                $highlightedPlanKey = $catalogConfig['highlighted_plan_key'] ?? null;
                if (! is_string($highlightedPlanKey) || ! in_array($highlightedPlanKey, $order, true)) {
                    $highlightedPlanKey = $order[1] ?? ($order[0] ?? null);
                }

                return [
                    $audience => [
                        'plans' => $this->buildPublicPlans($order, $plansByKey),
                        'highlightedPlanKey' => $highlightedPlanKey,
                        'comparisonSections' => $includeComparisonSections
                            ? $this->resolveComparisonSections($order, $audience)
                            : [],
                    ],
                ];
            })
            ->all();
    }

    private function buildPublicPlans(array $order, array $plansByKey): array
    {
        return collect($order)
            ->map(function (string $key) use ($plansByKey) {
                $plan = $plansByKey[$key] ?? null;

                if (! is_array($plan)) {
                    return null;
                }

                return array_merge($plan, [
                    'description' => data_get(config('billing.catalog_defaults', []), $key.'.description'),
                ]);
            })
            ->filter()
            ->values()
            ->all();
    }

    private function resolveDefaultAudience(array $catalogs, string $requestedAudience): string
    {
        if ($requestedAudience !== '' && array_key_exists($requestedAudience, $catalogs)) {
            return $requestedAudience;
        }

        $configuredDefault = (string) config('billing.default_public_audience', '');
        if ($configuredDefault !== '' && array_key_exists($configuredDefault, $catalogs)) {
            return $configuredDefault;
        }

        return array_key_first($catalogs) ?? 'team';
    }

    private function activeCatalog(array $catalogs, string $audience): array
    {
        return $catalogs[$audience] ?? [
            'plans' => [],
            'highlightedPlanKey' => null,
            'comparisonSections' => [],
        ];
    }

    private function resolveComparisonSections(array $order, ?string $audience = null): array
    {
        $comparisonConfig = $audience
            ? config('billing.comparison_catalogs.'.$audience, config('billing.comparison', []))
            : config('billing.comparison', []);

        return collect($comparisonConfig)
            ->map(function (array $section) use ($order) {
                $rows = collect($section['rows'] ?? [])
                    ->map(function (array $row) use ($order) {
                        $label = $this->translateComparisonLabel($row['label_key'] ?? null);

                        if ($label === '') {
                            return null;
                        }

                        $values = collect($order)
                            ->map(function (string $planKey) use ($row) {
                                return array_merge(
                                    ['plan_key' => $planKey],
                                    $this->normalizeComparisonValue($row['values'][$planKey] ?? null)
                                );
                            })
                            ->values()
                            ->all();

                        return [
                            'label' => $label,
                            'values' => $values,
                        ];
                    })
                    ->filter()
                    ->values()
                    ->all();

                $label = $this->translateComparisonLabel($section['label_key'] ?? null);

                if ($label === '' || $rows === []) {
                    return null;
                }

                return [
                    'label' => $label,
                    'rows' => $rows,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function translateComparisonLabel(mixed $key): string
    {
        if (! is_string($key) || trim($key) === '') {
            return '';
        }

        $map = $this->comparisonCopy();

        return $map[$key] ?? $key;
    }

    private function normalizeComparisonValue(mixed $value): array
    {
        if (is_bool($value)) {
            return [
                'type' => $value ? 'included' : 'excluded',
                'text' => null,
            ];
        }

        if (is_numeric($value)) {
            return [
                'type' => 'text',
                'text' => (string) $value,
            ];
        }

        if (is_string($value)) {
            $normalized = trim($value);

            return match ($normalized) {
                'unlimited' => ['type' => 'text', 'text' => $this->comparisonCopy()['pricing.comparison.values.unlimited'] ?? 'Unlimited'],
                'optional' => ['type' => 'text', 'text' => $this->comparisonCopy()['pricing.comparison.values.optional'] ?? 'Optional'],
                'owner_only' => ['type' => 'text', 'text' => $this->comparisonCopy()['pricing.comparison.values.owner_only'] ?? '1 owner'],
                'limited' => ['type' => 'text', 'text' => $this->comparisonCopy()['pricing.comparison.values.limited'] ?? 'Limited mode'],
                'contact' => ['type' => 'text', 'text' => $this->comparisonCopy()['pricing.comparison.values.contact'] ?? 'Contact us'],
                default => ['type' => 'text', 'text' => $normalized],
            };
        }

        return [
            'type' => 'excluded',
            'text' => null,
        ];
    }

    private function comparisonCopy(): array
    {
        $rawLocale = strtolower((string) app()->getLocale());
        $locale = str_starts_with($rawLocale, 'fr')
            ? 'fr'
            : (str_starts_with($rawLocale, 'es') ? 'es' : 'en');

        return match ($locale) {
            'fr' => [
                'pricing.comparison.sections.fundamentals' => 'Fondamentaux',
                'pricing.comparison.sections.operations' => 'Operations terrain',
                'pricing.comparison.sections.growth' => 'Croissance et automatisation',
                'pricing.comparison.rows.team_members' => 'Employes inclus',
                'pricing.comparison.rows.account_access' => 'Acces compte',
                'pricing.comparison.rows.clients' => 'Clients',
                'pricing.comparison.rows.requests' => 'Demandes / leads',
                'pricing.comparison.rows.estimates' => 'Devis',
                'pricing.comparison.rows.invoices' => 'Factures',
                'pricing.comparison.rows.tips' => 'Pourboires',
                'pricing.comparison.rows.catalog' => 'Catalogue produits et services',
                'pricing.comparison.rows.catalog_items' => 'Articles au catalogue',
                'pricing.comparison.rows.client_portal' => 'Portail client',
                'pricing.comparison.rows.jobs' => 'Jobs',
                'pricing.comparison.rows.tasks' => 'Taches',
                'pricing.comparison.rows.planning' => 'Planning',
                'pricing.comparison.rows.team_planning' => 'Planning d equipe',
                'pricing.comparison.rows.time_tracking' => 'Presence et pointage',
                'pricing.comparison.rows.reservations' => 'Reservations et check-in',
                'pricing.comparison.rows.recurring_jobs' => 'Jobs recurrents',
                'pricing.comparison.rows.reports' => 'Rapports integres',
                'pricing.comparison.rows.permissions' => 'Equipe et permissions',
                'pricing.comparison.rows.automations' => 'Automatisations avancees',
                'pricing.comparison.rows.plan_scan' => 'Scan de plan et devis IA',
                'pricing.comparison.rows.ai_assistant' => 'Assistant IA',
                'pricing.comparison.rows.campaigns' => 'Campagnes',
                'pricing.comparison.rows.loyalty' => 'Fidelite',
                'pricing.comparison.rows.onboarding' => 'Onboarding dedie',
                'pricing.comparison.rows.custom_integrations' => 'Integrations et SLA sur mesure',
                'pricing.comparison.values.unlimited' => 'Illimite',
                'pricing.comparison.values.optional' => 'Option',
                'pricing.comparison.values.owner_only' => '1 owner',
                'pricing.comparison.values.limited' => 'Mode limite',
                'pricing.comparison.values.contact' => 'Contact us',
            ],
            'es' => [
                'pricing.comparison.sections.fundamentals' => 'Fundamentos',
                'pricing.comparison.sections.operations' => 'Operaciones de campo',
                'pricing.comparison.sections.growth' => 'Crecimiento y automatizacion',
                'pricing.comparison.rows.team_members' => 'Empleados incluidos',
                'pricing.comparison.rows.account_access' => 'Acceso a la cuenta',
                'pricing.comparison.rows.clients' => 'Clientes',
                'pricing.comparison.rows.requests' => 'Solicitudes / leads',
                'pricing.comparison.rows.estimates' => 'Presupuestos',
                'pricing.comparison.rows.invoices' => 'Facturas',
                'pricing.comparison.rows.tips' => 'Propinas',
                'pricing.comparison.rows.catalog' => 'Catalogo de productos y servicios',
                'pricing.comparison.rows.catalog_items' => 'Elementos del catalogo',
                'pricing.comparison.rows.client_portal' => 'Portal del cliente',
                'pricing.comparison.rows.jobs' => 'Trabajos',
                'pricing.comparison.rows.tasks' => 'Tareas',
                'pricing.comparison.rows.planning' => 'Planificacion',
                'pricing.comparison.rows.team_planning' => 'Planificacion del equipo',
                'pricing.comparison.rows.time_tracking' => 'Seguimiento del tiempo',
                'pricing.comparison.rows.reservations' => 'Reservas y check-in',
                'pricing.comparison.rows.recurring_jobs' => 'Trabajos recurrentes',
                'pricing.comparison.rows.reports' => 'Informes integrados',
                'pricing.comparison.rows.permissions' => 'Permisos del equipo',
                'pricing.comparison.rows.automations' => 'Automatizaciones avanzadas',
                'pricing.comparison.rows.plan_scan' => 'Escaneo de planos y cotizaciones con IA',
                'pricing.comparison.rows.ai_assistant' => 'Asistente IA',
                'pricing.comparison.rows.campaigns' => 'Campanas',
                'pricing.comparison.rows.loyalty' => 'Fidelizacion',
                'pricing.comparison.rows.onboarding' => 'Onboarding dedicado',
                'pricing.comparison.rows.custom_integrations' => 'Integraciones personalizadas y SLA',
                'pricing.comparison.values.unlimited' => 'Ilimitado',
                'pricing.comparison.values.optional' => 'Complemento opcional',
                'pricing.comparison.values.owner_only' => '1 propietario',
                'pricing.comparison.values.limited' => 'Modo limitado',
                'pricing.comparison.values.contact' => 'Contactanos',
            ],
            default => [
                'pricing.comparison.sections.fundamentals' => 'Fundamentals',
                'pricing.comparison.sections.operations' => 'Field operations',
                'pricing.comparison.sections.growth' => 'Growth and automation',
                'pricing.comparison.rows.team_members' => 'Included employees',
                'pricing.comparison.rows.account_access' => 'Account access',
                'pricing.comparison.rows.clients' => 'Clients',
                'pricing.comparison.rows.requests' => 'Requests / leads',
                'pricing.comparison.rows.estimates' => 'Estimates',
                'pricing.comparison.rows.invoices' => 'Invoices',
                'pricing.comparison.rows.tips' => 'Tips',
                'pricing.comparison.rows.catalog' => 'Products and services catalog',
                'pricing.comparison.rows.catalog_items' => 'Catalog items',
                'pricing.comparison.rows.client_portal' => 'Client portal',
                'pricing.comparison.rows.jobs' => 'Jobs',
                'pricing.comparison.rows.tasks' => 'Tasks',
                'pricing.comparison.rows.planning' => 'Planning',
                'pricing.comparison.rows.team_planning' => 'Team planning',
                'pricing.comparison.rows.time_tracking' => 'Time tracking',
                'pricing.comparison.rows.reservations' => 'Reservations and check-in',
                'pricing.comparison.rows.recurring_jobs' => 'Recurring jobs',
                'pricing.comparison.rows.reports' => 'Built-in reports',
                'pricing.comparison.rows.permissions' => 'Team permissions',
                'pricing.comparison.rows.automations' => 'Advanced automations',
                'pricing.comparison.rows.plan_scan' => 'Plan scan and AI quotes',
                'pricing.comparison.rows.ai_assistant' => 'AI assistant',
                'pricing.comparison.rows.campaigns' => 'Campaigns',
                'pricing.comparison.rows.loyalty' => 'Loyalty',
                'pricing.comparison.rows.onboarding' => 'Dedicated onboarding',
                'pricing.comparison.rows.custom_integrations' => 'Custom integrations and SLA',
                'pricing.comparison.values.unlimited' => 'Unlimited',
                'pricing.comparison.values.optional' => 'Optional add-on',
                'pricing.comparison.values.owner_only' => '1 owner',
                'pricing.comparison.values.limited' => 'Limited mode',
                'pricing.comparison.values.contact' => 'Contact us',
            ],
        };
    }
}
