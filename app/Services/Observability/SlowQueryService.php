<?php

namespace App\Services\Observability;

use App\Services\Capacity\CapacityRunContextService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class SlowQueryService
{
    private const SAMPLE_KEY = 'queries:samples';

    private const ROUTE_INDEX_KEY = 'queries:routes';

    /** @var array<string, int> */
    private array $indexedRoutes = [];

    public function __construct(
        private readonly ObservabilityCacheStore $cache,
        private readonly ObservabilityLogService $logger,
        private readonly TelemetrySanitizer $sanitizer,
        private readonly TelemetryScope $scope,
        private readonly CapacityRunContextService $runContext
    ) {}

    public function recordExecutedQuery(QueryExecuted $query): void
    {
        if (! config('observability.enabled', false) || (float) $query->time < $this->slowThreshold()) {
            return;
        }

        $request = app()->bound('request') ? app('request') : null;
        $routeName = $request instanceof Request ? $request->route()?->getName() : null;

        $this->record($query->sql, (float) $query->time, [
            'connection' => $query->connectionName,
            'bindings_count' => count($query->bindings),
            'route_name' => is_string($routeName) ? $routeName : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function record(string $sql, float $timeMs, array $context = []): void
    {
        if (! config('observability.enabled', false) || $timeMs < $this->slowThreshold()) {
            return;
        }

        $routeName = is_string($context['route_name'] ?? null) && trim((string) $context['route_name']) !== ''
            ? trim((string) $context['route_name'])
            : null;
        $recordedAt = now();
        $sample = [
            ...$this->scope->tags(),
            'scenario_key' => $this->runContext->activeScenarioKey(),
            'time_ms' => round(max(0, $timeMs), 1),
            'query_fingerprint' => $this->sanitizer->queryFingerprint($sql),
            'statement' => $this->sanitizer->statementType($sql),
            'connection' => (string) ($context['connection'] ?? config('database.default')),
            'bindings_count' => max(0, (int) ($context['bindings_count'] ?? 0)),
            'route_name' => $routeName,
            'route_pattern' => $routeName !== null ? $this->sanitizer->routePatternForName($routeName) : null,
            'recorded_at' => $recordedAt->toIso8601String(),
        ];

        $retentionHours = $this->retentionHours();
        $this->cache->increment($this->counterKey(null, $recordedAt), $retentionHours + 1);
        $this->cache->append(self::SAMPLE_KEY, $sample, $this->sampleSize(), $retentionHours);

        if ($routeName !== null) {
            if (($this->indexedRoutes[$routeName] ?? 0) < $recordedAt->timestamp - 3600
                && $this->cache->addIndexValue(self::ROUTE_INDEX_KEY, $routeName, $retentionHours)) {
                $this->indexedRoutes[$routeName] = $recordedAt->timestamp;
            }
            $this->cache->increment($this->counterKey($routeName, $recordedAt), $retentionHours + 1);
            $this->cache->append($this->routeSamplesKey($routeName), $sample, $this->sampleSize(), $retentionHours);
        }

        if (($scopeId = $this->scope->activeId()) !== null) {
            $this->cache->increment($this->scopedCounterKey($scopeId, null), $retentionHours + 1);
            if ($routeName !== null) {
                $this->cache->increment($this->scopedCounterKey($scopeId, $routeName), $retentionHours + 1);
                if (is_string($sample['scenario_key'] ?? null)
                    && trim((string) $sample['scenario_key']) !== '') {
                    $this->cache->increment(
                        $this->scopedScenarioCounterKey(
                            $scopeId,
                            $routeName,
                            trim((string) $sample['scenario_key'])
                        ),
                        $retentionHours + 1
                    );
                }
            }
        }

        $this->logger->warning('slow_query', [
            'time_ms' => $sample['time_ms'],
            'connection' => $sample['connection'],
            'bindings_count' => $sample['bindings_count'],
            'route_name' => $sample['route_name'],
            'route_pattern' => $sample['route_pattern'],
            'statement' => $sample['statement'],
            'query_fingerprint' => $sample['query_fingerprint'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(?array $scope = null): array
    {
        $samples = $this->freshSamples(self::SAMPLE_KEY, $scope);
        $statistics = $this->statistics($samples);
        $count = $scope === null
            ? max($this->sumCounters(null), count($samples))
            : $this->scopedCount(null, $scope, count($samples));

        $routeNames = collect($this->cache->indexValues(self::ROUTE_INDEX_KEY, $this->retentionHours()));
        if (is_array($scope['route_names'] ?? null)) {
            $routeNames = $routeNames->filter(fn (string $routeName): bool => in_array(
                $routeName,
                $scope['route_names'],
                true
            ));
        }

        return [
            'window_hours' => $scope === null ? 24 : null,
            'counter_precision_minutes' => $this->counterBucketMinutes(),
            'count_24h' => $count,
            'sample_count_24h' => count($samples),
            'truncated' => $count > count($samples),
            'p95_ms' => $statistics['p95'],
            'p99_ms' => $statistics['p99'],
            'max_ms' => $statistics['max'],
            'by_route' => $routeNames
                ->map(fn (string $routeName): ?array => $this->routeSummary($routeName, $scope))
                ->filter()
                ->values()
                ->all(),
            'recent' => collect(array_slice($samples, -5))
                ->reverse()
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function routeSummary(string $routeName, ?array $scope): ?array
    {
        $samples = $this->freshSamples($this->routeSamplesKey($routeName), $scope);
        $statistics = $this->statistics($samples);
        $count = $scope === null
            ? max($this->sumCounters($routeName), count($samples))
            : $this->scopedCount($routeName, $scope, count($samples));
        if ($samples === [] && $count === 0) {
            return null;
        }

        return [
            'route_name' => $routeName,
            'route_pattern' => $this->sanitizer->routePatternForName($routeName),
            'count_24h' => $count,
            'sample_count_24h' => count($samples),
            'truncated' => $count > count($samples),
            'p95_ms' => $statistics['p95'],
            'p99_ms' => $statistics['p99'],
            'max_ms' => $statistics['max'],
            'by_scenario' => $scope === null ? [] : $this->scenarioSummaries($routeName, $scope, $samples),
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
            ->map(function ($scenarioSamples, string $scenarioKey) use ($routeName, $scopeId): array {
                $samples = $scenarioSamples->values()->all();
                $statistics = $this->statistics($samples);
                $count = max(
                    count($samples),
                    $this->cache->integer($this->scopedScenarioCounterKey($scopeId, $routeName, $scenarioKey))
                );

                return [
                    'scenario_key' => $scenarioKey,
                    'route_name' => $routeName,
                    'count_24h' => $count,
                    'sample_count_24h' => count($samples),
                    'truncated' => $count > count($samples),
                    'p95_ms' => $statistics['p95'],
                    'p99_ms' => $statistics['p99'],
                    'max_ms' => $statistics['max'],
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function freshSamples(string $key, ?array $scope): array
    {
        return $this->scope->filter($this->cache->get($key), $scope, $this->retentionHours());
    }

    /**
     * @param  array<int, array<string, mixed>>  $samples
     * @return array{p95: float|null, p99: float|null, max: float|null}
     */
    private function statistics(array $samples): array
    {
        $durations = array_values(array_map(fn (array $sample): float => (float) ($sample['time_ms'] ?? 0), $samples));

        return [
            'p95' => $this->percentile($durations, 95),
            'p99' => $this->percentile($durations, 99),
            'max' => $durations !== [] ? round(max($durations), 1) : null,
        ];
    }

    private function counterKey(?string $routeName, Carbon $hour): string
    {
        $scope = $routeName === null ? 'all' : sha1($routeName);
        $bucket = $this->counterBucket($hour);

        return sprintf('queries:counters:%s:%s', $scope, $bucket->format('YmdHi'));
    }

    private function scopedCounterKey(string $scopeId, ?string $routeName): string
    {
        $route = $routeName === null ? 'all' : sha1($routeName);

        return sprintf('queries:scoped:%s:%s', $scopeId, $route);
    }

    private function scopedScenarioCounterKey(string $scopeId, string $routeName, string $scenarioKey): string
    {
        return sprintf('queries:scoped:%s:%s:scenario:%s', $scopeId, sha1($routeName), sha1($scenarioKey));
    }

    /**
     * @param  array<string, mixed>  $scope
     */
    private function scopedCount(?string $routeName, array $scope, int $fallback): int
    {
        $scopeId = $this->scope->idFor($scope);
        if ($scopeId === null) {
            return $fallback;
        }

        return max($fallback, $this->cache->integer($this->scopedCounterKey($scopeId, $routeName)));
    }

    private function sumCounters(?string $routeName): int
    {
        $bucketMinutes = $this->counterBucketMinutes();
        $bucket = $this->counterBucket(now());
        $keys = collect(range(0, (int) ceil((24 * 60) / $bucketMinutes)))
            ->map(fn (int $offset): string => $this->counterKey(
                $routeName,
                $bucket->copy()->subMinutes($offset * $bucketMinutes)
            ))
            ->all();

        return array_sum($this->cache->integers($keys));
    }

    private function routeSamplesKey(string $routeName): string
    {
        return 'queries:route-samples:'.sha1($routeName);
    }

    private function slowThreshold(): float
    {
        return max(1, (float) config('observability.query.slow_ms', 400));
    }

    private function sampleSize(): int
    {
        return max(
            25,
            (int) config('observability.query.sample_size', 150),
            (int) config('observability.alerts.slow_queries_24h', 20) + 1,
            (int) config('capacity.shared.database.max_slow_queries_24h', 50) + 1
        );
    }

    private function retentionHours(): int
    {
        return max(24, (int) config('observability.query.retention_hours', 24));
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
}
