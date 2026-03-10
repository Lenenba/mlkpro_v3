<?php

namespace App\Services\Observability;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class RequestMetricsService
{
    private const ROUTE_INDEX_KEY = 'observability:requests:index';

    public function __construct(
        private readonly ObservabilityCacheStore $cache,
        private readonly ObservabilityLogService $logger
    ) {}

    public function record(Request $request, int $statusCode, float $durationMs): void
    {
        $routeName = $request->route()?->getName() ?: strtoupper($request->method()).' '.$request->path();

        $this->recordRouteSample($routeName, $durationMs, $statusCode, [
            'method' => strtoupper($request->method()),
            'path' => '/'.ltrim($request->path(), '/'),
            'route_name' => $routeName,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function recordRouteSample(string $routeName, float $durationMs, int $statusCode, array $context = []): void
    {
        if (! config('observability.enabled', true)) {
            return;
        }

        $routeName = trim($routeName) !== '' ? $routeName : 'unknown';
        $sample = [
            'route_name' => $routeName,
            'duration_ms' => round($durationMs, 1),
            'status_code' => $statusCode,
            'method' => (string) ($context['method'] ?? 'GET'),
            'path' => (string) ($context['path'] ?? ''),
            'recorded_at' => now()->toIso8601String(),
        ];

        if (! $this->shouldTrack($routeName, $sample['path'], $sample['duration_ms'], $statusCode)) {
            return;
        }

        $retentionHours = $this->retentionHours();
        $this->cache->append($this->samplesKey($routeName), $sample, $this->sampleSize(), $retentionHours);
        $this->cache->addIndexValue(self::ROUTE_INDEX_KEY, $routeName, $retentionHours);

        if ($sample['duration_ms'] >= $this->slowThreshold()) {
            $this->logger->warning('slow_request', [
                'route_name' => $routeName,
                'method' => $sample['method'],
                'path' => $sample['path'],
                'status_code' => $statusCode,
                'duration_ms' => $sample['duration_ms'],
            ]);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function summary(): array
    {
        return collect($this->cache->indexValues(self::ROUTE_INDEX_KEY))
            ->map(fn (string $routeName) => $this->routeSummary($routeName))
            ->filter()
            ->sortByDesc(fn (array $route) => [(float) ($route['p95_ms'] ?? 0), (int) ($route['count_24h'] ?? 0)])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function routeSummary(string $routeName): ?array
    {
        $samples = $this->freshSamples($routeName);
        if ($samples === []) {
            return null;
        }

        $durations = array_values(array_map(fn (array $sample) => (float) ($sample['duration_ms'] ?? 0), $samples));
        $statuses = array_values(array_map(fn (array $sample) => (int) ($sample['status_code'] ?? 0), $samples));
        $lastSample = end($samples) ?: null;

        return [
            'route_name' => $routeName,
            'count_24h' => count($samples),
            'slow_count_24h' => count(array_filter($durations, fn (float $duration) => $duration >= $this->slowThreshold())),
            'error_count_24h' => count(array_filter($statuses, fn (int $status) => $status >= 500)),
            'p50_ms' => $this->percentile($durations, 50),
            'p95_ms' => $this->percentile($durations, 95),
            'p99_ms' => $this->percentile($durations, 99),
            'max_ms' => round(max($durations), 1),
            'last_seen_at' => is_array($lastSample) ? ($lastSample['recorded_at'] ?? null) : null,
            'method' => is_array($lastSample) ? ($lastSample['method'] ?? null) : null,
            'path' => is_array($lastSample) ? ($lastSample['path'] ?? null) : null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function freshSamples(string $routeName): array
    {
        $cutoff = now()->subHours($this->retentionHours());

        return array_values(array_filter(
            $this->cache->get($this->samplesKey($routeName)),
            static function (array $sample) use ($cutoff): bool {
                $recordedAt = $sample['recorded_at'] ?? null;
                if (! is_string($recordedAt) || $recordedAt === '') {
                    return false;
                }

                return Carbon::parse($recordedAt)->greaterThanOrEqualTo($cutoff);
            }
        ));
    }

    private function shouldTrack(string $routeName, string $path, float $durationMs, int $statusCode): bool
    {
        if ($durationMs >= $this->slowThreshold() || $statusCode >= 500) {
            return true;
        }

        $trackedRoutes = config('observability.request.tracked_routes', []);
        if (! is_array($trackedRoutes) || $trackedRoutes === []) {
            return false;
        }

        return collect($trackedRoutes)->contains(function ($pattern) use ($routeName, $path) {
            if (! is_string($pattern) || trim($pattern) === '') {
                return false;
            }

            return Str::is($pattern, $routeName) || ($path !== '' && Str::is($pattern, ltrim($path, '/')));
        });
    }

    private function samplesKey(string $routeName): string
    {
        return 'observability:requests:'.sha1($routeName);
    }

    private function slowThreshold(): float
    {
        return max(1, (float) config('observability.request.slow_ms', 1200));
    }

    private function sampleSize(): int
    {
        return max(25, (int) config('observability.request.sample_size', 250));
    }

    private function retentionHours(): int
    {
        return max(1, (int) config('observability.request.retention_hours', 24));
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
