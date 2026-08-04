<?php

namespace App\Services\Capacity;

use App\Services\Observability\ObservabilityReportService;
use App\Services\Observability\TelemetryScope;
use Illuminate\Support\Carbon;

class CapacityReportService
{
    public function __construct(
        private readonly CapacityScenarioCatalog $catalog,
        private readonly ObservabilityReportService $observability,
        private readonly TelemetryScope $telemetryScope,
        private readonly CapacityRunnerResultService $runnerResults
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $baselineContext = $this->baselineContext();
        $baselineScope = $this->baselineScope($baselineContext);
        $observability = $this->observability->summary($baselineScope);
        $requestMetrics = $this->routeMetrics($observability['requests'] ?? []);
        $slowQueryMetrics = $this->routeMetrics(data_get($observability, 'slow_queries.by_route', []));
        $errorMetrics = $this->routeMetrics(data_get($observability, 'errors.by_route', []));
        $queueByScenario = is_array(data_get($observability, 'queue.by_scenario'))
            ? data_get($observability, 'queue.by_scenario')
            : [];

        $scenarios = collect($this->catalog->all())
            ->map(fn (array $scenario) => $this->buildScenarioSummary(
                $scenario,
                $requestMetrics,
                $slowQueryMetrics,
                $errorMetrics,
                is_array($queueByScenario[$scenario['key']] ?? null) ? $queueByScenario[$scenario['key']] : [],
                is_string($baselineScope['scope_id'] ?? null)
                    ? $this->runnerResults->latestForCurrentScope($scenario['key'])
                    : null
            ))
            ->values()
            ->all();

        $sharedChecks = $this->sharedChecks($observability);
        $configurationIssues = $this->catalog->issues();
        $remediation = $this->remediation($scenarios, $sharedChecks, $baselineContext, $configurationIssues);

        return [
            'generated_at' => now()->toIso8601String(),
            'status' => $this->status(
                $scenarios,
                $sharedChecks,
                $baselineContext,
                $configurationIssues,
                is_array($observability['data_quality'] ?? null) ? $observability['data_quality'] : []
            ),
            'baseline_context' => $baselineContext,
            'configuration_issues' => $configurationIssues,
            'data_quality' => $observability['data_quality'] ?? [],
            'scenarios' => $scenarios,
            'shared_checks' => $sharedChecks,
            'observability' => [
                'queue' => $observability['queue'] ?? [],
                'slow_queries' => collect($observability['slow_queries'] ?? [])->except('recent')->all(),
                'errors' => collect($observability['errors'] ?? [])->except('recent')->all(),
            ],
            'remediation' => $remediation,
        ];
    }

    /**
     * @param  array<string, mixed>  $scenario
     * @param  array<string, array<string, mixed>>  $requestMetrics
     * @param  array<string, array<string, mixed>>  $slowQueryMetrics
     * @param  array<string, array<string, mixed>>  $errorMetrics
     * @param  array<string, mixed>  $queueMetrics
     * @param  array<string, mixed>|null  $runnerResult
     * @return array<string, mixed>
     */
    private function buildScenarioSummary(
        array $scenario,
        array $requestMetrics,
        array $slowQueryMetrics,
        array $errorMetrics,
        array $queueMetrics,
        ?array $runnerResult
    ): array {
        $scenarioKey = (string) ($scenario['key'] ?? 'unknown');
        $routeNames = array_values(array_filter(
            $scenario['route_names'] ?? [],
            static fn ($value) => is_string($value) && $value !== ''
        ));
        $matched = collect($routeNames)
            ->map(function (string $routeName) use ($requestMetrics, $scenarioKey): ?array {
                $routeMetric = $requestMetrics[$routeName] ?? null;
                if (! is_array($routeMetric)) {
                    return null;
                }

                $scenarioMetric = is_array($routeMetric['by_scenario'] ?? null)
                    ? ($routeMetric['by_scenario'][$scenarioKey] ?? null)
                    : null;

                return is_array($scenarioMetric) ? $scenarioMetric : null;
            })
            ->filter(fn ($metric): bool => is_array($metric))
            ->values();
        $matchedSlowQueries = collect($routeNames)
            ->map(function (string $routeName) use ($slowQueryMetrics, $scenarioKey): ?array {
                $routeMetric = $slowQueryMetrics[$routeName] ?? null;
                if (! is_array($routeMetric)) {
                    return null;
                }

                $scenarioMetric = is_array($routeMetric['by_scenario'] ?? null)
                    ? ($routeMetric['by_scenario'][$scenarioKey] ?? null)
                    : null;

                return is_array($scenarioMetric) ? $scenarioMetric : null;
            })
            ->filter(fn ($metric): bool => is_array($metric))
            ->values();
        $matchedErrors = collect($routeNames)
            ->map(function (string $routeName) use ($errorMetrics, $scenarioKey): ?array {
                $routeMetric = $errorMetrics[$routeName] ?? null;
                if (! is_array($routeMetric)) {
                    return null;
                }

                $scenarioMetric = is_array($routeMetric['by_scenario'] ?? null)
                    ? ($routeMetric['by_scenario'][$scenarioKey] ?? null)
                    : null;

                return is_array($scenarioMetric) ? $scenarioMetric : null;
            })
            ->filter(fn ($metric): bool => is_array($metric))
            ->values();

        $sampleCount = (int) $matched->sum(fn (array $metric) => (int) ($metric['sample_count_24h'] ?? 0));
        $requestCount = (int) $matched->sum(fn (array $metric) => (int) ($metric['count_24h'] ?? 0));
        $expectedMethod = (string) ($scenario['method'] ?? 'GET');
        $acceptedStatusCodes = is_array($scenario['accepted_status_codes'] ?? null)
            ? $scenario['accepted_status_codes']
            : [200];
        $expectedMethodSamples = (int) $matched->sum(
            fn (array $metric) => (int) data_get($metric, "methods.{$expectedMethod}", 0)
        );
        $acceptedStatusSamples = (int) $matched->sum(function (array $metric) use ($acceptedStatusCodes): int {
            return collect($acceptedStatusCodes)
                ->sum(fn (int $statusCode): int => (int) data_get($metric, "status_codes.{$statusCode}", 0));
        });
        $candidateSegments = $matched
            ->flatMap(fn (array $metric): array => is_array($metric['segments'] ?? null) ? $metric['segments'] : [])
            ->filter(fn ($segment): bool => is_array($segment)
                && ($segment['method'] ?? null) === $expectedMethod
                && in_array((int) ($segment['status_code'] ?? 0), $acceptedStatusCodes, true))
            ->values();
        $validSegments = $candidateSegments
            ->filter(fn (array $segment): bool => ($segment['business_success'] ?? null) === true)
            ->values();
        $validSampleCount = (int) $validSegments->sum(fn (array $segment): int => (int) ($segment['count'] ?? 0));
        $candidateSampleCount = (int) $candidateSegments
            ->sum(fn (array $segment): int => (int) ($segment['count'] ?? 0));
        $clientLatency = is_array($runnerResult['client_latency_ms'] ?? null)
            ? $runnerResult['client_latency_ms']
            : [];
        $runnerCompletedRequests = is_numeric($runnerResult['completed_requests'] ?? null)
            ? (int) $runnerResult['completed_requests']
            : null;
        $telemetryMatchesRunner = $runnerCompletedRequests === null
            ? null
            : $requestCount === $runnerCompletedRequests;
        $queueCoverageIssues = $this->queueCoverageIssues($queueMetrics, $runnerResult);
        $queueMetrics['coverage_ready'] = $queueCoverageIssues === [];
        $queueMetrics['coverage_issues'] = $queueCoverageIssues;

        $observed = [
            'request_count_24h' => $requestCount,
            'request_count_in_scope' => $requestCount,
            'count_24h' => $requestCount,
            'sample_count_24h' => $sampleCount,
            'sample_count_in_scope' => $sampleCount,
            'valid_sample_count_24h' => $validSampleCount,
            'valid_sample_count_in_scope' => $validSampleCount,
            'truncated' => $matched->contains(fn (array $metric): bool => (bool) ($metric['truncated'] ?? false)),
            'runner_result' => $runnerResult,
            'runner_completed_requests' => $runnerCompletedRequests,
            'telemetry_matches_runner' => $telemetryMatchesRunner,
            'client_latency_ms' => $clientLatency,
            'p50_ms' => is_numeric($clientLatency['p50'] ?? null) ? (float) $clientLatency['p50'] : null,
            'p95_ms' => is_numeric($clientLatency['p95'] ?? null) ? (float) $clientLatency['p95'] : null,
            'p99_ms' => is_numeric($clientLatency['p99'] ?? null) ? (float) $clientLatency['p99'] : null,
            'max_ms' => is_numeric($clientLatency['max'] ?? null) ? (float) $clientLatency['max'] : null,
            'unexpected_method_count' => max(0, $sampleCount - $expectedMethodSamples),
            'invalid_response_count' => max(0, $sampleCount - $acceptedStatusSamples),
            'invalid_business_outcome_count' => max(0, $candidateSampleCount - $validSampleCount),
            'error_count_24h' => (int) $matched->sum(fn (array $metric) => (int) ($metric['error_count_24h'] ?? 0)),
            'error_count_in_scope' => (int) $matched->sum(fn (array $metric) => (int) ($metric['error_count_24h'] ?? 0)),
            'app_processing_ms' => $this->distribution($validSegments->all(), 'duration_ms'),
            'response_body_bytes' => $this->distribution($validSegments->all(), 'response_body_bytes'),
            'query_count' => $this->distribution($validSegments->all(), 'query_count'),
            'query_time_ms' => $this->distribution($validSegments->all(), 'query_time_ms'),
            'slow_queries' => [
                'count_24h' => (int) $matchedSlowQueries->sum(fn (array $metric) => (int) ($metric['count_24h'] ?? 0)),
                'p95_ms' => $this->maxMetric($matchedSlowQueries->all(), 'p95_ms'),
                'p99_ms' => $this->maxMetric($matchedSlowQueries->all(), 'p99_ms'),
                'max_ms' => $this->maxMetric($matchedSlowQueries->all(), 'max_ms'),
            ],
            'application_error_count_24h' => (int) $matchedErrors
                ->sum(fn (array $metric) => (int) ($metric['count_24h'] ?? 0)),
            'queue' => $queueMetrics,
            'last_seen_at' => $validSegments
                ->pluck('last_seen_at')
                ->filter(fn ($value) => is_string($value) && $value !== '')
                ->sortDesc()
                ->first(),
        ];

        $base = [
            'key' => $scenario['key'],
            'label' => $scenario['label'],
            'manifest_hash' => $scenario['manifest_hash'] ?? null,
            'method' => $expectedMethod,
            'accepted_status_codes' => $acceptedStatusCodes,
            'route_names' => $routeNames,
            'route_uris' => $scenario['route_uris'] ?? [],
            'protocol' => $scenario['protocol'] ?? [],
            'profile' => $scenario['profile'],
            'safety' => $scenario['safety'],
            'targets' => $scenario['targets'],
            'observed' => $observed,
            'blocker' => $scenario['blocker'],
        ];

        $minSamples = (int) data_get($scenario, 'targets.min_samples', 10);
        $blocker = is_array($scenario['blocker'] ?? null) ? $scenario['blocker'] : [];
        if ($this->isFormalBlocker($blocker)) {
            return array_merge($base, [
                'status' => 'blocked',
                'failures' => [(string) $blocker['reason']],
                'remediation' => [sprintf(
                    'Resolve the formal blocker with %s before its review date %s.',
                    (string) $blocker['owner'],
                    (string) $blocker['review_at']
                )],
            ]);
        }

        $queueReady = (int) ($queueMetrics['snapshot_count'] ?? 0) >= 2
            && ($queueMetrics['backlog_measurable'] ?? false)
            && ($queueMetrics['oldest_job_measurable'] ?? false)
            && ($queueMetrics['failed_jobs_measurable'] ?? false)
            && ! ($queueMetrics['truncated'] ?? false)
            && ($queueMetrics['coverage_ready'] ?? false);
        $latencyReady = collect(['p50', 'p95', 'p99', 'max'])
            ->every(fn (string $key): bool => is_numeric($clientLatency[$key] ?? null));
        $runnerReady = $runnerResult !== null
            && $runnerCompletedRequests !== null
            && $runnerCompletedRequests >= $minSamples
            && $telemetryMatchesRunner === true
            && $latencyReady;
        if ($matched->isEmpty()
            || $validSampleCount < $minSamples
            || $observed['truncated']
            || ! $runnerReady
            || ! $queueReady) {
            $failures = [];
            if ($validSampleCount < $minSamples) {
                $failures[] = sprintf(
                    'Only %d/%d required valid request samples are available.',
                    $validSampleCount,
                    $minSamples
                );
            }
            if ($observed['truncated']) {
                $failures[] = 'The scoped request capture is incomplete; no capacity conclusion is allowed.';
            }
            if ($runnerResult === null) {
                $failures[] = 'A validated aggregate result from the approved external runner is required.';
            } elseif (! $latencyReady) {
                $failures[] = 'The external runner result does not contain a complete client latency distribution.';
            }
            if ($runnerCompletedRequests !== null && $telemetryMatchesRunner !== true) {
                $failures[] = sprintf(
                    'External runner and internal telemetry request counts differ (%d versus %d).',
                    $runnerCompletedRequests,
                    $requestCount
                );
            }
            if ($observed['unexpected_method_count'] > 0) {
                $failures[] = sprintf('%d samples used an unexpected HTTP method.', $observed['unexpected_method_count']);
            }
            if ($observed['invalid_response_count'] > 0) {
                $failures[] = sprintf('%d samples returned an unexpected business status code.', $observed['invalid_response_count']);
            }
            if ($observed['invalid_business_outcome_count'] > 0) {
                $failures[] = sprintf('%d samples failed the configured business outcome assertion.', $observed['invalid_business_outcome_count']);
            }
            if (! $queueReady) {
                $failures[] = 'Complete and measurable queue snapshots must cover the external runner interval at the configured cadence.';
            }

            return array_merge($base, [
                'status' => 'insufficient_data',
                'failures' => array_values(array_unique($failures)),
                'remediation' => array_values(array_unique(array_merge(
                    $scenario['remediation'],
                    [
                        sprintf(
                            'Run the %s controlled scenario until at least %d valid samples are captured.',
                            $scenario['label'],
                            $minSamples
                        ),
                        'Import the sanitized aggregate runner result with capacity:result:import.',
                        'If execution is blocked, configure a reason, owner, and review date.',
                    ]
                ))),
            ]);
        }

        $failures = [];

        if ($observed['unexpected_method_count'] > 0) {
            $failures[] = sprintf('%d samples used an unexpected HTTP method.', $observed['unexpected_method_count']);
        }
        if ($observed['invalid_response_count'] > 0) {
            $failures[] = sprintf('%d samples returned an unexpected business status code.', $observed['invalid_response_count']);
        }
        if ($observed['invalid_business_outcome_count'] > 0) {
            $failures[] = sprintf('%d samples failed the configured business outcome assertion.', $observed['invalid_business_outcome_count']);
        }
        if ((float) ($clientLatency['p95'] ?? 0) > (float) data_get($scenario, 'targets.p95_ms', PHP_INT_MAX)) {
            $failures[] = sprintf(
                'External client latency p95 exceeded target (%s ms > %s ms).',
                $this->formatNumber($clientLatency['p95']),
                $this->formatNumber(data_get($scenario, 'targets.p95_ms'))
            );
        }
        if ((float) ($clientLatency['p99'] ?? 0) > (float) data_get($scenario, 'targets.p99_ms', PHP_INT_MAX)) {
            $failures[] = sprintf(
                'External client latency p99 exceeded target (%s ms > %s ms).',
                $this->formatNumber($clientLatency['p99']),
                $this->formatNumber(data_get($scenario, 'targets.p99_ms'))
            );
        }
        foreach (['response_body_bytes', 'query_count', 'query_time_ms'] as $measurement) {
            $valueCount = (int) data_get($observed, "{$measurement}.value_count", 0);
            if ($valueCount < $minSamples) {
                $failures[] = sprintf(
                    '%s is available for only %d/%d required samples.',
                    $measurement,
                    $valueCount,
                    $minSamples
                );
            }
        }
        $shared = config('capacity.shared', []);
        if ((float) ($queueMetrics['pending_jobs'] ?? 0) > (float) data_get($shared, 'queue.max_pending_jobs', 250)) {
            $failures[] = 'Queue pending jobs exceeded the shared target during this scenario.';
        }
        if ((float) ($queueMetrics['oldest_job_minutes'] ?? 0) > (float) data_get($shared, 'queue.max_oldest_job_minutes', 10)) {
            $failures[] = 'Queue oldest ready job age exceeded the shared target during this scenario.';
        }
        if ((float) ($queueMetrics['failed_jobs_24h'] ?? 0) > (float) data_get($shared, 'queue.max_failed_jobs_24h', 5)) {
            $failures[] = 'Failed jobs exceeded the shared target during this scenario.';
        }
        if (($observed['error_count_24h'] ?? 0) > (int) data_get($scenario, 'targets.error_count_24h', 0)) {
            $failures[] = sprintf(
                '5xx count exceeded target (%d > %d).',
                (int) $observed['error_count_24h'],
                (int) data_get($scenario, 'targets.error_count_24h', 0)
            );
        }

        return array_merge($base, [
            'status' => $failures === [] ? 'pass' : 'fail',
            'failures' => $failures,
            'remediation' => $failures === [] ? [] : $scenario['remediation'],
        ]);
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
        $dataQuality = is_array($observability['data_quality'] ?? null) ? $observability['data_quality'] : [];
        $storage = is_array($dataQuality['storage'] ?? null) ? $dataQuality['storage'] : [];
        $telemetryMeasurable = config('observability.enabled', false)
            && (bool) ($storage['shared'] ?? false)
            && (bool) ($storage['low_overhead'] ?? false)
            && (int) ($storage['dropped_writes'] ?? 0) === 0;
        $slowQueriesMeasurable = $telemetryMeasurable && ! ($queries['truncated'] ?? false);
        $errorsMeasurable = $telemetryMeasurable && ! ($errors['truncated'] ?? false);
        $shared = config('capacity.shared', []);

        return [
            $this->sharedCheck(
                'queue_pending_jobs',
                'Queue pending jobs',
                is_numeric($queue['pending_jobs'] ?? null) ? (float) $queue['pending_jobs'] : null,
                (float) data_get($shared, 'queue.max_pending_jobs', 250),
                (bool) ($queue['backlog_measurable'] ?? false),
                'Scale queue workers or isolate noisy workloads before increasing frontend traffic.'
            ),
            $this->sharedCheck(
                'queue_oldest_job_minutes',
                'Queue oldest ready job age',
                is_numeric($queue['oldest_job_minutes'] ?? null) ? (float) $queue['oldest_job_minutes'] : null,
                (float) data_get($shared, 'queue.max_oldest_job_minutes', 10),
                (bool) ($queue['oldest_job_measurable'] ?? false),
                'Investigate queue saturation and worker concurrency before accepting more load.'
            ),
            $this->sharedCheck(
                'failed_jobs_24h',
                'Failed jobs in 24h',
                is_numeric($queue['failed_jobs_24h'] ?? null) ? (float) $queue['failed_jobs_24h'] : null,
                (float) data_get($shared, 'queue.max_failed_jobs_24h', 5),
                (bool) ($queue['failed_jobs_measurable'] ?? false),
                'Reduce queue failures before validating higher throughput.'
            ),
            $this->sharedCheck(
                'slow_queries_24h',
                'Slow queries in 24h',
                is_numeric($queries['count_24h'] ?? null) ? (float) $queries['count_24h'] : null,
                (float) data_get($shared, 'database.max_slow_queries_24h', 50),
                $slowQueriesMeasurable,
                'Profile slow queries and add indexing or caching before scaling traffic.'
            ),
            $this->sharedCheck(
                'errors_1h',
                'Application errors in 1h',
                is_numeric($errors['count_1h'] ?? null) ? (float) $errors['count_1h'] : null,
                (float) data_get($shared, 'app.max_errors_1h', 2),
                $errorsMeasurable,
                'Stabilize application errors before validating larger traffic envelopes.'
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function baselineContext(): array
    {
        $configured = config('capacity.baseline', []);
        $configured = is_array($configured) ? $configured : [];
        $fields = [
            'run_id',
            'environment',
            'commit',
            'started_at',
            'ended_at',
            'traffic',
            'runner',
            'runner_hash',
            'fixture_hash',
            'allowed_origins',
            'exclusions',
            'mode',
            'approval_reference',
            'owner',
            'validator',
        ];
        $values = [];
        $missing = [];

        foreach ($fields as $field) {
            $value = $configured[$field] ?? null;
            $value = is_string($value) && trim($value) !== '' ? trim($value) : null;
            $values[$field] = $value;
            if ($value === null) {
                $missing[] = $field;
            }
        }

        $issues = [];
        $periodComplete = false;
        if ($values['started_at'] !== null && $values['ended_at'] !== null) {
            try {
                if (preg_match('/(?:Z|\+00:00|\+0000)$/i', $values['started_at']) !== 1
                    || preg_match('/(?:Z|\+00:00|\+0000)$/i', $values['ended_at']) !== 1) {
                    $issues[] = 'Baseline period timestamps must include an explicit UTC offset.';
                }
                $startedAt = Carbon::parse($values['started_at']);
                $endedAt = Carbon::parse($values['ended_at']);
                if ($startedAt->greaterThanOrEqualTo($endedAt)) {
                    $issues[] = 'Baseline started_at must be earlier than ended_at.';
                }
                if ($startedAt->lessThan(now()->subHours($this->retentionHours()))) {
                    $issues[] = 'Baseline period starts outside the telemetry retention window.';
                }
                $periodComplete = $endedAt->lessThanOrEqualTo(now());
            } catch (\Throwable) {
                $issues[] = 'Baseline period must use valid timestamps.';
            }
        }
        if ($values['environment'] !== null && $values['environment'] !== (string) config('app.env')) {
            $issues[] = 'Baseline environment must match the running application environment.';
        }
        if ($values['runner_hash'] !== null
            && preg_match('/^[a-f0-9]{64}$/i', $values['runner_hash']) !== 1) {
            $issues[] = 'Baseline runner_hash must be a 64-character SHA-256 hexadecimal digest.';
        }
        if ($values['fixture_hash'] !== null
            && preg_match('/^[a-f0-9]{64}$/i', $values['fixture_hash']) !== 1) {
            $issues[] = 'Baseline fixture_hash must be a 64-character SHA-256 hexadecimal digest.';
        }
        $allowedOrigins = [];
        if ($values['allowed_origins'] !== null) {
            foreach (explode(',', $values['allowed_origins']) as $configuredOrigin) {
                $origin = $this->normalizedHttpsOrigin($configuredOrigin);
                if ($origin === null) {
                    $issues[] = 'Baseline allowed_origins must contain only exact HTTPS origins.';

                    continue;
                }
                $allowedOrigins[] = $origin;
            }
            $allowedOrigins = array_values(array_unique($allowedOrigins));
            sort($allowedOrigins);
        }
        if ($values['allowed_origins'] !== null && $allowedOrigins === []) {
            $issues[] = 'Baseline allowed_origins must contain at least one exact HTTPS origin.';
        }
        $representative = filter_var($configured['representative'] ?? false, FILTER_VALIDATE_BOOL);
        $approved = filter_var($configured['approved'] ?? false, FILTER_VALIDATE_BOOL);
        $queueCanariesVerified = filter_var($configured['queue_canaries_verified'] ?? false, FILTER_VALIDATE_BOOL);
        $isolatedTenantVerified = filter_var(
            $configured['isolated_tenant_verified'] ?? false,
            FILTER_VALIDATE_BOOL
        );
        if (! in_array($values['mode'], ['staging', 'production_read_only'], true)) {
            $issues[] = 'Baseline mode must be staging or production_read_only.';
        }
        $allowedStagingEnvironments = collect(config('capacity.allowed_staging_environments', ['staging']))
            ->filter(fn ($environment): bool => is_string($environment))
            ->map(fn (string $environment): string => strtolower(trim($environment)))
            ->filter()
            ->unique()
            ->values()
            ->all();
        if ($values['mode'] === 'staging'
            && ! in_array(strtolower((string) $values['environment']), $allowedStagingEnvironments, true)) {
            $issues[] = 'A staging baseline environment must be present in the explicit staging allowlist.';
        }
        if ($values['mode'] === 'production_read_only'
            && strtolower((string) $values['environment']) !== 'production') {
            $issues[] = 'A production_read_only baseline must run in production.';
        }
        if (! $representative) {
            $issues[] = 'Baseline environment and data must be explicitly marked representative.';
        }
        if (! $approved) {
            $issues[] = 'Baseline execution must be explicitly approved.';
        }
        if (! $queueCanariesVerified) {
            $issues[] = 'P0-005 queue canaries must be verified before accepting the baseline.';
        }
        $requiresIsolatedTenant = collect($this->catalog->all())->contains(function (array $scenario): bool {
            $blocker = is_array($scenario['blocker'] ?? null) ? $scenario['blocker'] : [];

            return (bool) data_get($scenario, 'safety.requires_isolated_tenant', false)
                && ! $this->isFormalBlocker($blocker);
        });
        if ($requiresIsolatedTenant && ! $isolatedTenantVerified) {
            $issues[] = 'An isolated tenant must be explicitly verified for unblocked controlled-write scenarios.';
        }
        if ($values['owner'] !== null && $values['owner'] === $values['validator']) {
            $issues[] = 'Baseline owner and validator must be distinct.';
        }
        if ($values['mode'] === 'production_read_only') {
            foreach ($this->catalog->all() as $scenario) {
                if (data_get($scenario, 'safety.mode') === 'read_only'
                    || $this->isFormalBlocker(is_array($scenario['blocker'] ?? null) ? $scenario['blocker'] : [])) {
                    continue;
                }

                $issues[] = sprintf(
                    'Scenario %s must have a formal blocker in production_read_only mode.',
                    (string) ($scenario['key'] ?? 'unknown')
                );
            }
        }

        $scopeId = $this->telemetryScope->idFor([
            'environment' => $values['environment'],
            'release' => config('observability.release'),
            'run_id' => $values['run_id'],
            'commit' => $values['commit'],
            'started_at' => $values['started_at'],
            'ended_at' => $values['ended_at'],
        ]);

        return [
            'status' => $missing === [] && $issues === [] ? 'complete' : 'incomplete',
            'release' => config('observability.release'),
            'run_id' => $values['run_id'],
            'environment' => $values['environment'],
            'commit' => $values['commit'],
            'scope_id' => $scopeId,
            'period' => [
                'started_at' => $values['started_at'],
                'ended_at' => $values['ended_at'],
            ],
            'traffic' => $values['traffic'],
            'runner' => $values['runner'],
            'runner_hash' => is_string($values['runner_hash']) ? strtolower($values['runner_hash']) : null,
            'fixture_hash' => is_string($values['fixture_hash']) ? strtolower($values['fixture_hash']) : null,
            'allowed_origins' => $allowedOrigins,
            'mode' => $values['mode'],
            'period_complete' => $periodComplete,
            'representative' => $representative,
            'approved' => $approved,
            'approval_reference' => $values['approval_reference'],
            'queue_canaries_verified' => $queueCanariesVerified,
            'isolated_tenant_verified' => $isolatedTenantVerified,
            'exclusions' => $values['exclusions'] === null
                ? null
                : array_values(array_filter(array_map('trim', explode(',', $values['exclusions'])))),
            'owner' => $values['owner'],
            'validator' => $values['validator'],
            'runtime' => [
                'cache_store' => (string) config('observability.cache.store', config('cache.default')),
                'database_connection' => (string) config('database.default'),
                'queue_connection' => (string) config('queue.default'),
            ],
            'missing' => $missing,
            'issues' => $issues,
        ];
    }

    /**
     * @param  array<string, mixed>  $baselineContext
     * @return array<string, mixed>
     */
    private function baselineScope(array $baselineContext): array
    {
        if (($baselineContext['status'] ?? null) !== 'complete') {
            return ['match_none' => true];
        }

        $scope = [
            'environment' => $baselineContext['environment'],
            'release' => config('observability.release'),
            'run_id' => $baselineContext['run_id'],
            'commit' => $baselineContext['commit'],
            'started_at' => data_get($baselineContext, 'period.started_at'),
            'ended_at' => data_get($baselineContext, 'period.ended_at'),
            'route_names' => collect($this->catalog->all())
                ->flatMap(fn (array $scenario): array => $scenario['route_names'] ?? [])
                ->filter(fn ($routeName): bool => is_string($routeName))
                ->unique()
                ->values()
                ->all(),
        ];

        $scope['scope_id'] = $this->telemetryScope->idFor($scope);

        return $scope;
    }

    /**
     * @param  array<int, array<string, mixed>>  $scenarios
     * @param  array<int, array<string, mixed>>  $sharedChecks
     * @param  array<string, mixed>  $baselineContext
     * @param  array<int, string>  $configurationIssues
     * @return array<int, string>
     */
    private function remediation(
        array $scenarios,
        array $sharedChecks,
        array $baselineContext,
        array $configurationIssues
    ): array {
        return collect($scenarios)
            ->filter(fn (array $scenario) => in_array(
                $scenario['status'] ?? null,
                ['fail', 'insufficient_data', 'blocked'],
                true
            ))
            ->flatMap(fn (array $scenario) => $scenario['remediation'] ?? [])
            ->merge(
                collect($sharedChecks)
                    ->filter(fn (array $check) => in_array($check['status'] ?? null, ['fail', 'unknown'], true))
                    ->pluck('remediation')
            )
            ->merge(($baselineContext['status'] ?? null) === 'complete'
                ? []
                : ['Complete the baseline run context before accepting performance conclusions.'])
            ->merge($configurationIssues)
            ->filter(fn ($item) => is_string($item) && trim($item) !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $scenarios
     * @param  array<int, array<string, mixed>>  $sharedChecks
     * @param  array<string, mixed>  $baselineContext
     * @param  array<int, string>  $configurationIssues
     * @param  array<string, mixed>  $dataQuality
     */
    private function status(
        array $scenarios,
        array $sharedChecks,
        array $baselineContext,
        array $configurationIssues,
        array $dataQuality
    ): string {
        if ($configurationIssues !== []
            || collect($scenarios)->contains(fn (array $scenario) => ($scenario['status'] ?? null) === 'fail')
            || collect($sharedChecks)->contains(fn (array $check) => ($check['status'] ?? null) === 'fail')) {
            return 'critical';
        }

        if ($scenarios === []) {
            return 'unknown';
        }

        $incomplete = collect($scenarios)
            ->contains(fn (array $scenario) => ($scenario['status'] ?? null) === 'insufficient_data');
        $acceptedBlockers = collect($scenarios)
            ->contains(fn (array $scenario) => ($scenario['status'] ?? null) === 'blocked');
        $unknownSharedCheck = collect($sharedChecks)
            ->contains(fn (array $check) => ($check['status'] ?? null) === 'unknown');

        if ($incomplete
            || $unknownSharedCheck
            || ($baselineContext['status'] ?? null) !== 'complete'
            || ! ($baselineContext['period_complete'] ?? false)
            || ($dataQuality['status'] ?? null) !== 'ready') {
            return 'warning';
        }

        return $acceptedBlockers ? 'accepted_with_blockers' : 'healthy';
    }

    /**
     * @param  array<int, array<string, mixed>>  $metrics
     * @return array{p50: float|null, p95: float|null, p99: float|null, max: float|null, value_count: int, missing_count: int}
     */
    private function distribution(array $metrics, string $key): array
    {
        return [
            'p50' => $this->maxMetric($metrics, "{$key}.p50"),
            'p95' => $this->maxMetric($metrics, "{$key}.p95"),
            'p99' => $this->maxMetric($metrics, "{$key}.p99"),
            'max' => $this->maxMetric($metrics, "{$key}.max"),
            'value_count' => (int) collect($metrics)
                ->sum(fn (array $metric): int => (int) data_get($metric, "{$key}.value_count", 0)),
            'missing_count' => (int) collect($metrics)
                ->sum(fn (array $metric): int => (int) data_get($metric, "{$key}.missing_count", 0)),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $metrics
     */
    private function maxMetric(array $metrics, string $key): ?float
    {
        $values = array_values(array_filter(array_map(
            static fn (array $metric) => is_numeric(data_get($metric, $key)) ? (float) data_get($metric, $key) : null,
            $metrics
        ), static fn ($value) => $value !== null));

        return $values === [] ? null : round(max($values), 1);
    }

    private function formatNumber(mixed $value): string
    {
        return is_numeric($value) ? number_format((float) $value, 1, '.', '') : 'n/a';
    }

    /**
     * @param  array<string, mixed>  $queueMetrics
     * @param  array<string, mixed>|null  $runnerResult
     * @return array<int, string>
     */
    private function queueCoverageIssues(array $queueMetrics, ?array $runnerResult): array
    {
        if ($runnerResult === null) {
            return ['runner_result_missing'];
        }

        try {
            $runnerStartedAt = Carbon::parse((string) ($runnerResult['started_at'] ?? ''));
            $runnerEndedAt = Carbon::parse((string) ($runnerResult['ended_at'] ?? ''));
            $firstSnapshotAt = Carbon::parse((string) data_get($queueMetrics, 'coverage.first_recorded_at', ''));
            $lastSnapshotAt = Carbon::parse((string) data_get($queueMetrics, 'coverage.last_recorded_at', ''));
        } catch (\Throwable) {
            return ['queue_snapshot_timestamps_invalid'];
        }

        $interval = max(1, (int) config('capacity.shared.queue.snapshot_interval_seconds', 60));
        $maximumGap = max($interval, (int) config('capacity.shared.queue.max_snapshot_gap_seconds', 120));
        $grace = max(0, (int) config('capacity.shared.queue.coverage_grace_seconds', 30));
        $duration = max(1, (int) ($runnerResult['duration_seconds'] ?? 0));
        $requiredSnapshots = max(2, intdiv($duration, $interval) + 1);
        $issues = [];

        if ((int) ($queueMetrics['snapshot_count'] ?? 0) < $requiredSnapshots) {
            $issues[] = 'queue_snapshot_count_below_required_cadence';
        }
        if ($firstSnapshotAt->greaterThan($runnerStartedAt->copy()->addSeconds($grace))) {
            $issues[] = 'queue_snapshot_coverage_starts_too_late';
        }
        if ($lastSnapshotAt->lessThan($runnerEndedAt->copy()->subSeconds($grace))) {
            $issues[] = 'queue_snapshot_coverage_ends_too_early';
        }
        if (! is_numeric(data_get($queueMetrics, 'coverage.max_gap_seconds'))
            || (float) data_get($queueMetrics, 'coverage.max_gap_seconds') > $maximumGap) {
            $issues[] = 'queue_snapshot_gap_above_limit';
        }

        return array_values(array_unique($issues));
    }

    private function retentionHours(): int
    {
        return max(
            24,
            (int) config('observability.request.retention_hours', 24),
            (int) config('observability.query.retention_hours', 24),
            (int) config('observability.error.retention_hours', 24)
        );
    }

    private function normalizedHttpsOrigin(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $parts = parse_url(trim($value));
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || ($parts['host'] ?? '') === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            return null;
        }

        $host = strtolower($parts['host']);
        if (preg_match('/^[a-z0-9.-]+$/', $host) !== 1
            && preg_match('/^\[[a-f0-9:]+\]$/', $host) !== 1) {
            return null;
        }
        $port = $parts['port'] ?? null;
        if ($port !== null && ($port < 1 || $port > 65535)) {
            return null;
        }

        return 'https://'.$host.($port !== null && $port !== 443 ? ':'.$port : '');
    }

    /**
     * @param  array<string, mixed>  $blocker
     */
    private function isFormalBlocker(array $blocker): bool
    {
        if (! is_string($blocker['reason'] ?? null)
            || ! is_string($blocker['owner'] ?? null)
            || ! is_string($blocker['review_at'] ?? null)) {
            return false;
        }

        try {
            return Carbon::parse($blocker['review_at'])->isFuture();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function sharedCheck(
        string $key,
        string $label,
        ?float $observed,
        float $target,
        bool $measurable,
        string $remediation
    ): array {
        if (! $measurable || $observed === null) {
            return [
                'key' => $key,
                'label' => $label,
                'status' => 'unknown',
                'observed' => null,
                'target' => round($target, 1),
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

    /**
     * @return array<string, array<string, mixed>>
     */
    private function routeMetrics(mixed $metrics): array
    {
        return collect(is_array($metrics) ? $metrics : [])
            ->filter(fn ($route) => is_array($route) && is_string($route['route_name'] ?? null))
            ->keyBy('route_name')
            ->all();
    }
}
