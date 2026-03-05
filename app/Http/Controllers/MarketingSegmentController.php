<?php

namespace App\Http\Controllers;

use App\Models\AudienceSegment;
use App\Models\Campaign;
use App\Models\User;
use App\Services\Campaigns\SegmentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MarketingSegmentController extends Controller
{
    public function __construct(
        private readonly SegmentService $segmentService,
    ) {
    }

    public function index(Request $request)
    {
        [$owner, $canView] = $this->resolveAccess($request->user());
        if (!$canView) {
            abort(403);
        }

        $validated = $request->validate([
            'search' => 'nullable|string|max:120',
        ]);

        $segments = $this->segmentService->list($owner, $validated);

        return response()->json([
            'segments' => $segments,
        ]);
    }

    public function show(Request $request, AudienceSegment $segment)
    {
        [$owner, $canView] = $this->resolveAccess($request->user());
        if (!$canView) {
            abort(403);
        }

        if ((int) $segment->user_id !== (int) $owner->id) {
            abort(404);
        }

        return response()->json([
            'segment' => $segment,
        ]);
    }

    public function store(Request $request)
    {
        [$owner, , $canManage] = $this->resolveAccess($request->user());
        if (!$canManage) {
            abort(403);
        }

        $validated = $this->validatedPayload($request);
        $segment = $this->segmentService->save($owner, $request->user(), $validated);

        return response()->json([
            'message' => 'Segment created.',
            'segment' => $segment,
        ], 201);
    }

    public function update(Request $request, AudienceSegment $segment)
    {
        [$owner, , $canManage] = $this->resolveAccess($request->user());
        if (!$canManage) {
            abort(403);
        }

        if ((int) $segment->user_id !== (int) $owner->id) {
            abort(404);
        }

        $validated = $this->validatedPayload($request);
        $updated = $this->segmentService->save($owner, $request->user(), $validated, $segment);

        return response()->json([
            'message' => 'Segment updated.',
            'segment' => $updated,
        ]);
    }

    public function destroy(Request $request, AudienceSegment $segment)
    {
        [$owner, , $canManage] = $this->resolveAccess($request->user());
        if (!$canManage) {
            abort(403);
        }

        if ((int) $segment->user_id !== (int) $owner->id) {
            abort(404);
        }

        $this->segmentService->delete($owner, $segment);

        return response()->json([
            'message' => 'Segment deleted.',
        ]);
    }

    public function count(Request $request, AudienceSegment $segment)
    {
        [$owner, $canView] = $this->resolveAccess($request->user());
        if (!$canView) {
            abort(403);
        }

        if ((int) $segment->user_id !== (int) $owner->id) {
            abort(404);
        }

        $validated = $request->validate([
            'channels' => 'nullable|array',
            'channels.*' => ['string', Rule::in(Campaign::allowedChannels())],
        ]);

        $counts = $this->segmentService->computeEligibilityCounts(
            $owner,
            is_array($segment->filters) ? $segment->filters : [],
            is_array($segment->exclusions) ? $segment->exclusions : [],
            $validated['channels'] ?? []
        );

        $segment->forceFill([
            'cached_count' => (int) ($counts['total_eligible'] ?? 0),
            'last_computed_at' => now(),
        ])->save();

        return response()->json([
            'segment_id' => $segment->id,
            'counts' => $counts,
        ]);
    }

    public function previewCount(Request $request)
    {
        [$owner, $canView] = $this->resolveAccess($request->user());
        if (!$canView) {
            abort(403);
        }

        $validated = $request->validate([
            'filters' => 'nullable|array',
            'exclusions' => 'nullable|array',
            'channels' => 'nullable|array',
            'channels.*' => ['string', Rule::in(Campaign::allowedChannels())],
        ]);

        $counts = $this->segmentService->computeEligibilityCounts(
            $owner,
            is_array($validated['filters'] ?? null) ? $validated['filters'] : [],
            is_array($validated['exclusions'] ?? null) ? $validated['exclusions'] : [],
            $validated['channels'] ?? []
        );

        return response()->json([
            'counts' => $counts,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPayload(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1024',
            'filters' => 'nullable|array',
            'exclusions' => 'nullable|array',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:60',
            'is_shared' => 'nullable|boolean',
        ]);
    }

    private function resolveAccess(?User $user): array
    {
        if (!$user) {
            abort(401);
        }

        $ownerId = $user->accountOwnerId();
        $owner = $ownerId === $user->id
            ? $user
            : User::query()->select(['id'])->find($ownerId);
        if (!$owner) {
            abort(403);
        }

        if ($user->id === $owner->id) {
            return [$owner, true, true];
        }

        $membership = $user->relationLoaded('teamMembership')
            ? $user->teamMembership
            : $user->teamMembership()->first();

        $canManage = (bool) (
            $membership?->hasPermission('campaigns.manage')
            || $membership?->hasPermission('sales.manage')
        );
        $canView = $canManage
            || (bool) $membership?->hasPermission('campaigns.view')
            || (bool) $membership?->hasPermission('campaigns.send');

        return [$owner, $canView, $canManage];
    }
}

