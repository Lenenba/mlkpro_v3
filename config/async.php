<?php

return [
    'canary' => [
        'store' => env('ASYNC_QUEUE_CANARY_STORE', env('CACHE_STORE', 'database')),
        'prefix' => env('ASYNC_QUEUE_CANARY_PREFIX', 'async-queue-canary'),
        'ack_ttl_seconds' => (int) env('ASYNC_QUEUE_CANARY_TTL_SECONDS', 600),
        'timeout_seconds' => (int) env('ASYNC_QUEUE_CANARY_TIMEOUT_SECONDS', 60),
        'release' => env('ASYNC_QUEUE_CANARY_RELEASE'),
        'commit' => env('ASYNC_QUEUE_CANARY_COMMIT'),
        'max_timeout_seconds' => 600,
    ],

    'workloads' => [
        'notifications' => [
            'queue' => env('ASYNC_QUEUE_NOTIFICATIONS', 'notifications'),
            'backoff' => [60, 300, 900],
        ],
        'leads' => [
            'queue' => env('ASYNC_QUEUE_LEADS', 'leads'),
            'backoff' => [300, 900, 1800],
        ],
        'works' => [
            'queue' => env('ASYNC_QUEUE_WORKS', 'works'),
            'backoff' => [60, 300, 900],
        ],
        'demos' => [
            'queue' => env('ASYNC_QUEUE_DEMOS', 'demo-provisioning'),
            'backoff' => [60, 300, 900],
            'timeout' => 900,
        ],
        'plan_scans' => [
            'queue' => env('ASYNC_QUEUE_PLAN_SCANS', 'plan-scans'),
            'run_inline' => env('ASYNC_PLAN_SCANS_INLINE', env('APP_ENV', 'production') === 'local'),
            'backoff' => [60],
            'timeout' => 240,
        ],
        'campaigns_dispatch' => [
            'queue' => env('ASYNC_QUEUE_CAMPAIGNS_DISPATCH', env('CAMPAIGNS_QUEUE_DISPATCH', 'campaigns-dispatch')),
            'backoff' => [60, 300, 900],
        ],
        'campaigns_send' => [
            'queue' => env('ASYNC_QUEUE_CAMPAIGNS_SEND', env('CAMPAIGNS_QUEUE_SEND', 'campaigns-send')),
            'backoff' => [30, 120, 300, 600],
        ],
        'campaigns_maintenance' => [
            'queue' => env('ASYNC_QUEUE_CAMPAIGNS_MAINTENANCE', env('CAMPAIGNS_QUEUE_MAINTENANCE', 'campaigns-maintenance')),
            'backoff' => [120, 300, 900],
        ],
        'social_automation' => [
            'queue' => env('ASYNC_QUEUE_SOCIAL_AUTOMATION', 'social-automation'),
            'backoff' => [60, 300, 900],
        ],
        'social_publish' => [
            'queue' => env('ASYNC_QUEUE_SOCIAL_PUBLISH', 'social-publish'),
            'backoff' => [30, 120, 300],
            'timeout' => 60,
        ],
    ],

    'workers' => [
        'development' => [
            'environment' => 'development',
            'include_default' => true,
            'tries' => 3,
            'workloads' => [
                'notifications',
                'leads',
                'works',
                'demos',
                'plan_scans',
                'campaigns_send',
                'campaigns_dispatch',
                'campaigns_maintenance',
                'social_publish',
                'social_automation',
            ],
        ],
        'operations' => [
            'environment' => 'production',
            'include_default' => true,
            'tries' => 3,
            'workloads' => [
                'notifications',
                'leads',
                'works',
                'demos',
            ],
        ],
        'plan-scans' => [
            'environment' => 'production',
            'workloads' => ['plan_scans'],
        ],
        'campaigns' => [
            'environment' => 'production',
            'workloads' => [
                'campaigns_send',
                'campaigns_dispatch',
                'campaigns_maintenance',
            ],
        ],
        'social' => [
            'environment' => 'production',
            'workloads' => [
                'social_publish',
                'social_automation',
            ],
        ],
    ],
];
