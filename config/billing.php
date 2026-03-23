<?php

$providerRequested = strtolower((string) env('BILLING_PROVIDER', 'stripe'));
$providerRequested = $providerRequested !== '' ? $providerRequested : 'stripe';
$providerEffective = in_array($providerRequested, ['stripe', 'paddle'], true) ? $providerRequested : 'stripe';

$stripeEnabled = filter_var(env('STRIPE_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
$stripeKeysReady = (bool) env('STRIPE_SECRET');
$stripePrices = [
    env('STRIPE_PRICE_STARTER'),
    env('STRIPE_PRICE_GROWTH'),
    env('STRIPE_PRICE_SCALE'),
];
$stripePricesReady = ! empty(array_filter(
    $stripePrices,
    fn ($value) => is_string($value) ? trim($value) !== '' : ! empty($value)
));
$stripeReady = $stripeEnabled && $stripeKeysReady && $stripePricesReady;

$pricePrefix = $providerEffective === 'stripe' ? 'STRIPE' : 'PADDLE';

return [
    'provider' => $providerRequested,
    'provider_effective' => $providerEffective,
    'provider_ready' => $providerEffective === 'stripe' ? $stripeReady : true,
    'catalog_defaults' => [
        'free' => [
            'description' => 'Free starter access for very small teams.',
            'contact_only' => false,
            'prices' => [
                'CAD' => ['amount' => 0, 'stripe_price_id' => env('STRIPE_PRICE_FREE_CAD', env('STRIPE_PRICE_FREE'))],
                'EUR' => ['amount' => 0, 'stripe_price_id' => env('STRIPE_PRICE_FREE_EUR')],
                'USD' => ['amount' => 0, 'stripe_price_id' => env('STRIPE_PRICE_FREE_USD')],
            ],
        ],
        'solo_essential' => [
            'description' => 'Essential solo plan for independent operators.',
            'contact_only' => false,
            'prices' => [
                'CAD' => ['amount' => env('STRIPE_PRICE_SOLO_ESSENTIAL_CAD_AMOUNT', env('STRIPE_PRICE_SOLO_ESSENTIAL_AMOUNT', 19)), 'stripe_price_id' => env('STRIPE_PRICE_SOLO_ESSENTIAL_CAD', env('STRIPE_PRICE_SOLO_ESSENTIAL'))],
                'EUR' => ['amount' => env('STRIPE_PRICE_SOLO_ESSENTIAL_EUR_AMOUNT', 14), 'stripe_price_id' => env('STRIPE_PRICE_SOLO_ESSENTIAL_EUR')],
                'USD' => ['amount' => env('STRIPE_PRICE_SOLO_ESSENTIAL_USD_AMOUNT', 16), 'stripe_price_id' => env('STRIPE_PRICE_SOLO_ESSENTIAL_USD')],
            ],
        ],
        'solo_pro' => [
            'description' => 'Structured solo plan for active independent operators.',
            'contact_only' => false,
            'prices' => [
                'CAD' => ['amount' => env('STRIPE_PRICE_SOLO_PRO_CAD_AMOUNT', env('STRIPE_PRICE_SOLO_PRO_AMOUNT', 39)), 'stripe_price_id' => env('STRIPE_PRICE_SOLO_PRO_CAD', env('STRIPE_PRICE_SOLO_PRO'))],
                'EUR' => ['amount' => env('STRIPE_PRICE_SOLO_PRO_EUR_AMOUNT', 29), 'stripe_price_id' => env('STRIPE_PRICE_SOLO_PRO_EUR')],
                'USD' => ['amount' => env('STRIPE_PRICE_SOLO_PRO_USD_AMOUNT', 32), 'stripe_price_id' => env('STRIPE_PRICE_SOLO_PRO_USD')],
            ],
        ],
        'solo_growth' => [
            'description' => 'Advanced solo plan with automation and richer booking flows.',
            'contact_only' => false,
            'prices' => [
                'CAD' => ['amount' => env('STRIPE_PRICE_SOLO_GROWTH_CAD_AMOUNT', env('STRIPE_PRICE_SOLO_GROWTH_AMOUNT', 59)), 'stripe_price_id' => env('STRIPE_PRICE_SOLO_GROWTH_CAD', env('STRIPE_PRICE_SOLO_GROWTH'))],
                'EUR' => ['amount' => env('STRIPE_PRICE_SOLO_GROWTH_EUR_AMOUNT', 43), 'stripe_price_id' => env('STRIPE_PRICE_SOLO_GROWTH_EUR')],
                'USD' => ['amount' => env('STRIPE_PRICE_SOLO_GROWTH_USD_AMOUNT', 48), 'stripe_price_id' => env('STRIPE_PRICE_SOLO_GROWTH_USD')],
            ],
        ],
        'starter' => [
            'description' => 'Starter plan for growing teams.',
            'contact_only' => false,
            'prices' => [
                'CAD' => ['amount' => env('STRIPE_PRICE_STARTER_CAD_AMOUNT', env('STRIPE_PRICE_STARTER_AMOUNT', 29)), 'stripe_price_id' => env('STRIPE_PRICE_STARTER_CAD', env('STRIPE_PRICE_STARTER'))],
                'EUR' => ['amount' => env('STRIPE_PRICE_STARTER_EUR_AMOUNT', 21), 'stripe_price_id' => env('STRIPE_PRICE_STARTER_EUR')],
                'USD' => ['amount' => env('STRIPE_PRICE_STARTER_USD_AMOUNT', 24), 'stripe_price_id' => env('STRIPE_PRICE_STARTER_USD')],
            ],
        ],
        'growth' => [
            'description' => 'Growth plan for larger teams and automation.',
            'contact_only' => false,
            'prices' => [
                'CAD' => ['amount' => env('STRIPE_PRICE_GROWTH_CAD_AMOUNT', env('STRIPE_PRICE_GROWTH_AMOUNT', 79)), 'stripe_price_id' => env('STRIPE_PRICE_GROWTH_CAD', env('STRIPE_PRICE_GROWTH'))],
                'EUR' => ['amount' => env('STRIPE_PRICE_GROWTH_EUR_AMOUNT', 57), 'stripe_price_id' => env('STRIPE_PRICE_GROWTH_EUR')],
                'USD' => ['amount' => env('STRIPE_PRICE_GROWTH_USD_AMOUNT', 64), 'stripe_price_id' => env('STRIPE_PRICE_GROWTH_USD')],
            ],
        ],
        'scale' => [
            'description' => 'Scale plan with advanced support and included AI.',
            'contact_only' => false,
            'prices' => [
                'CAD' => ['amount' => env('STRIPE_PRICE_SCALE_CAD_AMOUNT', env('STRIPE_PRICE_SCALE_AMOUNT', 149)), 'stripe_price_id' => env('STRIPE_PRICE_SCALE_CAD', env('STRIPE_PRICE_SCALE'))],
                'EUR' => ['amount' => env('STRIPE_PRICE_SCALE_EUR_AMOUNT', 109), 'stripe_price_id' => env('STRIPE_PRICE_SCALE_EUR')],
                'USD' => ['amount' => env('STRIPE_PRICE_SCALE_USD_AMOUNT', 119), 'stripe_price_id' => env('STRIPE_PRICE_SCALE_USD')],
            ],
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
                'quotes' => 25,
                'requests' => 25,
                'plan_scan_quotes' => 0,
                'invoices' => 25,
                'jobs' => 0,
                'products' => 50,
                'services' => 50,
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
                'quotes' => 100,
                'requests' => 100,
                'plan_scan_quotes' => 0,
                'invoices' => 100,
                'jobs' => 100,
                'products' => 150,
                'services' => 150,
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
                'quotes' => 500,
                'requests' => 500,
                'plan_scan_quotes' => 150,
                'invoices' => 500,
                'jobs' => 500,
                'products' => 1000,
                'services' => 1000,
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
            'order' => ['free', 'starter', 'growth', 'scale', 'enterprise'],
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
                            'solo_essential' => '25',
                            'solo_pro' => '100',
                            'solo_growth' => '500',
                        ],
                    ],
                    [
                        'label_key' => 'pricing.comparison.rows.estimates',
                        'values' => [
                            'solo_essential' => '25',
                            'solo_pro' => '100',
                            'solo_growth' => '500',
                        ],
                    ],
                    [
                        'label_key' => 'pricing.comparison.rows.invoices',
                        'values' => [
                            'solo_essential' => '25',
                            'solo_pro' => '100',
                            'solo_growth' => '500',
                        ],
                    ],
                    [
                        'label_key' => 'pricing.comparison.rows.catalog_items',
                        'values' => [
                            'solo_essential' => '50',
                            'solo_pro' => '150',
                            'solo_growth' => '1000',
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
                        'free' => '20',
                        'starter' => 'unlimited',
                        'growth' => 'unlimited',
                        'scale' => 'unlimited',
                        'enterprise' => 'unlimited',
                    ],
                ],
                [
                    'label_key' => 'pricing.comparison.rows.estimates',
                    'values' => [
                        'free' => '20',
                        'starter' => 'unlimited',
                        'growth' => 'unlimited',
                        'scale' => 'unlimited',
                        'enterprise' => 'unlimited',
                    ],
                ],
                [
                    'label_key' => 'pricing.comparison.rows.invoices',
                    'values' => [
                        'free' => '20',
                        'starter' => 'unlimited',
                        'growth' => 'unlimited',
                        'scale' => 'unlimited',
                        'enterprise' => 'unlimited',
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
