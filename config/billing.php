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
$stripePricesReady = !empty(array_filter(
    $stripePrices,
    fn($value) => is_string($value) ? trim($value) !== '' : !empty($value)
));
$stripeReady = $stripeEnabled && $stripeKeysReady && $stripePricesReady;

$pricePrefix = $providerEffective === 'stripe' ? 'STRIPE' : 'PADDLE';

return [
    'provider' => $providerRequested,
    'provider_effective' => $providerEffective,
    'provider_ready' => $providerEffective === 'stripe' ? $stripeReady : true,
    'plans' => [
        'free' => [
            'name' => 'Gratuit',
            'price_id' => null,
            'price' => env($pricePrefix . '_PRICE_FREE_AMOUNT', 0),
            'features' => [
                'Core scheduling',
                'Basic quotes and invoices',
                'Email support',
            ],
        ],
        'starter' => [
            'name' => 'Starter',
            'price_id' => env($pricePrefix . '_PRICE_STARTER'),
            'price' => env($pricePrefix . '_PRICE_STARTER_AMOUNT'),
            'features' => [
                'Clients and quotes',
                'Jobs and invoices',
                'Email support',
            ],
        ],
        'growth' => [
            'name' => 'Growth',
            'price_id' => env($pricePrefix . '_PRICE_GROWTH'),
            'price' => env($pricePrefix . '_PRICE_GROWTH_AMOUNT'),
            'features' => [
                'All Starter features',
                'Team members',
                'Advanced workflow',
            ],
        ],
        'scale' => [
            'name' => 'Scale',
            'price_id' => env($pricePrefix . '_PRICE_SCALE'),
            'price' => env($pricePrefix . '_PRICE_SCALE_AMOUNT'),
            'features' => [
                'All Growth features',
                'Priority support',
                'Custom onboarding',
            ],
        ],
    ],
];
