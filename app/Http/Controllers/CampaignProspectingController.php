<?php

namespace App\Http\Controllers;

use App\Http\Requests\Campaigns\ImportCampaignProspectBatchRequest;
use App\Models\Campaign;
use App\Models\CampaignProspect;
use App\Models\CampaignProspectBatch;
use App\Models\Request as LeadRequest;
use App\Models\User;
use App\Services\Campaigns\CampaignProspectConversionService;
use App\Services\Campaigns\CampaignProspectingOutreachService;
use App\Services\Campaigns\CampaignProspectingService;
use Illuminate\Http\Request;

class CampaignProspectingController extends Controller
{
    public function __construct(
        private readonly CampaignProspectingService $prospectingService,
        private readonly CampaignProspectingOutreachService $prospectingOutreachService,
        private readonly CampaignProspectConversionService $prospectConversionService,
    ) {
    }

    public function import(ImportCampaignProspectBatchRequest $request, Campaign $campaign)
    {
        [$owner, , $canManage] = $this->resolveCampaignAccess($request->user(), $campaign);
        if (! $canManage) {
            abort(403);
        }

        $batches = $this->prospectingService->importBatches(
            accountOwner: $owner,
            actor: $request->user(),
            campaign: $campaign,
            payload: $request->validated()
        );

        return response()->json([
            'message' => 'Prospect batches imported.',
            'batches' => $batches->map(fn (CampaignProspectBatch $batch) => $this->batchPayload($batch))->values()->all(),
            'total_imported' => $batches->sum('input_count'),
        ], 201);
    }

    public function batches(Request $request, Campaign $campaign)
    {
        [$owner, $canView] = $this->resolveCampaignAccess($request->user(), $campaign);
        if (! $canView) {
            abort(403);
        }

        $limit = max(1, min(25, (int) $request->integer('limit', 12)));

        $baseQuery = CampaignProspectBatch::query()
            ->where('campaign_id', $campaign->id)
            ->where('user_id', $owner->id);

        $batches = (clone $baseQuery)
            ->orderByDesc('batch_number')
            ->limit($limit)
            ->get();

        return response()->json([
            'batches' => $batches->map(fn (CampaignProspectBatch $batch) => $this->batchPayload($batch))->values()->all(),
            'summary' => [
                'total_batches' => (clone $baseQuery)->count(),
                'analyzed_batches' => (clone $baseQuery)->where('status', CampaignProspectBatch::STATUS_ANALYZED)->count(),
                'approved_batches' => (clone $baseQuery)->where('status', CampaignProspectBatch::STATUS_APPROVED)->count(),
                'canceled_batches' => (clone $baseQuery)->where('status', CampaignProspectBatch::STATUS_CANCELED)->count(),
            ],
        ]);
    }

    public function showBatch(Request $request, Campaign $campaign, CampaignProspectBatch $batch)
    {
        [$owner, $canView] = $this->resolveCampaignAccess($request->user(), $campaign);
        if (! $canView) {
            abort(403);
        }

        if ((int) $batch->campaign_id !== (int) $campaign->id || (int) $batch->user_id !== (int) $owner->id) {
            abort(404);
        }

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:40'],
            'match_status' => ['nullable', 'string', 'max:40'],
        ]);

        $prospects = CampaignProspect::query()
            ->where('campaign_prospect_batch_id', $batch->id)
            ->with([
                'matchedCustomer:id,first_name,last_name,company_name,email,phone',
                'matchedLead:id,title,contact_name,contact_email,contact_phone,status',
            ])
            ->when($filters['search'] ?? null, function ($query, $search): void {
                $search = trim((string) $search);
                if ($search === '') {
                    return;
                }

                $query->where(function ($builder) use ($search): void {
                    $builder->where('company_name', 'like', '%'.$search.'%')
                        ->orWhere('contact_name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%')
                        ->orWhere('phone', 'like', '%'.$search.'%')
                        ->orWhere('website_domain', 'like', '%'.$search.'%');
                });
            })
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', (string) $status))
            ->when($filters['match_status'] ?? null, fn ($query, $matchStatus) => $query->where('match_status', (string) $matchStatus))
            ->orderByDesc('priority_score')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();

        return response()->json([
            'batch' => $this->batchPayload($batch->fresh()),
            'filters' => $filters,
            'prospects' => $prospects,
        ]);
    }

    public function prospects(Request $request, Campaign $campaign)
    {
        [$owner, $canView] = $this->resolveCampaignAccess($request->user(), $campaign);
        if (! $canView) {
            abort(403);
        }

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:40'],
            'batch_id' => ['nullable', 'integer'],
            'match_status' => ['nullable', 'string', 'max:40'],
        ]);

        $prospects = CampaignProspect::query()
            ->where('campaign_id', $campaign->id)
            ->where('user_id', $owner->id)
            ->with([
                'batch:id,campaign_id,batch_number,status',
                'matchedCustomer:id,first_name,last_name,company_name,email,phone',
                'matchedLead:id,title,contact_name,contact_email,contact_phone,status',
            ])
            ->when($filters['batch_id'] ?? null, fn ($query, $batchId) => $query->where('campaign_prospect_batch_id', (int) $batchId))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', (string) $status))
            ->when($filters['match_status'] ?? null, fn ($query, $matchStatus) => $query->where('match_status', (string) $matchStatus))
            ->when($filters['search'] ?? null, function ($query, $search): void {
                $search = trim((string) $search);
                if ($search === '') {
                    return;
                }

                $query->where(function ($builder) use ($search): void {
                    $builder->where('company_name', 'like', '%'.$search.'%')
                        ->orWhere('contact_name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%')
                        ->orWhere('phone', 'like', '%'.$search.'%')
                        ->orWhere('website_domain', 'like', '%'.$search.'%');
                });
            })
            ->orderByDesc('priority_score')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();

        return response()->json([
            'prospects' => $prospects,
            'filters' => $filters,
        ]);
    }

    public function leadOptions(Request $request, Campaign $campaign)
    {
        [$owner, $canView] = $this->resolveCampaignAccess($request->user(), $campaign);
        if (! $canView) {
            abort(403);
        }

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:15'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));
        $limit = (int) ($validated['limit'] ?? 8);

        $leads = LeadRequest::query()
            ->where('user_id', $owner->id)
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($builder) use ($search): void {
                    $builder->where('title', 'like', '%'.$search.'%')
                        ->orWhere('service_type', 'like', '%'.$search.'%')
                        ->orWhere('contact_name', 'like', '%'.$search.'%')
                        ->orWhere('contact_email', 'like', '%'.$search.'%')
                        ->orWhere('contact_phone', 'like', '%'.$search.'%');
                });
            })
            ->orderByRaw('CASE WHEN next_follow_up_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('next_follow_up_at')
            ->latest()
            ->limit($limit)
            ->get([
                'id',
                'title',
                'status',
                'service_type',
                'contact_name',
                'contact_email',
                'contact_phone',
            ]);

        return response()->json([
            'leads' => $leads->map(fn (LeadRequest $lead) => [
                'id' => $lead->id,
                'title' => $lead->title,
                'status' => $lead->status,
                'service_type' => $lead->service_type,
                'contact_name' => $lead->contact_name,
                'contact_email' => $lead->contact_email,
                'contact_phone' => $lead->contact_phone,
            ])->values()->all(),
            'filters' => [
                'search' => $search,
                'limit' => $limit,
            ],
        ]);
    }

    public function showProspect(Request $request, Campaign $campaign, CampaignProspect $prospect)
    {
        [$owner, $canView] = $this->resolveCampaignAccess($request->user(), $campaign);
        if (! $canView) {
            abort(403);
        }

        if ((int) $prospect->campaign_id !== (int) $campaign->id || (int) $prospect->user_id !== (int) $owner->id) {
            abort(404);
        }

        $prospect->load($this->prospectRelations());

        return response()->json([
            'prospect' => $this->prospectPayload($prospect),
        ]);
    }

    public function updateProspectStatus(Request $request, Campaign $campaign, CampaignProspect $prospect)
    {
        [$owner, , $canManage] = $this->resolveCampaignAccess($request->user(), $campaign);
        if (! $canManage) {
            abort(403);
        }

        $validated = $request->validate([
            'status' => ['required', 'string', 'max:40'],
            'reason' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $updated = $this->prospectingOutreachService->updateProspectStatus(
            accountOwner: $owner,
            actor: $request->user(),
            campaign: $campaign,
            prospect: $prospect,
            status: (string) $validated['status'],
            reason: $validated['reason'] ?? null,
            note: $validated['note'] ?? null
        );

        return response()->json([
            'message' => 'Prospect status updated.',
            'prospect' => $this->prospectPayload($updated->load($this->prospectRelations())),
        ]);
    }

    public function bulkUpdateProspects(Request $request, Campaign $campaign)
    {
        [$owner, , $canManage] = $this->resolveCampaignAccess($request->user(), $campaign);
        if (! $canManage) {
            abort(403);
        }

        $validated = $request->validate([
            'prospect_ids' => ['required', 'array', 'min:1', 'max:50'],
            'prospect_ids.*' => ['integer'],
            'status' => ['required', 'string', 'in:approved,disqualified,do_not_contact'],
            'reason' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $prospectIds = collect($validated['prospect_ids'] ?? [])
            ->map(fn ($value) => (int) $value)
            ->filter(fn (int $value) => $value > 0)
            ->unique()
            ->values();

        $prospects = CampaignProspect::query()
            ->where('campaign_id', $campaign->id)
            ->where('user_id', $owner->id)
            ->whereIn('id', $prospectIds->all())
            ->get()
            ->keyBy('id');

        $updated = $prospectIds->map(function (int $prospectId) use ($prospects, $owner, $request, $campaign, $validated) {
            /** @var CampaignProspect|null $prospect */
            $prospect = $prospects->get($prospectId);
            if (! $prospect) {
                return null;
            }

            return $this->prospectingOutreachService->updateProspectStatus(
                accountOwner: $owner,
                actor: $request->user(),
                campaign: $campaign,
                prospect: $prospect,
                status: (string) $validated['status'],
                reason: $validated['reason'] ?? null,
                note: $validated['note'] ?? null
            )->load($this->prospectRelations());
        })->filter()->values();

        return response()->json([
            'message' => sprintf('%d prospect(s) updated.', $updated->count()),
            'updated_count' => $updated->count(),
            'prospects' => $updated->map(fn (CampaignProspect $prospect) => $this->prospectPayload($prospect))->values()->all(),
        ]);
    }

    public function convertProspectToLead(Request $request, Campaign $campaign, CampaignProspect $prospect)
    {
        [$owner, , $canManage] = $this->resolveCampaignAccess($request->user(), $campaign);
        if (! $canManage) {
            abort(403);
        }

        $validated = $request->validate([
            'lead_id' => ['nullable', 'integer'],
            'title' => ['nullable', 'string', 'max:255'],
            'service_type' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->prospectConversionResponse($owner, $request->user(), $campaign, $prospect, $validated);
    }

    public function linkProspectToLead(Request $request, Campaign $campaign, CampaignProspect $prospect)
    {
        [$owner, , $canManage] = $this->resolveCampaignAccess($request->user(), $campaign);
        if (! $canManage) {
            abort(403);
        }

        $validated = $request->validate([
            'lead_id' => ['required', 'integer'],
        ]);

        return $this->prospectConversionResponse($owner, $request->user(), $campaign, $prospect, $validated);
    }

    private function prospectConversionResponse(User $owner, User $actor, Campaign $campaign, CampaignProspect $prospect, array $validated)
    {
        $result = $this->prospectConversionService->convert(
            accountOwner: $owner,
            actor: $actor,
            campaign: $campaign,
            prospect: $prospect,
            payload: $validated,
        );

        $freshProspect = $result['prospect']->load($this->prospectRelations());

        return response()->json([
            'message' => $result['created']
                ? 'Prospect converted to lead.'
                : 'Prospect linked to existing lead.',
            'lead' => $result['lead'],
            'created' => $result['created'],
            'prospect' => $this->prospectPayload($freshProspect),
        ]);
    }

    public function approveBatch(Request $request, Campaign $campaign, CampaignProspectBatch $batch)
    {
        [$owner, , $canManage] = $this->resolveCampaignAccess($request->user(), $campaign);
        if (! $canManage) {
            abort(403);
        }

        $batch = $this->prospectingService->approveBatch($owner, $request->user(), $campaign, $batch);

        return response()->json([
            'message' => 'Prospect batch approved.',
            'batch' => $this->batchPayload($batch),
        ]);
    }

    public function rejectBatch(Request $request, Campaign $campaign, CampaignProspectBatch $batch)
    {
        [$owner, , $canManage] = $this->resolveCampaignAccess($request->user(), $campaign);
        if (! $canManage) {
            abort(403);
        }

        $batch = $this->prospectingService->rejectBatch($owner, $request->user(), $campaign, $batch);

        return response()->json([
            'message' => 'Prospect batch rejected.',
            'batch' => $this->batchPayload($batch),
        ]);
    }

    private function batchPayload(CampaignProspectBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'campaign_id' => $batch->campaign_id,
            'source_type' => $batch->source_type,
            'source_reference' => $batch->source_reference,
            'batch_number' => $batch->batch_number,
            'input_count' => $batch->input_count,
            'accepted_count' => $batch->accepted_count,
            'rejected_count' => $batch->rejected_count,
            'duplicate_count' => $batch->duplicate_count,
            'blocked_count' => $batch->blocked_count,
            'scored_count' => $batch->scored_count,
            'contacted_count' => $batch->contacted_count,
            'replied_count' => $batch->replied_count,
            'lead_count' => $batch->lead_count,
            'customer_count' => $batch->customer_count,
            'status' => $batch->status,
            'analysis_summary' => $batch->analysis_summary,
            'approved_by_user_id' => $batch->approved_by_user_id,
            'approved_at' => optional($batch->approved_at)->toJSON(),
            'created_at' => optional($batch->created_at)->toJSON(),
            'updated_at' => optional($batch->updated_at)->toJSON(),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function prospectRelations(): array
    {
        return [
            'batch:id,campaign_id,batch_number,status',
            'matchedCustomer:id,first_name,last_name,company_name,email,phone',
            'matchedLead:id,title,contact_name,contact_email,contact_phone,status',
            'convertedLead:id,title,contact_name,contact_email,contact_phone,status',
            'activities' => fn ($query) => $query->limit(25),
        ];
    }

    private function prospectPayload(CampaignProspect $prospect): array
    {
        return [
            'id' => $prospect->id,
            'campaign_id' => $prospect->campaign_id,
            'campaign_prospect_batch_id' => $prospect->campaign_prospect_batch_id,
            'source_type' => $prospect->source_type,
            'source_reference' => $prospect->source_reference,
            'external_ref' => $prospect->external_ref,
            'status' => $prospect->status,
            'match_status' => $prospect->match_status,
            'company_name' => $prospect->company_name,
            'contact_name' => $prospect->contact_name,
            'first_name' => $prospect->first_name,
            'last_name' => $prospect->last_name,
            'email' => $prospect->email,
            'phone' => $prospect->phone,
            'website' => $prospect->website,
            'website_domain' => $prospect->website_domain,
            'city' => $prospect->city,
            'state' => $prospect->state,
            'country' => $prospect->country,
            'industry' => $prospect->industry,
            'company_size' => $prospect->company_size,
            'tags' => $prospect->tags,
            'fit_score' => $prospect->fit_score,
            'intent_score' => $prospect->intent_score,
            'priority_score' => $prospect->priority_score,
            'qualification_summary' => $prospect->qualification_summary,
            'blocked_reason' => $prospect->blocked_reason,
            'do_not_contact' => $prospect->do_not_contact,
            'owner_notes' => $prospect->owner_notes,
            'metadata' => $prospect->metadata,
            'first_contacted_at' => optional($prospect->first_contacted_at)->toJSON(),
            'last_contacted_at' => optional($prospect->last_contacted_at)->toJSON(),
            'last_replied_at' => optional($prospect->last_replied_at)->toJSON(),
            'last_activity_at' => optional($prospect->last_activity_at)->toJSON(),
            'created_at' => optional($prospect->created_at)->toJSON(),
            'updated_at' => optional($prospect->updated_at)->toJSON(),
            'batch' => $prospect->batch ? [
                'id' => $prospect->batch->id,
                'batch_number' => $prospect->batch->batch_number,
                'status' => $prospect->batch->status,
            ] : null,
            'matched_customer' => $prospect->matchedCustomer,
            'matched_lead' => $prospect->matchedLead,
            'converted_lead' => $prospect->convertedLead,
            'activities' => $prospect->relationLoaded('activities')
                ? $prospect->activities->map(fn ($activity) => $this->activityPayload($activity))->values()->all()
                : [],
        ];
    }

    private function activityPayload($activity): array
    {
        return [
            'id' => $activity->id,
            'activity_type' => $activity->activity_type,
            'channel' => $activity->channel,
            'summary' => $activity->summary,
            'payload' => $activity->payload,
            'campaign_run_id' => $activity->campaign_run_id,
            'campaign_recipient_id' => $activity->campaign_recipient_id,
            'actor_user_id' => $activity->actor_user_id,
            'occurred_at' => optional($activity->occurred_at)->toJSON(),
            'created_at' => optional($activity->created_at)->toJSON(),
        ];
    }

    private function resolveCampaignAccess(?User $user, Campaign $campaign): array
    {
        if (! $user) {
            abort(401);
        }

        $ownerId = $user->accountOwnerId();
        $owner = $ownerId === $user->id
            ? $user
            : User::query()->select(['id'])->find($ownerId);

        if (! $owner || (int) $campaign->user_id !== (int) $owner->id) {
            abort(404);
        }

        if ($user->id === $owner->id) {
            return [$owner, true, true, true];
        }

        $membership = $user->relationLoaded('teamMembership')
            ? $user->teamMembership
            : $user->teamMembership()->first();

        $canManage = (bool) (
            $membership?->hasPermission('campaigns.manage')
            || $membership?->hasPermission('sales.manage')
        );
        $canSend = (bool) $membership?->hasPermission('campaigns.send');
        $canView = $canManage
            || $canSend
            || (bool) $membership?->hasPermission('campaigns.view');

        return [$owner, $canView, $canManage, $canSend];
    }
}
