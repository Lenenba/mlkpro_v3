<?php

return [
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
        'plan_scans' => [
            'queue' => env('ASYNC_QUEUE_PLAN_SCANS', 'default'),
            'run_inline' => env('ASYNC_PLAN_SCANS_INLINE', env('APP_ENV', 'production') === 'local'),
            'backoff' => [60, 300, 900],
        ],
        'campaigns_dispatch' => [
            'queue' => env('ASYNC_QUEUE_CAMPAIGNS_DISPATCH', 'campaigns-dispatch'),
            'backoff' => [60, 300, 900],
        ],
        'campaigns_send' => [
            'queue' => env('ASYNC_QUEUE_CAMPAIGNS_SEND', 'campaigns-send'),
            'backoff' => [30, 120, 300, 600],
        ],
        'campaigns_maintenance' => [
            'queue' => env('ASYNC_QUEUE_CAMPAIGNS_MAINTENANCE', 'campaigns-maintenance'),
            'backoff' => [120, 300, 900],
        ],
    ],
];
