<?php

$providerRequested = strtolower((string) env('BILLING_PROVIDER', 'stripe'));
$providerRequested = $providerRequested !== '' ? $providerRequested : 'stripe';
$providerEffective = in_array($providerRequested, ['stripe', 'paddle'], true) ? $providerRequested : 'stripe';

$billingEnv = static function (string $key, mixed $default = null): mixed {
    $value = env($key);

    if ($value === null) {
        return $default;
    }

    if (is_string($value) && trim($value) === '') {
        return $default;
    }

    return $value;
};

$stripeEnabled = filter_var(env('STRIPE_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
$stripeKeysReady = (bool) env('STRIPE_SECRET');
$stripePrices = [
    $billingEnv('STRIPE_PRICE_STARTER'),
    $billingEnv('STRIPE_PRICE_GROWTH'),
    $billingEnv('STRIPE_PRICE_SCALE'),
];
$stripePricesReady = ! empty(array_filter(
    $stripePrices,
    fn ($value) => is_string($value) ? trim($value) !== '' : ! empty($value)
));
$stripeReady = $stripeEnabled && $stripeKeysReady && $stripePricesReady;
$billingReminderDays = array_values(array_unique(array_filter(
    array_map(
        static fn (string $value): int => (int) trim($value),
        explode(',', (string) env('BILLING_UPCOMING_REMINDER_DAYS', '7,3,1'))
    ),
    static fn (int $value): bool => $value >= 1 && $value <= 30
)));
if ($billingReminderDays === []) {
    $billingReminderDays = [7, 3, 1];
}
$billingReminderTime = trim((string) env('BILLING_UPCOMING_REMINDERS_TIME', '09:00'));
$billingReminderTime = preg_match('/^\d{2}:\d{2}$/', $billingReminderTime) ? $billingReminderTime : '09:00';
$annualDiscountPercent = 0.0;
$annualDiscountMultiplier = (100 - $annualDiscountPercent) / 100;
$billingBaseCurrency = strtoupper((string) $billingEnv('BILLING_BASE_CURRENCY', 'CAD'));
if ($billingBaseCurrency === '') {
    $billingBaseCurrency = 'CAD';
}
$currencyConversionRates = [
    'CAD' => 1.0,
    'EUR' => (float) $billingEnv('BILLING_CAD_TO_EUR_RATE', 0.6333333333),
    'USD' => (float) $billingEnv('BILLING_CAD_TO_USD_RATE', 0.7333333333),
];
$currencyRoundingIncrements = [
    'CAD' => (float) $billingEnv('BILLING_CURRENCY_INCREMENT_CAD', 0.01),
    'EUR' => (float) $billingEnv('BILLING_CURRENCY_INCREMENT_EUR', 1.00),
    'USD' => (float) $billingEnv('BILLING_CURRENCY_INCREMENT_USD', 1.00),
];
$roundBillingAmount = static function (float $amount, float $increment): float {
    if ($increment <= 0) {
        return round($amount, 2);
    }

    return round(round($amount / $increment) * $increment, 2);
};
$convertFromBaseCurrency = static function (float $baseAmount, string $currencyCode) use (
    $billingBaseCurrency,
    $currencyConversionRates,
    $currencyRoundingIncrements,
    $roundBillingAmount,
): ?float {
    $currencyCode = strtoupper(trim($currencyCode));

    if ($currencyCode === $billingBaseCurrency) {
        return round($baseAmount, 2);
    }

    $rate = $currencyConversionRates[$currencyCode] ?? null;
    if (! is_numeric($rate) || (float) $rate <= 0) {
        return null;
    }

    $increment = (float) ($currencyRoundingIncrements[$currencyCode] ?? 0.01);

    return $roundBillingAmount($baseAmount * (float) $rate, $increment);
};
$catalogPlanPrices = static function (string $planCode, array $monthlyPrices) use (
    $billingBaseCurrency,
    $annualDiscountMultiplier,
    $billingEnv,
    $convertFromBaseCurrency,
): array {
    $planCode = strtoupper($planCode);
    $prices = [];
    $baseDefinition = is_array($monthlyPrices[$billingBaseCurrency] ?? null) ? $monthlyPrices[$billingBaseCurrency] : [];
    $baseEnvPrefix = 'STRIPE_PRICE_'.$planCode.'_'.$billingBaseCurrency;
    $legacyPrefix = 'STRIPE_PRICE_'.$planCode;
    $baseDefaultAmount = is_numeric($baseDefinition['amount'] ?? null)
        ? (float) $baseDefinition['amount']
        : 0.0;
    $baseCurrencyAmount = (float) $billingEnv(
        $baseEnvPrefix.'_AMOUNT',
        $billingBaseCurrency === 'CAD'
            ? $billingEnv($legacyPrefix.'_AMOUNT', $baseDefaultAmount)
            : $baseDefaultAmount
    );

    foreach ($monthlyPrices as $currencyCode => $definition) {
        $currencyCode = strtoupper((string) $currencyCode);
        $envPrefix = 'STRIPE_PRICE_'.$planCode.'_'.$currencyCode;
        $defaultAmount = is_numeric($definition['amount'] ?? null)
            ? (float) $definition['amount']
            : null;
        $resolvedMonthlyAmount = $billingEnv(
            $envPrefix.'_AMOUNT',
            $currencyCode === $billingBaseCurrency
                ? ($billingBaseCurrency === 'CAD'
                    ? $billingEnv($legacyPrefix.'_AMOUNT', $defaultAmount)
                    : $defaultAmount)
                : $defaultAmount
        );

        if ((! is_numeric($resolvedMonthlyAmount) || (float) $resolvedMonthlyAmount <= 0) && $baseCurrencyAmount > 0) {
            $resolvedMonthlyAmount = $convertFromBaseCurrency($baseCurrencyAmount, $currencyCode);
        }

        $monthlyAmount = is_numeric($resolvedMonthlyAmount) ? (float) $resolvedMonthlyAmount : 0.0;
        $yearlyDefault = round($monthlyAmount * 12 * $annualDiscountMultiplier, 2);

        $prices[$currencyCode] = [
            'monthly' => [
                'amount' => $monthlyAmount,
                'stripe_price_id' => $definition['stripe_price_id'] ?? null,
            ],
            'yearly' => [
                'amount' => $billingEnv(
                    $envPrefix.'_YEARLY_AMOUNT',
                    $currencyCode === 'CAD'
                        ? $billingEnv($legacyPrefix.'_YEARLY_AMOUNT', $yearlyDefault)
                        : $yearlyDefault
                ),
                'stripe_price_id' => $billingEnv(
                    $envPrefix.'_YEARLY',
                    $currencyCode === 'CAD'
                        ? $billingEnv($legacyPrefix.'_YEARLY')
                        : null
                ),
            ],
        ];
    }

    return $prices;
};

$pricePrefix = $providerEffective === 'stripe' ? 'STRIPE' : 'PADDLE';

return [
    'provider' => $providerRequested,
    'provider_effective' => $providerEffective,
    'provider_ready' => $providerEffective === 'stripe' ? $stripeReady : true,
    'annual_discount_percent' => $annualDiscountPercent,
    'currency_conversion' => [
        'base_currency' => $billingBaseCurrency,
        'rates' => $currencyConversionRates,
        'rounding_increments' => $currencyRoundingIncrements,
    ],
    'upcoming_reminders' => [
        'enabled' => filter_var(env('BILLING_UPCOMING_REMINDERS_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'days' => $billingReminderDays,
        'time' => $billingReminderTime,
    ],
    'catalog_defaults' => [
        'free' => [
            'description' => 'Free starter access for very small teams.',
            'contact_only' => false,
            'prices' => $catalogPlanPrices('free', [
                'CAD' => ['amount' => 0, 'stripe_price_id' => $billingEnv('STRIPE_PRICE_FREE_CAD', $billingEnv('STRIPE_PRICE_FREE'))],
                'EUR' => ['amount' => 0, 'stripe_price_id' => $billingEnv('STRIPE_PRICE_FREE_EUR')],
                'USD' => ['amount' => 0, 'stripe_price_id' => $billingEnv('STRIPE_PRICE_FREE_USD')],
            ]),
        ],
        'solo_essential' => [
            'description' => 'Essential solo plan for independent operators.',
            'contact_only' => false,
            'prices' => $catalogPlanPrices('solo_essential', [
                'CAD' => ['amount' => $billingEnv('STRIPE_PRICE_SOLO_ESSENTIAL_CAD_AMOUNT', $billingEnv('STRIPE_PRICE_SOLO_ESSENTIAL_AMOUNT', 19)), 'stripe_price_id' => $billingEnv('STRIPE_PRICE_SOLO_ESSENTIAL_CAD', $billingEnv('STRIPE_PRICE_SOLO_ESSENTIAL'))],
                'EUR' => ['stripe_price_id' => $billingEnv('STRIPE_PRICE_SOLO_ESSENTIAL_EUR')],
                'USD' => ['stripe_price_id' => $billingEnv('STRIPE_PRICE_SOLO_ESSENTIAL_USD')],
            ]),
        ],
        'solo_pro' => [
            'description' => 'Structured solo plan for active independent operators.',
            'contact_only' => false,
            'prices' => $catalogPlanPrices('solo_pro', [
                'CAD' => ['amount' => $billingEnv('STRIPE_PRICE_SOLO_PRO_CAD_AMOUNT', $billingEnv('STRIPE_PRICE_SOLO_PRO_AMOUNT', 39)), 'stripe_price_id' => $billingEnv('STRIPE_PRICE_SOLO_PRO_CAD', $billingEnv('STRIPE_PRICE_SOLO_PRO'))],
                'EUR' => ['stripe_price_id' => $billingEnv('STRIPE_PRICE_SOLO_PRO_EUR')],
                'USD' => ['stripe_price_id' => $billingEnv('STRIPE_PRICE_SOLO_PRO_USD')],
            ]),
        ],
        'solo_growth' => [
            'description' => 'Advanced solo plan with automation and richer booking flows.',
            'contact_only' => false,
            'prices' => $catalogPlanPrices('solo_growth', [
                'CAD' => ['amount' => $billingEnv('STRIPE_PRICE_SOLO_GROWTH_CAD_AMOUNT', $billingEnv('STRIPE_PRICE_SOLO_GROWTH_AMOUNT', 59)), 'stripe_price_id' => $billingEnv('STRIPE_PRICE_SOLO_GROWTH_CAD', $billingEnv('STRIPE_PRICE_SOLO_GROWTH'))],
                'EUR' => ['stripe_price_id' => $billingEnv('STRIPE_PRICE_SOLO_GROWTH_EUR')],
                'USD' => ['stripe_price_id' => $billingEnv('STRIPE_PRICE_SOLO_GROWTH_USD')],
            ]),
        ],
        'starter' => [
            'description' => 'Starter plan for growing teams.',
            'contact_only' => false,
            'prices' => $catalogPlanPrices('starter', [
                'CAD' => ['amount' => $billingEnv('STRIPE_PRICE_STARTER_CAD_AMOUNT', $billingEnv('STRIPE_PRICE_STARTER_AMOUNT', 29)), 'stripe_price_id' => $billingEnv('STRIPE_PRICE_STARTER_CAD', $billingEnv('STRIPE_PRICE_STARTER'))],
                'EUR' => ['stripe_price_id' => $billingEnv('STRIPE_PRICE_STARTER_EUR')],
                'USD' => ['stripe_price_id' => $billingEnv('STRIPE_PRICE_STARTER_USD')],
            ]),
        ],
        'growth' => [
            'description' => 'Growth plan for larger teams and automation.',
            'contact_only' => false,
            'prices' => $catalogPlanPrices('growth', [
                'CAD' => ['amount' => $billingEnv('STRIPE_PRICE_GROWTH_CAD_AMOUNT', $billingEnv('STRIPE_PRICE_GROWTH_AMOUNT', 79)), 'stripe_price_id' => $billingEnv('STRIPE_PRICE_GROWTH_CAD', $billingEnv('STRIPE_PRICE_GROWTH'))],
                'EUR' => ['stripe_price_id' => $billingEnv('STRIPE_PRICE_GROWTH_EUR')],
                'USD' => ['stripe_price_id' => $billingEnv('STRIPE_PRICE_GROWTH_USD')],
            ]),
        ],
        'scale' => [
            'description' => 'Scale plan with advanced support and included AI.',
            'contact_only' => false,
            'prices' => $catalogPlanPrices('scale', [
                'CAD' => ['amount' => $billingEnv('STRIPE_PRICE_SCALE_CAD_AMOUNT', $billingEnv('STRIPE_PRICE_SCALE_AMOUNT', 149)), 'stripe_price_id' => $billingEnv('STRIPE_PRICE_SCALE_CAD', $billingEnv('STRIPE_PRICE_SCALE'))],
                'EUR' => ['stripe_price_id' => $billingEnv('STRIPE_PRICE_SCALE_EUR')],
                'USD' => ['stripe_price_id' => $billingEnv('STRIPE_PRICE_SCALE_USD')],
            ]),
        ],
        'enterprise' => [
            'description' => 'Enterprise plan with custom pricing.',
            'contact_only' => true,
            'prices' => [],
        ],
    ],
    'plans' => [
        'free' => [
            'name' => 'Gratuit',
            'price_id' => null,
            'price' => env($pricePrefix.'_PRICE_FREE_AMOUNT', 0),
            'audience' => 'team',
            'owner_only' => false,
            'recommended' => false,
            'onboarding_enabled' => false,
            'default_limits' => [
                'quotes' => null,
                'requests' => null,
                'plan_scan_quotes' => 10,
                'invoices' => null,
                'jobs' => 10,
                'products' => null,
                'services' => null,
                'tasks' => 25,
                'team_members' => 1,
                'assistant_requests' => 0,
            ],
            'features' => [
                'Jusqu a 3 employes',
                'Clients, devis et factures de base',
                'Jobs, taches et planning simple',
                'Catalogue produits et services',
                'Portail client (acceptation + paiement)',
                'Support email',
            ],
        ],
        'solo_essential' => [
            'name' => 'Solo Essential',
            'price_id' => env($pricePrefix.'_PRICE_SOLO_ESSENTIAL'),
            'price' => env($pricePrefix.'_PRICE_SOLO_ESSENTIAL_AMOUNT', 19),
            'audience' => 'solo',
            'owner_only' => true,
            'recommended' => false,
            'onboarding_enabled' => true,
            'default_limits' => [
                'quotes' => null,
                'requests' => null,
                'plan_scan_quotes' => 0,
                'invoices' => null,
                'jobs' => 0,
                'products' => null,
                'services' => null,
                'tasks' => 0,
                'team_members' => null,
                'assistant_requests' => 0,
            ],
            'default_modules' => [
                'quotes' => true,
                'requests' => true,
                'reservations' => false,
                'plan_scans' => false,
                'invoices' => true,
                'jobs' => false,
                'products' => true,
                'performance' => false,
                'presence' => false,
                'planning' => false,
                'sales' => false,
                'services' => true,
                'tasks' => false,
                'team_members' => false,
                'assistant' => false,
                'campaigns' => false,
                'loyalty' => false,
            ],
            'features' => [
                'Devis et factures sans friction',
                'Demandes clients et suivi de base',
                'Catalogue produits et services',
                'Page publique simple',
            ],
        ],
        'solo_pro' => [
            'name' => 'Solo Pro',
            'price_id' => env($pricePrefix.'_PRICE_SOLO_PRO'),
            'price' => env($pricePrefix.'_PRICE_SOLO_PRO_AMOUNT', 39),
            'audience' => 'solo',
            'owner_only' => true,
            'recommended' => true,
            'onboarding_enabled' => true,
            'default_limits' => [
                'quotes' => null,
                'requests' => null,
                'plan_scan_quotes' => 0,
                'invoices' => null,
                'jobs' => 100,
                'products' => null,
                'services' => null,
                'tasks' => 200,
                'team_members' => null,
                'assistant_requests' => 0,
            ],
            'default_modules' => [
                'quotes' => true,
                'requests' => true,
                'reservations' => false,
                'plan_scans' => false,
                'invoices' => true,
                'jobs' => true,
                'products' => true,
                'performance' => false,
                'presence' => false,
                'planning' => false,
                'sales' => false,
                'services' => true,
                'tasks' => true,
                'team_members' => false,
                'assistant' => false,
                'campaigns' => false,
                'loyalty' => false,
            ],
            'features' => [
                'Tout Solo Essential',
                'Jobs et taches pour mieux suivre le terrain',
                'Execution plus cadree sans complexite equipe',
                'Plan solo recommande',
            ],
        ],
        'solo_growth' => [
            'name' => 'Solo Growth',
            'price_id' => env($pricePrefix.'_PRICE_SOLO_GROWTH'),
            'price' => env($pricePrefix.'_PRICE_SOLO_GROWTH_AMOUNT', 59),
            'audience' => 'solo',
            'owner_only' => true,
            'recommended' => false,
            'onboarding_enabled' => true,
            'default_limits' => [
                'quotes' => null,
                'requests' => null,
                'plan_scan_quotes' => 150,
                'invoices' => null,
                'jobs' => 500,
                'products' => null,
                'services' => null,
                'tasks' => 1200,
                'team_members' => null,
                'assistant_requests' => 1000,
            ],
            'default_modules' => [
                'quotes' => true,
                'requests' => true,
                'reservations' => true,
                'plan_scans' => true,
                'invoices' => true,
                'jobs' => true,
                'products' => true,
                'performance' => true,
                'presence' => false,
                'planning' => true,
                'sales' => false,
                'services' => true,
                'tasks' => true,
                'team_members' => false,
                'assistant' => true,
                'campaigns' => true,
                'loyalty' => true,
            ],
            'features' => [
                'Tout Solo Pro',
                'Reservations et planning',
                'Assistant, campagnes et fidelite',
                'Scan de plan et automatisations avancees',
            ],
        ],
        'starter' => [
            'name' => 'Starter',
            'price_id' => env($pricePrefix.'_PRICE_STARTER'),
            'price' => env($pricePrefix.'_PRICE_STARTER_AMOUNT'),
            'audience' => 'team',
            'owner_only' => false,
            'recommended' => false,
            'onboarding_enabled' => true,
            'default_limits' => [
                'quotes' => null,
                'requests' => null,
                'plan_scan_quotes' => 100,
                'invoices' => null,
                'jobs' => 100,
                'products' => null,
                'services' => null,
                'tasks' => 200,
                'team_members' => 5,
                'assistant_requests' => 0,
            ],
            'features' => [
                'Jusqu a 10 employes',
                'Clients, devis, jobs et factures',
                'Planning d equipe (jour/semaine/mois/annee)',
                'Presence et pointage equipe',
                'Catalogue produits/services + prix',
                'Portail client et validations',
                'Performance par periode',
                'Assistant IA (option payante)',
            ],
        ],
        'growth' => [
            'name' => 'Growth',
            'price_id' => env($pricePrefix.'_PRICE_GROWTH'),
            'price' => env($pricePrefix.'_PRICE_GROWTH_AMOUNT'),
            'audience' => 'team',
            'owner_only' => false,
            'recommended' => true,
            'onboarding_enabled' => true,
            'default_limits' => [
                'quotes' => null,
                'requests' => null,
                'plan_scan_quotes' => 300,
                'invoices' => null,
                'jobs' => 300,
                'products' => null,
                'services' => null,
                'tasks' => 600,
                'team_members' => 15,
                'assistant_requests' => 0,
            ],
            'features' => [
                'Jusqu a 25 employes',
                'Tout Starter',
                'Equipe et permissions',
                'Planning combine jobs + taches',
                'Performance avancee clients/equipe',
                'Scan de plan et devis accelere',
                'Workflow avance et automatisations',
                'Support prioritaire',
                'Assistant IA (option payante)',
            ],
        ],
        'scale' => [
            'name' => 'Scale',
            'price_id' => env($pricePrefix.'_PRICE_SCALE'),
            'price' => env($pricePrefix.'_PRICE_SCALE_AMOUNT'),
            'audience' => 'team',
            'owner_only' => false,
            'recommended' => false,
            'onboarding_enabled' => true,
            'default_limits' => [
                'quotes' => null,
                'requests' => null,
                'plan_scan_quotes' => 1000,
                'invoices' => null,
                'jobs' => 1000,
                'products' => null,
                'services' => null,
                'tasks' => 2500,
                'team_members' => 50,
                'assistant_requests' => 0,
            ],
            'features' => [
                'Jusqu a 50 employes',
                'Tout Growth',
                'Rapports avances et exports',
                'Assistant IA (inclus)',
                'Onboarding dedie',
                'Support prioritaire',
                'Parametrages avances',
            ],
        ],
        'enterprise' => [
            'name' => 'Enterprise',
            'price_id' => null,
            'price' => 'Sur mesure',
            'audience' => 'team',
            'owner_only' => false,
            'recommended' => false,
            'onboarding_enabled' => false,
            'contact_only' => true,
            'team_members_min' => 50,
            'features' => [
                '50+ employes',
                'Tout Scale',
                'Integrations et SLA sur mesure',
                'Onboarding et accompagnement dedie',
                'Support prioritaire 24/7',
            ],
        ],
    ],
    'default_public_audience' => 'solo',
    'public_catalogs' => [
        'solo' => [
            'order' => ['solo_essential', 'solo_pro', 'solo_growth'],
            'highlighted_plan_key' => 'solo_pro',
        ],
        'team' => [
            'order' => ['starter', 'growth', 'scale', 'enterprise'],
            'highlighted_plan_key' => 'growth',
        ],
    ],
    'comparison_catalogs' => [
        'solo' => [
            [
                'label_key' => 'pricing.comparison.sections.fundamentals',
                'rows' => [
                    [
                        'label_key' => 'pricing.comparison.rows.account_access',
                        'values' => [
                            'solo_essential' => 'owner_only',
                            'solo_pro' => 'owner_only',
                            'solo_growth' => 'owner_only',
                        ],
                    ],
                    [
                        'label_key' => 'pricing.comparison.rows.requests',
                        'values' => [
                            'solo_essential' => 'unlimited',
                            'solo_pro' => 'unlimited',
                            'solo_growth' => 'unlimited',
                        ],
                    ],
                    [
                        'label_key' => 'pricing.comparison.rows.estimates',
                        'values' => [
                            'solo_essential' => 'unlimited',
                            'solo_pro' => 'unlimited',
                            'solo_growth' => 'unlimited',
                        ],
                    ],
                    [
                        'label_key' => 'pricing.comparison.rows.invoices',
                        'values' => [
                            'solo_essential' => 'unlimited',
                            'solo_pro' => 'unlimited',
                            'solo_growth' => 'unlimited',
                        ],
                    ],
                    [
                        'label_key' => 'pricing.comparison.rows.tips',
                        'values' => [
                            'solo_essential' => true,
                            'solo_pro' => true,
                            'solo_growth' => true,
                        ],
                    ],
                    [
                        'label_key' => 'pricing.comparison.rows.catalog_items',
                        'values' => [
                            'solo_essential' => 'unlimited',
                            'solo_pro' => 'unlimited',
                            'solo_growth' => 'unlimited',
                        ],
                    ],
                ],
            ],
            [
                'label_key' => 'pricing.comparison.sections.operations',
                'rows' => [
                    [
                        'label_key' => 'pricing.comparison.rows.jobs',
                        'values' => [
                            'solo_essential' => false,
                            'solo_pro' => '100',
                            'solo_growth' => '500',
                        ],
                    ],
                    [
                        'label_key' => 'pricing.comparison.rows.tasks',
                        'values' => [
                            'solo_essential' => false,
                            'solo_pro' => '200',
                            'solo_growth' => '1200',
                        ],
                    ],
                    [
                        'label_key' => 'pricing.comparison.rows.planning',
                        'values' => [
                            'solo_essential' => false,
                            'solo_pro' => false,
                            'solo_growth' => true,
                        ],
                    ],
                    [
                        'label_key' => 'pricing.comparison.rows.reservations',
                        'values' => [
                            'solo_essential' => false,
                            'solo_pro' => false,
                            'solo_growth' => 'limited',
                        ],
                    ],
                ],
            ],
            [
                'label_key' => 'pricing.comparison.sections.growth',
                'rows' => [
                    [
                        'label_key' => 'pricing.comparison.rows.ai_assistant',
                        'values' => [
                            'solo_essential' => false,
                            'solo_pro' => false,
                            'solo_growth' => '1000/mo',
                        ],
                    ],
                    [
                        'label_key' => 'pricing.comparison.rows.campaigns',
                        'values' => [
                            'solo_essential' => false,
                            'solo_pro' => false,
                            'solo_growth' => true,
                        ],
                    ],
                    [
                        'label_key' => 'pricing.comparison.rows.loyalty',
                        'values' => [
                            'solo_essential' => false,
                            'solo_pro' => false,
                            'solo_growth' => true,
                        ],
                    ],
                    [
                        'label_key' => 'pricing.comparison.rows.plan_scan',
                        'values' => [
                            'solo_essential' => false,
                            'solo_pro' => false,
                            'solo_growth' => '150',
                        ],
                    ],
                ],
            ],
        ],
    ],
    'public_order' => [
        'free',
        'starter',
        'growth',
        'scale',
        'enterprise',
    ],
    'comparison' => [
        [
            'label_key' => 'pricing.comparison.sections.fundamentals',
            'rows' => [
                [
                    'label_key' => 'pricing.comparison.rows.team_members',
                    'values' => [
                        'free' => '3',
                        'starter' => '10',
                        'growth' => '25',
                        'scale' => '50',
                        'enterprise' => '50+',
                    ],
                ],
                [
                    'label_key' => 'pricing.comparison.rows.clients',
                    'values' => [
                        'free' => 'unlimited',
                        'starter' => 'unlimited',
                        'growth' => 'unlimited',
                        'scale' => 'unlimited',
                        'enterprise' => 'unlimited',
                    ],
                ],
                [
                    'label_key' => 'pricing.comparison.rows.requests',
                    'values' => [
                        'free' => 'unlimited',
                        'starter' => 'unlimited',
                        'growth' => 'unlimited',
                        'scale' => 'unlimited',
                        'enterprise' => 'unlimited',
                    ],
                ],
                [
                    'label_key' => 'pricing.comparison.rows.estimates',
                    'values' => [
                        'free' => 'unlimited',
                        'starter' => 'unlimited',
                        'growth' => 'unlimited',
                        'scale' => 'unlimited',
                        'enterprise' => 'unlimited',
                    ],
                ],
                [
                    'label_key' => 'pricing.comparison.rows.invoices',
                    'values' => [
                        'free' => 'unlimited',
                        'starter' => 'unlimited',
                        'growth' => 'unlimited',
                        'scale' => 'unlimited',
                        'enterprise' => 'unlimited',
                    ],
                ],
                [
                    'label_key' => 'pricing.comparison.rows.tips',
                    'values' => [
                        'free' => true,
                        'starter' => true,
                        'growth' => true,
                        'scale' => true,
                        'enterprise' => true,
                    ],
                ],
                [
                    'label_key' => 'pricing.comparison.rows.catalog',
                    'values' => [
                        'free' => true,
                        'starter' => true,
                        'growth' => true,
                        'scale' => true,
                        'enterprise' => true,
                    ],
                ],
                [
                    'label_key' => 'pricing.comparison.rows.client_portal',
                    'values' => [
                        'free' => true,
                        'starter' => true,
                        'growth' => true,
                        'scale' => true,
                        'enterprise' => true,
                    ],
                ],
            ],
        ],
        [
            'label_key' => 'pricing.comparison.sections.operations',
            'rows' => [
                [
                    'label_key' => 'pricing.comparison.rows.team_planning',
                    'values' => [
                        'free' => true,
                        'starter' => true,
                        'growth' => true,
                        'scale' => true,
                        'enterprise' => true,
                    ],
                ],
                [
                    'label_key' => 'pricing.comparison.rows.time_tracking',
                    'values' => [
                        'free' => false,
                        'starter' => true,
                        'growth' => true,
                        'scale' => true,
                        'enterprise' => true,
                    ],
                ],
                [
                    'label_key' => 'pricing.comparison.rows.reservations',
                    'values' => [
                        'free' => false,
                        'starter' => true,
                        'growth' => true,
                        'scale' => true,
                        'enterprise' => true,
                    ],
                ],
                [
                    'label_key' => 'pricing.comparison.rows.recurring_jobs',
                    'values' => [
                        'free' => false,
                        'starter' => false,
                        'growth' => true,
                        'scale' => true,
                        'enterprise' => true,
                    ],
                ],
                [
                    'label_key' => 'pricing.comparison.rows.reports',
                    'values' => [
                        'free' => false,
                        'starter' => true,
                        'growth' => true,
                        'scale' => true,
                        'enterprise' => true,
                    ],
                ],
            ],
        ],
        [
            'label_key' => 'pricing.comparison.sections.growth',
            'rows' => [
                [
                    'label_key' => 'pricing.comparison.rows.permissions',
                    'values' => [
                        'free' => false,
                        'starter' => false,
                        'growth' => true,
                        'scale' => true,
                        'enterprise' => true,
                    ],
                ],
                [
                    'label_key' => 'pricing.comparison.rows.automations',
                    'values' => [
                        'free' => false,
                        'starter' => false,
                        'growth' => true,
                        'scale' => true,
                        'enterprise' => true,
                    ],
                ],
                [
                    'label_key' => 'pricing.comparison.rows.plan_scan',
                    'values' => [
                        'free' => false,
                        'starter' => false,
                        'growth' => true,
                        'scale' => true,
                        'enterprise' => true,
                    ],
                ],
                [
                    'label_key' => 'pricing.comparison.rows.ai_assistant',
                    'values' => [
                        'free' => false,
                        'starter' => 'optional',
                        'growth' => 'optional',
                        'scale' => true,
                        'enterprise' => true,
                    ],
                ],
                [
                    'label_key' => 'pricing.comparison.rows.onboarding',
                    'values' => [
                        'free' => false,
                        'starter' => false,
                        'growth' => false,
                        'scale' => true,
                        'enterprise' => true,
                    ],
                ],
                [
                    'label_key' => 'pricing.comparison.rows.custom_integrations',
                    'values' => [
                        'free' => false,
                        'starter' => false,
                        'growth' => false,
                        'scale' => false,
                        'enterprise' => 'contact',
                    ],
                ],
            ],
        ],
    ],
];
