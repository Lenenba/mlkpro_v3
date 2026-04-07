<?php

namespace App\Http\Controllers;

use App\Models\PlatformSetting;
use App\Services\MegaMenus\MegaMenuRenderer;
use App\Services\PublicFooterSectionResolver;
use App\Support\CurrencyFormatter;
use App\Support\PlanDisplay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class LegalController extends Controller
{
    public function terms(): Response
    {
        return Inertia::render('Terms', [
            'canLogin' => Route::has('login'),
            'canRegister' => Route::has('onboarding.index'),
            ...$this->publicChrome('terms'),
        ]);
    }

    public function privacy(): Response
    {
        return Inertia::render('Privacy', [
            'canLogin' => Route::has('login'),
            'canRegister' => Route::has('onboarding.index'),
            ...$this->publicChrome('privacy'),
        ]);
    }

    public function refund(): Response
    {
        return Inertia::render('Refund', [
            'canLogin' => Route::has('login'),
            'canRegister' => Route::has('onboarding.index'),
            ...$this->publicChrome('refund'),
        ]);
    }

    public function pricing(Request $request): Response
    {
        $rawPlans = config('billing.plans', []);
        $planDisplayOverrides = PlatformSetting::getValue('plan_display', []);
        $catalogs = $this->resolvePublicPlanCatalogs($rawPlans, $planDisplayOverrides);
        $defaultAudience = $this->resolveDefaultPricingAudience($catalogs, (string) $request->input('audience', ''));
        $activeCatalog = $catalogs[$defaultAudience] ?? [
            'plans' => [],
            'highlightedPlanKey' => null,
            'comparisonSections' => [],
        ];

        return Inertia::render('Pricing', [
            'canLogin' => Route::has('login'),
            'canRegister' => Route::has('onboarding.index'),
            'pricingCatalogs' => $catalogs,
            'defaultAudience' => $defaultAudience,
            'pricingPlans' => $activeCatalog['plans'],
            'highlightedPlanKey' => $activeCatalog['highlightedPlanKey'],
            'comparisonSections' => $activeCatalog['comparisonSections'],
            'megaMenu' => app(MegaMenuRenderer::class)->resolveForLocation('header', 'pricing'),
            'footerMenu' => app(MegaMenuRenderer::class)->resolveForLocation('footer', 'pricing'),
            'footerSection' => app(PublicFooterSectionResolver::class)->resolve(app()->getLocale()),
        ]);
    }

    private function publicChrome(string $zone): array
    {
        return [
            'megaMenu' => app(MegaMenuRenderer::class)->resolveForLocation('header', $zone),
            'footerMenu' => app(MegaMenuRenderer::class)->resolveForLocation('footer', $zone),
            'footerSection' => app(PublicFooterSectionResolver::class)->resolve(app()->getLocale()),
        ];
    }

    private function resolvePlanDisplayPrice($raw): ?string
    {
        $rawValue = is_string($raw) ? trim($raw) : $raw;

        if (is_numeric($rawValue)) {
            return CurrencyFormatter::format((float) $rawValue, null);
        }

        if (is_string($rawValue) && $rawValue !== '') {
            return $rawValue;
        }

        return null;
    }

    private function resolvePublicPlanOrder(array $rawPlans): array
    {
        $configuredOrder = config('billing.public_order', []);
        $order = collect($configuredOrder)
            ->filter(fn ($key) => is_string($key) && isset($rawPlans[$key]))
            ->values()
            ->all();

        return $order !== [] ? $order : array_keys($rawPlans);
    }

    private function resolvePublicPlanCatalogs(array $rawPlans, array $planDisplayOverrides): array
    {
        $catalogDefaults = config('billing.catalog_defaults', []);
        $configuredCatalogs = config('billing.public_catalogs', []);

        if (! is_array($configuredCatalogs) || $configuredCatalogs === []) {
            $order = $this->resolvePublicPlanOrder($rawPlans);

            return [
                'team' => [
                    'plans' => $this->buildPublicPlans($order, $rawPlans, $planDisplayOverrides, $catalogDefaults),
                    'highlightedPlanKey' => in_array('growth', $order, true) ? 'growth' : ($order[1] ?? ($order[0] ?? null)),
                    'comparisonSections' => $this->resolveComparisonSections($order, 'team'),
                ],
            ];
        }

        return collect($configuredCatalogs)
            ->mapWithKeys(function (mixed $catalogConfig, mixed $audience) use ($rawPlans, $planDisplayOverrides, $catalogDefaults) {
                if (! is_string($audience) || ! is_array($catalogConfig)) {
                    return [];
                }

                $order = collect($catalogConfig['order'] ?? [])
                    ->filter(fn ($key) => is_string($key) && isset($rawPlans[$key]))
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
                        'plans' => $this->buildPublicPlans($order, $rawPlans, $planDisplayOverrides, $catalogDefaults),
                        'highlightedPlanKey' => $highlightedPlanKey,
                        'comparisonSections' => $this->resolveComparisonSections($order, $audience),
                    ],
                ];
            })
            ->all();
    }

    private function buildPublicPlans(array $order, array $rawPlans, array $planDisplayOverrides, array $catalogDefaults): array
    {
        return collect($order)
            ->map(function (string $key) use ($rawPlans, $planDisplayOverrides, $catalogDefaults) {
                $plan = $rawPlans[$key] ?? [];
                $display = PlanDisplay::merge($plan, $key, $planDisplayOverrides);
                $contactOnly = (bool) ($plan['contact_only'] ?? data_get($catalogDefaults, $key.'.contact_only', false));
                $pricingByPeriod = $this->buildPublicPricingByPeriod($display['price'] ?? null, $contactOnly);

                return [
                    'key' => $key,
                    'name' => $display['name'],
                    'price' => $display['price'],
                    'display_price' => $pricingByPeriod['monthly']['display_price'],
                    'description' => data_get($catalogDefaults, $key.'.description'),
                    'features' => $display['features'],
                    'badge' => $display['badge'],
                    'audience' => (string) ($plan['audience'] ?? 'team'),
                    'contact_only' => $contactOnly,
                    'onboarding_enabled' => (bool) ($plan['onboarding_enabled'] ?? false),
                    'annual_discount_percent' => (int) round((float) config('billing.annual_discount_percent', 20)),
                    'prices_by_period' => $pricingByPeriod,
                ];
            })
            ->values()
            ->all();
    }

    private function buildPublicPricingByPeriod(mixed $rawPrice, bool $contactOnly): array
    {
        $discountPercent = max(0, min(100, (float) config('billing.annual_discount_percent', 20)));
        $rawValue = is_string($rawPrice) ? trim($rawPrice) : $rawPrice;

        if ($contactOnly) {
            return [
                'monthly' => [
                    'billing_period' => 'monthly',
                    'amount' => null,
                    'display_price' => $this->resolvePlanDisplayPrice($rawPrice),
                ],
                'yearly' => [
                    'billing_period' => 'yearly',
                    'amount' => null,
                    'display_price' => $this->resolvePlanDisplayPrice($rawPrice),
                ],
            ];
        }

        if (is_numeric($rawValue)) {
            $monthlyAmount = (float) $rawValue;
            $yearlyAmount = round($monthlyAmount * 12 * ((100 - $discountPercent) / 100), 2);

            return [
                'monthly' => [
                    'billing_period' => 'monthly',
                    'amount' => number_format($monthlyAmount, 2, '.', ''),
                    'display_price' => CurrencyFormatter::format($monthlyAmount, null),
                ],
                'yearly' => [
                    'billing_period' => 'yearly',
                    'amount' => number_format($yearlyAmount, 2, '.', ''),
                    'display_price' => CurrencyFormatter::format($yearlyAmount, null),
                ],
            ];
        }

        $displayPrice = $this->resolvePlanDisplayPrice($rawPrice);

        return [
            'monthly' => [
                'billing_period' => 'monthly',
                'amount' => null,
                'display_price' => $displayPrice,
            ],
            'yearly' => [
                'billing_period' => 'yearly',
                'amount' => null,
                'display_price' => $displayPrice,
            ],
        ];
    }

    private function resolveDefaultPricingAudience(array $catalogs, string $requestedAudience): string
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
        $locale = str_starts_with(app()->getLocale(), 'fr') ? 'fr' : 'en';

        return $locale === 'fr'
            ? [
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
            ]
            : [
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
            ];
    }
}
