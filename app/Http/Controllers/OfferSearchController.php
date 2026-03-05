<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Campaigns\OfferSearchService;
use Illuminate\Http\Request;

class OfferSearchController extends Controller
{
    public function __construct(
        private readonly OfferSearchService $offerSearchService,
    ) {
    }

    public function search(Request $request)
    {
        [$owner, $canView] = $this->resolveAccess($request->user());
        if (!$canView) {
            abort(403);
        }

        $validated = $request->validate([
            'q' => 'nullable|string|max:120',
            'type' => 'nullable|string|in:product,service,all',
            'sort' => 'nullable|string|in:relevance,newest,best_sellers,alphabetical',
            'cursor' => 'nullable|string|max:500',
            'limit' => 'nullable|integer|min:1|max:50',
            'category' => 'nullable|array',
            'category.*' => 'integer',
            'category_id' => 'nullable|integer',
            'status' => 'nullable|string|in:active,inactive,all',
            'availability' => 'nullable|string|in:in_stock,bookable,all',
            'price_min' => 'nullable|numeric|min:0',
            'price_max' => 'nullable|numeric|min:0',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:60',
        ]);

        $payload = $this->offerSearchService->search($owner, $validated);

        return response()->json($payload);
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

