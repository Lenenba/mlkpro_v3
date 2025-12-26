<?php

return [
    'plans' => [
        'free' => [
            'name' => 'Gratuit',
            'price_id' => env('PADDLE_PRICE_FREE'),
            'price' => env('PADDLE_PRICE_FREE_AMOUNT', 0),
            'features' => [
                'Core scheduling',
                'Basic quotes and invoices',
                'Email support',
            ],
        ],
        'starter' => [
            'name' => 'Starter',
            'price_id' => env('PADDLE_PRICE_STARTER'),
            'price' => env('PADDLE_PRICE_STARTER_AMOUNT'),
            'features' => [
                'Clients and quotes',
                'Jobs and invoices',
                'Email support',
            ],
        ],
        'growth' => [
            'name' => 'Growth',
            'price_id' => env('PADDLE_PRICE_GROWTH'),
            'price' => env('PADDLE_PRICE_GROWTH_AMOUNT'),
            'features' => [
                'All Starter features',
                'Team members',
                'Advanced workflow',
            ],
        ],
        'scale' => [
            'name' => 'Scale',
            'price_id' => env('PADDLE_PRICE_SCALE'),
            'price' => env('PADDLE_PRICE_SCALE_AMOUNT'),
            'features' => [
                'All Growth features',
                'Priority support',
                'Custom onboarding',
            ],
        ],
    ],
];
