<?php

namespace App\Services\Observability;

use App\Services\QueueHealthService;

class ObservabilityReportService
{
    public function __construct(
        private readonly QueueHealthService $queueHealth,
        private readonly RequestMetricsService $requestMetrics,
        private readonly SlowQueryService $slowQueries,
        private readonly ErrorMetricsService $errors
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $queue = $this->queueHealth->summary();
        $requests = $this->requestMetrics->summary();
        $queries = $this->slowQueries->summary();
        $errors = $this->errors->summary();
        $alerts = $this->alerts($queue, $requests, $queries, $errors);

        return [
            'generated_at' => now()->toIso8601String(),
            'status' => $this->statusFromAlerts($alerts),
            'queue' => $queue,
            'requests' => $requests,
            'slow_queries' => $queries,
            'errors' => $errors,
            'alerts' => $alerts,
        ];
    }

    /**
     * @param  array<string, mixed>  $queue
     * @param  array<int, array<string, mixed>>  $requests
     * @param  array<string, mixed>  $queries
     * @param  array<string, mixed>  $errors
     * @return array<int, array<string, mixed>>
     */
    private function alerts(array $queue, array $requests, array $queries, array $errors): array
    {
        $alerts = [];
        $thresholds = config('observability.alerts', []);

        if (($queue['pending_jobs'] ?? 0) >= (int) ($thresholds['queue_pending_jobs'] ?? PHP_INT_MAX)) {
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

        if (($queue['oldest_job_minutes'] ?? 0) !== null
            && (float) ($queue['oldest_job_minutes'] ?? 0) >= (float) ($thresholds['queue_oldest_job_minutes'] ?? PHP_INT_MAX)) {
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

        if (($queue['failed_jobs_24h'] ?? 0) >= (int) ($thresholds['failed_jobs_24h'] ?? PHP_INT_MAX)) {
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

        if (($queries['count_24h'] ?? 0) >= (int) ($thresholds['slow_queries_24h'] ?? PHP_INT_MAX)) {
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

        if (($errors['count_1h'] ?? 0) >= (int) ($thresholds['errors_1h'] ?? PHP_INT_MAX)) {
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

        foreach ($requests as $route) {
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
                        ['label' => 'Samples', 'value' => (int) ($route['count_24h'] ?? 0)],
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
                        ['label' => 'Samples', 'value' => (int) ($route['count_24h'] ?? 0)],
                    ],
                ];
            }
        }

        return $alerts;
    }

    /**
     * @param  array<int, array<string, mixed>>  $alerts
     */
    private function statusFromAlerts(array $alerts): string
    {
        if (collect($alerts)->contains(fn (array $alert) => ($alert['severity'] ?? null) === 'critical')) {
            return 'critical';
        }

        if ($alerts !== []) {
            return 'warning';
        }

        return 'healthy';
    }
}
