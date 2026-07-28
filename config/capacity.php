<?php

return [
    'baseline' => [
        'run_id' => env('CAPACITY_BASELINE_RUN_ID'),
        'environment' => env('CAPACITY_BASELINE_ENVIRONMENT', env('APP_ENV')),
        'commit' => env('CAPACITY_BASELINE_COMMIT'),
        'started_at' => env('CAPACITY_BASELINE_STARTED_AT'),
        'ended_at' => env('CAPACITY_BASELINE_ENDED_AT'),
        'traffic' => env('CAPACITY_BASELINE_TRAFFIC'),
        'runner' => env('CAPACITY_BASELINE_RUNNER'),
        'runner_hash' => env('CAPACITY_BASELINE_RUNNER_HASH'),
        'exclusions' => env('CAPACITY_BASELINE_EXCLUSIONS'),
        'mode' => env('CAPACITY_BASELINE_MODE'),
        'representative' => env('CAPACITY_BASELINE_REPRESENTATIVE', false),
        'approved' => env('CAPACITY_BASELINE_APPROVED', false),
        'approval_reference' => env('CAPACITY_BASELINE_APPROVAL_REFERENCE'),
        'queue_canaries_verified' => env('CAPACITY_BASELINE_QUEUE_CANARIES_VERIFIED', false),
        'isolated_tenant_verified' => env('CAPACITY_BASELINE_ISOLATED_TENANT_VERIFIED', false),
        'owner' => env('CAPACITY_BASELINE_OWNER'),
        'validator' => env('CAPACITY_BASELINE_VALIDATOR'),
    ],

    'protocol' => [
        'transport' => 'http',
        'request_format' => 'json',
        'headers' => [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ],
        'follow_redirects' => false,
        'runner_policy' => 'external_approved_harness',
    ],

    'runner_results' => [
        'limit' => (int) env('CAPACITY_RUNNER_RESULT_LIMIT', 25),
        'retention_hours' => (int) env('CAPACITY_RUNNER_RESULT_RETENTION_HOURS', 24),
    ],

    'scenario_start_buffer_seconds' => (int) env('CAPACITY_SCENARIO_START_BUFFER_SECONDS', 60),

    'allowed_staging_environments' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CAPACITY_ALLOWED_STAGING_ENVIRONMENTS', 'staging'))
    ))),

    'scenarios' => [
        'dashboard_usage' => [
            'label' => 'Dashboard usage',
            'route_name' => 'dashboard',
            'method' => 'GET',
            'accepted_status_codes' => [200],
            'protocol' => [
                'authentication' => 'authenticated_session',
                'csrf' => false,
                'fixture_reference' => 'external:dashboard-owner',
                'outcome' => ['strategy' => 'status_code'],
            ],
            'profile' => [
                'virtual_users' => 25,
                'duration' => '10m',
                'ramp_up' => '2m',
                'minimum_completed_requests' => (int) env('CAPACITY_DASHBOARD_MIN_COMPLETED_REQUESTS', 250),
            ],
            'safety' => [
                'mode' => 'read_only',
                'requires_isolated_tenant' => false,
                'external_effects' => [],
            ],
            'blocker' => [
                'reason' => env('CAPACITY_DASHBOARD_BLOCKED_REASON'),
                'owner' => env('CAPACITY_DASHBOARD_BLOCKED_OWNER'),
                'review_at' => env('CAPACITY_DASHBOARD_BLOCKED_REVIEW_AT'),
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
            'accepted_status_codes' => [200],
            'protocol' => [
                'authentication' => 'authenticated_session',
                'csrf' => false,
                'fixture_reference' => 'external:customer-detail',
                'outcome' => ['strategy' => 'status_code'],
            ],
            'profile' => [
                'virtual_users' => 20,
                'duration' => '10m',
                'ramp_up' => '2m',
                'minimum_completed_requests' => (int) env('CAPACITY_CUSTOMER_SHOW_MIN_COMPLETED_REQUESTS', 200),
            ],
            'safety' => [
                'mode' => 'read_only',
                'requires_isolated_tenant' => false,
                'external_effects' => [],
            ],
            'blocker' => [
                'reason' => env('CAPACITY_CUSTOMER_SHOW_BLOCKED_REASON'),
                'owner' => env('CAPACITY_CUSTOMER_SHOW_BLOCKED_OWNER'),
                'review_at' => env('CAPACITY_CUSTOMER_SHOW_BLOCKED_REVIEW_AT'),
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
            'accepted_status_codes' => [201],
            'protocol' => [
                'authentication' => 'authenticated_session',
                'csrf' => true,
                'fixture_reference' => 'external:reservation-write',
                'outcome' => ['strategy' => 'json_key_present', 'field' => 'reservation'],
            ],
            'profile' => [
                'virtual_users' => 15,
                'duration' => '10m',
                'ramp_up' => '2m',
                'minimum_completed_requests' => (int) env('CAPACITY_RESERVATION_STORE_MIN_COMPLETED_REQUESTS', 150),
            ],
            'safety' => [
                'mode' => 'controlled_write',
                'requires_isolated_tenant' => true,
                'external_effects' => ['notifications', 'calendar synchronization'],
            ],
            'blocker' => [
                'reason' => env('CAPACITY_RESERVATION_STORE_BLOCKED_REASON'),
                'owner' => env('CAPACITY_RESERVATION_STORE_BLOCKED_OWNER'),
                'review_at' => env('CAPACITY_RESERVATION_STORE_BLOCKED_REVIEW_AT'),
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
            'accepted_status_codes' => [200],
            'protocol' => [
                'authentication' => 'authenticated_session',
                'csrf' => true,
                'fixture_reference' => 'external:sale-write',
                'outcome' => ['strategy' => 'json_key_present', 'field' => 'sale'],
            ],
            'profile' => [
                'virtual_users' => 15,
                'duration' => '10m',
                'ramp_up' => '2m',
                'minimum_completed_requests' => (int) env('CAPACITY_SALES_STORE_MIN_COMPLETED_REQUESTS', 150),
            ],
            'safety' => [
                'mode' => 'controlled_write',
                'requires_isolated_tenant' => true,
                'external_effects' => ['inventory writes', 'payment setup', 'notifications'],
            ],
            'blocker' => [
                'reason' => env('CAPACITY_SALES_STORE_BLOCKED_REASON'),
                'owner' => env('CAPACITY_SALES_STORE_BLOCKED_OWNER'),
                'review_at' => env('CAPACITY_SALES_STORE_BLOCKED_REVIEW_AT'),
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
            'accepted_status_codes' => [201],
            'protocol' => [
                'authentication' => 'public_csrf_session',
                'csrf' => true,
                'fixture_reference' => 'external:public-request-write',
                'outcome' => ['strategy' => 'json_field_equals', 'field' => 'tone', 'value' => 'success'],
            ],
            'profile' => [
                'virtual_users' => 20,
                'duration' => '10m',
                'ramp_up' => '2m',
                'minimum_completed_requests' => (int) env('CAPACITY_PUBLIC_REQUEST_MIN_COMPLETED_REQUESTS', 200),
            ],
            'safety' => [
                'mode' => 'controlled_write',
                'requires_isolated_tenant' => true,
                'external_effects' => ['notifications', 'lead creation'],
            ],
            'blocker' => [
                'reason' => env('CAPACITY_PUBLIC_REQUEST_BLOCKED_REASON'),
                'owner' => env('CAPACITY_PUBLIC_REQUEST_BLOCKED_OWNER'),
                'review_at' => env('CAPACITY_PUBLIC_REQUEST_BLOCKED_REVIEW_AT'),
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
            'accepted_status_codes' => [200],
            'protocol' => [
                'authentication' => 'public',
                'csrf' => false,
                'fixture_reference' => 'external:public-store-browse',
                'outcome' => ['strategy' => 'status_code'],
            ],
            'profile' => [
                'virtual_users' => 20,
                'duration' => '10m',
                'ramp_up' => '2m',
                'minimum_completed_requests' => (int) env('CAPACITY_PUBLIC_STORE_SHOW_MIN_COMPLETED_REQUESTS', 200),
            ],
            'safety' => [
                'mode' => 'read_only',
                'requires_isolated_tenant' => false,
                'external_effects' => [],
            ],
            'blocker' => [
                'reason' => env('CAPACITY_PUBLIC_STORE_SHOW_BLOCKED_REASON'),
                'owner' => env('CAPACITY_PUBLIC_STORE_SHOW_BLOCKED_OWNER'),
                'review_at' => env('CAPACITY_PUBLIC_STORE_SHOW_BLOCKED_REVIEW_AT'),
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
            'accepted_status_codes' => [201],
            'protocol' => [
                'authentication' => 'public_cart_session',
                'csrf' => true,
                'fixture_reference' => 'external:public-store-checkout',
                'outcome' => ['strategy' => 'json_key_present', 'field' => 'redirect_url'],
            ],
            'profile' => [
                'virtual_users' => 10,
                'duration' => '10m',
                'ramp_up' => '2m',
                'minimum_completed_requests' => (int) env('CAPACITY_PUBLIC_STORE_CHECKOUT_MIN_COMPLETED_REQUESTS', 100),
            ],
            'safety' => [
                'mode' => 'controlled_write',
                'requires_isolated_tenant' => true,
                'external_effects' => ['order creation', 'payment setup', 'notifications'],
            ],
            'blocker' => [
                'reason' => env('CAPACITY_PUBLIC_STORE_CHECKOUT_BLOCKED_REASON'),
                'owner' => env('CAPACITY_PUBLIC_STORE_CHECKOUT_BLOCKED_OWNER'),
                'review_at' => env('CAPACITY_PUBLIC_STORE_CHECKOUT_BLOCKED_REVIEW_AT'),
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
            'snapshot_interval_seconds' => (int) env('CAPACITY_QUEUE_SNAPSHOT_INTERVAL_SECONDS', 60),
            'max_snapshot_gap_seconds' => (int) env('CAPACITY_QUEUE_MAX_SNAPSHOT_GAP_SECONDS', 120),
            'coverage_grace_seconds' => (int) env('CAPACITY_QUEUE_COVERAGE_GRACE_SECONDS', 30),
        ],
        'database' => [
            'max_slow_queries_24h' => (int) env('CAPACITY_DB_MAX_SLOW_QUERIES_24H', 50),
        ],
        'app' => [
            'max_errors_1h' => (int) env('CAPACITY_APP_MAX_ERRORS_1H', 2),
        ],
    ],
];
