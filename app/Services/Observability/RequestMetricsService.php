<?php

namespace App\Services\Observability;

use App\Services\Capacity\CapacityRunContextService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class RequestMetricsService
{
    private const ROUTE_INDEX_KEY = 'requests:index';

    private const ATTRIBUTE_ACTIVE = '_observability_active';

    private const ATTRIBUTE_QUERY_COUNT = '_observability_query_count';

    private const ATTRIBUTE_QUERY_TIME_MS = '_observability_query_time_ms';

    private const ATTRIBUTE_SCENARIO_KEY = '_observability_scenario_key';

    private const ATTRIBUTE_SCOPE_TAGS = '_observability_scope_tags';

    public function __construct(
        private readonly ObservabilityCacheStore $cache,
        private readonly ObservabilityLogService $logger,
        private readonly TelemetrySanitizer $sanitizer,
        private readonly TelemetryScope $scope,
        private readonly CapacityRunContextService $runContext
    ) {}

    public function beginRequest(Request $request): void
    {
        if (! config('observability.enabled', false)) {
            return;
        }

        $request->attributes->set(self::ATTRIBUTE_ACTIVE, true);
        $request->attributes->set(self::ATTRIBUTE_QUERY_COUNT, 0);
        $request->attributes->set(self::ATTRIBUTE_QUERY_TIME_MS, 0.0);
        $request->attributes->set(self::ATTRIBUTE_SCENARIO_KEY, $this->runContext->activeScenarioKey());
        $scopeTags = $this->scope->tags();
        $scopeTags['scope_id'] = $this->scope->activeId();
        $request->attributes->set(self::ATTRIBUTE_SCOPE_TAGS, $scopeTags);
    }

    public function recordExecutedQuery(QueryExecuted $query): void
    {
        if (! config('observability.enabled', false) || ! app()->bound('request')) {
            return;
        }

        $request = request();
        if (! $request instanceof Request || ! $request->attributes->getBoolean(self::ATTRIBUTE_ACTIVE)) {
            return;
        }

        $request->attributes->set(
            self::ATTRIBUTE_QUERY_COUNT,
            (int) $request->attributes->get(self::ATTRIBUTE_QUERY_COUNT, 0) + 1
        );
        $request->attributes->set(
            self::ATTRIBUTE_QUERY_TIME_MS,
            (float) $request->attributes->get(self::ATTRIBUTE_QUERY_TIME_MS, 0.0) + (float) $query->time
        );
    }

    /**
     * @return array{query_count: int, query_time_ms: float, scenario_key: string|null, scope_tags: array<string, mixed>}
     */
    public function finishRequest(Request $request): array
    {
        try {
            return [
                'query_count' => max(0, (int) $request->attributes->get(self::ATTRIBUTE_QUERY_COUNT, 0)),
                'query_time_ms' => round(max(0, (float) $request->attributes->get(self::ATTRIBUTE_QUERY_TIME_MS, 0.0)), 1),
                'scenario_key' => $this->nullableString(
                    $request->attributes->get(self::ATTRIBUTE_SCENARIO_KEY)
                ),
                'scope_tags' => is_array($request->attributes->get(self::ATTRIBUTE_SCOPE_TAGS))
                    ? $request->attributes->get(self::ATTRIBUTE_SCOPE_TAGS)
                    : [],
            ];
        } finally {
            $this->discardRequest($request);
        }
    }

    public function discardRequest(Request $request): void
    {
        try {
            $request->attributes->remove(self::ATTRIBUTE_ACTIVE);
            $request->attributes->remove(self::ATTRIBUTE_QUERY_COUNT);
            $request->attributes->remove(self::ATTRIBUTE_QUERY_TIME_MS);
            $request->attributes->remove(self::ATTRIBUTE_SCENARIO_KEY);
            $request->attributes->remove(self::ATTRIBUTE_SCOPE_TAGS);
        } catch (\Throwable) {
            // Request telemetry must never alter the business response.
        }
    }

    /**
     * @param  array{query_count?: int, query_time_ms?: float, scenario_key?: string|null, scope_tags?: array<string, mixed>}  $queryMetrics
     */
    public function record(
        Request $request,
        int $statusCode,
        float $durationMs,
        ?int $responseBodyBytes = null,
        array $queryMetrics = [],
        ?bool $businessSuccess = null
    ): void {
        $routeName = $request->route()?->getName();
        $routeName = is_string($routeName) && trim($routeName) !== ''
            ? $routeName
            : strtoupper($request->method()).' unnamed-route';

        $this->recordRouteSample($routeName, $durationMs, $statusCode, [
            'method' => strtoupper($request->method()),
            'route_pattern' => $this->sanitizer->routePattern($request),
            'response_body_bytes' => $responseBodyBytes,
            'query_count' => $queryMetrics['query_count'] ?? null,
            'query_time_ms' => $queryMetrics['query_time_ms'] ?? null,
            'scenario_key' => $queryMetrics['scenario_key'] ?? null,
            'scope_tags' => $queryMetrics['scope_tags'] ?? [],
            'business_success' => $businessSuccess,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function recordRouteSample(string $routeName, float $durationMs, int $statusCode, array $context = []): void
    {
        if (! config('observability.enabled', false)) {
            return;
        }

        $routeName = trim($routeName) !== '' ? trim($routeName) : 'unknown';
        $recordedAt = now();
        $routePattern = is_string($context['route_pattern'] ?? null)
            && trim((string) $context['route_pattern']) !== ''
                ? trim((string) $context['route_pattern'])
                : $this->sanitizer->routePatternForName($routeName);
        $durationMs = round(max(0, $durationMs), 1);
        if (! $this->shouldTrack($routeName, $routePattern, $durationMs, $statusCode)) {
            return;
        }

        $scopeTags = is_array($context['scope_tags'] ?? null)
            ? $context['scope_tags']
            : $this->scope->tags();
        $sample = [
            ...$scopeTags,
            'scenario_key' => array_key_exists('scenario_key', $context)
                ? $this->nullableString($context['scenario_key'])
                : $this->runContext->activeScenarioKey(),
            'route_name' => $routeName,
            'duration_ms' => $durationMs,
            'status_code' => $statusCode,
            'method' => strtoupper((string) ($context['method'] ?? 'GET')),
            'route_pattern' => $routePattern,
            'response_body_bytes' => $this->nullableNonNegativeInteger($context['response_body_bytes'] ?? null),
            'query_count' => $this->nullableNonNegativeInteger($context['query_count'] ?? null),
            'query_time_ms' => $this->nullableNonNegativeFloat($context['query_time_ms'] ?? null),
            'business_success' => is_bool($context['business_success'] ?? null)
                ? $context['business_success']
                : ($statusCode >= 200 && $statusCode < 300),
            'recorded_at' => $recordedAt->toIso8601String(),
        ];

        $retentionHours = $this->retentionHours();
        $scenarioKey = is_string($sample['scenario_key'] ?? null)
            && trim((string) $sample['scenario_key']) !== ''
                ? trim((string) $sample['scenario_key'])
                : null;
        $scopeId = array_key_exists('scope_tags', $context)
            ? $this->nullableString($scopeTags['scope_id'] ?? null)
            : $this->scope->activeId();
        if ($scenarioKey === null) {
            $scopeId = null;
        }
        if ($scopeId !== null) {
            $sequence = $this->cache->incrementValue(
                $this->scopedCounterKey($scopeId, $routeName, 'requests'),
                $retentionHours + 1
            );
            if ($scenarioKey !== null) {
                $this->cache->increment(
                    $this->scopedScenarioCounterKey($scopeId, $routeName, $scenarioKey, 'requests'),
                    $retentionHours + 1
                );
            }

            if ($statusCode >= 500) {
                $this->cache->increment($this->scopedCounterKey($scopeId, $routeName, 'errors'), $retentionHours + 1);
                if ($scenarioKey !== null) {
                    $this->cache->increment(
                        $this->scopedScenarioCounterKey($scopeId, $routeName, $scenarioKey, 'errors'),
                        $retentionHours + 1
                    );
                }
            }

            if ($sample['duration_ms'] >= $this->slowThreshold()) {
                $this->cache->increment($this->scopedCounterKey($scopeId, $routeName, 'slow'), $retentionHours + 1);
                if ($scenarioKey !== null) {
                    $this->cache->increment(
                        $this->scopedScenarioCounterKey($scopeId, $routeName, $scenarioKey, 'slow'),
                        $retentionHours + 1
                    );
                }
            }

            if ($sequence !== null && $sequence <= $this->maxScopeSamples()) {
                $this->cache->put(
                    $this->scopedSampleKey($scopeId, $routeName, $sequence),
                    $sample,
                    $retentionHours
                );
            }
        } else {
            if (! $this->isConfiguredRoute($routeName, $routePattern)
                || Route::getRoutes()->getByName($routeName) === null) {
                $this->cache->addIndexValue(self::ROUTE_INDEX_KEY, $routeName, $retentionHours);
            }
            $this->cache->increment($this->counterKey($routeName, 'requests', $recordedAt), $retentionHours + 1);
            if ($statusCode >= 500) {
                $this->cache->increment($this->counterKey($routeName, 'errors', $recordedAt), $retentionHours + 1);
            }
            if ($sample['duration_ms'] >= $this->slowThreshold()) {
                $this->cache->increment($this->counterKey($routeName, 'slow', $recordedAt), $retentionHours + 1);
            }
            $this->cache->append($this->samplesKey($routeName), $sample, $this->sampleSize(), $retentionHours);
        }

        if ($sample['duration_ms'] >= $this->slowThreshold()) {
            $this->logger->warning('slow_request', [
                'route_name' => $routeName,
                'method' => $sample['method'],
                'route_pattern' => $sample['route_pattern'],
                'status_code' => $statusCode,
                'duration_ms' => $sample['duration_ms'],
                'response_body_bytes' => $sample['response_body_bytes'],
                'query_count' => $sample['query_count'],
                'query_time_ms' => $sample['query_time_ms'],
            ]);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function summary(?array $scope = null): array
    {
        $routeNames = collect($this->cache->indexValues(self::ROUTE_INDEX_KEY, $this->retentionHours()))
            ->merge($this->configuredRouteNames())
            ->unique()
            ->values();
        if (is_array($scope['route_names'] ?? null)) {
            $routeNames = $routeNames->filter(fn (string $routeName): bool => in_array(
                $routeName,
                $scope['route_names'],
                true
            ));
        }

        return $routeNames
            ->map(fn (string $routeName) => $this->routeSummary($routeName, $scope))
            ->filter()
            ->sortByDesc(fn (array $route) => [(float) ($route['p95_ms'] ?? 0), (int) ($route['count_24h'] ?? 0)])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function routeSummary(string $routeName, ?array $scope): ?array
    {
        $samples = $this->freshSamples($routeName, $scope);
        $scoped = $scope !== null;
        $counters = $scoped
            ? $this->scopedCounterTotals($routeName, $scope, $samples)
            : $this->counterTotals($routeName);
        if ($samples === [] && $counters['requests'] === 0) {
            return null;
        }

        return [
            'route_name' => $routeName,
            'window_hours' => $scoped ? null : 24,
            'sampling_strategy' => $scoped ? 'complete_scope_capture' : 'bounded_operational_buffer',
            'sample_limit' => $scoped ? $this->maxScopeSamples() : $this->sampleSize(),
            'counter_precision_minutes' => $this->counterBucketMinutes(),
            ...$this->aggregateSamples($samples, $counters),
            'by_scenario' => $scoped
                ? $this->scenarioSummaries($routeName, $scope, $samples)
                : [],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $samples
     * @param  array{requests: int, slow: int, errors: int}  $counters
     * @return array<string, mixed>
     */
    private function aggregateSamples(array $samples, array $counters): array
    {
        $requestCount = max($counters['requests'], count($samples));
        $lastSample = end($samples) ?: null;
        $firstSample = reset($samples) ?: null;
        $latency = $this->statistics($samples, 'duration_ms');

        return [
            'count_24h' => $requestCount,
            'sample_count_24h' => count($samples),
            'truncated' => $requestCount > count($samples),
            'slow_count_24h' => max($counters['slow'], 0),
            'error_count_24h' => max($counters['errors'], 0),
            'p50_ms' => $latency['p50'],
            'p95_ms' => $latency['p95'],
            'p99_ms' => $latency['p99'],
            'max_ms' => $latency['max'],
            'response_body_bytes' => $this->statistics($samples, 'response_body_bytes'),
            'query_count' => $this->statistics($samples, 'query_count'),
            'query_time_ms' => $this->statistics($samples, 'query_time_ms'),
            'status_classes' => collect($samples)
                ->countBy(fn (array $sample): string => intdiv((int) ($sample['status_code'] ?? 0), 100).'xx')
                ->sortKeys()
                ->all(),
            'status_codes' => collect($samples)
                ->countBy(fn (array $sample): string => (string) ((int) ($sample['status_code'] ?? 0)))
                ->sortKeys()
                ->all(),
            'methods' => collect($samples)
                ->countBy(fn (array $sample): string => strtoupper((string) ($sample['method'] ?? 'UNKNOWN')))
                ->sortKeys()
                ->all(),
            'method_status_classes' => collect($samples)
                ->countBy(fn (array $sample): string => sprintf(
                    '%s:%dxx',
                    strtoupper((string) ($sample['method'] ?? 'UNKNOWN')),
                    intdiv((int) ($sample['status_code'] ?? 0), 100)
                ))
                ->sortKeys()
                ->all(),
            'method_status_codes' => collect($samples)
                ->countBy(fn (array $sample): string => sprintf(
                    '%s:%d',
                    strtoupper((string) ($sample['method'] ?? 'UNKNOWN')),
                    (int) ($sample['status_code'] ?? 0)
                ))
                ->sortKeys()
                ->all(),
            'business_success_count' => count(array_filter(
                $samples,
                static fn (array $sample): bool => ($sample['business_success'] ?? null) === true
            )),
            'business_failure_count' => count(array_filter(
                $samples,
                static fn (array $sample): bool => ($sample['business_success'] ?? null) === false
            )),
            'business_outcome_unknown_count' => count(array_filter(
                $samples,
                static fn (array $sample): bool => ! is_bool($sample['business_success'] ?? null)
            )),
            'segments' => $this->segments($samples),
            'first_seen_at' => is_array($firstSample) ? ($firstSample['recorded_at'] ?? null) : null,
            'last_seen_at' => is_array($lastSample) ? ($lastSample['recorded_at'] ?? null) : null,
            'method' => is_array($lastSample) ? ($lastSample['method'] ?? null) : null,
            'route_pattern' => is_array($lastSample) ? ($lastSample['route_pattern'] ?? null) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $scope
     * @param  array<int, array<string, mixed>>  $samples
     * @return array<string, array<string, mixed>>
     */
    private function scenarioSummaries(string $routeName, array $scope, array $samples): array
    {
        $scopeId = $this->scope->idFor($scope);
        if ($scopeId === null) {
            return [];
        }

        return collect($samples)
            ->filter(fn (array $sample): bool => is_string($sample['scenario_key'] ?? null)
                && trim((string) $sample['scenario_key']) !== '')
            ->groupBy(fn (array $sample): string => trim((string) $sample['scenario_key']))
            ->map(function (Collection $scenarioSamples, string $scenarioKey) use ($routeName, $scopeId): array {
                $samples = $scenarioSamples->values()->all();

                return [
                    'scenario_key' => $scenarioKey,
                    'route_name' => $routeName,
                    'window_hours' => null,
                    'sampling_strategy' => 'complete_scope_capture',
                    'sample_limit' => $this->maxScopeSamples(),
                    'counter_precision_minutes' => $this->counterBucketMinutes(),
                    ...$this->aggregateSamples(
                        $samples,
                        $this->scopedScenarioCounterTotals($scopeId, $routeName, $scenarioKey, $samples)
                    ),
                ];
            })
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $samples
     * @return array<int, array<string, mixed>>
     */
    private function segments(array $samples): array
    {
        return collect($samples)
            ->groupBy(fn (array $sample): string => sprintf(
                '%s:%d:%s',
                strtoupper((string) ($sample['method'] ?? 'UNKNOWN')),
                (int) ($sample['status_code'] ?? 0),
                match ($sample['business_success'] ?? null) {
                    true => 'success',
                    false => 'failure',
                    default => 'unknown',
                }
            ))
            ->map(function (Collection $segment, string $key): array {
                $segmentSamples = $segment->values()->all();
                $last = end($segmentSamples) ?: null;
                [$method, $statusCode, $outcome] = array_pad(explode(':', $key, 3), 3, 'unknown');

                return [
                    'method' => $method,
                    'status_code' => (int) $statusCode,
                    'business_success' => match ($outcome) {
                        'success' => true,
                        'failure' => false,
                        default => null,
                    },
                    'count' => count($segmentSamples),
                    'duration_ms' => $this->statistics($segmentSamples, 'duration_ms'),
                    'response_body_bytes' => $this->statistics($segmentSamples, 'response_body_bytes'),
                    'query_count' => $this->statistics($segmentSamples, 'query_count'),
                    'query_time_ms' => $this->statistics($segmentSamples, 'query_time_ms'),
                    'last_seen_at' => is_array($last) ? ($last['recorded_at'] ?? null) : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function freshSamples(string $routeName, ?array $scope): array
    {
        if ($scope !== null) {
            $scopeId = $this->scope->idFor($scope);
            if ($scopeId === null || ($scope['match_none'] ?? false) === true) {
                return [];
            }

            $requestCount = max(0, $this->cache->integer(
                $this->scopedCounterKey($scopeId, $routeName, 'requests')
            ));
            $capturedCount = min($requestCount, $this->maxScopeSamples());
            if ($capturedCount <= 0) {
                return [];
            }

            $keys = collect(range(1, $capturedCount))
                ->map(fn (int $sequence): string => $this->scopedSampleKey(
                    $scopeId,
                    $routeName,
                    $sequence
                ))
                ->all();
            $samples = array_values(array_filter(
                $this->cache->values($keys),
                'is_array'
            ));

            return $this->scope->filter($samples, $scope, $this->retentionHours());
        }

        return $this->scope->filter(
            $this->cache->get($this->samplesKey($routeName)),
            null,
            $this->retentionHours()
        );
    }

    private function shouldTrack(string $routeName, ?string $routePattern, float $durationMs, int $statusCode): bool
    {
        if ($durationMs >= $this->slowThreshold() || $statusCode >= 500) {
            return true;
        }

        return $this->isConfiguredRoute($routeName, $routePattern);
    }

    private function isConfiguredRoute(string $routeName, ?string $routePattern): bool
    {
        $trackedRoutes = config('observability.request.tracked_routes', []);
        if (! is_array($trackedRoutes) || $trackedRoutes === []) {
            return false;
        }

        return collect($trackedRoutes)->contains(function ($pattern) use ($routeName, $routePattern) {
            if (! is_string($pattern) || trim($pattern) === '') {
                return false;
            }

            return Str::is($pattern, $routeName)
                || ($routePattern !== null && Str::is($pattern, ltrim($routePattern, '/')));
        });
    }

    /**
     * @return array<int, string>
     */
    private function configuredRouteNames(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route): ?string => is_string($route->getName()) ? $route->getName() : null)
            ->filter(fn (?string $routeName): bool => $routeName !== null
                && $this->isConfiguredRoute($routeName, $this->sanitizer->routePatternForName($routeName)))
            ->unique()
            ->values()
            ->all();
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function samplesKey(string $routeName): string
    {
        return 'requests:samples:'.sha1($routeName);
    }

    private function counterKey(string $routeName, string $metric, Carbon $hour): string
    {
        $bucket = $this->counterBucket($hour);

        return sprintf('requests:counters:%s:%s:%s', sha1($routeName), $metric, $bucket->format('YmdHi'));
    }

    private function scopedCounterKey(string $scopeId, string $routeName, string $metric): string
    {
        return sprintf('requests:scoped:%s:%s:%s', $scopeId, sha1($routeName), $metric);
    }

    private function scopedSampleKey(string $scopeId, string $routeName, int $sequence): string
    {
        return sprintf('requests:scoped-samples:%s:%s:%d', $scopeId, sha1($routeName), $sequence);
    }

    private function scopedScenarioCounterKey(
        string $scopeId,
        string $routeName,
        string $scenarioKey,
        string $metric
    ): string {
        return sprintf(
            'requests:scoped:%s:%s:scenario:%s:%s',
            $scopeId,
            sha1($routeName),
            sha1($scenarioKey),
            $metric
        );
    }

    /**
     * @return array{requests: int, slow: int, errors: int}
     */
    private function counterTotals(string $routeName): array
    {
        $bucketMinutes = $this->counterBucketMinutes();
        $bucket = $this->counterBucket(now());
        $keysByMetric = collect(['requests', 'slow', 'errors'])
            ->mapWithKeys(fn (string $metric): array => [
                $metric => collect(range(0, (int) ceil((24 * 60) / $bucketMinutes)))
                    ->map(fn (int $offset): string => $this->counterKey(
                        $routeName,
                        $metric,
                        $bucket->copy()->subMinutes($offset * $bucketMinutes)
                    ))
                    ->all(),
            ])
            ->all();
        $values = $this->cache->integers(array_merge(...array_values($keysByMetric)));

        return [
            'requests' => array_sum(array_intersect_key($values, array_flip($keysByMetric['requests']))),
            'slow' => array_sum(array_intersect_key($values, array_flip($keysByMetric['slow']))),
            'errors' => array_sum(array_intersect_key($values, array_flip($keysByMetric['errors']))),
        ];
    }

    /**
     * @param  array<string, mixed>  $scope
     * @param  array<int, array<string, mixed>>  $samples
     * @return array{requests: int, slow: int, errors: int}
     */
    private function scopedCounterTotals(string $routeName, array $scope, array $samples): array
    {
        $fallback = [
            'requests' => count($samples),
            'slow' => count(array_filter(
                $samples,
                fn (array $sample): bool => (float) ($sample['duration_ms'] ?? 0) >= $this->slowThreshold()
            )),
            'errors' => count(array_filter(
                $samples,
                static fn (array $sample): bool => (int) ($sample['status_code'] ?? 0) >= 500
            )),
        ];
        $scopeId = $this->scope->idFor($scope);
        if ($scopeId === null) {
            return $fallback;
        }

        $keys = collect(['requests', 'slow', 'errors'])
            ->mapWithKeys(fn (string $metric): array => [
                $metric => $this->scopedCounterKey($scopeId, $routeName, $metric),
            ])
            ->all();
        $values = $this->cache->integers(array_values($keys));

        return [
            'requests' => max($fallback['requests'], $values[$keys['requests']] ?? 0),
            'slow' => max($fallback['slow'], $values[$keys['slow']] ?? 0),
            'errors' => max($fallback['errors'], $values[$keys['errors']] ?? 0),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $samples
     * @return array{requests: int, slow: int, errors: int}
     */
    private function scopedScenarioCounterTotals(
        string $scopeId,
        string $routeName,
        string $scenarioKey,
        array $samples
    ): array {
        $fallback = [
            'requests' => count($samples),
            'slow' => count(array_filter(
                $samples,
                fn (array $sample): bool => (float) ($sample['duration_ms'] ?? 0) >= $this->slowThreshold()
            )),
            'errors' => count(array_filter(
                $samples,
                static fn (array $sample): bool => (int) ($sample['status_code'] ?? 0) >= 500
            )),
        ];
        $keys = collect(['requests', 'slow', 'errors'])
            ->mapWithKeys(fn (string $metric): array => [
                $metric => $this->scopedScenarioCounterKey(
                    $scopeId,
                    $routeName,
                    $scenarioKey,
                    $metric
                ),
            ])
            ->all();
        $values = $this->cache->integers(array_values($keys));

        return [
            'requests' => max($fallback['requests'], $values[$keys['requests']] ?? 0),
            'slow' => max($fallback['slow'], $values[$keys['slow']] ?? 0),
            'errors' => max($fallback['errors'], $values[$keys['errors']] ?? 0),
        ];
    }

    private function slowThreshold(): float
    {
        return max(1, (float) config('observability.request.slow_ms', 1200));
    }

    private function sampleSize(): int
    {
        return max(25, (int) config('observability.request.sample_size', 250));
    }

    private function maxScopeSamples(): int
    {
        return max(1, (int) config('observability.request.max_scope_samples', 20_000));
    }

    private function retentionHours(): int
    {
        return max(24, (int) config('observability.request.retention_hours', 24));
    }

    private function counterBucketMinutes(): int
    {
        return min(60, max(1, (int) config('observability.cache.counter_bucket_minutes', 5)));
    }

    private function counterBucket(Carbon $timestamp): Carbon
    {
        $seconds = $this->counterBucketMinutes() * 60;

        return Carbon::createFromTimestampUTC(
            intdiv($timestamp->getTimestamp(), $seconds) * $seconds
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $samples
     * @return array{p50: float|null, p95: float|null, p99: float|null, max: float|null, value_count: int, missing_count: int}
     */
    private function statistics(array $samples, string $key): array
    {
        $values = array_values(array_filter(array_map(
            static fn (array $sample): ?float => is_numeric($sample[$key] ?? null) ? (float) $sample[$key] : null,
            $samples
        ), static fn (?float $value): bool => $value !== null));

        return [
            'p50' => $this->percentile($values, 50),
            'p95' => $this->percentile($values, 95),
            'p99' => $this->percentile($values, 99),
            'max' => $values !== [] ? round(max($values), 1) : null,
            'value_count' => count($values),
            'missing_count' => count($samples) - count($values),
        ];
    }

    /**
     * @param  array<int, float>  $values
     */
    private function percentile(array $values, float $percentile): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);

        $index = ($percentile / 100) * (count($values) - 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);

        if ($lower === $upper) {
            return round($values[$lower], 1);
        }

        $weight = $index - $lower;

        return round($values[$lower] + (($values[$upper] - $values[$lower]) * $weight), 1);
    }

    private function nullableNonNegativeInteger(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, (int) $value) : null;
    }

    private function nullableNonNegativeFloat(mixed $value): ?float
    {
        return is_numeric($value) ? round(max(0, (float) $value), 1) : null;
    }
}
