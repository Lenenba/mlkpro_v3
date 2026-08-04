<?php

namespace App\Services\Observability;

use App\Services\Capacity\CapacityRunContextService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

class ErrorMetricsService
{
    private const SAMPLE_KEY = 'errors:samples';

    private const ROUTE_INDEX_KEY = 'errors:routes';

    /** @var array<string, int> */
    private array $indexedRoutes = [];

    public function __construct(
        private readonly ObservabilityCacheStore $cache,
        private readonly ObservabilityLogService $logger,
        private readonly TelemetrySanitizer $sanitizer,
        private readonly TelemetryScope $scope,
        private readonly ExceptionStatusCodeResolver $statusCodes,
        private readonly CapacityRunContextService $runContext
    ) {}

    public function record(Throwable $exception, ?Request $request = null): void
    {
        if (! config('observability.enabled', false)) {
            return;
        }

        $statusCode = $this->statusCodes->resolve($exception);

        if ($statusCode < 500) {
            return;
        }

        $routeName = $request?->route()?->getName();
        $routeName = is_string($routeName) && trim($routeName) !== '' ? $routeName : null;
        $recordedAt = now();
        $sample = [
            ...$this->scope->tags(),
            'scenario_key' => $this->runContext->activeScenarioKey(),
            'exception' => $exception::class,
            'fingerprint' => hash('sha256', implode('|', [
                $exception::class,
                (string) $statusCode,
                $routeName ?? 'unattributed',
            ])),
            'status_code' => $statusCode,
            'route_name' => $routeName,
            'method' => $request?->method(),
            'route_pattern' => $this->sanitizer->routePattern($request),
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

        $this->logger->error('application_error', [
            'exception' => $sample['exception'],
            'fingerprint' => $sample['fingerprint'],
            'status_code' => $statusCode,
            'route_name' => $sample['route_name'],
            'method' => $sample['method'],
            'route_pattern' => $sample['route_pattern'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(?array $scope = null): array
    {
        $samples = $this->freshSamples($scope);
        $count24Hours = $scope === null
            ? max($this->sumCounters(null, 24), count($samples))
            : $this->scopedCount(null, $scope, count($samples));
        try {
            $oneHourAnchor = is_string($scope['ended_at'] ?? null)
                ? Carbon::parse($scope['ended_at'])
                : now();
        } catch (Throwable) {
            $oneHourAnchor = now();
        }
        $oneHourCutoff = $oneHourAnchor->copy()->subHour();
        $countOneHour = count(array_filter($samples, static function (array $sample) use ($oneHourCutoff): bool {
            try {
                return Carbon::parse((string) ($sample['recorded_at'] ?? ''))->greaterThanOrEqualTo($oneHourCutoff);
            } catch (Throwable) {
                return false;
            }
        }));
        $recent = collect(array_slice($samples, -5))
            ->reverse()
            ->values()
            ->all();

        $byException = collect($samples)
            ->groupBy('exception')
            ->map(fn ($items, $exception) => [
                'exception' => $exception,
                'sample_count' => count($items),
            ])
            ->sortByDesc('sample_count')
            ->take(5)
            ->values()
            ->all();

        return [
            'window_hours' => $scope === null ? 24 : null,
            'counter_precision_minutes' => $this->counterBucketMinutes(),
            'count_1h' => $countOneHour,
            'sample_count_1h' => $countOneHour,
            'count_24h' => $count24Hours,
            'sample_count_24h' => count($samples),
            'truncated' => $count24Hours > count($samples),
            'top_exceptions' => $byException,
            'by_route' => collect($this->cache->indexValues(self::ROUTE_INDEX_KEY, $this->retentionHours()))
                ->map(function (string $routeName) use ($scope, $samples): ?array {
                    $routeSamples = array_values(array_filter(
                        $samples,
                        static fn (array $sample): bool => ($sample['route_name'] ?? null) === $routeName
                    ));
                    $count = $scope === null
                        ? max($this->sumCounters($routeName, 24), count($routeSamples))
                        : $this->scopedCount($routeName, $scope, count($routeSamples));
                    if ($routeSamples === [] && $count === 0) {
                        return null;
                    }

                    return [
                        'route_name' => $routeName,
                        'route_pattern' => $this->sanitizer->routePatternForName($routeName),
                        'count_24h' => $count,
                        'sample_count_24h' => count($routeSamples),
                        'truncated' => $count > count($routeSamples),
                        'by_scenario' => $scope === null
                            ? []
                            : $this->scenarioSummaries($routeName, $scope, $routeSamples),
                    ];
                })
                ->filter()
                ->values()
                ->all(),
            'recent' => $recent,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function freshSamples(?array $scope): array
    {
        return $this->scope->filter($this->cache->get(self::SAMPLE_KEY), $scope, $this->retentionHours());
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
                ];
            })
            ->all();
    }

    private function counterKey(?string $routeName, Carbon $hour): string
    {
        $scope = $routeName === null ? 'all' : sha1($routeName);
        $bucket = $this->counterBucket($hour);

        return sprintf('errors:counters:%s:%s', $scope, $bucket->format('YmdHi'));
    }

    private function scopedCounterKey(string $scopeId, ?string $routeName): string
    {
        $route = $routeName === null ? 'all' : sha1($routeName);

        return sprintf('errors:scoped:%s:%s', $scopeId, $route);
    }

    private function scopedScenarioCounterKey(string $scopeId, string $routeName, string $scenarioKey): string
    {
        return sprintf('errors:scoped:%s:%s:scenario:%s', $scopeId, sha1($routeName), sha1($scenarioKey));
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

    private function sumCounters(?string $routeName, int $hours): int
    {
        $bucketMinutes = $this->counterBucketMinutes();
        $bucket = $this->counterBucket(now());
        $keys = collect(range(0, (int) ceil(($hours * 60) / $bucketMinutes)))
            ->map(fn (int $offset): string => $this->counterKey(
                $routeName,
                $bucket->copy()->subMinutes($offset * $bucketMinutes)
            ))
            ->all();

        return array_sum($this->cache->integers($keys));
    }

    private function sampleSize(): int
    {
        return max(
            25,
            (int) config('observability.error.sample_size', 150),
            (int) config('observability.alerts.errors_1h', 10) + 1,
            (int) config('capacity.shared.app.max_errors_1h', 2) + 1
        );
    }

    private function retentionHours(): int
    {
        return max(24, (int) config('observability.error.retention_hours', 24));
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
}
