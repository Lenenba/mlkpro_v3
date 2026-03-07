<?php

return [
    'enabled' => env('OBSERVABILITY_ENABLED', true),

    'log_channel' => env('OBSERVABILITY_LOG_CHANNEL', 'observability'),

    'request' => [
        'tracked_routes' => [
            'dashboard',
            'customer.show',
            'customer.options',
            'client.reservations.store',
            'sales.store',
            'sales.update',
            'work.index',
            'task.index',
            'client.reservations.slots',
            'portal.orders.show',
            'public.requests.suggest',
            'public.requests.store',
            'public.store.show',
            'public.store.checkout',
        ],
        'slow_ms' => (int) env('OBSERVABILITY_SLOW_REQUEST_MS', 1200),
        'sample_size' => (int) env('OBSERVABILITY_REQUEST_SAMPLE_SIZE', 250),
        'retention_hours' => (int) env('OBSERVABILITY_REQUEST_RETENTION_HOURS', 24),
    ],

    'query' => [
        'slow_ms' => (int) env('OBSERVABILITY_SLOW_QUERY_MS', 400),
        'sample_size' => (int) env('OBSERVABILITY_QUERY_SAMPLE_SIZE', 150),
        'retention_hours' => (int) env('OBSERVABILITY_QUERY_RETENTION_HOURS', 24),
    ],

    'error' => [
        'sample_size' => (int) env('OBSERVABILITY_ERROR_SAMPLE_SIZE', 150),
        'retention_hours' => (int) env('OBSERVABILITY_ERROR_RETENTION_HOURS', 24),
    ],

    'alerts' => [
        'queue_pending_jobs' => (int) env('OBSERVABILITY_ALERT_QUEUE_PENDING_JOBS', 100),
        'queue_oldest_job_minutes' => (int) env('OBSERVABILITY_ALERT_QUEUE_OLDEST_JOB_MINUTES', 15),
        'failed_jobs_24h' => (int) env('OBSERVABILITY_ALERT_FAILED_JOBS_24H', 10),
        'slow_queries_24h' => (int) env('OBSERVABILITY_ALERT_SLOW_QUERIES_24H', 20),
        'errors_1h' => (int) env('OBSERVABILITY_ALERT_ERRORS_1H', 5),
        'request_p95_ms' => (int) env('OBSERVABILITY_ALERT_REQUEST_P95_MS', 1500),
        'request_p99_ms' => (int) env('OBSERVABILITY_ALERT_REQUEST_P99_MS', 2500),
    ],
];
