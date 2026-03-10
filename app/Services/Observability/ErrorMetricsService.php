<?php

namespace App\Services\Observability;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ErrorMetricsService
{
    private const SAMPLE_KEY = 'observability:errors:samples';

    public function __construct(
        private readonly ObservabilityCacheStore $cache,
        private readonly ObservabilityLogService $logger
    ) {}

    public function record(Throwable $exception, ?Request $request = null): void
    {
        if (! config('observability.enabled', true)) {
            return;
        }

        $statusCode = $exception instanceof HttpExceptionInterface
            ? (int) $exception->getStatusCode()
            : 500;

        if ($statusCode < 500) {
            return;
        }

        $sample = [
            'exception' => $exception::class,
            'message' => mb_substr(trim($exception->getMessage()), 0, 240),
            'status_code' => $statusCode,
            'route_name' => $request?->route()?->getName(),
            'method' => $request?->method(),
            'path' => $request ? '/'.ltrim($request->path(), '/') : null,
            'recorded_at' => now()->toIso8601String(),
        ];

        $this->cache->append(self::SAMPLE_KEY, $sample, $this->sampleSize(), $this->retentionHours());

        $this->logger->error('application_error', [
            'exception' => $sample['exception'],
            'status_code' => $statusCode,
            'route_name' => $sample['route_name'],
            'method' => $sample['method'],
            'path' => $sample['path'],
            'message' => $sample['message'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $samples = $this->freshSamples();
        $lastHourCutoff = now()->subHour();
        $recent = collect(array_slice($samples, -5))
            ->reverse()
            ->values()
            ->all();

        $byException = collect($samples)
            ->groupBy('exception')
            ->map(fn ($items, $exception) => [
                'exception' => $exception,
                'count' => count($items),
            ])
            ->sortByDesc('count')
            ->take(5)
            ->values()
            ->all();

        $errorsLastHour = count(array_filter($samples, static function (array $sample) use ($lastHourCutoff): bool {
            $recordedAt = $sample['recorded_at'] ?? null;
            if (! is_string($recordedAt) || $recordedAt === '') {
                return false;
            }

            return Carbon::parse($recordedAt)->greaterThanOrEqualTo($lastHourCutoff);
        }));

        return [
            'count_1h' => $errorsLastHour,
            'count_24h' => count($samples),
            'top_exceptions' => $byException,
            'recent' => $recent,
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

    private function sampleSize(): int
    {
        return max(25, (int) config('observability.error.sample_size', 150));
    }

    private function retentionHours(): int
    {
        return max(1, (int) config('observability.error.retention_hours', 24));
    }
}
