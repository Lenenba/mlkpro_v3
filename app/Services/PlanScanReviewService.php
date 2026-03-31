<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\PlanScan;
use App\Models\User;

class PlanScanReviewService
{
    public function __construct(private readonly PlanScanService $planScanService) {}

    /**
     * @param  array<string, mixed>  $reviewedPayload
     * @param  array<string, mixed>  $options
     */
    public function applyReviewedPayload(
        PlanScan $planScan,
        User $user,
        array $reviewedPayload,
        array $options = []
    ): PlanScan {
        $planScan->trade_type = (string) ($reviewedPayload['trade_type'] ?? $planScan->trade_type ?? 'general');
        $metrics = is_array($reviewedPayload['metrics'] ?? null) ? $reviewedPayload['metrics'] : [];

        $analysis = $this->planScanService->analyze(
            $planScan,
            $metrics,
            $metrics['priority'] ?? null,
            $reviewedPayload
        );

        $analysisPayload = is_array($analysis['analysis'] ?? null) ? $analysis['analysis'] : [];
        $analysisPayload['summary'] = (string) ($options['summary'] ?? 'Estimation mise a jour a partir du payload de revue confirme.');
        $analysisPayload['ai'] = array_merge(
            is_array($analysisPayload['ai'] ?? null) ? $analysisPayload['ai'] : [],
            [
                'reviewed' => true,
                'reviewed_at' => $reviewedPayload['reviewed_at'] ?? now()->toIso8601String(),
                'reviewed_line_count' => count($reviewedPayload['line_items'] ?? []),
                'review_source' => $options['source'] ?? 'manual_ui',
                'assistant_instruction' => $options['assistant_instruction'] ?? null,
            ]
        );

        $planScan->update([
            'trade_type' => $planScan->trade_type,
            'ai_reviewed_payload' => $reviewedPayload,
            'ai_review_required' => (bool) ($options['ai_review_required'] ?? false),
            'metrics' => $analysis['metrics'],
            'analysis' => $analysisPayload,
            'variants' => $analysis['variants'],
            'confidence_score' => max(
                (int) ($planScan->confidence_score ?? 0),
                min(95, (int) ($analysis['confidence_score'] ?? 0) + 3)
            ),
            'analyzed_at' => now(),
        ]);

        ActivityLog::record($user, $planScan, (string) ($options['activity'] ?? 'reviewed'), [
            'trade_type' => $planScan->trade_type,
            'reviewed_line_count' => count($reviewedPayload['line_items'] ?? []),
            'source' => $options['source'] ?? 'manual_ui',
        ], (string) ($options['activity_message'] ?? 'Plan scan review saved'));

        return $planScan->fresh(['customer', 'property']);
    }
}
