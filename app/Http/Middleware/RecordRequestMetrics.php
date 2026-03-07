<?php

namespace App\Http\Middleware;

use App\Services\Observability\RequestMetricsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RecordRequestMetrics
{
    public function __construct(
        private readonly RequestMetricsService $metrics
    ) {}

    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = hrtime(true);
        $response = $next($request);
        $durationMs = (hrtime(true) - $startedAt) / 1_000_000;

        $this->metrics->record($request, $response->getStatusCode(), $durationMs);

        return $response;
    }
}
