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
            'features' => [
                'Jusqu a 3 employes',
                'Clients, devis et factures de base',
                'Jobs, taches et planning simple',
                'Catalogue produits et services',
                'Portail client (acceptation + paiement)',
                'Support email',
            ],
        ],
        'starter' => [
            'name' => 'Starter',
            'price_id' => env($pricePrefix.'_PRICE_STARTER'),
            'price' => env($pricePrefix.'_PRICE_STARTER_AMOUNT'),
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
];
