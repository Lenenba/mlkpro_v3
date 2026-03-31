<?php

namespace App\Jobs;

use App\Models\ActivityLog;
use App\Models\PlanScan;
use App\Models\User;
use App\Services\PlanScanAiPipelineService;
use App\Services\PlanScanService;
use App\Support\QueueWorkload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AnalyzePlanScanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /**
     * @param  array<string, mixed>  $metrics
     */
    public function __construct(
        public int $scanId,
        public int $actorUserId,
        public array $metrics = [],
        public array $options = []
    ) {
        $this->onQueue(QueueWorkload::queue('plan_scans', 'plan-scans'));
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return QueueWorkload::backoff('plan_scans', [60, 300, 900]);
    }

    public function handle(
        PlanScanService $planScanService,
        PlanScanAiPipelineService $pipeline
    ): void {
        $scan = PlanScan::query()->find($this->scanId);
        $actor = User::query()->find($this->actorUserId);

        if (! $scan) {
            return;
        }

        $mode = (string) ($this->options['mode'] ?? 'initial');
        $retryCount = (int) ($scan->ai_retry_count ?? 0);
        if (in_array($mode, ['retry', 'escalate'], true)) {
            $retryCount++;
        }

        $scan->update([
            'status' => PlanScanService::STATUS_PROCESSING,
            'ai_status' => config('services.openai.key')
                ? ($mode === 'escalate' ? 'escalating' : 'extracting')
                : 'skipped',
            'ai_retry_count' => $retryCount,
            'ai_last_requested_at' => now(),
            'ai_escalated_at' => $mode === 'escalate' ? now() : $scan->ai_escalated_at,
            'error_message' => null,
        ]);

        $extraction = $pipeline->run($scan, $this->metrics, $this->options);
        $normalized = is_array($extraction['normalized'] ?? null) ? $extraction['normalized'] : [];
        $effectiveMetrics = $this->effectiveMetrics($normalized, $this->metrics);
        $reviewedPayload = $this->initialReviewedPayload($scan, $normalized, $effectiveMetrics);

        try {
            $analysis = $planScanService->analyze(
                $scan,
                $effectiveMetrics,
                $effectiveMetrics['priority'] ?? null,
                $reviewedPayload
            );
            $analysisPayload = is_array($analysis['analysis'] ?? null) ? $analysis['analysis'] : [];
            $analysisPayload['summary'] = ($extraction['status'] ?? null) === 'completed'
                ? 'Estimation enrichie par une lecture IA du plan, des metriques detectees et des lignes initiales a revoir.'
                : 'Estimation basee sur le plan importe et les metriques disponibles.';
            $analysisPayload['assumptions'] = array_values(array_unique(array_merge(
                is_array($analysisPayload['assumptions'] ?? null) ? $analysisPayload['assumptions'] : [],
                is_array($normalized['assumptions'] ?? null) ? $normalized['assumptions'] : []
            )));
            $analysisPayload['ai'] = [
                'status' => $extraction['status'] ?? null,
                'model' => $extraction['model'] ?? null,
                'review_required' => (bool) ($extraction['review_required'] ?? false),
                'cache_hit' => (bool) ($extraction['cache_hit'] ?? false),
                'cache_source' => $extraction['cache_source'] ?? null,
                'estimated_cost_usd' => $extraction['estimated_cost_usd'] ?? null,
                'attempts' => $extraction['attempts'] ?? [],
                'trade_guess' => $normalized['trade_guess'] ?? null,
                'confidence' => $normalized['confidence'] ?? [],
                'review_flags' => $normalized['review_flags'] ?? [],
                'field_flags' => $normalized['field_flags'] ?? [],
                'recommended_action' => $normalized['recommended_action'] ?? null,
                'requested_mode' => $mode,
            ];

            $scan->update([
                'status' => PlanScanService::STATUS_READY,
                'ai_status' => $extraction['status'] ?? 'skipped',
                'ai_model' => $extraction['model'] ?? null,
                'ai_cache_key' => $extraction['cache_key'] ?? null,
                'ai_cache_hit' => (bool) ($extraction['cache_hit'] ?? false),
                'ai_cache_source' => $extraction['cache_source'] ?? null,
                'ai_usage' => $extraction['usage'] ?? null,
                'ai_attempts' => $extraction['attempts'] ?? null,
                'ai_estimated_cost_usd' => $extraction['estimated_cost_usd'] ?? null,
                'ai_extraction_raw' => $extraction['raw'] ?? null,
                'ai_extraction_normalized' => $normalized ?: null,
                'ai_reviewed_payload' => $reviewedPayload ?: null,
                'ai_review_required' => (bool) ($extraction['review_required'] ?? false),
                'ai_retry_count' => $retryCount,
                'ai_last_requested_at' => $scan->ai_last_requested_at ?? now(),
                'ai_escalated_at' => $mode === 'escalate' ? now() : $scan->ai_escalated_at,
                'ai_failed_at' => ($extraction['status'] ?? null) === 'failed' ? now() : null,
                'ai_error_message' => $extraction['error_message'] ?? null,
                'metrics' => $analysis['metrics'],
                'analysis' => $analysisPayload,
                'variants' => $analysis['variants'],
                'confidence_score' => $this->resolveConfidenceScore(
                    (int) ($analysis['confidence_score'] ?? 0),
                    (int) data_get($normalized, 'confidence.overall', 0),
                    (bool) ($extraction['review_required'] ?? false)
                ),
                'error_message' => null,
                'analyzed_at' => now(),
            ]);

            ActivityLog::record($actor, $scan, 'analyzed', [
                'trade_type' => $scan->trade_type,
                'confidence_score' => $scan->confidence_score,
                'ai_status' => $scan->ai_status,
                'ai_model' => $scan->ai_model,
                'ai_cache_hit' => (bool) $scan->ai_cache_hit,
                'ai_estimated_cost_usd' => $scan->ai_estimated_cost_usd,
                'ai_retry_count' => $scan->ai_retry_count,
                'requested_mode' => $mode,
            ], 'Plan scan analyzed');
        } catch (\Throwable $exception) {
            $scan->update([
                'status' => PlanScanService::STATUS_FAILED,
                'ai_status' => $extraction['status'] ?? 'failed',
                'ai_model' => $extraction['model'] ?? null,
                'ai_cache_key' => $extraction['cache_key'] ?? null,
                'ai_cache_hit' => (bool) ($extraction['cache_hit'] ?? false),
                'ai_cache_source' => $extraction['cache_source'] ?? null,
                'ai_usage' => $extraction['usage'] ?? null,
                'ai_attempts' => $extraction['attempts'] ?? null,
                'ai_estimated_cost_usd' => $extraction['estimated_cost_usd'] ?? null,
                'ai_extraction_raw' => $extraction['raw'] ?? null,
                'ai_extraction_normalized' => $normalized ?: null,
                'ai_reviewed_payload' => $reviewedPayload ?: null,
                'ai_review_required' => true,
                'ai_retry_count' => $retryCount,
                'ai_last_requested_at' => $scan->ai_last_requested_at ?? now(),
                'ai_escalated_at' => $mode === 'escalate' ? now() : $scan->ai_escalated_at,
                'ai_failed_at' => now(),
                'ai_error_message' => $extraction['error_message'] ?? null,
                'error_message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<string, mixed>  $manualMetrics
     * @return array<string, mixed>
     */
    private function effectiveMetrics(array $normalized, array $manualMetrics): array
    {
        return [
            'surface_m2' => $manualMetrics['surface_m2']
                ?? data_get($normalized, 'metrics.surface_m2_estimate'),
            'rooms' => $manualMetrics['rooms']
                ?? data_get($normalized, 'metrics.room_count_estimate'),
            'priority' => $manualMetrics['priority'] ?? 'balanced',
        ];
    }

    private function resolveConfidenceScore(int $baseScore, int $aiScore, bool $reviewRequired): int
    {
        $resolved = max($baseScore, $aiScore);

        if ($reviewRequired) {
            $resolved = min($resolved, 89);
        }

        return max(0, min(100, $resolved));
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<string, mixed>  $effectiveMetrics
     * @return array<string, mixed>
     */
    private function initialReviewedPayload(PlanScan $scan, array $normalized, array $effectiveMetrics): array
    {
        return [
            'trade_type' => $scan->trade_type ?: data_get($normalized, 'trade_guess') ?: 'general',
            'metrics' => [
                'surface_m2' => $effectiveMetrics['surface_m2'] ?? null,
                'rooms' => $effectiveMetrics['rooms'] ?? null,
                'priority' => $effectiveMetrics['priority'] ?? 'balanced',
            ],
            'line_items' => is_array($normalized['detected_lines'] ?? null) ? $normalized['detected_lines'] : [],
            'assumptions' => is_array($normalized['assumptions'] ?? null) ? $normalized['assumptions'] : [],
            'review_flags' => is_array($normalized['review_flags'] ?? null) ? $normalized['review_flags'] : [],
            'reviewed_at' => null,
            'updated_by_user_id' => null,
        ];
    }
}
