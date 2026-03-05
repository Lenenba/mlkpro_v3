<?php

namespace App\Http\Controllers;

use App\Http\Requests\Campaigns\StoreCampaignRequest;
use App\Http\Requests\Campaigns\UpdateCampaignRequest;
use App\Models\AudienceSegment;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Product;
use App\Models\User;
use App\Services\Campaigns\CampaignService;
use App\Services\Campaigns\MarketingSettingsService;
use Illuminate\Http\Request;

class CampaignController extends Controller
{
    public function __construct(
        private readonly CampaignService $campaignService,
        private readonly MarketingSettingsService $marketingSettingsService,
    ) {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        [$owner, $canView, $canManage, $canSend] = $this->resolveCampaignAccess($user);
        if (!$canView) {
            abort(403);
        }

        $filters = $request->only(['search', 'status', 'type']);
        $campaignsQuery = Campaign::query()
            ->where('user_id', $owner->id)
            ->when($filters['search'] ?? null, function ($query, $search): void {
                $search = trim((string) $search);
                if ($search === '') {
                    return;
                }

                $query->where('name', 'like', '%' . $search . '%');
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
            ->simplePaginate(12)
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
        if (!$user) {
            abort(401);
        }

        [$owner, , $canManage, $canSend] = $this->resolveCampaignAccess($user);
        if (!$canManage) {
            abort(403);
        }

        return $this->inertiaOrJson('Campaigns/Wizard', [
            'campaign' => null,
            'products' => [],
            'selectedOffers' => [],
            'segments' => $this->segmentsForOwner($owner->id),
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
        if (!$user) {
            abort(401);
        }

        [$owner, , $canManage] = $this->resolveCampaignAccess($user);
        if (!$canManage) {
            abort(403);
        }

        $validated = $request->validated();
        $segmentId = $validated['audience_segment_id'] ?? null;
        if ($segmentId) {
            $segmentExists = AudienceSegment::query()
                ->where('user_id', $owner->id)
                ->whereKey($segmentId)
                ->exists();
            if (!$segmentExists) {
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
        if (!$user) {
            abort(401);
        }

        [$owner, , $canManage, $canSend] = $this->resolveCampaignAccess($user);
        if (!$canManage) {
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
        if (!$user) {
            abort(401);
        }

        [$owner, , $canManage] = $this->resolveCampaignAccess($user);
        if (!$canManage) {
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
            if (!$segmentExists) {
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
        if (!$user) {
            abort(401);
        }

        [$owner, $canView, $canManage, $canSend] = $this->resolveCampaignAccess($user);
        if (!$canView) {
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

        return $this->inertiaOrJson('Campaigns/Show', [
            'campaign' => $campaign,
            'eventStats' => $eventsByType,
            'clickNoConversion' => $clickNoConversion,
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
        if (!$user) {
            abort(401);
        }

        [$owner, , $canManage] = $this->resolveCampaignAccess($user);
        if (!$canManage) {
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

    private function enums(): array
    {
        return [
            'types' => Campaign::allowedTypes(),
            'channels' => Campaign::allowedChannels(),
            'offer_modes' => Campaign::allowedOfferModes(),
            'language_modes' => Campaign::allowedLanguageModes(),
            'offer_types' => ['product', 'service'],
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
                if (!$model) {
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

    private function resolveCampaignAccess(User $user): array
    {
        $ownerId = $user->accountOwnerId();
        $owner = $ownerId === $user->id
            ? $user
            : User::query()->select(['id'])->find($ownerId);

        if (!$owner) {
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
