<?php

namespace App\Services\ServiceRequests;

use App\Models\Request as LeadRequest;
use App\Models\ServiceRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LegacyServiceRequestBackfillVerificationService
{
    public function __construct(
        private readonly LegacyServiceRequestBackfillAnalysisService $analysisService,
    ) {}

    /**
     * @return array{
     *     verification_id:string,
     *     batch_id:string,
     *     account_id:int|null,
     *     generated_at:string,
     *     source_summary_path:string,
     *     report_path:string,
     *     segment_path:string,
     *     source_backfilled_count:int,
     *     source_failed_count:int,
     *     migrated_leads_checked:int,
     *     verified_leads:int,
     *     leads_with_issues:int,
     *     issue_counts:array<string,int>,
     *     remaining_eligible_count:int,
     *     remaining_review_count:int,
     *     remaining_eligible_reason_counts:array<string,int>,
     *     remaining_review_reason_counts:array<string,int>,
     *     remaining_eligible_samples:array<int,array<string,mixed>>,
     *     review_samples:array<int,array<string,mixed>>,
     *     consistency_issue_samples:array<int,array<string,mixed>>,
     *     source_failure_samples:array<int,array<string,mixed>>
     * }
     */
    public function verify(?string $batchId = null, ?int $accountId = null, int $sampleLimit = 10): array
    {
        $sampleLimit = max(1, $sampleLimit);
        $sourceSummary = $this->resolveSourceSummary($batchId, $accountId);
        $resolvedAccountId = is_numeric($accountId)
            ? (int) $accountId
            : (is_numeric($sourceSummary['account_id'] ?? null) ? (int) $sourceSummary['account_id'] : null);

        $verificationId = 'service-request-backfill-verification-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6));
        $sourceSummaryPath = (string) ($sourceSummary['summary_path'] ?? '');
        $journalDirectory = $sourceSummaryPath !== ''
            ? str_replace('\\', '/', dirname($sourceSummaryPath))
            : 'service-request-backfills/'.$verificationId;
        $reportPath = $journalDirectory.'/verification.json';
        $segmentPath = $journalDirectory.'/verification-segments.csv';

        $segmentHandle = fopen('php://temp', 'w+');
        fputcsv($segmentHandle, [
            'segment_type',
            'legacy_request_id',
            'lead_name',
            'reason',
            'issue_codes',
            'details',
        ]);

        $issueCounts = [];
        $consistencyIssueSamples = [];
        $verifiedLeads = 0;
        $leadsWithIssues = 0;

        foreach ((array) ($sourceSummary['results'] ?? []) as $row) {
            $issues = $this->verifyBackfilledLead($row);

            if ($issues['issue_codes'] === []) {
                $verifiedLeads++;

                continue;
            }

            $leadsWithIssues++;

            foreach ((array) $issues['issue_codes'] as $issueCode) {
                $issueCounts[$issueCode] = (int) ($issueCounts[$issueCode] ?? 0) + 1;
            }

            if (count($consistencyIssueSamples) < $sampleLimit) {
                $consistencyIssueSamples[] = $issues;
            }

            fputcsv($segmentHandle, [
                'needs_correction',
                (int) ($issues['legacy_request_id'] ?? 0),
                (string) ($issues['lead_name'] ?? 'Lead'),
                (string) ($issues['reason'] ?? 'migration'),
                implode('|', (array) ($issues['issue_codes'] ?? [])),
                json_encode($issues['details'] ?? [], JSON_UNESCAPED_SLASHES),
            ]);
        }

        ksort($issueCounts);

        $remaining = $this->collectRemainingSegments($resolvedAccountId, $sampleLimit, $segmentHandle);

        $report = [
            'verification_id' => $verificationId,
            'batch_id' => (string) ($sourceSummary['batch_id'] ?? 'unknown-batch'),
            'account_id' => $resolvedAccountId,
            'generated_at' => now()->toISOString(),
            'source_summary_path' => $sourceSummaryPath,
            'report_path' => $reportPath,
            'segment_path' => $segmentPath,
            'source_backfilled_count' => (int) ($sourceSummary['backfilled_count'] ?? 0),
            'source_failed_count' => (int) ($sourceSummary['failed_count'] ?? 0),
            'migrated_leads_checked' => count((array) ($sourceSummary['results'] ?? [])),
            'verified_leads' => $verifiedLeads,
            'leads_with_issues' => $leadsWithIssues,
            'issue_counts' => $issueCounts,
            'remaining_eligible_count' => (int) ($remaining['eligible_count'] ?? 0),
            'remaining_review_count' => (int) ($remaining['review_count'] ?? 0),
            'remaining_eligible_reason_counts' => (array) ($remaining['eligible_reason_counts'] ?? []),
            'remaining_review_reason_counts' => (array) ($remaining['review_reason_counts'] ?? []),
            'remaining_eligible_samples' => (array) ($remaining['eligible_samples'] ?? []),
            'review_samples' => (array) ($remaining['review_samples'] ?? []),
            'consistency_issue_samples' => $consistencyIssueSamples,
            'source_failure_samples' => array_slice((array) ($sourceSummary['failures'] ?? []), 0, $sampleLimit),
        ];

        rewind($segmentHandle);
        $segmentContents = stream_get_contents($segmentHandle) ?: '';
        fclose($segmentHandle);

        Storage::disk('local')->put($segmentPath, $segmentContents);
        Storage::disk('local')->put($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

        return $report;
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array{
     *     legacy_request_id:int,
     *     lead_name:string,
     *     reason:string,
     *     issue_codes:array<int,string>,
     *     details:array<string,mixed>
     * }
     */
    private function verifyBackfilledLead(array $row): array
    {
        $legacyRequestId = (int) ($row['legacy_request_id'] ?? 0);
        $lead = LeadRequest::query()->find($legacyRequestId);
        $serviceRequestId = (int) ($row['service_request_id'] ?? 0);
        $serviceRequest = ServiceRequest::query()->find($serviceRequestId);
        $reason = (string) ($row['reason'] ?? 'eligible');

        $issues = [];
        $details = [
            'legacy_request_id' => $legacyRequestId,
            'service_request_id' => $serviceRequestId,
        ];

        if (! $lead) {
            $issues[] = 'missing_legacy_request';

            return [
                'legacy_request_id' => $legacyRequestId,
                'lead_name' => 'Lead #'.$legacyRequestId,
                'reason' => $reason,
                'issue_codes' => $issues,
                'details' => $details,
            ];
        }

        if (! $serviceRequest) {
            $issues[] = 'missing_service_request';
        } else {
            if ((int) ($serviceRequest->prospect_id ?? 0) !== $legacyRequestId) {
                $issues[] = 'service_request_not_linked_to_legacy_request';
                $details['actual_prospect_id'] = $serviceRequest->prospect_id;
            }

            if ((string) ($serviceRequest->source_ref ?? '') !== 'lead:'.$legacyRequestId) {
                $issues[] = 'service_request_source_ref_mismatch';
                $details['actual_source_ref'] = $serviceRequest->source_ref;
            }

            $backfillBatchId = data_get($serviceRequest->meta, 'legacy_backfill.batch_id');
            if (! is_string($backfillBatchId) || trim($backfillBatchId) === '') {
                $issues[] = 'service_request_missing_backfill_meta';
            }

            if (
                $lead->created_at
                && $serviceRequest->created_at
                && $lead->created_at->getTimestamp() !== $serviceRequest->created_at->getTimestamp()
            ) {
                $issues[] = 'service_request_created_at_mismatch';
            }

            if (
                $lead->updated_at
                && $serviceRequest->updated_at
                && $lead->updated_at->getTimestamp() !== $serviceRequest->updated_at->getTimestamp()
            ) {
                $issues[] = 'service_request_updated_at_mismatch';
            }
        }

        $duplicateCount = ServiceRequest::query()
            ->where('prospect_id', $legacyRequestId)
            ->count();

        if ($duplicateCount > 1) {
            $issues[] = 'duplicate_service_requests_for_legacy_request';
            $details['duplicate_count'] = $duplicateCount;
        }

        return [
            'legacy_request_id' => $legacyRequestId,
            'lead_name' => $this->analysisService->displayNameForLead($lead),
            'reason' => $reason,
            'issue_codes' => collect($issues)->unique()->values()->all(),
            'details' => $details,
        ];
    }

    /**
     * @param  resource  $segmentHandle
     * @return array{
     *     eligible_count:int,
     *     review_count:int,
     *     eligible_reason_counts:array<string,int>,
     *     review_reason_counts:array<string,int>,
     *     eligible_samples:array<int,array<string,mixed>>,
     *     review_samples:array<int,array<string,mixed>>
     * }
     */
    private function collectRemainingSegments(?int $accountId, int $sampleLimit, $segmentHandle): array
    {
        $summary = [
            'eligible_count' => 0,
            'review_count' => 0,
            'eligible_reason_counts' => [],
            'review_reason_counts' => [],
            'eligible_samples' => [],
            'review_samples' => [],
        ];

        $this->analysisService->query($accountId)
            ->orderBy('requests.id')
            ->chunkById(200, function (Collection $leads) use (&$summary, $sampleLimit, $segmentHandle): void {
                foreach ($leads as $lead) {
                    $classification = $this->analysisService->classifyLead($lead);
                    $bucket = (string) ($classification['bucket'] ?? '');

                    if (! in_array($bucket, ['eligible', 'review'], true)) {
                        continue;
                    }

                    $reason = (string) ($classification['reason'] ?? $bucket);
                    $sample = [
                        'id' => (int) $lead->id,
                        'name' => $this->analysisService->displayNameForLead($lead),
                        'reason' => $reason,
                        'signals' => $classification['signals'] ?? [],
                    ];

                    $summary[$bucket.'_count']++;
                    $reasonKey = $bucket.'_reason_counts';
                    $summary[$reasonKey][$reason] = (int) ($summary[$reasonKey][$reason] ?? 0) + 1;

                    if (count($summary[$bucket.'_samples']) < $sampleLimit) {
                        $summary[$bucket.'_samples'][] = $sample;
                    }

                    fputcsv($segmentHandle, [
                        $bucket === 'eligible' ? 'remaining_eligible' : 'needs_review',
                        (int) $lead->id,
                        (string) $sample['name'],
                        $reason,
                        '',
                        json_encode($sample['signals'] ?? [], JSON_UNESCAPED_SLASHES),
                    ]);
                }
            }, 'requests.id', 'id');

        ksort($summary['eligible_reason_counts']);
        ksort($summary['review_reason_counts']);

        return $summary;
    }

    /**
     * @return array<string,mixed>
     */
    private function resolveSourceSummary(?string $batchId, ?int $accountId): array
    {
        $candidates = collect(Storage::disk('local')->allFiles('service-request-backfills'))
            ->filter(fn (string $path): bool => Str::endsWith(str_replace('\\', '/', $path), '/summary.json'))
            ->map(function (string $path): ?array {
                $payload = json_decode((string) Storage::disk('local')->get($path), true);

                if (! is_array($payload)) {
                    return null;
                }

                $payload['summary_path'] = $payload['summary_path'] ?? str_replace('\\', '/', $path);

                return $payload;
            })
            ->filter()
            ->when($batchId, fn (Collection $rows) => $rows->filter(
                fn (array $row): bool => (string) ($row['batch_id'] ?? '') === $batchId
            ))
            ->when(is_numeric($accountId), fn (Collection $rows) => $rows->filter(
                fn (array $row): bool => (int) ($row['account_id'] ?? 0) === (int) $accountId
            ))
            ->sortByDesc(fn (array $row): string => (string) ($row['started_at'] ?? ''))
            ->values();

        /** @var array<string,mixed>|null $summary */
        $summary = $candidates->first();

        if (! $summary) {
            throw new \RuntimeException('No service request backfill summary could be found for this scope.');
        }

        return $summary;
    }
}
