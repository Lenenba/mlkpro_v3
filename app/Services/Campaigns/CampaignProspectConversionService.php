<?php

namespace App\Services\Campaigns;

use App\Models\ActivityLog;
use App\Models\Campaign;
use App\Models\CampaignProspect;
use App\Models\CampaignProspectActivity;
use App\Models\CampaignProspectBatch;
use App\Models\CampaignRecipient;
use App\Models\Request as LeadRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CampaignProspectConversionService
{
    public function __construct(
        private readonly CampaignLeadAttributionService $leadAttributionService,
        private readonly CampaignTrackingService $trackingService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{lead: LeadRequest, prospect: CampaignProspect, created: bool}
     */
    public function convert(
        User $accountOwner,
        User $actor,
        Campaign $campaign,
        CampaignProspect $prospect,
        array $payload = []
    ): array {
        $this->assertProspectAccess($accountOwner, $campaign, $prospect);

        if ($prospect->converted_to_lead_id) {
            throw ValidationException::withMessages([
                'prospect' => ['This prospect is already linked to a lead.'],
            ]);
        }

        if ((int) ($prospect->converted_to_customer_id ?? 0) > 0) {
            throw ValidationException::withMessages([
                'prospect' => ['This prospect has already been converted to a customer.'],
            ]);
        }

        $recipient = $this->latestRecipientForProspect($campaign, $prospect);
        $leadId = isset($payload['lead_id']) && is_numeric($payload['lead_id'])
            ? (int) $payload['lead_id']
            : null;
        $created = $leadId === null;
        $timestamp = now();

        $result = DB::transaction(function () use (
            $accountOwner,
            $actor,
            $campaign,
            $prospect,
            $payload,
            $leadId,
            $recipient,
            $created,
            $timestamp
        ): array {
            if ($leadId) {
                $lead = LeadRequest::query()
                    ->where('user_id', $accountOwner->id)
                    ->whereKey($leadId)
                    ->first();

                if (! $lead) {
                    throw ValidationException::withMessages([
                        'lead_id' => ['Lead not found for this tenant.'],
                    ]);
                }

                $lead->forceFill([
                    'customer_id' => $lead->customer_id ?: $prospect->matched_customer_id,
                    'meta' => $this->leadAttributionService->mergeLeadMeta(
                        is_array($lead->meta) ? $lead->meta : [],
                        $this->leadAttributionService->buildProspectAttributionMeta($campaign, $prospect, $recipient)
                    ),
                ])->save();

                ActivityLog::record($actor, $lead, 'campaign_prospect_linked', [
                    'campaign_id' => $campaign->id,
                    'prospect_id' => $prospect->id,
                ], 'Campaign prospect linked to lead');
            } else {
                $lead = LeadRequest::query()->create([
                    'user_id' => $accountOwner->id,
                    'customer_id' => $prospect->matched_customer_id,
                    'channel' => $this->resolveLeadChannel($prospect, $recipient),
                    'status' => $this->defaultLeadStatus($prospect),
                    'status_updated_at' => $timestamp,
                    'next_follow_up_at' => $timestamp->copy()->addDay(),
                    'title' => $this->resolveLeadTitle($prospect, $payload),
                    'service_type' => $this->resolveLeadServiceType($campaign, $prospect, $payload),
                    'description' => $this->resolveLeadDescription($prospect, $payload),
                    'contact_name' => $this->resolveContactName($prospect),
                    'contact_email' => $prospect->email,
                    'contact_phone' => $prospect->phone,
                    'city' => $prospect->city,
                    'state' => $prospect->state,
                    'country' => $prospect->country,
                    'meta' => $this->leadAttributionService->buildProspectAttributionMeta($campaign, $prospect, $recipient),
                ]);

                ActivityLog::record($actor, $lead, 'created', [
                    'campaign_id' => $campaign->id,
                    'prospect_id' => $prospect->id,
                    'source_kind' => CampaignLeadAttributionService::SOURCE_KIND_PROSPECTING,
                ], 'Lead created from campaign prospect');
            }

            $metadata = is_array($prospect->metadata) ? $prospect->metadata : [];
            $metadata['lead_conversion'] = [
                'lead_id' => (int) $lead->id,
                'created' => $created,
                'converted_at' => $timestamp->toJSON(),
                'converted_by_user_id' => $actor->id,
            ];

            $prospect->forceFill([
                'status' => CampaignProspect::STATUS_CONVERTED_TO_LEAD,
                'matched_lead_id' => $lead->id,
                'converted_to_lead_id' => $lead->id,
                'last_activity_at' => $timestamp,
                'metadata' => $metadata,
            ])->save();

            CampaignProspectActivity::query()->create([
                'campaign_prospect_id' => $prospect->id,
                'campaign_id' => $campaign->id,
                'campaign_run_id' => $recipient?->campaign_run_id,
                'campaign_recipient_id' => $recipient?->id,
                'user_id' => $campaign->user_id,
                'actor_user_id' => $actor->id,
                'activity_type' => $created ? 'converted_to_lead' : 'linked_to_existing_lead',
                'channel' => $recipient?->channel ? strtoupper((string) $recipient->channel) : null,
                'summary' => $created
                    ? 'Prospect converted to a new lead.'
                    : 'Prospect linked to an existing lead.',
                'payload' => [
                    'lead_id' => $lead->id,
                    'created' => $created,
                ],
                'occurred_at' => $timestamp,
            ]);

            $this->refreshBatchCounters((int) $prospect->campaign_prospect_batch_id);

            return [
                'lead' => $lead->fresh(),
                'prospect' => $prospect->fresh(),
                'created' => $created,
            ];
        });

        if ($recipient && ! $recipient->converted_at) {
            $this->trackingService->markConverted($recipient, 'lead', (int) $result['lead']->id, [
                'source' => 'prospect_conversion',
                'actor_user_id' => $actor->id,
            ]);

            $result['prospect'] = $result['prospect']->fresh();
        }

        return $result;
    }

    private function assertProspectAccess(User $accountOwner, Campaign $campaign, CampaignProspect $prospect): void
    {
        if ((int) $campaign->user_id !== (int) $accountOwner->id) {
            throw ValidationException::withMessages([
                'campaign' => ['Campaign not found for this tenant.'],
            ]);
        }

        if ((int) $prospect->campaign_id !== (int) $campaign->id || (int) $prospect->user_id !== (int) $accountOwner->id) {
            throw ValidationException::withMessages([
                'prospect' => ['Prospect not found for this campaign.'],
            ]);
        }
    }

    private function latestRecipientForProspect(Campaign $campaign, CampaignProspect $prospect): ?CampaignRecipient
    {
        return CampaignRecipient::query()
            ->where('campaign_id', $campaign->id)
            ->where('metadata->prospect_id', $prospect->id)
            ->orderByRaw('COALESCE(clicked_at, opened_at, delivered_at, sent_at, queued_at, created_at) DESC')
            ->orderByDesc('id')
            ->first();
    }

    private function refreshBatchCounters(int $batchId): void
    {
        $counts = CampaignProspect::query()
            ->where('campaign_prospect_batch_id', $batchId)
            ->selectRaw('SUM(CASE WHEN first_contacted_at IS NOT NULL THEN 1 ELSE 0 END) as contacted_count')
            ->selectRaw('SUM(CASE WHEN last_replied_at IS NOT NULL OR status IN (?, ?) THEN 1 ELSE 0 END) as replied_count', [
                CampaignProspect::STATUS_REPLIED,
                CampaignProspect::STATUS_QUALIFIED,
            ])
            ->selectRaw('SUM(CASE WHEN converted_to_lead_id IS NOT NULL OR status = ? THEN 1 ELSE 0 END) as lead_count', [
                CampaignProspect::STATUS_CONVERTED_TO_LEAD,
            ])
            ->selectRaw('SUM(CASE WHEN converted_to_customer_id IS NOT NULL OR status = ? THEN 1 ELSE 0 END) as customer_count', [
                CampaignProspect::STATUS_CONVERTED_TO_CUSTOMER,
            ])
            ->first();

        $batch = CampaignProspectBatch::query()->find($batchId);
        if (! $batch) {
            return;
        }

        $contactedCount = (int) ($counts?->contacted_count ?? 0);
        $status = $batch->status;
        if ($contactedCount > 0 && in_array($status, [CampaignProspectBatch::STATUS_APPROVED, CampaignProspectBatch::STATUS_ANALYZED], true)) {
            $status = CampaignProspectBatch::STATUS_RUNNING;
        }

        $batch->forceFill([
            'contacted_count' => $contactedCount,
            'replied_count' => (int) ($counts?->replied_count ?? 0),
            'lead_count' => (int) ($counts?->lead_count ?? 0),
            'customer_count' => (int) ($counts?->customer_count ?? 0),
            'status' => $status,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveLeadTitle(CampaignProspect $prospect, array $payload): string
    {
        $title = trim((string) ($payload['title'] ?? ''));
        if ($title !== '') {
            return $title;
        }

        $fallback = trim((string) ($prospect->company_name ?: $prospect->contact_name));

        return $fallback !== '' ? $fallback : 'Campaign prospect #'.$prospect->id;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveLeadServiceType(Campaign $campaign, CampaignProspect $prospect, array $payload): ?string
    {
        $serviceType = trim((string) ($payload['service_type'] ?? ''));
        if ($serviceType !== '') {
            return $serviceType;
        }

        $industry = trim((string) ($prospect->industry ?? ''));
        if ($industry !== '') {
            return $industry;
        }

        $campaignType = trim((string) $campaign->resolvedCampaignType());

        return $campaignType !== '' ? $campaignType : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveLeadDescription(CampaignProspect $prospect, array $payload): ?string
    {
        $description = trim((string) ($payload['description'] ?? ''));
        if ($description !== '') {
            return $description;
        }

        $summary = trim((string) ($prospect->qualification_summary ?? ''));
        if ($summary !== '') {
            return $summary;
        }

        $notes = trim((string) ($prospect->owner_notes ?? ''));

        return $notes !== '' ? $notes : null;
    }

    private function resolveContactName(CampaignProspect $prospect): ?string
    {
        $contactName = trim((string) ($prospect->contact_name ?? ''));
        if ($contactName !== '') {
            return $contactName;
        }

        $name = trim(implode(' ', array_filter([
            trim((string) ($prospect->first_name ?? '')),
            trim((string) ($prospect->last_name ?? '')),
        ])));

        return $name !== '' ? $name : null;
    }

    private function resolveLeadChannel(CampaignProspect $prospect, ?CampaignRecipient $recipient): ?string
    {
        $channel = strtolower(trim((string) ($recipient?->channel ?? '')));
        if ($channel !== '') {
            return $channel;
        }

        return match ($prospect->source_type) {
            CampaignProspect::SOURCE_ADS => 'ads',
            CampaignProspect::SOURCE_LANDING_PAGE => 'web_form',
            CampaignProspect::SOURCE_CONNECTOR,
            CampaignProspect::SOURCE_DIRECTORY_API,
            CampaignProspect::SOURCE_CSV,
            CampaignProspect::SOURCE_IMPORT => 'import',
            default => 'manual',
        };
    }

    private function defaultLeadStatus(CampaignProspect $prospect): string
    {
        return match ($prospect->status) {
            CampaignProspect::STATUS_QUALIFIED => LeadRequest::STATUS_QUALIFIED,
            CampaignProspect::STATUS_REPLIED => LeadRequest::STATUS_CONTACTED,
            default => LeadRequest::STATUS_NEW,
        };
    }
}
