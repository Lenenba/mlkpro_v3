<?php

return [
    'scenarios' => [
        'dashboard_usage' => [
            'label' => 'Dashboard usage',
            'route_name' => 'dashboard',
            'method' => 'GET',
            'profile' => [
                'virtual_users' => 25,
                'duration' => '10m',
                'ramp_up' => '2m',
            ],
            'targets' => [
                'min_samples' => (int) env('CAPACITY_DASHBOARD_MIN_SAMPLES', 25),
                'p95_ms' => (int) env('CAPACITY_DASHBOARD_P95_MS', 700),
                'p99_ms' => (int) env('CAPACITY_DASHBOARD_P99_MS', 1200),
                'error_count_24h' => (int) env('CAPACITY_DASHBOARD_ERRORS_24H', 0),
            ],
            'remediation' => [
                'Optimize dashboard aggregates and cache stable counters.',
            ],
        ],
        'customer_detail_access' => [
            'label' => 'Customer detail access',
            'route_name' => 'customer.show',
            'method' => 'GET',
            'profile' => [
                'virtual_users' => 20,
                'duration' => '10m',
                'ramp_up' => '2m',
            ],
            'targets' => [
                'min_samples' => (int) env('CAPACITY_CUSTOMER_SHOW_MIN_SAMPLES', 20),
                'p95_ms' => (int) env('CAPACITY_CUSTOMER_SHOW_P95_MS', 900),
                'p99_ms' => (int) env('CAPACITY_CUSTOMER_SHOW_P99_MS', 1500),
                'error_count_24h' => (int) env('CAPACITY_CUSTOMER_SHOW_ERRORS_24H', 0),
            ],
            'remediation' => [
                'Profile the customer detail read model and trim non-essential payload sections.',
            ],
        ],
        'reservation_creation' => [
            'label' => 'Reservation creation',
            'route_name' => 'client.reservations.store',
            'method' => 'POST',
            'profile' => [
                'virtual_users' => 15,
                'duration' => '10m',
                'ramp_up' => '2m',
            ],
            'targets' => [
                'min_samples' => (int) env('CAPACITY_RESERVATION_STORE_MIN_SAMPLES', 15),
                'p95_ms' => (int) env('CAPACITY_RESERVATION_STORE_P95_MS', 900),
                'p99_ms' => (int) env('CAPACITY_RESERVATION_STORE_P99_MS', 1500),
                'error_count_24h' => (int) env('CAPACITY_RESERVATION_STORE_ERRORS_24H', 0),
            ],
            'remediation' => [
                'Profile slot locking, conflict detection, and reservation side effects under contention.',
            ],
        ],
        'sales_creation' => [
            'label' => 'Sales creation',
            'route_name' => 'sales.store',
            'method' => 'POST',
            'profile' => [
                'virtual_users' => 15,
                'duration' => '10m',
                'ramp_up' => '2m',
            ],
            'targets' => [
                'min_samples' => (int) env('CAPACITY_SALES_STORE_MIN_SAMPLES', 15),
                'p95_ms' => (int) env('CAPACITY_SALES_STORE_P95_MS', 1200),
                'p99_ms' => (int) env('CAPACITY_SALES_STORE_P99_MS', 1800),
                'error_count_24h' => (int) env('CAPACITY_SALES_STORE_ERRORS_24H', 0),
            ],
            'remediation' => [
                'Inspect sale write orchestration, inventory writes, and external payment setup latency.',
            ],
        ],
        'public_request_submission' => [
            'label' => 'Public request submission',
            'route_name' => 'public.requests.store',
            'method' => 'POST',
            'profile' => [
                'virtual_users' => 20,
                'duration' => '10m',
                'ramp_up' => '2m',
            ],
            'targets' => [
                'min_samples' => (int) env('CAPACITY_PUBLIC_REQUEST_MIN_SAMPLES', 20),
                'p95_ms' => (int) env('CAPACITY_PUBLIC_REQUEST_P95_MS', 700),
                'p99_ms' => (int) env('CAPACITY_PUBLIC_REQUEST_P99_MS', 1200),
                'error_count_24h' => (int) env('CAPACITY_PUBLIC_REQUEST_ERRORS_24H', 0),
            ],
            'remediation' => [
                'Keep the public request flow lean and push non-essential follow-up work deeper into the queue.',
            ],
        ],
        'public_store_browse' => [
            'label' => 'Public store browse',
            'route_name' => 'public.store.show',
            'method' => 'GET',
            'profile' => [
                'virtual_users' => 20,
                'duration' => '10m',
                'ramp_up' => '2m',
            ],
            'targets' => [
                'min_samples' => (int) env('CAPACITY_PUBLIC_STORE_SHOW_MIN_SAMPLES', 20),
                'p95_ms' => (int) env('CAPACITY_PUBLIC_STORE_SHOW_P95_MS', 600),
                'p99_ms' => (int) env('CAPACITY_PUBLIC_STORE_SHOW_P99_MS', 1000),
                'error_count_24h' => (int) env('CAPACITY_PUBLIC_STORE_SHOW_ERRORS_24H', 0),
            ],
            'remediation' => [
                'Reduce public store payload and cache tenant storefront reads that do not change often.',
            ],
        ],
        'public_store_checkout' => [
            'label' => 'Public store checkout',
            'route_name' => 'public.store.checkout',
            'method' => 'POST',
            'profile' => [
                'virtual_users' => 10,
                'duration' => '10m',
                'ramp_up' => '2m',
            ],
            'targets' => [
                'min_samples' => (int) env('CAPACITY_PUBLIC_STORE_CHECKOUT_MIN_SAMPLES', 10),
                'p95_ms' => (int) env('CAPACITY_PUBLIC_STORE_CHECKOUT_P95_MS', 1200),
                'p99_ms' => (int) env('CAPACITY_PUBLIC_STORE_CHECKOUT_P99_MS', 1800),
                'error_count_24h' => (int) env('CAPACITY_PUBLIC_STORE_CHECKOUT_ERRORS_24H', 0),
            ],
            'remediation' => [
                'Review checkout payment setup and order write path for blocking calls or excess queries.',
            ],
        ],
    ],

    'shared' => [
        'queue' => [
            'max_pending_jobs' => (int) env('CAPACITY_QUEUE_MAX_PENDING_JOBS', 250),
            'max_oldest_job_minutes' => (int) env('CAPACITY_QUEUE_MAX_OLDEST_JOB_MINUTES', 10),
            'max_failed_jobs_24h' => (int) env('CAPACITY_QUEUE_MAX_FAILED_JOBS_24H', 5),
        ],
        'database' => [
            'max_slow_queries_24h' => (int) env('CAPACITY_DB_MAX_SLOW_QUERIES_24H', 50),
        ],
        'app' => [
            'max_errors_1h' => (int) env('CAPACITY_APP_MAX_ERRORS_1H', 2),
        ],
    ],
];
