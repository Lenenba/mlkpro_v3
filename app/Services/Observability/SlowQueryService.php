<?php

namespace App\Services\Observability;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class SlowQueryService
{
    private const SAMPLE_KEY = 'observability:queries:samples';

    public function __construct(
        private readonly ObservabilityCacheStore $cache,
        private readonly ObservabilityLogService $logger
    ) {}

    public function recordExecutedQuery(QueryExecuted $query): void
    {
        $this->record($query->sql, (float) $query->time, [
            'connection' => $query->connectionName,
            'bindings_count' => count($query->bindings),
            'route_name' => request()?->route()?->getName(),
            'path' => request()?->path(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function record(string $sql, float $timeMs, array $context = []): void
    {
        if (! config('observability.enabled', true)) {
            return;
        }

        $threshold = $this->slowThreshold();
        if ($timeMs < $threshold) {
            return;
        }

        $sample = [
            'time_ms' => round($timeMs, 1),
            'sql' => Str::limit(trim(preg_replace('/\s+/', ' ', $sql) ?? ''), 240),
            'connection' => (string) ($context['connection'] ?? config('database.default')),
            'bindings_count' => (int) ($context['bindings_count'] ?? 0),
            'route_name' => $context['route_name'] ?? null,
            'path' => isset($context['path']) ? '/'.ltrim((string) $context['path'], '/') : null,
            'recorded_at' => now()->toIso8601String(),
        ];

        $this->cache->append(self::SAMPLE_KEY, $sample, $this->sampleSize(), $this->retentionHours());

        $this->logger->warning('slow_query', [
            'time_ms' => $sample['time_ms'],
            'connection' => $sample['connection'],
            'bindings_count' => $sample['bindings_count'],
            'route_name' => $sample['route_name'],
            'path' => $sample['path'],
            'sql' => $sample['sql'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $samples = $this->freshSamples();
        $durations = array_values(array_map(fn (array $sample) => (float) ($sample['time_ms'] ?? 0), $samples));

        return [
            'count_24h' => count($samples),
            'p95_ms' => $this->percentile($durations, 95),
            'p99_ms' => $this->percentile($durations, 99),
            'max_ms' => $durations !== [] ? round(max($durations), 1) : null,
            'recent' => collect(array_slice($samples, -5))
                ->reverse()
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function freshSamples(): array
    {
        $cutoff = now()->subHours($this->retentionHours());

        return array_values(array_filter(
            $this->cache->get(self::SAMPLE_KEY),
            static function (array $sample) use ($cutoff): bool {
                $recordedAt = $sample['recorded_at'] ?? null;
                if (! is_string($recordedAt) || $recordedAt === '') {
                    return false;
                }

                return Carbon::parse($recordedAt)->greaterThanOrEqualTo($cutoff);
            }
        ));
    }

    private function slowThreshold(): float
    {
        return max(1, (float) config('observability.query.slow_ms', 400));
    }

    private function sampleSize(): int
    {
        return max(25, (int) config('observability.query.sample_size', 150));
    }

    private function retentionHours(): int
    {
        return max(1, (int) config('observability.query.retention_hours', 24));
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
