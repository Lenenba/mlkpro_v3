<?php

namespace App\Services\Observability;

use App\Services\QueueHealthService;
use App\Services\Social\SocialDeliveryHealthService;

class ObservabilityReportService
{
    public function __construct(
        private readonly QueueHealthService $queueHealth,
        private readonly RequestMetricsService $requestMetrics,
        private readonly SlowQueryService $slowQueries,
        private readonly ErrorMetricsService $errors,
        private readonly ObservabilityCacheStore $cache,
        private readonly TelemetryQueryGuard $queryGuard,
        private readonly SocialDeliveryHealthService $pulseDeliveryHealth,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(?array $scope = null): array
    {
        $metrics = $this->queryGuard->run(fn (): array => [
            'queue' => $scope === null
                ? $this->queueHealth->summary(record: true)
                : $this->queueHealth->summaryForScope($scope),
            'requests' => $this->requestMetrics->summary($scope),
            'queries' => $this->slowQueries->summary($scope),
            'errors' => $this->errors->summary($scope),
            'pulse_delivery' => $scope === null
                ? $this->pulseDeliveryHealth->summary()
                : ['scope_excluded' => true],
        ]);
        $queue = $metrics['queue'];
        $requests = $metrics['requests'];
        $queries = $metrics['queries'];
        $errors = $metrics['errors'];
        $pulseDelivery = $metrics['pulse_delivery'];
        $dataQuality = $this->dataQuality($queue, $requests, $queries, $errors);
        $alerts = $this->alerts($queue, $requests, $queries, $errors, $pulseDelivery);
        $windowStartedAt = is_string($scope['started_at'] ?? null)
            ? $scope['started_at']
            : now()->subHours(24)->toIso8601String();
        $windowEndedAt = is_string($scope['ended_at'] ?? null)
            ? $scope['ended_at']
            : now()->toIso8601String();

        return [
            'generated_at' => now()->toIso8601String(),
            'environment' => (string) config('app.env'),
            'release' => config('observability.release'),
            'window' => [
                'started_at' => $windowStartedAt,
                'ended_at' => $windowEndedAt,
                'hours' => $scope === null ? 24 : null,
                'counter_precision_minutes' => max(
                    1,
                    (int) config('observability.cache.counter_bucket_minutes', 5)
                ),
            ],
            'status' => $this->statusFromAlerts($alerts, $dataQuality),
            'data_quality' => $dataQuality,
            'queue' => $queue,
            'requests' => $requests,
            'slow_queries' => $queries,
            'errors' => $errors,
            'pulse_delivery' => $pulseDelivery,
            'alerts' => $alerts,
        ];
    }

    /**
     * @param  array<string, mixed>  $queue
     * @param  array<int, array<string, mixed>>  $requests
     * @param  array<string, mixed>  $queries
     * @param  array<string, mixed>  $errors
     * @param  array<string, mixed>  $pulseDelivery
     * @return array<int, array<string, mixed>>
     */
    private function alerts(
        array $queue,
        array $requests,
        array $queries,
        array $errors,
        array $pulseDelivery,
    ): array {
        $alerts = [];
        $thresholds = config('observability.alerts', []);

        if (! ($queue['backlog_measurable'] ?? false)) {
            $alerts[] = [
                'code' => 'queue_backlog_unmeasurable',
                'severity' => 'warning',
                'title' => 'Queue backlog is not measurable',
                'message' => 'The active queue driver did not provide a reliable backlog measurement.',
                'details' => [
                    ['label' => 'Driver', 'value' => (string) ($queue['driver'] ?? 'unknown')],
                ],
            ];
        }

        if (($queue['backlog_measurable'] ?? false)
            && is_numeric($queue['pending_jobs'] ?? null)
            && (float) $queue['pending_jobs'] > (int) ($thresholds['queue_pending_jobs'] ?? PHP_INT_MAX)) {
            $alerts[] = [
                'code' => 'queue_pending_jobs',
                'severity' => 'warning',
                'title' => 'Queue backlog high',
                'message' => 'Pending jobs exceeded the configured threshold.',
                'details' => [
                    ['label' => 'Pending jobs', 'value' => (int) ($queue['pending_jobs'] ?? 0)],
                    ['label' => 'Threshold', 'value' => (int) ($thresholds['queue_pending_jobs'] ?? 0)],
                ],
            ];
        }

        if (! ($queue['oldest_job_measurable'] ?? false)) {
            $alerts[] = [
                'code' => 'queue_oldest_job_unmeasurable',
                'severity' => 'warning',
                'title' => 'Queue latency is not measurable',
                'message' => 'The active queue driver did not provide the age of its oldest ready job.',
                'details' => [
                    ['label' => 'Driver', 'value' => (string) ($queue['driver'] ?? 'unknown')],
                ],
            ];
        }

        if (($queue['oldest_job_measurable'] ?? false)
            && ($queue['oldest_job_minutes'] ?? null) !== null
            && (float) ($queue['oldest_job_minutes'] ?? 0) > (float) ($thresholds['queue_oldest_job_minutes'] ?? PHP_INT_MAX)) {
            $alerts[] = [
                'code' => 'queue_oldest_job_minutes',
                'severity' => 'warning',
                'title' => 'Queue latency high',
                'message' => 'The oldest queued job is older than the configured threshold.',
                'details' => [
                    ['label' => 'Oldest job (minutes)', 'value' => (float) ($queue['oldest_job_minutes'] ?? 0)],
                    ['label' => 'Threshold', 'value' => (int) ($thresholds['queue_oldest_job_minutes'] ?? 0)],
                ],
            ];
        }

        if (! ($queue['failed_jobs_measurable'] ?? false)) {
            $alerts[] = [
                'code' => 'failed_jobs_unmeasurable',
                'severity' => 'warning',
                'title' => 'Failed jobs are not measurable',
                'message' => 'The configured failed-job backend cannot be measured by the application report.',
                'details' => [
                    ['label' => 'Driver', 'value' => (string) config('queue.failed.driver', 'unknown')],
                ],
            ];
        }

        if (($queue['failed_jobs_measurable'] ?? false)
            && is_numeric($queue['failed_jobs_24h'] ?? null)
            && (float) $queue['failed_jobs_24h'] > (int) ($thresholds['failed_jobs_24h'] ?? PHP_INT_MAX)) {
            $alerts[] = [
                'code' => 'failed_jobs_24h',
                'severity' => 'critical',
                'title' => 'Failed jobs spike',
                'message' => 'Failed jobs in the last 24 hours exceeded the configured threshold.',
                'details' => [
                    ['label' => 'Failed jobs (24h)', 'value' => (int) ($queue['failed_jobs_24h'] ?? 0)],
                    ['label' => 'Threshold', 'value' => (int) ($thresholds['failed_jobs_24h'] ?? 0)],
                ],
            ];
        }

        if (($queries['count_24h'] ?? 0) > (int) ($thresholds['slow_queries_24h'] ?? PHP_INT_MAX)) {
            $alerts[] = [
                'code' => 'slow_queries_24h',
                'severity' => 'warning',
                'title' => 'Slow query volume high',
                'message' => 'Slow query count exceeded the configured threshold.',
                'details' => [
                    ['label' => 'Slow queries (24h)', 'value' => (int) ($queries['count_24h'] ?? 0)],
                    ['label' => 'Threshold', 'value' => (int) ($thresholds['slow_queries_24h'] ?? 0)],
                    ['label' => 'Worst query (ms)', 'value' => $queries['max_ms'] ?? 'n/a'],
                ],
            ];
        }

        if (($errors['count_1h'] ?? 0) > (int) ($thresholds['errors_1h'] ?? PHP_INT_MAX)) {
            $alerts[] = [
                'code' => 'errors_1h',
                'severity' => 'critical',
                'title' => 'Application errors elevated',
                'message' => '5xx errors in the last hour exceeded the configured threshold.',
                'details' => [
                    ['label' => 'Errors (1h)', 'value' => (int) ($errors['count_1h'] ?? 0)],
                    ['label' => 'Threshold', 'value' => (int) ($thresholds['errors_1h'] ?? 0)],
                ],
            ];
        }

        if (! ($pulseDelivery['scope_excluded'] ?? false)) {
            $unknownCount = (int) data_get($pulseDelivery, 'active_status_counts.unknown', 0);
            $deadCount = (int) data_get($pulseDelivery, 'active_status_counts.dead', 0);
            $expiredClaims = (int) ($pulseDelivery['expired_claims'] ?? 0);
            $reconciliationExpiredClaims = (int) data_get(
                $pulseDelivery,
                'reconciliation.expired_claims',
                0,
            );
            $reconciliationOperatorReview = (int) data_get(
                $pulseDelivery,
                'reconciliation.operator_review',
                0,
            );
            $oldestActionableMinutes = is_numeric($pulseDelivery['oldest_actionable_age_seconds'] ?? null)
                ? (float) $pulseDelivery['oldest_actionable_age_seconds'] / 60
                : null;

            if ($unknownCount > (int) ($thresholds['pulse_delivery_unknown'] ?? PHP_INT_MAX)) {
                $alerts[] = [
                    'code' => 'pulse_delivery_unknown',
                    'severity' => 'critical',
                    'title' => 'Pulse delivery requires manual reconciliation',
                    'message' => 'At least one delivery has an ambiguous remote outcome and must not be retried automatically.',
                    'details' => [
                        ['label' => 'Unknown deliveries', 'value' => $unknownCount],
                    ],
                ];
            }

            if ($deadCount > (int) ($thresholds['pulse_delivery_dead'] ?? PHP_INT_MAX)) {
                $alerts[] = [
                    'code' => 'pulse_delivery_dead',
                    'severity' => 'warning',
                    'title' => 'Pulse delivery failures require attention',
                    'message' => 'Terminal delivery failures exceeded the configured threshold.',
                    'details' => [
                        ['label' => 'Dead deliveries', 'value' => $deadCount],
                    ],
                ];
            }

            if ($expiredClaims > (int) ($thresholds['pulse_delivery_expired_claims'] ?? PHP_INT_MAX)) {
                $alerts[] = [
                    'code' => 'pulse_delivery_expired_claims',
                    'severity' => 'warning',
                    'title' => 'Pulse delivery claims expired',
                    'message' => 'One or more delivery leases are waiting for the durable sweeper.',
                    'details' => [
                        ['label' => 'Expired claims', 'value' => $expiredClaims],
                    ],
                ];
            }

            if ($oldestActionableMinutes !== null
                && $oldestActionableMinutes > (float) ($thresholds['pulse_delivery_oldest_actionable_minutes'] ?? PHP_INT_MAX)) {
                $alerts[] = [
                    'code' => 'pulse_delivery_oldest_actionable',
                    'severity' => 'warning',
                    'title' => 'Pulse delivery outbox is delayed',
                    'message' => 'The oldest actionable Pulse delivery exceeded the configured age threshold.',
                    'details' => [
                        ['label' => 'Oldest actionable (minutes)', 'value' => round($oldestActionableMinutes, 2)],
                    ],
                ];
            }

            if ($reconciliationExpiredClaims > (int) ($thresholds['pulse_reconciliation_expired_claims'] ?? PHP_INT_MAX)) {
                $alerts[] = [
                    'code' => 'pulse_reconciliation_expired_claims',
                    'severity' => 'warning',
                    'title' => 'Pulse reconciliation claims expired',
                    'message' => 'One or more status-read leases require recovery before another remote read.',
                    'details' => [
                        ['label' => 'Expired reconciliation claims', 'value' => $reconciliationExpiredClaims],
                    ],
                ];
            }

            if ($reconciliationOperatorReview > (int) ($thresholds['pulse_reconciliation_operator_review'] ?? PHP_INT_MAX)) {
                $alerts[] = [
                    'code' => 'pulse_reconciliation_operator_review',
                    'severity' => 'critical',
                    'title' => 'Pulse reconciliation requires operator review',
                    'message' => 'Automatic status reads stopped without a terminal delivery result; no new creation is allowed.',
                    'details' => [
                        ['label' => 'Deliveries requiring review', 'value' => $reconciliationOperatorReview],
                    ],
                ];
            }
        }

        foreach ($requests as $route) {
            $minimumSamples = $this->minimumSamplesForRoute((string) ($route['route_name'] ?? ''));
            if ((int) ($route['sample_count_24h'] ?? 0) < $minimumSamples) {
                continue;
            }

            if (($route['p95_ms'] ?? 0) >= (float) ($thresholds['request_p95_ms'] ?? PHP_INT_MAX)) {
                $alerts[] = [
                    'code' => 'request_p95:'.$route['route_name'],
                    'severity' => 'warning',
                    'title' => 'Route latency p95 high',
                    'message' => 'A tracked route exceeded the p95 latency threshold.',
                    'details' => [
                        ['label' => 'Route', 'value' => $route['route_name']],
                        ['label' => 'p95 (ms)', 'value' => $route['p95_ms']],
                        ['label' => 'Threshold', 'value' => (int) ($thresholds['request_p95_ms'] ?? 0)],
                        ['label' => 'Samples', 'value' => (int) ($route['sample_count_24h'] ?? 0)],
                    ],
                ];
            }

            if (($route['p99_ms'] ?? 0) >= (float) ($thresholds['request_p99_ms'] ?? PHP_INT_MAX)) {
                $alerts[] = [
                    'code' => 'request_p99:'.$route['route_name'],
                    'severity' => 'critical',
                    'title' => 'Route latency p99 high',
                    'message' => 'A tracked route exceeded the p99 latency threshold.',
                    'details' => [
                        ['label' => 'Route', 'value' => $route['route_name']],
                        ['label' => 'p99 (ms)', 'value' => $route['p99_ms']],
                        ['label' => 'Threshold', 'value' => (int) ($thresholds['request_p99_ms'] ?? 0)],
                        ['label' => 'Samples', 'value' => (int) ($route['sample_count_24h'] ?? 0)],
                    ],
                ];
            }
        }

        return $alerts;
    }

    /**
     * @param  array<string, mixed>  $queue
     * @param  array<int, array<string, mixed>>  $requests
     * @param  array<string, mixed>  $queries
     * @param  array<string, mixed>  $errors
     * @return array<string, mixed>
     */
    private function dataQuality(array $queue, array $requests, array $queries, array $errors): array
    {
        $storage = $this->cache->health();
        $issues = [];

        if (! config('observability.enabled', false)) {
            $issues[] = 'observability_disabled';
        }
        if (! ($storage['shared'] ?? false)) {
            $issues[] = 'cache_store_not_shared';
        }
        if (! ($storage['low_overhead'] ?? false)) {
            $issues[] = 'cache_store_not_low_overhead';
        }
        if ((int) ($storage['dropped_writes'] ?? 0) > 0) {
            $issues[] = 'telemetry_writes_dropped';
        }
        if ((int) ($storage['read_failures'] ?? 0) > 0) {
            $issues[] = 'telemetry_reads_failed';
        }
        if (config('observability.release') === null || trim((string) config('observability.release')) === '') {
            $issues[] = 'release_missing';
        }
        if (! ($queue['backlog_measurable'] ?? false)) {
            $issues[] = 'queue_backlog_unmeasurable';
        }
        if (! ($queue['oldest_job_measurable'] ?? false)) {
            $issues[] = 'queue_oldest_job_unmeasurable';
        }
        if (! ($queue['failed_jobs_measurable'] ?? false)) {
            $issues[] = 'failed_jobs_unmeasurable';
        }
        if (array_key_exists('snapshot_count', $queue) && (int) $queue['snapshot_count'] === 0) {
            $issues[] = 'queue_scope_samples_missing';
        }
        if (($queue['truncated'] ?? false) === true) {
            $issues[] = 'queue_scope_samples_truncated';
        }
        if ($requests === []) {
            $issues[] = 'request_samples_missing';
        }
        if (collect($requests)->contains(fn (array $route): bool => (bool) ($route['truncated'] ?? false))) {
            $issues[] = 'request_samples_truncated';
        }
        if (($queries['truncated'] ?? false) === true) {
            $issues[] = 'slow_query_samples_truncated';
        }
        if (($errors['truncated'] ?? false) === true) {
            $issues[] = 'error_samples_truncated';
        }

        $status = 'ready';
        if (! config('observability.enabled', false) || (int) ($storage['read_failures'] ?? 0) > 0) {
            $status = 'unavailable';
        } elseif ($issues !== []) {
            $status = $requests === [] ? 'collecting' : 'degraded';
        }

        return [
            'status' => $status,
            'issues' => $issues,
            'storage' => $storage,
        ];
    }

    private function minimumSamplesForRoute(string $routeName): int
    {
        $scenarios = config('capacity.scenarios', []);

        foreach (is_array($scenarios) ? $scenarios : [] as $scenario) {
            if (! is_array($scenario)) {
                continue;
            }

            $routeNames = $scenario['route_names'] ?? [$scenario['route_name'] ?? null];
            $routeNames = is_array($routeNames) ? $routeNames : [$routeNames];
            if (in_array($routeName, $routeNames, true)) {
                return max(1, (int) data_get($scenario, 'targets.min_samples', 1));
            }
        }

        return max(1, (int) config('observability.request.min_alert_samples', 10));
    }

    /**
     * @param  array<int, array<string, mixed>>  $alerts
     * @param  array<string, mixed>  $dataQuality
     */
    private function statusFromAlerts(array $alerts, array $dataQuality): string
    {
        if (collect($alerts)->contains(fn (array $alert) => ($alert['severity'] ?? null) === 'critical')) {
            return 'critical';
        }

        if ($alerts !== []) {
            return 'warning';
        }

        if (($dataQuality['status'] ?? null) !== 'ready') {
            return 'unknown';
        }

        return 'healthy';
    }
}
