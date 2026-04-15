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
            'description' => 'Grandfathered legacy access retained for older workspaces only.',
            'contact_only' => false,
            'prices' => $catalogPlanPrices('free', [
                'CAD' => ['amount' => 0, 'stripe_price_id' => $billingEnv('STRIPE_PRICE_FREE_CAD', $billingEnv('STRIPE_PRICE_FREE'))],
                'EUR' => ['amount' => 0, 'stripe_price_id' => $billingEnv('STRIPE_PRICE_FREE_EUR')],
                'USD' => ['amount' => 0, 'stripe_price_id' => $billingEnv('STRIPE_PRICE_FREE_USD')],
            ]),
        ],
        'solo_essential' => [
            'description' => 'Core plan for solo operators who need a clear operating foundation.',
            'contact_only' => false,
            'prices' => $catalogPlanPrices('solo_essential', [
                'CAD' => ['amount' => $billingEnv('STRIPE_PRICE_SOLO_ESSENTIAL_CAD_AMOUNT', $billingEnv('STRIPE_PRICE_SOLO_ESSENTIAL_AMOUNT', 19)), 'stripe_price_id' => $billingEnv('STRIPE_PRICE_SOLO_ESSENTIAL_CAD', $billingEnv('STRIPE_PRICE_SOLO_ESSENTIAL'))],
                'EUR' => ['stripe_price_id' => $billingEnv('STRIPE_PRICE_SOLO_ESSENTIAL_EUR')],
                'USD' => ['stripe_price_id' => $billingEnv('STRIPE_PRICE_SOLO_ESSENTIAL_USD')],
            ]),
        ],
        'solo_pro' => [
            'description' => 'Growth plan for solo operators who need more structure and execution capacity.',
            'contact_only' => false,
            'prices' => $catalogPlanPrices('solo_pro', [
                'CAD' => ['amount' => $billingEnv('STRIPE_PRICE_SOLO_PRO_CAD_AMOUNT', $billingEnv('STRIPE_PRICE_SOLO_PRO_AMOUNT', 39)), 'stripe_price_id' => $billingEnv('STRIPE_PRICE_SOLO_PRO_CAD', $billingEnv('STRIPE_PRICE_SOLO_PRO'))],
                'EUR' => ['stripe_price_id' => $billingEnv('STRIPE_PRICE_SOLO_PRO_EUR')],
                'USD' => ['stripe_price_id' => $billingEnv('STRIPE_PRICE_SOLO_PRO_USD')],
            ]),
        ],
        'solo_growth' => [
            'description' => 'Scale plan for solo operators who want automation and premium headroom.',
            'contact_only' => false,
            'prices' => $catalogPlanPrices('solo_growth', [
                'CAD' => ['amount' => $billingEnv('STRIPE_PRICE_SOLO_GROWTH_CAD_AMOUNT', $billingEnv('STRIPE_PRICE_SOLO_GROWTH_AMOUNT', 59)), 'stripe_price_id' => $billingEnv('STRIPE_PRICE_SOLO_GROWTH_CAD', $billingEnv('STRIPE_PRICE_SOLO_GROWTH'))],
                'EUR' => ['stripe_price_id' => $billingEnv('STRIPE_PRICE_SOLO_GROWTH_EUR')],
                'USD' => ['stripe_price_id' => $billingEnv('STRIPE_PRICE_SOLO_GROWTH_USD')],
            ]),
        ],
        'starter' => [
            'description' => 'Core team plan for shared execution and collaboration.',
            'contact_only' => false,
            'prices' => $catalogPlanPrices('starter', [
                'CAD' => ['amount' => $billingEnv('STRIPE_PRICE_STARTER_CAD_AMOUNT', $billingEnv('STRIPE_PRICE_STARTER_AMOUNT', 29)), 'stripe_price_id' => $billingEnv('STRIPE_PRICE_STARTER_CAD', $billingEnv('STRIPE_PRICE_STARTER'))],
                'EUR' => ['stripe_price_id' => $billingEnv('STRIPE_PRICE_STARTER_EUR')],
                'USD' => ['stripe_price_id' => $billingEnv('STRIPE_PRICE_STARTER_USD')],
            ]),
        ],
        'growth' => [
            'description' => 'Growth team plan for permissions, automation, and higher operating volume.',
            'contact_only' => false,
            'prices' => $catalogPlanPrices('growth', [
                'CAD' => ['amount' => $billingEnv('STRIPE_PRICE_GROWTH_CAD_AMOUNT', $billingEnv('STRIPE_PRICE_GROWTH_AMOUNT', 79)), 'stripe_price_id' => $billingEnv('STRIPE_PRICE_GROWTH_CAD', $billingEnv('STRIPE_PRICE_GROWTH'))],
                'EUR' => ['stripe_price_id' => $billingEnv('STRIPE_PRICE_GROWTH_EUR')],
                'USD' => ['stripe_price_id' => $billingEnv('STRIPE_PRICE_GROWTH_USD')],
            ]),
        ],
        'scale' => [
            'description' => 'Scale team plan for advanced operations, AI, and premium support.',
            'contact_only' => false,
            'prices' => $catalogPlanPrices('scale', [
                'CAD' => ['amount' => $billingEnv('STRIPE_PRICE_SCALE_CAD_AMOUNT', $billingEnv('STRIPE_PRICE_SCALE_AMOUNT', 149)), 'stripe_price_id' => $billingEnv('STRIPE_PRICE_SCALE_CAD', $billingEnv('STRIPE_PRICE_SCALE'))],
                'EUR' => ['stripe_price_id' => $billingEnv('STRIPE_PRICE_SCALE_EUR')],
                'USD' => ['stripe_price_id' => $billingEnv('STRIPE_PRICE_SCALE_USD')],
            ]),
        ],
        'enterprise' => [
            'description' => 'Custom enterprise plan with advanced governance, support, and integrations.',
            'contact_only' => true,
            'prices' => [],
        ],
    ],
    'plans' => [
        'free' => [
            'name' => 'Legacy Free',
            'price_id' => null,
            'price' => env($pricePrefix.'_PRICE_FREE_AMOUNT', 0),
            'audience' => 'team',
            'owner_only' => false,
            'recommended' => false,
            'onboarding_enabled' => false,
            'deprecated' => true,
            'legacy_only' => true,
            'default_modules' => [
                'accounting' => false,
            ],
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
                'Plan conserve uniquement pour les anciens espaces',
                'Aucune nouvelle souscription ni nouvel onboarding',
                'Acces de base maintenu pendant la migration legacy',
                'Support de migration vers les forfaits actifs',
            ],
        ],
        'solo_essential' => [
            'name' => 'Solo Core',
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
                'jobs' => 300,
                'products' => null,
                'services' => null,
                'tasks' => 1000,
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
                'sales' => true,
                'expenses' => true,
                'accounting' => false,
                'services' => true,
                'tasks' => true,
                'team_members' => false,
                'assistant' => false,
                'campaigns' => false,
                'loyalty' => false,
            ],
            'features' => [
                'Demandes, devis, factures, jobs et taches',
                'Catalogue, ventes et operations du quotidien',
                'Portail client et page publique',
                'Execution solo simple sans modules avances',
            ],
        ],
        'solo_pro' => [
            'name' => 'Solo Growth',
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
                'jobs' => 1500,
                'products' => null,
                'services' => null,
                'tasks' => 5000,
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
                'sales' => true,
                'expenses' => true,
                'accounting' => false,
                'services' => true,
                'tasks' => true,
                'team_members' => false,
                'assistant' => false,
                'campaigns' => false,
                'loyalty' => false,
            ],
            'features' => [
                'Tout Solo Core',
                'Plus de volume pour jobs et taches',
                'Catalogue et ventes sans logique equipe',
                'Plan solo recommande',
            ],
        ],
        'solo_growth' => [
            'name' => 'Solo Scale',
            'price_id' => env($pricePrefix.'_PRICE_SOLO_GROWTH'),
            'price' => env($pricePrefix.'_PRICE_SOLO_GROWTH_AMOUNT', 59),
            'audience' => 'solo',
            'owner_only' => true,
            'recommended' => false,
            'onboarding_enabled' => true,
            'default_limits' => [
                'quotes' => null,
                'requests' => null,
                'plan_scan_quotes' => 500,
                'invoices' => null,
                'jobs' => 5000,
                'products' => null,
                'services' => null,
                'tasks' => 20000,
                'team_members' => null,
                'assistant_requests' => 3000,
            ],
            'default_modules' => [
                'quotes' => true,
                'requests' => true,
                'reservations' => true,
                'plan_scans' => true,
                'invoices' => true,
                'jobs' => true,
                'products' => true,
                'performance' => false,
                'presence' => false,
                'planning' => true,
                'sales' => true,
                'expenses' => true,
                'accounting' => false,
                'services' => true,
                'tasks' => true,
                'team_members' => false,
                'assistant' => true,
                'campaigns' => true,
                'loyalty' => true,
            ],
            'features' => [
                'Tout Solo Growth',
                'Reservations et planning en mode solo limite',
                'Assistant, scan de plan, campagnes et fidelite',
                'Automatisation et capacite premium pour scaler seul',
                'Support prioritaire',
            ],
        ],
        'starter' => [
            'name' => 'Team Core',
            'price_id' => env($pricePrefix.'_PRICE_STARTER'),
            'price' => env($pricePrefix.'_PRICE_STARTER_AMOUNT'),
            'audience' => 'team',
            'owner_only' => false,
            'recommended' => false,
            'onboarding_enabled' => true,
            'default_limits' => [
                'quotes' => null,
                'requests' => null,
                'plan_scan_quotes' => 25,
                'invoices' => null,
                'jobs' => 1000,
                'products' => null,
                'services' => null,
                'tasks' => 3000,
                'team_members' => 5,
                'assistant_requests' => 500,
            ],
            'default_modules' => [
                'quotes' => true,
                'requests' => true,
                'reservations' => false,
                'plan_scans' => true,
                'invoices' => true,
                'jobs' => true,
                'products' => true,
                'performance' => true,
                'presence' => true,
                'planning' => true,
                'sales' => true,
                'expenses' => true,
                'accounting' => false,
                'services' => true,
                'tasks' => true,
                'team_members' => true,
                'assistant' => true,
                'campaigns' => false,
                'loyalty' => false,
            ],
            'features' => [
                'Base partagee pour demandes, devis, jobs et factures',
                'Planning d equipe, presence et collaboration',
                'Assistant et scan de plan en mode limite',
                'Jusqu a 5 membres',
            ],
        ],
        'growth' => [
            'name' => 'Team Growth',
            'price_id' => env($pricePrefix.'_PRICE_GROWTH'),
            'price' => env($pricePrefix.'_PRICE_GROWTH_AMOUNT'),
            'audience' => 'team',
            'owner_only' => false,
            'recommended' => true,
            'onboarding_enabled' => true,
            'default_limits' => [
                'quotes' => null,
                'requests' => null,
                'plan_scan_quotes' => 400,
                'invoices' => null,
                'jobs' => 5000,
                'products' => null,
                'services' => null,
                'tasks' => 15000,
                'team_members' => 15,
                'assistant_requests' => 2500,
            ],
            'default_modules' => [
                'quotes' => true,
                'requests' => true,
                'reservations' => false,
                'plan_scans' => true,
                'invoices' => true,
                'jobs' => true,
                'products' => true,
                'performance' => true,
                'presence' => true,
                'planning' => true,
                'sales' => true,
                'expenses' => true,
                'accounting' => false,
                'services' => true,
                'tasks' => true,
                'team_members' => true,
                'assistant' => true,
                'campaigns' => true,
                'loyalty' => true,
            ],
            'features' => [
                'Tout Team Core',
                'Plus de permissions, campagnes et fidelite',
                'Automatisations avancees et plus de volume',
                'Plan equipe recommande',
            ],
        ],
        'scale' => [
            'name' => 'Team Scale',
            'price_id' => env($pricePrefix.'_PRICE_SCALE'),
            'price' => env($pricePrefix.'_PRICE_SCALE_AMOUNT'),
            'audience' => 'team',
            'owner_only' => false,
            'recommended' => false,
            'onboarding_enabled' => true,
            'default_limits' => [
                'quotes' => null,
                'requests' => null,
                'plan_scan_quotes' => 1500,
                'invoices' => null,
                'jobs' => 20000,
                'products' => null,
                'services' => null,
                'tasks' => 75000,
                'team_members' => 50,
                'assistant_requests' => 10000,
            ],
            'default_modules' => [
                'quotes' => true,
                'requests' => true,
                'reservations' => false,
                'plan_scans' => true,
                'invoices' => true,
                'jobs' => true,
                'products' => true,
                'performance' => true,
                'presence' => true,
                'planning' => true,
                'sales' => true,
                'expenses' => true,
                'accounting' => false,
                'services' => true,
                'tasks' => true,
                'team_members' => true,
                'assistant' => true,
                'campaigns' => true,
                'loyalty' => true,
            ],
            'features' => [
                'Tout Team Growth',
                'Capacite premium, IA et scans renforces',
                'Pilotage avance et support premium',
                'Onboarding dedie et reporting approfondi',
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
            'default_modules' => [
                'quotes' => true,
                'requests' => true,
                'reservations' => true,
                'plan_scans' => true,
                'invoices' => true,
                'jobs' => true,
                'products' => true,
                'performance' => true,
                'presence' => true,
                'planning' => true,
                'sales' => true,
                'expenses' => true,
                'accounting' => false,
                'services' => true,
                'tasks' => true,
                'team_members' => true,
                'assistant' => true,
                'campaigns' => true,
                'loyalty' => true,
            ],
            'features' => [
                '50+ membres',
                'Tout Team Scale',
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
                            'solo_essential' => '300',
                            'solo_pro' => '1500',
                            'solo_growth' => '5000',
                        ],
                    ],
                    [
                        'label_key' => 'pricing.comparison.rows.tasks',
                        'values' => [
                            'solo_essential' => '1000',
                            'solo_pro' => '5000',
                            'solo_growth' => '20000',
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
                            'solo_growth' => '3000/mo',
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
                            'solo_growth' => '500',
                        ],
                    ],
                ],
            ],
        ],
    ],
    'public_order' => [
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
                        'starter' => '5',
                        'growth' => '15',
                        'scale' => '50',
                        'enterprise' => '50+',
                    ],
                ],
                [
                    'label_key' => 'pricing.comparison.rows.requests',
                    'values' => [
                        'starter' => 'unlimited',
                        'growth' => 'unlimited',
                        'scale' => 'unlimited',
                        'enterprise' => 'unlimited',
                    ],
                ],
                [
                    'label_key' => 'pricing.comparison.rows.estimates',
                    'values' => [
                        'starter' => 'unlimited',
                        'growth' => 'unlimited',
                        'scale' => 'unlimited',
                        'enterprise' => 'unlimited',
                    ],
                ],
                [
                    'label_key' => 'pricing.comparison.rows.invoices',
                    'values' => [
                        'starter' => 'unlimited',
                        'growth' => 'unlimited',
                        'scale' => 'unlimited',
                        'enterprise' => 'unlimited',
                    ],
                ],
                [
                    'label_key' => 'pricing.comparison.rows.tips',
                    'values' => [
                        'starter' => true,
                        'growth' => true,
                        'scale' => true,
                        'enterprise' => true,
                    ],
                ],
                [
                    'label_key' => 'pricing.comparison.rows.catalog',
                    'values' => [
                        'starter' => true,
                        'growth' => true,
                        'scale' => true,
                        'enterprise' => true,
                    ],
                ],
                [
                    'label_key' => 'pricing.comparison.rows.client_portal',
                    'values' => [
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
                    'label_key' => 'pricing.comparison.rows.jobs',
                    'values' => [
                        'starter' => '1000',
                        'growth' => '5000',
                        'scale' => '20000',
                        'enterprise' => 'Custom',
                    ],
                ],
                [
                    'label_key' => 'pricing.comparison.rows.tasks',
                    'values' => [
                        'starter' => '3000',
                        'growth' => '15000',
                        'scale' => '75000',
                        'enterprise' => 'Custom',
                    ],
                ],
                [
                    'label_key' => 'pricing.comparison.rows.team_planning',
                    'values' => [
                        'starter' => true,
                        'growth' => true,
                        'scale' => true,
                        'enterprise' => true,
                    ],
                ],
                [
                    'label_key' => 'pricing.comparison.rows.time_tracking',
                    'values' => [
                        'starter' => true,
                        'growth' => true,
                        'scale' => true,
                        'enterprise' => true,
                    ],
                ],
                [
                    'label_key' => 'pricing.comparison.rows.reservations',
                    'values' => [
                        'starter' => 'limited',
                        'growth' => 'limited',
                        'scale' => 'limited',
                        'enterprise' => 'limited',
                    ],
                ],
                [
                    'label_key' => 'pricing.comparison.rows.reports',
                    'values' => [
                        'starter' => 'Basic',
                        'growth' => 'Advanced',
                        'scale' => 'Advanced+',
                        'enterprise' => 'Custom',
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
                        'starter' => 'Basic',
                        'growth' => 'Advanced',
                        'scale' => 'Advanced+',
                        'enterprise' => 'Custom',
                    ],
                ],
                [
                    'label_key' => 'pricing.comparison.rows.automations',
                    'values' => [
                        'starter' => false,
                        'growth' => true,
                        'scale' => true,
                        'enterprise' => true,
                    ],
                ],
                [
                    'label_key' => 'pricing.comparison.rows.plan_scan',
                    'values' => [
                        'starter' => '25',
                        'growth' => '400',
                        'scale' => '1500',
                        'enterprise' => 'Custom',
                    ],
                ],
                [
                    'label_key' => 'pricing.comparison.rows.ai_assistant',
                    'values' => [
                        'starter' => '500/mo',
                        'growth' => '2500/mo',
                        'scale' => '10000/mo',
                        'enterprise' => 'Custom',
                    ],
                ],
                [
                    'label_key' => 'pricing.comparison.rows.campaigns',
                    'values' => [
                        'starter' => false,
                        'growth' => true,
                        'scale' => true,
                        'enterprise' => true,
                    ],
                ],
                [
                    'label_key' => 'pricing.comparison.rows.loyalty',
                    'values' => [
                        'starter' => false,
                        'growth' => true,
                        'scale' => true,
                        'enterprise' => true,
                    ],
                ],
                [
                    'label_key' => 'pricing.comparison.rows.onboarding',
                    'values' => [
                        'starter' => false,
                        'growth' => false,
                        'scale' => true,
                        'enterprise' => true,
                    ],
                ],
                [
                    'label_key' => 'pricing.comparison.rows.custom_integrations',
                    'values' => [
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
