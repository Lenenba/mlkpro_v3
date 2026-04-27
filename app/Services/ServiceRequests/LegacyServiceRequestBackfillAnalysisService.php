<?php

namespace App\Services\ServiceRequests;

use App\Models\Request as LeadRequest;
use App\Models\ServiceRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class LegacyServiceRequestBackfillAnalysisService
{
    private const INBOUND_CHANNELS = [
        'api',
        'email',
        'form',
        'kiosk_client',
        'mail',
        'phone',
        'portal',
        'qr',
        'sms',
        'text',
        'wa',
        'web_form',
        'website',
        'webhook',
        'whatsapp',
    ];

    private const DEMAND_REQUEST_TYPES = [
        'api_inbound',
        'contact_request',
        'quote_request',
        'service_inquiry',
        'service_request',
    ];

    private const SERVICE_PROGRESS_STATUSES = [
        LeadRequest::STATUS_CALL_REQUESTED,
        LeadRequest::STATUS_QUOTE_SENT,
        LeadRequest::STATUS_WON,
        LeadRequest::STATUS_LOST,
        LeadRequest::STATUS_CONVERTED,
    ];

    /**
     * @return Builder<LeadRequest>
     */
    public function query(?int $accountId = null): Builder
    {
        return LeadRequest::query()
            ->with([
                'quote:id,request_id,prospect_id,customer_id,status,accepted_at,created_at,updated_at',
                'serviceRequests:id,prospect_id,source_ref,created_at,updated_at',
            ])
            ->when($accountId, fn (Builder $query) => $query->where('user_id', $accountId));
    }

    /**
     * @return array{
     *     scanned:int,
     *     eligible_count:int,
     *     already_backfilled_count:int,
     *     review_count:int,
     *     excluded_count:int,
     *     reason_counts:array<string,int>,
     *     eligible_samples:array<int,array<string,mixed>>,
     *     review_samples:array<int,array<string,mixed>>,
     *     excluded_samples:array<int,array<string,mixed>>
     * }
     */
    public function analyze(?int $accountId = null, int $sampleLimit = 10): array
    {
        $sampleLimit = max(1, $sampleLimit);

        $summary = [
            'scanned' => 0,
            'eligible_count' => 0,
            'already_backfilled_count' => 0,
            'review_count' => 0,
            'excluded_count' => 0,
            'reason_counts' => [],
            'eligible_samples' => [],
            'already_backfilled_samples' => [],
            'review_samples' => [],
            'excluded_samples' => [],
        ];

        $this->query($accountId)
            ->orderBy('requests.id')
            ->chunkById(200, function (Collection $leads) use (&$summary, $sampleLimit): void {
                foreach ($leads as $lead) {
                    $classification = $this->classifyLead($lead);
                    $bucket = (string) ($classification['bucket'] ?? 'review');
                    $reason = (string) ($classification['reason'] ?? $bucket);

                    $summary['scanned']++;
                    $summary[$bucket.'_count']++;
                    $summary['reason_counts'][$reason] = (int) ($summary['reason_counts'][$reason] ?? 0) + 1;

                    $sampleKey = $bucket.'_samples';
                    if (count($summary[$sampleKey]) < $sampleLimit) {
                        $summary[$sampleKey][] = [
                            'id' => (int) $lead->id,
                            'name' => $this->displayNameForLead($lead),
                            'reason' => $reason,
                            'signals' => $classification['signals'] ?? [],
                        ];
                    }
                }
            }, 'requests.id', 'id');

        ksort($summary['reason_counts']);

        return $summary;
    }

    /**
     * @return array{
     *     bucket:'eligible'|'already_backfilled'|'review'|'excluded',
     *     reason:string,
     *     signals:array<string,mixed>
     * }
     */
    public function classifyLead(LeadRequest $lead): array
    {
        $existingServiceRequest = $this->existingServiceRequest($lead);
        $channel = strtolower(trim((string) ($lead->channel ?? '')));
        $requestType = strtolower(trim((string) data_get($lead->meta, 'request_type', '')));
        $sourceKind = strtolower(trim((string) data_get($lead->meta, 'source_kind', '')));
        $hasQuote = $lead->relationLoaded('quote') ? $lead->quote !== null : $lead->quote()->exists();
        $hasInboundChannel = in_array($channel, self::INBOUND_CHANNELS, true);
        $hasDemandRequestType = in_array($requestType, self::DEMAND_REQUEST_TYPES, true);
        $hasProgressedStatus = in_array((string) $lead->status, self::SERVICE_PROGRESS_STATUSES, true);
        $hasServiceabilityContext = $lead->is_serviceable !== null
            || $lead->lat !== null
            || $lead->lng !== null
            || filled($lead->street1)
            || filled($lead->street2)
            || filled($lead->city)
            || filled($lead->state)
            || filled($lead->postal_code)
            || filled($lead->country);
        $hasDemandContent = filled($lead->service_type)
            || filled($lead->title)
            || filled($lead->description);
        $isOutboundCampaignProspect = $sourceKind === 'campaign_prospecting';
        $isSecondaryRecord = $lead->duplicate_of_prospect_id !== null || $lead->merged_into_prospect_id !== null;

        $signals = [
            'channel' => $channel !== '' ? $channel : null,
            'request_type' => $requestType !== '' ? $requestType : null,
            'source_kind' => $sourceKind !== '' ? $sourceKind : null,
            'status' => (string) $lead->status,
            'has_quote' => $hasQuote,
            'has_inbound_channel' => $hasInboundChannel,
            'has_demand_request_type' => $hasDemandRequestType,
            'has_progressed_status' => $hasProgressedStatus,
            'has_serviceability_context' => $hasServiceabilityContext,
            'has_demand_content' => $hasDemandContent,
            'customer_id' => $lead->customer_id !== null ? (int) $lead->customer_id : null,
            'existing_service_request_id' => $existingServiceRequest?->id,
            'is_secondary_record' => $isSecondaryRecord,
            'is_archived' => $lead->archived_at !== null,
            'is_anonymized' => $lead->isAnonymized(),
        ];

        if ($existingServiceRequest) {
            return [
                'bucket' => 'already_backfilled',
                'reason' => 'already_backfilled.existing_service_request',
                'signals' => $signals,
            ];
        }

        if ($lead->isAnonymized()) {
            return [
                'bucket' => 'excluded',
                'reason' => 'excluded.anonymized',
                'signals' => $signals,
            ];
        }

        if ($isSecondaryRecord) {
            return [
                'bucket' => 'excluded',
                'reason' => 'excluded.secondary_record',
                'signals' => $signals,
            ];
        }

        if ($hasQuote) {
            return [
                'bucket' => 'eligible',
                'reason' => 'eligible.quote_attached',
                'signals' => $signals,
            ];
        }

        if ($hasDemandRequestType) {
            return [
                'bucket' => 'eligible',
                'reason' => 'eligible.request_type',
                'signals' => $signals,
            ];
        }

        if ($sourceKind === 'campaign_inbound') {
            return [
                'bucket' => 'eligible',
                'reason' => 'eligible.campaign_inbound',
                'signals' => $signals,
            ];
        }

        if ($hasProgressedStatus) {
            return [
                'bucket' => 'eligible',
                'reason' => 'eligible.progressed_status',
                'signals' => $signals,
            ];
        }

        if ($hasServiceabilityContext) {
            return [
                'bucket' => 'eligible',
                'reason' => 'eligible.serviceability_context',
                'signals' => $signals,
            ];
        }

        if ($lead->customer_id !== null && $hasDemandContent) {
            return [
                'bucket' => 'eligible',
                'reason' => 'eligible.customer_linked_demand',
                'signals' => $signals,
            ];
        }

        if ($isOutboundCampaignProspect) {
            return [
                'bucket' => 'excluded',
                'reason' => 'excluded.outbound_campaign_prospect',
                'signals' => $signals,
            ];
        }

        if ($hasInboundChannel) {
            return [
                'bucket' => 'eligible',
                'reason' => 'eligible.inbound_channel',
                'signals' => $signals,
            ];
        }

        return [
            'bucket' => 'review',
            'reason' => 'review.prospect_like',
            'signals' => $signals,
        ];
    }

    public function displayNameForLead(LeadRequest $lead): string
    {
        $name = trim((string) ($lead->contact_name ?? ''));
        if ($name !== '') {
            return $name;
        }

        $title = trim((string) ($lead->title ?? ''));
        if ($title !== '') {
            return $title;
        }

        $email = trim((string) ($lead->contact_email ?? ''));
        if ($email !== '') {
            return $email;
        }

        return 'Lead #'.$lead->id;
    }

    private function existingServiceRequest(LeadRequest $lead): ?ServiceRequest
    {
        if ($lead->relationLoaded('serviceRequests')) {
            /** @var ServiceRequest|null $serviceRequest */
            $serviceRequest = $lead->serviceRequests->first();

            return $serviceRequest;
        }

        return $lead->serviceRequests()->first(['id', 'prospect_id', 'source_ref', 'created_at', 'updated_at']);
    }
}
