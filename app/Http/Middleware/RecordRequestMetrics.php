<?php

namespace App\Http\Middleware;

use App\Services\Capacity\CapacityOutcomeClassifier;
use App\Services\Observability\ExceptionStatusCodeResolver;
use App\Services\Observability\RequestMetricsService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class RecordRequestMetrics
{
    public function __construct(
        private readonly RequestMetricsService $metrics,
        private readonly ExceptionStatusCodeResolver $statusCodes,
        private readonly CapacityOutcomeClassifier $outcomes
    ) {}

    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('observability.enabled', false)) {
            return $next($request);
        }

        $startedAt = hrtime(true);
        $this->metrics->beginRequest($request);

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $this->recordSafely(
                $request,
                $this->statusCodes->resolve($exception),
                $this->duration($startedAt),
                null,
                false
            );

            throw $exception;
        }

        $this->recordSafely(
            $request,
            $response->getStatusCode(),
            $this->duration($startedAt),
            $response,
            true
        );

        return $response;
    }

    private function recordSafely(
        Request $request,
        int $statusCode,
        float $durationMs,
        ?Response $response,
        bool $deferred
    ): void {
        try {
            $queryMetrics = $this->metrics->finishRequest($request);
            $responseBodyBytes = $response === null ? null : $this->responseBodyBytes($request, $response);
            $scenarioKey = is_string($queryMetrics['scenario_key'] ?? null)
                ? $queryMetrics['scenario_key']
                : null;
            $businessSuccess = $scenarioKey === null
                ? null
                : $this->outcomes->classify($request, $statusCode, $response, $scenarioKey);

            if ($deferred) {
                defer(
                    fn () => $this->writeSafely(
                        $request,
                        $statusCode,
                        $durationMs,
                        $responseBodyBytes,
                        $queryMetrics,
                        $businessSuccess
                    ),
                    always: true
                );

                return;
            }

            $this->writeSafely(
                $request,
                $statusCode,
                $durationMs,
                $responseBodyBytes,
                $queryMetrics,
                $businessSuccess
            );
        } catch (Throwable $exception) {
            $this->metrics->discardRequest($request);

            $this->logFailure($exception);
        }
    }

    /**
     * @param  array{query_count: int, query_time_ms: float, scenario_key: string|null, scope_tags: array<string, mixed>}  $queryMetrics
     */
    private function writeSafely(
        Request $request,
        int $statusCode,
        float $durationMs,
        ?int $responseBodyBytes,
        array $queryMetrics,
        ?bool $businessSuccess
    ): void {
        try {
            $this->metrics->record(
                $request,
                $statusCode,
                $durationMs,
                $responseBodyBytes,
                $queryMetrics,
                $businessSuccess
            );
        } catch (Throwable $exception) {
            $this->logFailure($exception);
        }
    }

    private function logFailure(Throwable $exception): void
    {
        try {
            Log::warning('request_observability_failed', [
                'exception' => $exception::class,
            ]);
        } catch (Throwable) {
            // Telemetry is strictly best-effort.
        }
    }

    private function responseBodyBytes(Request $request, Response $response): ?int
    {
        if ($request->isMethod('HEAD') || in_array($response->getStatusCode(), [204, 304], true)) {
            return 0;
        }

        $contentLength = $response->headers->get('Content-Length');
        if (is_string($contentLength) && ctype_digit($contentLength)) {
            return (int) $contentLength;
        }

        if ($response instanceof BinaryFileResponse) {
            $size = $response->getFile()->getSize();

            return is_int($size) ? max(0, $size) : null;
        }
        if ($response instanceof StreamedResponse) {
            return null;
        }

        $content = $response->getContent();

        return is_string($content) ? strlen($content) : null;
    }

    private function duration(int $startedAt): float
    {
        return (hrtime(true) - $startedAt) / 1_000_000;
    }
}
