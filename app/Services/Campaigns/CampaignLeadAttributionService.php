<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignProspect;
use App\Models\CampaignProspectBatch;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\Request as LeadRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class CampaignLeadAttributionService
{
    public const SOURCE_KIND_PROSPECTING = 'campaign_prospecting';
    public const SOURCE_KIND_INBOUND = 'campaign_inbound';

    private const SESSION_KEY_PREFIX = 'campaign_lead_attribution.';
    private const ATTRIBUTION_TTL_HOURS = 24 * 14;

    public function rememberRecipientClick(Request $request, CampaignRecipient $recipient): void
    {
        $campaign = $recipient->campaign;
        if (! $campaign) {
            return;
        }

        $metadata = is_array($recipient->metadata) ? $recipient->metadata : [];

        $request->session()->put($this->sessionKey((int) $campaign->user_id), [
            'campaign_id' => (int) $campaign->id,
            'campaign_run_id' => $recipient->campaign_run_id ? (int) $recipient->campaign_run_id : null,
            'campaign_recipient_id' => (int) $recipient->id,
            'source_kind' => isset($metadata['prospect_id'])
                ? self::SOURCE_KIND_PROSPECTING
                : self::SOURCE_KIND_INBOUND,
            'source_direction' => 'inbound',
            'source_campaign_direction' => $campaign->resolvedCampaignDirection(),
            'source_channel' => strtoupper((string) $recipient->channel),
            'source_prospect_id' => isset($metadata['prospect_id']) && is_numeric($metadata['prospect_id'])
                ? (int) $metadata['prospect_id']
                : null,
            'source_prospect_batch_id' => isset($metadata['prospect_batch_id']) && is_numeric($metadata['prospect_batch_id'])
                ? (int) $metadata['prospect_batch_id']
                : null,
            'source_outreach_phase' => $metadata['outreach_phase'] ?? null,
            'source_tracking_origin' => 'tracking_click',
            'source_utm_source' => 'campaign',
            'source_utm_medium' => 'owned',
            'source_utm_campaign' => (string) $campaign->id,
            'captured_at' => now()->toJSON(),
        ]);
    }

    public function syncPublicFormAttribution(Request $request, User $owner): void
    {
        $resolved = $this->resolveCampaignFromUtm($owner, trim((string) $request->query('utm_campaign', '')));
        if ($resolved) {
            $request->session()->put($this->sessionKey($owner->id), [
                'campaign_id' => (int) $resolved->id,
                'campaign_run_id' => null,
                'campaign_recipient_id' => null,
                'source_kind' => self::SOURCE_KIND_INBOUND,
                'source_direction' => 'inbound',
                'source_campaign_direction' => $resolved->resolvedCampaignDirection(),
                'source_channel' => null,
                'source_prospect_id' => null,
                'source_prospect_batch_id' => null,
                'source_outreach_phase' => null,
                'source_tracking_origin' => 'utm_query',
                'source_utm_source' => $this->queryValue($request, 'utm_source'),
                'source_utm_medium' => $this->queryValue($request, 'utm_medium'),
                'source_utm_campaign' => $this->queryValue($request, 'utm_campaign'),
                'source_utm_term' => $this->queryValue($request, 'utm_term'),
                'source_utm_content' => $this->queryValue($request, 'utm_content'),
                'captured_at' => now()->toJSON(),
            ]);

            return;
        }

        $existing = $request->session()->get($this->sessionKey($owner->id));
        if ($this->isValidPayload($existing)) {
            return;
        }

        $request->session()->forget($this->sessionKey($owner->id));
    }

    public function forgetAttribution(Request $request, User $owner): void
    {
        $request->session()->forget($this->sessionKey($owner->id));
    }

    /**
     * @return array<string, mixed>
     */
    public function buildProspectAttributionMeta(
        Campaign $campaign,
        CampaignProspect $prospect,
        ?CampaignRecipient $recipient = null
    ): array {
        $metadata = is_array($recipient?->metadata) ? $recipient->metadata : [];

        return $this->filterMeta([
            'source_kind' => self::SOURCE_KIND_PROSPECTING,
            'source_direction' => 'outbound',
            'source_campaign_direction' => $campaign->resolvedCampaignDirection(),
            'source_campaign_id' => (int) $campaign->id,
            'source_campaign_name' => (string) $campaign->name,
            'source_campaign_run_id' => $recipient?->campaign_run_id ? (int) $recipient->campaign_run_id : null,
            'source_campaign_recipient_id' => $recipient?->id ? (int) $recipient->id : null,
            'source_prospect_id' => (int) $prospect->id,
            'source_prospect_batch_id' => (int) $prospect->campaign_prospect_batch_id,
            'source_channel' => $recipient?->channel ? strtoupper((string) $recipient->channel) : null,
            'source_outreach_phase' => $metadata['outreach_phase']
                ?? data_get($prospect->metadata, 'sequence.current_phase'),
            'source_fit_score' => $prospect->fit_score,
            'source_intent_score' => $prospect->intent_score,
            'source_priority_score' => $prospect->priority_score,
            'source_first_contacted_at' => optional($prospect->first_contacted_at)->toJSON(),
            'source_last_contacted_at' => optional($prospect->last_contacted_at)->toJSON(),
            'source_last_replied_at' => optional($prospect->last_replied_at)->toJSON(),
            'source_converted_at' => now()->toJSON(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildInboundAttributionMeta(Request $request, User $owner): array
    {
        $payload = $request->session()->get($this->sessionKey($owner->id));
        if (! $this->isValidPayload($payload)) {
            return [];
        }

        $campaign = Campaign::query()
            ->where('user_id', $owner->id)
            ->whereKey((int) ($payload['campaign_id'] ?? 0))
            ->first();

        if (! $campaign) {
            $request->session()->forget($this->sessionKey($owner->id));

            return [];
        }

        return $this->filterMeta([
            'source_kind' => $payload['source_kind'] ?? self::SOURCE_KIND_INBOUND,
            'source_direction' => 'inbound',
            'source_campaign_direction' => $payload['source_campaign_direction'] ?? $campaign->resolvedCampaignDirection(),
            'source_campaign_id' => (int) $campaign->id,
            'source_campaign_name' => (string) $campaign->name,
            'source_campaign_run_id' => isset($payload['campaign_run_id']) && is_numeric($payload['campaign_run_id'])
                ? (int) $payload['campaign_run_id']
                : null,
            'source_campaign_recipient_id' => isset($payload['campaign_recipient_id']) && is_numeric($payload['campaign_recipient_id'])
                ? (int) $payload['campaign_recipient_id']
                : null,
            'source_prospect_id' => isset($payload['source_prospect_id']) && is_numeric($payload['source_prospect_id'])
                ? (int) $payload['source_prospect_id']
                : null,
            'source_prospect_batch_id' => isset($payload['source_prospect_batch_id']) && is_numeric($payload['source_prospect_batch_id'])
                ? (int) $payload['source_prospect_batch_id']
                : null,
            'source_channel' => $payload['source_channel'] ?? null,
            'source_outreach_phase' => $payload['source_outreach_phase'] ?? null,
            'source_tracking_origin' => $payload['source_tracking_origin'] ?? null,
            'source_utm_source' => $payload['source_utm_source'] ?? null,
            'source_utm_medium' => $payload['source_utm_medium'] ?? null,
            'source_utm_campaign' => $payload['source_utm_campaign'] ?? null,
            'source_utm_term' => $payload['source_utm_term'] ?? null,
            'source_utm_content' => $payload['source_utm_content'] ?? null,
            'source_inbound_attributed_at' => now()->toJSON(),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $meta
     * @param  array<string, mixed>  $attribution
     * @return array<string, mixed>
     */
    public function mergeLeadMeta(?array $meta, array $attribution): array
    {
        return $this->filterMeta(array_merge($meta ?: [], $attribution));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function campaignOriginForLead(LeadRequest $lead, User $owner): ?array
    {
        $meta = is_array($lead->meta) ? $lead->meta : [];
        $campaignId = isset($meta['source_campaign_id']) && is_numeric($meta['source_campaign_id'])
            ? (int) $meta['source_campaign_id']
            : null;

        if (! $campaignId) {
            return null;
        }

        $campaign = Campaign::query()
            ->where('user_id', $owner->id)
            ->whereKey($campaignId)
            ->first(['id', 'user_id', 'name', 'status', 'campaign_direction', 'prospecting_enabled']);

        $prospectId = isset($meta['source_prospect_id']) && is_numeric($meta['source_prospect_id'])
            ? (int) $meta['source_prospect_id']
            : null;
        $batchId = isset($meta['source_prospect_batch_id']) && is_numeric($meta['source_prospect_batch_id'])
            ? (int) $meta['source_prospect_batch_id']
            : null;
        $runId = isset($meta['source_campaign_run_id']) && is_numeric($meta['source_campaign_run_id'])
            ? (int) $meta['source_campaign_run_id']
            : null;
        $recipientId = isset($meta['source_campaign_recipient_id']) && is_numeric($meta['source_campaign_recipient_id'])
            ? (int) $meta['source_campaign_recipient_id']
            : null;

        $prospect = $prospectId
            ? CampaignProspect::query()
                ->where('user_id', $owner->id)
                ->whereKey($prospectId)
                ->first([
                    'id',
                    'campaign_id',
                    'campaign_prospect_batch_id',
                    'status',
                    'company_name',
                    'contact_name',
                    'first_contacted_at',
                    'last_replied_at',
                ])
            : null;

        $batch = $batchId
            ? CampaignProspectBatch::query()
                ->where('user_id', $owner->id)
                ->whereKey($batchId)
                ->first(['id', 'campaign_id', 'batch_number', 'status'])
            : null;

        $run = $runId
            ? CampaignRun::query()
                ->where('campaign_id', $campaignId)
                ->whereKey($runId)
                ->first(['id', 'campaign_id', 'status', 'trigger_type', 'created_at'])
            : null;

        $recipient = $recipientId
            ? CampaignRecipient::query()
                ->where('campaign_id', $campaignId)
                ->whereKey($recipientId)
                ->first([
                    'id',
                    'campaign_id',
                    'campaign_run_id',
                    'channel',
                    'status',
                    'sent_at',
                    'clicked_at',
                    'converted_at',
                ])
            : null;

        return [
            'kind' => (string) ($meta['source_kind'] ?? self::SOURCE_KIND_INBOUND),
            'direction' => (string) ($meta['source_direction'] ?? 'inbound'),
            'campaign_direction' => (string) ($meta['source_campaign_direction'] ?? $campaign?->resolvedCampaignDirection() ?? ''),
            'channel' => $meta['source_channel'] ?? $recipient?->channel,
            'outreach_phase' => $meta['source_outreach_phase'] ?? null,
            'tracking_origin' => $meta['source_tracking_origin'] ?? null,
            'utm' => $this->filterMeta([
                'source' => $meta['source_utm_source'] ?? null,
                'medium' => $meta['source_utm_medium'] ?? null,
                'campaign' => $meta['source_utm_campaign'] ?? null,
                'term' => $meta['source_utm_term'] ?? null,
                'content' => $meta['source_utm_content'] ?? null,
            ]),
            'first_outreach_at' => $meta['source_first_contacted_at'] ?? optional($prospect?->first_contacted_at)->toJSON(),
            'last_replied_at' => $meta['source_last_replied_at'] ?? optional($prospect?->last_replied_at)->toJSON(),
            'converted_at' => $meta['source_converted_at'] ?? $meta['source_inbound_attributed_at'] ?? optional($lead->created_at)->toJSON(),
            'campaign' => $campaign ? [
                'id' => (int) $campaign->id,
                'name' => (string) $campaign->name,
                'status' => (string) $campaign->status,
                'campaign_direction' => (string) $campaign->resolvedCampaignDirection(),
            ] : null,
            'prospect' => $prospect ? [
                'id' => (int) $prospect->id,
                'status' => (string) $prospect->status,
                'company_name' => (string) ($prospect->company_name ?? ''),
                'contact_name' => (string) ($prospect->contact_name ?? ''),
            ] : null,
            'batch' => $batch ? [
                'id' => (int) $batch->id,
                'batch_number' => (int) $batch->batch_number,
                'status' => (string) $batch->status,
            ] : null,
            'run' => $run ? [
                'id' => (int) $run->id,
                'status' => (string) $run->status,
                'trigger_type' => (string) $run->trigger_type,
                'created_at' => optional($run->created_at)->toJSON(),
            ] : null,
            'recipient' => $recipient ? [
                'id' => (int) $recipient->id,
                'channel' => (string) $recipient->channel,
                'status' => (string) $recipient->status,
                'sent_at' => optional($recipient->sent_at)->toJSON(),
                'clicked_at' => optional($recipient->clicked_at)->toJSON(),
                'converted_at' => optional($recipient->converted_at)->toJSON(),
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function funnelForCampaign(Campaign $campaign): array
    {
        $prospectsQuery = CampaignProspect::query()
            ->where('campaign_id', $campaign->id)
            ->where('user_id', $campaign->user_id);

        $leadsQuery = LeadRequest::query()
            ->where('user_id', $campaign->user_id)
            ->where('meta->source_campaign_id', $campaign->id);

        $prospectCount = (clone $prospectsQuery)->count();
        $contactedCount = (clone $prospectsQuery)->whereNotNull('first_contacted_at')->count();
        $repliedCount = (clone $prospectsQuery)
            ->where(function ($query): void {
                $query->whereNotNull('last_replied_at')
                    ->orWhereIn('status', [
                        CampaignProspect::STATUS_REPLIED,
                        CampaignProspect::STATUS_QUALIFIED,
                        CampaignProspect::STATUS_CONVERTED_TO_LEAD,
                        CampaignProspect::STATUS_CONVERTED_TO_CUSTOMER,
                    ]);
            })
            ->count();
        $qualifiedCount = (clone $prospectsQuery)
            ->whereIn('status', [
                CampaignProspect::STATUS_QUALIFIED,
                CampaignProspect::STATUS_CONVERTED_TO_LEAD,
                CampaignProspect::STATUS_CONVERTED_TO_CUSTOMER,
            ])
            ->count();
        $leadCount = (clone $leadsQuery)->count();
        $customerCount = (clone $leadsQuery)
            ->whereIn('status', [
                LeadRequest::STATUS_WON,
                LeadRequest::STATUS_CONVERTED,
            ])
            ->count();

        return [
            'direction' => $campaign->resolvedCampaignDirection(),
            'stages' => [
                'prospects' => $prospectCount,
                'contacted' => $contactedCount,
                'replied' => $repliedCount,
                'qualified' => $qualifiedCount,
                'leads' => $leadCount,
                'customers' => $customerCount,
            ],
            'rates' => [
                'prospect_to_lead_percent' => $prospectCount > 0
                    ? round(($leadCount / $prospectCount) * 100, 2)
                    : null,
                'lead_to_customer_percent' => $leadCount > 0
                    ? round(($customerCount / $leadCount) * 100, 2)
                    : null,
                'overall_customer_percent' => $prospectCount > 0
                    ? round(($customerCount / $prospectCount) * 100, 2)
                    : null,
            ],
        ];
    }

    private function sessionKey(int $ownerId): string
    {
        return self::SESSION_KEY_PREFIX.$ownerId;
    }

    private function isValidPayload(mixed $payload): bool
    {
        if (! is_array($payload)) {
            return false;
        }

        $campaignId = $payload['campaign_id'] ?? null;
        $capturedAt = $payload['captured_at'] ?? null;

        if (! is_numeric($campaignId) || ! is_string($capturedAt) || trim($capturedAt) === '') {
            return false;
        }

        try {
            return Carbon::parse($capturedAt)->diffInHours(now()) <= self::ATTRIBUTION_TTL_HOURS;
        } catch (\Throwable) {
            return false;
        }
    }

    private function resolveCampaignFromUtm(User $owner, string $utmCampaign): ?Campaign
    {
        $candidate = trim($utmCampaign);
        if ($candidate === '') {
            return null;
        }

        if (is_numeric($candidate)) {
            return Campaign::query()
                ->where('user_id', $owner->id)
                ->whereKey((int) $candidate)
                ->first();
        }

        $lowerCandidate = Str::lower($candidate);
        $slugCandidate = Str::slug($candidate);

        return Campaign::query()
            ->where('user_id', $owner->id)
            ->get(['id', 'user_id', 'name', 'status', 'campaign_direction', 'prospecting_enabled'])
            ->first(function (Campaign $campaign) use ($lowerCandidate, $slugCandidate): bool {
                $name = trim((string) $campaign->name);

                return Str::lower($name) === $lowerCandidate
                    || Str::slug($name) === $slugCandidate;
            });
    }

    private function queryValue(Request $request, string $key): ?string
    {
        $value = trim((string) $request->query($key, ''));

        return $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function filterMeta(array $meta): array
    {
        return array_filter($meta, static function ($value): bool {
            if (is_array($value)) {
                return $value !== [];
            }

            return $value !== null && $value !== '';
        });
    }
}
