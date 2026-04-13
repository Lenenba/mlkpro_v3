<?php

namespace App\Http\Controllers;

use App\Enums\CampaignAudienceSourceLogic;
use App\Http\Requests\Campaigns\StoreCampaignRequest;
use App\Http\Requests\Campaigns\UpdateCampaignRequest;
use App\Models\AudienceSegment;
use App\Models\Campaign;
use App\Models\CampaignProspect;
use App\Models\CampaignProspectBatch;
use App\Models\CampaignRecipient;
use App\Models\MailingList;
use App\Models\Product;
use App\Models\User;
use App\Models\VipTier;
use App\Services\Campaigns\CampaignLeadAttributionService;
use App\Services\Campaigns\CampaignService;
use App\Services\Campaigns\MarketingSettingsService;
use App\Services\Campaigns\ProspectProviderConnectionService;
use App\Services\Customers\CustomerBulkAudienceBridgeService;
use Illuminate\Http\Request;

class CampaignController extends Controller
{
    public function __construct(
        private readonly CampaignService $campaignService,
        private readonly MarketingSettingsService $marketingSettingsService,
        private readonly CampaignLeadAttributionService $leadAttributionService,
        private readonly ProspectProviderConnectionService $prospectProviderConnectionService,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        [$owner, $canView, $canManage, $canSend] = $this->resolveCampaignAccess($user);
        if (! $canView) {
            abort(403);
        }

        $filters = $request->only(['search', 'status', 'type']);
        $filters['per_page'] = $this->resolveDataTablePerPage($request);
        $campaignsQuery = Campaign::query()
            ->where('user_id', $owner->id)
            ->when($filters['search'] ?? null, function ($query, $search): void {
                $search = trim((string) $search);
                if ($search === '') {
                    return;
                }

                $query->where('name', 'like', '%'.$search.'%');
            })
            ->when($filters['status'] ?? null, function ($query, $status): void {
                $query->where('status', (string) $status);
            })
            ->when($filters['type'] ?? null, function ($query, $type): void {
                $candidate = (string) $type;
                $query->where(function ($builder) use ($candidate): void {
                    $builder->where('campaign_type', $candidate)
                        ->orWhere('type', $candidate);
                });
            });

        $campaigns = (clone $campaignsQuery)
            ->with([
                'offers.offer:id,name,price,image,stock,item_type,sku,number',
                'channels:id,campaign_id,channel,is_enabled',
            ])
            ->withCount(['runs', 'recipients'])
            ->orderByDesc('updated_at')
            ->paginate((int) $filters['per_page'])
            ->withQueryString();

        $stats = [
            'total' => (clone $campaignsQuery)->count(),
            'draft' => (clone $campaignsQuery)->where('status', Campaign::STATUS_DRAFT)->count(),
            'scheduled' => (clone $campaignsQuery)->where('status', Campaign::STATUS_SCHEDULED)->count(),
            'running' => (clone $campaignsQuery)->where('status', Campaign::STATUS_RUNNING)->count(),
            'completed' => (clone $campaignsQuery)->where('status', Campaign::STATUS_COMPLETED)->count(),
        ];

        return $this->inertiaOrJson('Campaigns/Index', [
            'campaigns' => $campaigns,
            'filters' => $filters,
            'stats' => $stats,
            'prospectProviderSummary' => $this->prospectProviderConnectionService->summaryForOwner($owner),
            'enums' => $this->enums(),
            'access' => [
                'can_view' => $canView,
                'can_manage' => $canManage,
                'can_send' => $canSend,
            ],
        ]);
    }

    public function create(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        [$owner, , $canManage, $canSend] = $this->resolveCampaignAccess($user);
        if (! $canManage) {
            abort(403);
        }

        [$seedAudience, $seedCampaign, $seedStep] = $this->resolveCustomerSeed($request, $owner->id);

        return $this->inertiaOrJson('Campaigns/Wizard', [
            'campaign' => null,
            'products' => [],
            'selectedOffers' => [],
            'seedAudience' => $seedAudience,
            'seedCampaign' => $seedCampaign,
            'seedStep' => $seedStep,
            'segments' => $this->segmentsForOwner($owner->id),
            'mailingLists' => $this->mailingListsForOwner($owner->id),
            'vipTiers' => $this->vipTiersForOwner($owner->id),
            'availableProspectProviders' => $this->prospectProviderConnectionService->availablePayloadsForAudience($owner),
            'enums' => $this->enums(),
            'marketingSettings' => $this->marketingSettingsService->getResolved($owner),
            'access' => [
                'can_manage' => $canManage,
                'can_send' => $canSend,
            ],
        ]);
    }

    public function store(StoreCampaignRequest $request)
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        [$owner, , $canManage] = $this->resolveCampaignAccess($user);
        if (! $canManage) {
            abort(403);
        }

        $validated = $request->validated();
        $segmentId = $validated['audience_segment_id'] ?? null;
        if ($segmentId) {
            $segmentExists = AudienceSegment::query()
                ->where('user_id', $owner->id)
                ->whereKey($segmentId)
                ->exists();
            if (! $segmentExists) {
                abort(422, 'Invalid segment for this tenant.');
            }
        }

        $campaign = $this->campaignService->saveCampaign($owner, $user, $validated);

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Campaign created.',
                'campaign' => $campaign,
            ], 201);
        }

        return redirect()
            ->route('campaigns.edit', $campaign)
            ->with('success', 'Campaign created.');
    }

    public function edit(Request $request, Campaign $campaign)
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        [$owner, , $canManage, $canSend] = $this->resolveCampaignAccess($user);
        if (! $canManage) {
            abort(403);
        }

        if ((int) $campaign->user_id !== (int) $owner->id) {
            abort(404);
        }

        $campaign->load([
            'offers.offer:id,name,price,stock,image,item_type,sku,number,promo_discount_percent,promo_end_at',
            'products:id,name,price,image,stock,promo_discount_percent,promo_end_at',
            'channels',
            'audience',
            'audienceSegment',
            'runs' => fn ($query) => $query->latest()->limit(5),
        ]);

        return $this->inertiaOrJson('Campaigns/Wizard', [
            'campaign' => $campaign,
            'products' => [],
            'selectedOffers' => $this->selectedOffersForCampaign($campaign),
            'segments' => $this->segmentsForOwner($owner->id),
            'mailingLists' => $this->mailingListsForOwner($owner->id),
            'vipTiers' => $this->vipTiersForOwner($owner->id),
            'availableProspectProviders' => $this->prospectProviderConnectionService->availablePayloadsForAudience($owner),
            'enums' => $this->enums(),
            'marketingSettings' => $this->marketingSettingsService->getResolved($owner),
            'access' => [
                'can_manage' => $canManage,
                'can_send' => $canSend,
            ],
        ]);
    }

    public function update(UpdateCampaignRequest $request, Campaign $campaign)
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        [$owner, , $canManage] = $this->resolveCampaignAccess($user);
        if (! $canManage) {
            abort(403);
        }

        if ((int) $campaign->user_id !== (int) $owner->id) {
            abort(404);
        }

        $validated = $request->validated();
        $segmentId = $validated['audience_segment_id'] ?? null;
        if ($segmentId) {
            $segmentExists = AudienceSegment::query()
                ->where('user_id', $owner->id)
                ->whereKey($segmentId)
                ->exists();
            if (! $segmentExists) {
                abort(422, 'Invalid segment for this tenant.');
            }
        }

        $campaign = $this->campaignService->saveCampaign($owner, $user, $validated, $campaign);

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Campaign updated.',
                'campaign' => $campaign,
            ]);
        }

        return redirect()
            ->back()
            ->with('success', 'Campaign updated.');
    }

    public function show(Request $request, Campaign $campaign)
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        [$owner, $canView, $canManage, $canSend] = $this->resolveCampaignAccess($user);
        if (! $canView) {
            abort(403);
        }

        if ((int) $campaign->user_id !== (int) $owner->id) {
            abort(404);
        }

        $campaign->load([
            'offers.offer:id,name,price,image,stock,item_type,sku,number',
            'products:id,name,price,image,stock',
            'channels',
            'audience',
            'runs' => fn ($query) => $query->latest()->withCount('recipients'),
        ]);

        $eventsByType = $campaign->events()
            ->selectRaw('event_type, COUNT(*) as total')
            ->groupBy('event_type')
            ->pluck('total', 'event_type');

        $clickNoConversion = CampaignRecipient::query()
            ->where('campaign_id', $campaign->id)
            ->whereNotNull('clicked_at')
            ->whereNull('converted_at')
            ->with('customer:id,first_name,last_name,company_name,email,phone')
            ->orderByDesc('clicked_at')
            ->limit(100)
            ->get([
                'id',
                'campaign_run_id',
                'customer_id',
                'channel',
                'destination',
                'status',
                'clicked_at',
                'converted_at',
            ]);

        $latestRun = $campaign->runs->first();
        $latestSummary = is_array($latestRun?->summary) ? $latestRun->summary : [];
        $abAssignments = is_array($latestSummary['ab_assignments'] ?? null)
            ? $latestSummary['ab_assignments']
            : [];
        $abVariantA = (int) ($abAssignments['A'] ?? 0);
        $abVariantB = (int) ($abAssignments['B'] ?? 0);
        $abTotal = $abVariantA + $abVariantB;

        $fallbackCount = 0;
        $channelInsights = [];
        if ($latestRun) {
            $fallbackCount = CampaignRecipient::query()
                ->where('campaign_run_id', $latestRun->id)
                ->whereNotNull('metadata->fallback->parent_recipient_id')
                ->count();

            $statusCounts = CampaignRecipient::query()
                ->where('campaign_run_id', $latestRun->id)
                ->selectRaw('channel, status, COUNT(*) as total')
                ->groupBy('channel', 'status')
                ->get();

            $fallbackByChannel = CampaignRecipient::query()
                ->where('campaign_run_id', $latestRun->id)
                ->whereNotNull('metadata->fallback->parent_recipient_id')
                ->selectRaw('channel, COUNT(*) as total')
                ->groupBy('channel')
                ->pluck('total', 'channel');

            $sentLikeStatuses = [
                CampaignRecipient::STATUS_SENT,
                CampaignRecipient::STATUS_DELIVERED,
                CampaignRecipient::STATUS_OPENED,
                CampaignRecipient::STATUS_CLICKED,
                CampaignRecipient::STATUS_CONVERTED,
            ];
            $deliveredLikeStatuses = [
                CampaignRecipient::STATUS_DELIVERED,
                CampaignRecipient::STATUS_OPENED,
                CampaignRecipient::STATUS_CLICKED,
                CampaignRecipient::STATUS_CONVERTED,
            ];

            foreach ($statusCounts->groupBy('channel') as $channel => $rows) {
                $targeted = (int) $rows->sum('total');
                $sent = (int) $rows
                    ->filter(fn ($row) => in_array((string) $row->status, $sentLikeStatuses, true))
                    ->sum('total');
                $delivered = (int) $rows
                    ->filter(fn ($row) => in_array((string) $row->status, $deliveredLikeStatuses, true))
                    ->sum('total');
                $failed = (int) $rows
                    ->filter(fn ($row) => (string) $row->status === CampaignRecipient::STATUS_FAILED)
                    ->sum('total');
                $clicked = (int) $rows
                    ->filter(fn ($row) => (string) $row->status === CampaignRecipient::STATUS_CLICKED)
                    ->sum('total');
                $converted = (int) $rows
                    ->filter(fn ($row) => (string) $row->status === CampaignRecipient::STATUS_CONVERTED)
                    ->sum('total');
                $fallbackForChannel = (int) ($fallbackByChannel[$channel] ?? 0);

                $channelInsights[(string) $channel] = [
                    'targeted' => $targeted,
                    'sent' => $sent,
                    'delivered' => $delivered,
                    'failed' => $failed,
                    'clicked' => $clicked,
                    'converted' => $converted,
                    'fallback_count' => $fallbackForChannel,
                    'delivery_rate_percent' => $targeted > 0
                        ? round(($delivered / $targeted) * 100, 2)
                        : 0.0,
                ];
            }
        }

        $failedCount = (int) ($latestSummary['failed'] ?? 0);
        $fallbackRate = $failedCount > 0
            ? round(($fallbackCount / $failedCount) * 100, 2)
            : 0.0;

        $deliveryInsights = [
            'latest_run_id' => $latestRun?->id,
            'ab_assignments' => [
                'A' => $abVariantA,
                'B' => $abVariantB,
                'total' => $abTotal,
                'split_a_percent' => $abTotal > 0 ? round(($abVariantA / $abTotal) * 100, 2) : null,
                'split_b_percent' => $abTotal > 0 ? round(($abVariantB / $abTotal) * 100, 2) : null,
            ],
            'holdout_count' => (int) ($latestSummary['holdout_count'] ?? 0),
            'fallback' => [
                'count' => $fallbackCount,
                'failed_count' => $failedCount,
                'rate_percent' => $fallbackRate,
            ],
            'channels' => $channelInsights,
        ];
        $funnel = $this->leadAttributionService->funnelForCampaign($campaign);
        $prospectingDashboard = $this->prospectingDashboard($campaign);

        return $this->inertiaOrJson('Campaigns/Show', [
            'campaign' => $campaign,
            'eventStats' => $eventsByType,
            'clickNoConversion' => $clickNoConversion,
            'deliveryInsights' => $deliveryInsights,
            'funnel' => $funnel,
            'prospectingDashboard' => $prospectingDashboard,
            'access' => [
                'can_view' => $canView,
                'can_manage' => $canManage,
                'can_send' => $canSend,
            ],
        ]);
    }

    public function destroy(Request $request, Campaign $campaign)
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        [$owner, , $canManage] = $this->resolveCampaignAccess($user);
        if (! $canManage) {
            abort(403);
        }

        if ((int) $campaign->user_id !== (int) $owner->id) {
            abort(404);
        }

        $campaign->delete();

        if ($this->shouldReturnJson($request)) {
            return response()->json(['message' => 'Campaign deleted.']);
        }

        return redirect()
            ->route('campaigns.index')
            ->with('success', 'Campaign deleted.');
    }

    private function prospectingDashboard(Campaign $campaign): array
    {
        $batchBaseQuery = CampaignProspectBatch::query()->where('campaign_id', $campaign->id);
        $prospectBaseQuery = CampaignProspect::query()->where('campaign_id', $campaign->id);

        $summary = [
            'total_batches' => (clone $batchBaseQuery)->count(),
            'pending_review_batches' => (clone $batchBaseQuery)->where('status', CampaignProspectBatch::STATUS_ANALYZED)->count(),
            'approved_batches' => (clone $batchBaseQuery)->where('status', CampaignProspectBatch::STATUS_APPROVED)->count(),
            'running_batches' => (clone $batchBaseQuery)->where('status', CampaignProspectBatch::STATUS_RUNNING)->count(),
            'total_prospects' => (clone $prospectBaseQuery)->count(),
            'review_required_prospects' => (clone $prospectBaseQuery)->where('status', CampaignProspect::STATUS_SCORED)->count(),
            'ready_for_outreach_prospects' => (clone $prospectBaseQuery)->where('status', CampaignProspect::STATUS_APPROVED)->count(),
            'follow_up_due_prospects' => (clone $prospectBaseQuery)->where('status', CampaignProspect::STATUS_FOLLOW_UP_DUE)->count(),
            'qualified_prospects' => (clone $prospectBaseQuery)->where('status', CampaignProspect::STATUS_QUALIFIED)->count(),
            'converted_leads' => (clone $prospectBaseQuery)
                ->where(function ($query): void {
                    $query->whereNotNull('converted_to_lead_id')
                        ->orWhere('status', CampaignProspect::STATUS_CONVERTED_TO_LEAD);
                })
                ->count(),
            'do_not_contact_prospects' => (clone $prospectBaseQuery)
                ->where(function ($query): void {
                    $query->where('do_not_contact', true)
                        ->orWhere('status', CampaignProspect::STATUS_DO_NOT_CONTACT);
                })
                ->count(),
        ];

        $recentBatches = (clone $batchBaseQuery)
            ->orderByDesc('batch_number')
            ->limit(5)
            ->get([
                'id',
                'batch_number',
                'status',
                'source_type',
                'source_reference',
                'input_count',
                'accepted_count',
                'duplicate_count',
                'blocked_count',
                'rejected_count',
                'contacted_count',
                'lead_count',
                'created_at',
            ])
            ->map(fn (CampaignProspectBatch $batch) => [
                'id' => $batch->id,
                'batch_number' => $batch->batch_number,
                'status' => $batch->status,
                'source_type' => $batch->source_type,
                'source_reference' => $batch->source_reference,
                'input_count' => $batch->input_count,
                'accepted_count' => $batch->accepted_count,
                'duplicate_count' => $batch->duplicate_count,
                'blocked_count' => $batch->blocked_count,
                'rejected_count' => $batch->rejected_count,
                'contacted_count' => $batch->contacted_count,
                'lead_count' => $batch->lead_count,
                'created_at' => optional($batch->created_at)->toJSON(),
            ])
            ->values()
            ->all();

        $topProspects = CampaignProspect::query()
            ->where('campaign_id', $campaign->id)
            ->with([
                'batch:id,batch_number,status',
                'matchedLead:id,title,contact_name,contact_email,status',
                'convertedLead:id,title,contact_name,contact_email,status',
            ])
            ->orderByDesc('priority_score')
            ->orderByDesc('fit_score')
            ->orderByDesc('id')
            ->limit(8)
            ->get([
                'id',
                'campaign_prospect_batch_id',
                'source_type',
                'source_reference',
                'company_name',
                'contact_name',
                'email',
                'phone',
                'status',
                'match_status',
                'priority_score',
                'fit_score',
                'intent_score',
                'matched_lead_id',
                'converted_to_lead_id',
                'last_activity_at',
            ])
            ->map(fn (CampaignProspect $prospect) => [
                'id' => $prospect->id,
                'batch' => $prospect->batch ? [
                    'id' => $prospect->batch->id,
                    'batch_number' => $prospect->batch->batch_number,
                    'status' => $prospect->batch->status,
                ] : null,
                'source_type' => $prospect->source_type,
                'source_reference' => $prospect->source_reference,
                'company_name' => $prospect->company_name,
                'contact_name' => $prospect->contact_name,
                'email' => $prospect->email,
                'phone' => $prospect->phone,
                'status' => $prospect->status,
                'match_status' => $prospect->match_status,
                'priority_score' => $prospect->priority_score,
                'fit_score' => $prospect->fit_score,
                'intent_score' => $prospect->intent_score,
                'matched_lead' => $prospect->matchedLead ? [
                    'id' => $prospect->matchedLead->id,
                    'title' => $prospect->matchedLead->title,
                    'contact_name' => $prospect->matchedLead->contact_name,
                    'contact_email' => $prospect->matchedLead->contact_email,
                    'status' => $prospect->matchedLead->status,
                ] : null,
                'converted_lead' => $prospect->convertedLead ? [
                    'id' => $prospect->convertedLead->id,
                    'title' => $prospect->convertedLead->title,
                    'contact_name' => $prospect->convertedLead->contact_name,
                    'contact_email' => $prospect->convertedLead->contact_email,
                    'status' => $prospect->convertedLead->status,
                ] : null,
                'last_activity_at' => optional($prospect->last_activity_at)->toJSON(),
            ])
            ->values()
            ->all();

        return [
            'enabled' => $campaign->usesProspecting(),
            'direction' => $campaign->resolvedCampaignDirection(),
            'summary' => $summary,
            'recent_batches' => $recentBatches,
            'top_prospects' => $topProspects,
        ];
    }

    private function productsForOwner(int $ownerId)
    {
        return Product::query()
            ->where('user_id', $ownerId)
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'price',
                'stock',
                'image',
                'promo_discount_percent',
                'promo_end_at',
            ]);
    }

    private function segmentsForOwner(int $ownerId)
    {
        return AudienceSegment::query()
            ->where('user_id', $ownerId)
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'description',
                'filters',
                'exclusions',
                'tags',
                'cached_count',
                'last_computed_at',
                'updated_at',
            ]);
    }

    private function mailingListsForOwner(int $ownerId)
    {
        return MailingList::query()
            ->where('user_id', $ownerId)
            ->withCount('customers')
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'description',
                'tags',
                'updated_at',
            ]);
    }

    private function vipTiersForOwner(int $ownerId)
    {
        return VipTier::query()
            ->where('user_id', $ownerId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get([
                'id',
                'code',
                'name',
                'perks',
                'is_active',
            ]);
    }

    private function enums(): array
    {
        return [
            'types' => Campaign::allowedTypes(),
            'directions' => Campaign::allowedDirections(),
            'channels' => Campaign::allowedChannels(),
            'offer_modes' => Campaign::allowedOfferModes(),
            'language_modes' => Campaign::allowedLanguageModes(),
            'offer_types' => ['product', 'service'],
            'audience_source_logic' => CampaignAudienceSourceLogic::values(),
            'statuses' => [
                Campaign::STATUS_DRAFT,
                Campaign::STATUS_SCHEDULED,
                Campaign::STATUS_RUNNING,
                Campaign::STATUS_COMPLETED,
                Campaign::STATUS_CANCELED,
                Campaign::STATUS_FAILED,
            ],
        ];
    }

    private function selectedOffersForCampaign(Campaign $campaign): array
    {
        return $campaign->offers
            ->map(function ($offer) {
                $model = $offer->offer;
                if (! $model) {
                    return null;
                }

                $type = strtolower((string) ($model->item_type ?: $offer->offer_type));

                return [
                    'offer_type' => $type,
                    'offer_id' => (int) $model->id,
                    'id' => (int) $model->id,
                    'type' => $type,
                    'name' => (string) $model->name,
                    'price' => (float) $model->price,
                    'status' => $model->is_active ? 'active' : 'inactive',
                    'thumbnailUrl' => $model->image_url,
                    'categoryName' => $model->relationLoaded('category') ? $model->category?->name : null,
                    'sku' => $type === 'product' ? ($model->sku ?: null) : null,
                    'serviceCode' => $type === 'service' ? ($model->number ?: $model->sku ?: null) : null,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>, 2: int|null}
     */
    private function resolveCustomerSeed(Request $request, int $ownerId): array
    {
        $validated = $request->validate([
            'seed_mailing_list_id' => ['nullable', 'integer'],
            'seed_objective' => ['nullable', 'string', 'max:60'],
            'seed_step' => ['nullable', 'integer', 'min:1', 'max:5'],
        ]);

        $mailingListId = (int) ($validated['seed_mailing_list_id'] ?? 0);
        if ($mailingListId < 1) {
            return [null, [], null];
        }

        $mailingList = MailingList::query()
            ->where('user_id', $ownerId)
            ->withCount('customers')
            ->find($mailingListId);

        if (! $mailingList) {
            abort(404);
        }

        $bulkAudienceBridgeService = app(CustomerBulkAudienceBridgeService::class);
        $seedObjective = (string) ($validated['seed_objective'] ?? '');

        return [[
            'include_mailing_list_ids' => [(int) $mailingList->id],
            'exclude_mailing_list_ids' => [],
            'source_logic' => CampaignAudienceSourceLogic::UNION->value,
            'label' => (string) $mailingList->name,
            'customers_count' => (int) ($mailingList->customers_count ?? 0),
        ], [
            'name' => $bulkAudienceBridgeService->seedCampaignName($mailingList, $seedObjective),
            'campaign_type' => $bulkAudienceBridgeService->seedCampaignType($seedObjective),
        ], (int) ($validated['seed_step'] ?? 3)];
    }

    private function resolveCampaignAccess(User $user): array
    {
        $ownerId = $user->accountOwnerId();
        $owner = $ownerId === $user->id
            ? $user
            : User::query()->find($ownerId);

        if (! $owner) {
            abort(403);
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
