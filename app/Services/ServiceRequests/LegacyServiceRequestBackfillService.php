<?php

namespace App\Services\ServiceRequests;

use App\Models\ActivityLog;
use App\Models\Request as LeadRequest;
use App\Models\ServiceRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class LegacyServiceRequestBackfillService
{
    public const ACTION_SERVICE_REQUEST_BACKFILLED = 'legacy_service_request_backfilled';

    public const ACTION_LEAD_LINKED = 'legacy_request_linked_to_service_request';

    public function __construct(
        private readonly LegacyServiceRequestBackfillAnalysisService $analysisService,
        private readonly ServiceRequestIntakeService $intakeService,
    ) {}

    /**
     * @return array{
     *     batch_id:string,
     *     account_id:int|null,
     *     started_at:string,
     *     completed_at:string,
     *     journal_directory:string,
     *     summary_path:string,
     *     mapping_path:string,
     *     scanned:int,
     *     eligible_count:int,
     *     already_backfilled_count:int,
     *     review_count:int,
     *     excluded_count:int,
     *     backfilled_count:int,
     *     failed_count:int,
     *     reason_counts:array<string,int>,
     *     results:array<int,array<string,mixed>>,
     *     failures:array<int,array<string,mixed>>
     * }
     */
    public function execute(?int $accountId = null, int $chunkSize = 100): array
    {
        $startedAt = now();
        $batchId = 'service-request-backfill-'.$startedAt->format('Ymd-His').'-'.Str::lower(Str::random(6));
        $journalDirectory = 'service-request-backfills/'.$batchId;
        $summaryPath = $journalDirectory.'/summary.json';
        $mappingPath = $journalDirectory.'/mappings.csv';

        $mappingHandle = fopen('php://temp', 'w+');
        fputcsv($mappingHandle, [
            'legacy_request_id',
            'service_request_id',
            'account_id',
            'customer_id',
            'legacy_status',
            'service_request_status',
            'channel',
            'reason',
            'quote_id',
            'service_request_created_at',
            'service_request_updated_at',
        ]);

        $summary = [
            'batch_id' => $batchId,
            'account_id' => $accountId,
            'started_at' => $startedAt->toISOString(),
            'completed_at' => $startedAt->toISOString(),
            'journal_directory' => $journalDirectory,
            'summary_path' => $summaryPath,
            'mapping_path' => $mappingPath,
            'scanned' => 0,
            'eligible_count' => 0,
            'already_backfilled_count' => 0,
            'review_count' => 0,
            'excluded_count' => 0,
            'backfilled_count' => 0,
            'failed_count' => 0,
            'reason_counts' => [],
            'results' => [],
            'failures' => [],
        ];

        $this->analysisService->query($accountId)
            ->orderBy('requests.id')
            ->chunkById($chunkSize, function (Collection $leads) use (&$summary, $mappingHandle, $batchId): void {
                foreach ($leads as $lead) {
                    $classification = $this->analysisService->classifyLead($lead);
                    $bucket = (string) ($classification['bucket'] ?? 'review');
                    $reason = (string) ($classification['reason'] ?? $bucket);

                    $summary['scanned']++;
                    $summary[$bucket.'_count']++;
                    $summary['reason_counts'][$reason] = (int) ($summary['reason_counts'][$reason] ?? 0) + 1;

                    if ($bucket !== 'eligible') {
                        continue;
                    }

                    try {
                        $result = DB::transaction(
                            fn (): array => $this->backfillLead((int) $lead->id, $classification, $batchId)
                        );

                        $summary['backfilled_count']++;
                        $summary['results'][] = $result;
                        fputcsv($mappingHandle, $this->mappingCsvRow($result));
                    } catch (Throwable $throwable) {
                        $summary['failed_count']++;
                        $summary['failures'][] = [
                            'lead_id' => (int) $lead->id,
                            'lead_name' => $this->analysisService->displayNameForLead($lead),
                            'reason' => $reason,
                            'message' => $throwable->getMessage(),
                        ];
                    }
                }
            }, 'requests.id', 'id');

        $summary['completed_at'] = now()->toISOString();
        ksort($summary['reason_counts']);

        rewind($mappingHandle);
        $mappingContents = stream_get_contents($mappingHandle) ?: '';
        fclose($mappingHandle);

        Storage::disk('local')->put($mappingPath, $mappingContents);
        Storage::disk('local')->put($summaryPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

        return $summary;
    }

    /**
     * @param  array{reason:string,signals:array<string,mixed>}  $classification
     * @return array<string,mixed>
     */
    private function backfillLead(int $leadId, array $classification, string $batchId): array
    {
        /** @var LeadRequest $lead */
        $lead = LeadRequest::query()
            ->with([
                'quote:id,request_id,prospect_id,customer_id,status,accepted_at,created_at,updated_at',
                'serviceRequests:id,prospect_id,source_ref,created_at,updated_at',
            ])
            ->findOrFail($leadId);

        if ($lead->serviceRequests->isNotEmpty()) {
            throw new \RuntimeException('A service request is already linked to this legacy request.');
        }

        $serviceStatus = $this->mapLeadStatus($lead);
        $requestType = $this->resolveRequestType($lead);
        $acceptedAt = $this->resolveAcceptedAt($lead, $serviceStatus);
        $backfilledAt = now();

        $serviceRequest = $this->intakeService->createFromLead($lead, [
            'status' => $serviceStatus,
            'request_type' => $requestType,
            'accepted_at' => $acceptedAt,
            'meta' => [
                'legacy_backfill' => [
                    'batch_id' => $batchId,
                    'backfilled_at' => $backfilledAt->toISOString(),
                    'reason' => (string) ($classification['reason'] ?? 'eligible'),
                    'legacy_status' => (string) $lead->status,
                    'legacy_channel' => (string) ($lead->channel ?? ''),
                ],
            ],
        ]);

        $this->preserveTimestamps($serviceRequest, $lead);

        ActivityLog::record(null, $serviceRequest, self::ACTION_SERVICE_REQUEST_BACKFILLED, [
            'batch_id' => $batchId,
            'legacy_request_id' => $lead->id,
            'reason' => $classification['reason'] ?? 'eligible',
        ], 'Legacy service request backfilled from lead');

        ActivityLog::record(null, $lead, self::ACTION_LEAD_LINKED, [
            'batch_id' => $batchId,
            'service_request_id' => $serviceRequest->id,
            'reason' => $classification['reason'] ?? 'eligible',
        ], 'Legacy lead linked to service request');

        return [
            'batch_id' => $batchId,
            'legacy_request_id' => (int) $lead->id,
            'service_request_id' => (int) $serviceRequest->id,
            'account_id' => (int) $lead->user_id,
            'customer_id' => $lead->customer_id !== null ? (int) $lead->customer_id : null,
            'prospect_id' => (int) $lead->id,
            'legacy_status' => (string) $lead->status,
            'service_request_status' => (string) $serviceRequest->status,
            'channel' => (string) ($lead->channel ?? ''),
            'reason' => (string) ($classification['reason'] ?? 'eligible'),
            'quote_id' => $lead->quote?->id ? (int) $lead->quote->id : null,
            'service_request_created_at' => optional($serviceRequest->created_at)->toISOString(),
            'service_request_updated_at' => optional($serviceRequest->updated_at)->toISOString(),
        ];
    }

    private function mapLeadStatus(LeadRequest $lead): string
    {
        return match ((string) $lead->status) {
            LeadRequest::STATUS_CONTACTED,
            LeadRequest::STATUS_QUALIFIED,
            LeadRequest::STATUS_QUOTE_SENT => ServiceRequest::STATUS_IN_PROGRESS,
            LeadRequest::STATUS_CALL_REQUESTED => ServiceRequest::STATUS_PENDING,
            LeadRequest::STATUS_WON,
            LeadRequest::STATUS_CONVERTED => ServiceRequest::STATUS_ACCEPTED,
            LeadRequest::STATUS_LOST => ServiceRequest::STATUS_REFUSED,
            default => ServiceRequest::STATUS_NEW,
        };
    }

    private function resolveRequestType(LeadRequest $lead): ?string
    {
        $requestType = trim((string) data_get($lead->meta, 'request_type', ''));
        if ($requestType !== '') {
            return $requestType;
        }

        if ($lead->quote !== null) {
            return 'quote_request';
        }

        if ($lead->status === LeadRequest::STATUS_CALL_REQUESTED) {
            return 'contact_request';
        }

        return 'legacy_backfill';
    }

    private function resolveAcceptedAt(LeadRequest $lead, string $serviceStatus): ?Carbon
    {
        if ($serviceStatus !== ServiceRequest::STATUS_ACCEPTED) {
            return null;
        }

        return $lead->quote?->accepted_at
            ?: $lead->converted_at
            ?: $lead->updated_at
            ?: $lead->created_at
            ?: now();
    }

    private function preserveTimestamps(ServiceRequest $serviceRequest, LeadRequest $lead): void
    {
        $createdAt = $lead->created_at ?? now();
        $updatedAt = $lead->updated_at ?? $createdAt;

        $serviceRequest->timestamps = false;
        $serviceRequest->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ])->save();
        $serviceRequest->timestamps = true;
        $serviceRequest->refresh();
    }

    /**
     * @param  array<string,mixed>  $result
     * @return array<int,int|string|null>
     */
    private function mappingCsvRow(array $result): array
    {
        return [
            (int) ($result['legacy_request_id'] ?? 0),
            (int) ($result['service_request_id'] ?? 0),
            (int) ($result['account_id'] ?? 0),
            $result['customer_id'] !== null ? (int) $result['customer_id'] : null,
            (string) ($result['legacy_status'] ?? ''),
            (string) ($result['service_request_status'] ?? ''),
            (string) ($result['channel'] ?? ''),
            (string) ($result['reason'] ?? ''),
            $result['quote_id'] !== null ? (int) $result['quote_id'] : null,
            (string) ($result['service_request_created_at'] ?? ''),
            (string) ($result['service_request_updated_at'] ?? ''),
        ];
    }
}
