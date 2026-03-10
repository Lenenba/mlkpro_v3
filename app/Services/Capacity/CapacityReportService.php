<?php

namespace App\Services\Capacity;

use App\Services\Observability\ObservabilityReportService;

class CapacityReportService
{
    public function __construct(
        private readonly CapacityScenarioCatalog $catalog,
        private readonly ObservabilityReportService $observability
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $observability = $this->observability->summary();
        $requestMetrics = collect($observability['requests'] ?? [])
            ->filter(fn ($route) => is_array($route) && is_string($route['route_name'] ?? null))
            ->keyBy('route_name');

        $scenarios = collect($this->catalog->all())
            ->map(fn (array $scenario) => $this->buildScenarioSummary($scenario, $requestMetrics->all()))
            ->values()
            ->all();

        $sharedChecks = $this->sharedChecks($observability);
        $remediation = $this->remediation($scenarios, $sharedChecks);

        return [
            'generated_at' => now()->toIso8601String(),
            'status' => $this->status($scenarios, $sharedChecks),
            'scenarios' => $scenarios,
            'shared_checks' => $sharedChecks,
            'observability' => [
                'queue' => $observability['queue'] ?? [],
                'slow_queries' => $observability['slow_queries'] ?? [],
                'errors' => $observability['errors'] ?? [],
            ],
            'remediation' => $remediation,
        ];
    }

    /**
     * @param  array<string, mixed>  $scenario
     * @param  array<string, array<string, mixed>>  $requestMetrics
     * @return array<string, mixed>
     */
    private function buildScenarioSummary(array $scenario, array $requestMetrics): array
    {
        $routeNames = array_values(array_filter(
            $scenario['route_names'] ?? [],
            static fn ($value) => is_string($value) && $value !== ''
        ));
        $matched = collect($routeNames)
            ->map(fn (string $routeName) => $requestMetrics[$routeName] ?? null)
            ->filter(fn ($metric) => is_array($metric))
            ->values();

        $minSamples = (int) data_get($scenario, 'targets.min_samples', 10);
        $observed = [
            'count_24h' => (int) $matched->sum(fn (array $metric) => (int) ($metric['count_24h'] ?? 0)),
            'error_count_24h' => (int) $matched->sum(fn (array $metric) => (int) ($metric['error_count_24h'] ?? 0)),
            'p95_ms' => $this->maxMetric($matched->all(), 'p95_ms'),
            'p99_ms' => $this->maxMetric($matched->all(), 'p99_ms'),
            'last_seen_at' => $matched
                ->pluck('last_seen_at')
                ->filter(fn ($value) => is_string($value) && $value !== '')
                ->sortDesc()
                ->first(),
        ];

        if ($matched->isEmpty() || $observed['count_24h'] < $minSamples) {
            return [
                'key' => $scenario['key'],
                'label' => $scenario['label'],
                'method' => $scenario['method'],
                'route_names' => $routeNames,
                'profile' => $scenario['profile'],
                'targets' => $scenario['targets'],
                'observed' => $observed,
                'status' => 'insufficient_data',
                'failures' => [
                    'Not enough captured request samples to validate this scenario.',
                ],
                'remediation' => array_values(array_unique(array_merge(
                    $scenario['remediation'],
                    [
                        sprintf(
                            'Run the %s load scenario until at least %d samples are captured.',
                            $scenario['label'],
                            $minSamples
                        ),
                    ]
                ))),
            ];
        }

        $failures = [];

        if (($observed['p95_ms'] ?? 0) > (float) data_get($scenario, 'targets.p95_ms', 0)) {
            $failures[] = sprintf(
                'p95 latency exceeded target (%s ms > %s ms).',
                $this->formatNumber($observed['p95_ms']),
                $this->formatNumber(data_get($scenario, 'targets.p95_ms'))
            );
        }

        if (($observed['p99_ms'] ?? 0) > (float) data_get($scenario, 'targets.p99_ms', 0)) {
            $failures[] = sprintf(
                'p99 latency exceeded target (%s ms > %s ms).',
                $this->formatNumber($observed['p99_ms']),
                $this->formatNumber(data_get($scenario, 'targets.p99_ms'))
            );
        }

        if (($observed['error_count_24h'] ?? 0) > (int) data_get($scenario, 'targets.error_count_24h', 0)) {
            $failures[] = sprintf(
                '5xx count exceeded target (%d > %d).',
                (int) $observed['error_count_24h'],
                (int) data_get($scenario, 'targets.error_count_24h', 0)
            );
        }

        return [
            'key' => $scenario['key'],
            'label' => $scenario['label'],
            'method' => $scenario['method'],
            'route_names' => $routeNames,
            'profile' => $scenario['profile'],
            'targets' => $scenario['targets'],
            'observed' => $observed,
            'status' => $failures === [] ? 'pass' : 'fail',
            'failures' => $failures,
            'remediation' => $failures === [] ? [] : $scenario['remediation'],
        ];
    }

    /**
     * @param  array<string, mixed>  $observability
     * @return array<int, array<string, mixed>>
     */
    private function sharedChecks(array $observability): array
    {
        $queue = is_array($observability['queue'] ?? null) ? $observability['queue'] : [];
        $queries = is_array($observability['slow_queries'] ?? null) ? $observability['slow_queries'] : [];
        $errors = is_array($observability['errors'] ?? null) ? $observability['errors'] : [];

        $shared = config('capacity.shared', []);

        return [
            $this->sharedCheck(
                'queue_pending_jobs',
                'Queue pending jobs',
                (float) ($queue['pending_jobs'] ?? 0),
                (float) data_get($shared, 'queue.max_pending_jobs', 250),
                'Scale queue workers or isolate noisy workloads before increasing frontend traffic.'
            ),
            $this->sharedCheck(
                'queue_oldest_job_minutes',
                'Queue oldest job age',
                $queue['oldest_job_minutes'] !== null ? (float) $queue['oldest_job_minutes'] : null,
                (float) data_get($shared, 'queue.max_oldest_job_minutes', 10),
                'Investigate queue saturation and worker concurrency before accepting more load.'
            ),
            $this->sharedCheck(
                'failed_jobs_24h',
                'Failed jobs in 24h',
                (float) ($queue['failed_jobs_24h'] ?? 0),
                (float) data_get($shared, 'queue.max_failed_jobs_24h', 5),
                'Reduce queue failures before validating higher throughput.'
            ),
            $this->sharedCheck(
                'slow_queries_24h',
                'Slow queries in 24h',
                (float) ($queries['count_24h'] ?? 0),
                (float) data_get($shared, 'database.max_slow_queries_24h', 50),
                'Profile slow queries and add indexing or caching before scaling traffic.'
            ),
            $this->sharedCheck(
                'errors_1h',
                'Application errors in 1h',
                (float) ($errors['count_1h'] ?? 0),
                (float) data_get($shared, 'app.max_errors_1h', 2),
                'Stabilize application errors before validating larger traffic envelopes.'
            ),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $scenarios
     * @param  array<int, array<string, mixed>>  $sharedChecks
     * @return array<int, string>
     */
    private function remediation(array $scenarios, array $sharedChecks): array
    {
        return collect($scenarios)
            ->filter(fn (array $scenario) => in_array($scenario['status'] ?? null, ['fail', 'insufficient_data'], true))
            ->flatMap(fn (array $scenario) => $scenario['remediation'] ?? [])
            ->merge(
                collect($sharedChecks)
                    ->filter(fn (array $check) => ($check['status'] ?? null) === 'fail')
                    ->pluck('remediation')
            )
            ->filter(fn ($item) => is_string($item) && trim($item) !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $scenarios
     * @param  array<int, array<string, mixed>>  $sharedChecks
     */
    private function status(array $scenarios, array $sharedChecks): string
    {
        $hasFailures = collect($scenarios)->contains(fn (array $scenario) => ($scenario['status'] ?? null) === 'fail')
            || collect($sharedChecks)->contains(fn (array $check) => ($check['status'] ?? null) === 'fail');

        if ($hasFailures) {
            return 'critical';
        }

        $hasInsufficientData = collect($scenarios)
            ->contains(fn (array $scenario) => ($scenario['status'] ?? null) === 'insufficient_data');

        if ($hasInsufficientData) {
            return 'warning';
        }

        return 'healthy';
    }

    /**
     * @param  array<int, array<string, mixed>>  $metrics
     */
    private function maxMetric(array $metrics, string $key): ?float
    {
        $values = array_values(array_filter(array_map(
            static fn (array $metric) => isset($metric[$key]) ? (float) $metric[$key] : null,
            $metrics
        ), static fn ($value) => $value !== null));

        if ($values === []) {
            return null;
        }

        return round(max($values), 1);
    }

    private function formatNumber(mixed $value): string
    {
        if (! is_numeric($value)) {
            return 'n/a';
        }

        return number_format((float) $value, 1, '.', '');
    }

    /**
     * @return array<string, mixed>
     */
    private function sharedCheck(
        string $key,
        string $label,
        ?float $observed,
        float $target,
        string $remediation
    ): array {
        if ($observed === null) {
            return [
                'key' => $key,
                'label' => $label,
                'status' => 'pass',
                'observed' => null,
                'target' => $target,
                'remediation' => $remediation,
            ];
        }

        return [
            'key' => $key,
            'label' => $label,
            'status' => $observed > $target ? 'fail' : 'pass',
            'observed' => round($observed, 1),
            'target' => round($target, 1),
            'remediation' => $remediation,
        ];
    }
}
