<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\User;
use App\Models\VipTier;
use App\Services\Campaigns\VipService;
use Illuminate\Http\Request;

class MarketingVipController extends Controller
{
    public function __construct(
        private readonly VipService $vipService,
    ) {
    }

    public function index(Request $request)
    {
        [$owner, $canView] = $this->resolveAccess($request->user());
        if (!$canView) {
            abort(403);
        }

        $validated = $request->validate([
            'is_active' => 'nullable|boolean',
        ]);

        $tiers = $this->vipService->listTiers($owner, $validated);
        $vipCustomersCount = Customer::query()
            ->where('user_id', $owner->id)
            ->where('is_vip', true)
            ->count();

        return response()->json([
            'vip_tiers' => $tiers,
            'vip_customers_count' => $vipCustomersCount,
        ]);
    }

    public function store(Request $request)
    {
        [$owner, , $canManage] = $this->resolveAccess($request->user());
        if (!$canManage) {
            abort(403);
        }

        $validated = $this->validatedTierPayload($request);
        $tier = $this->vipService->saveTier($owner, $request->user(), $validated);

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'VIP tier created.',
                'vip_tier' => $tier,
            ], 201);
        }

        return redirect()->back()->with('success', 'VIP tier created.');
    }

    public function update(Request $request, VipTier $vipTier)
    {
        [$owner, , $canManage] = $this->resolveAccess($request->user());
        if (!$canManage) {
            abort(403);
        }

        if ((int) $vipTier->user_id !== (int) $owner->id) {
            abort(404);
        }

        $validated = $this->validatedTierPayload($request);
        $tier = $this->vipService->saveTier($owner, $request->user(), $validated, $vipTier);

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'VIP tier updated.',
                'vip_tier' => $tier,
            ]);
        }

        return redirect()->back()->with('success', 'VIP tier updated.');
    }

    public function destroy(Request $request, VipTier $vipTier)
    {
        [$owner, , $canManage] = $this->resolveAccess($request->user());
        if (!$canManage) {
            abort(403);
        }

        if ((int) $vipTier->user_id !== (int) $owner->id) {
            abort(404);
        }

        $this->vipService->deleteTier($owner, $vipTier);

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'VIP tier deleted.',
            ]);
        }

        return redirect()->back()->with('success', 'VIP tier deleted.');
    }

    public function updateCustomer(Request $request, Customer $customer)
    {
        [$owner, , $canManage] = $this->resolveAccess($request->user());
        if (!$canManage) {
            abort(403);
        }

        if ((int) $customer->user_id !== (int) $owner->id) {
            abort(404);
        }

        $validated = $request->validate([
            'is_vip' => ['required', 'boolean'],
            'vip_tier_id' => ['nullable', 'integer'],
        ]);

        $updatedCustomer = $this->vipService->updateCustomerVip(
            $owner,
            $request->user(),
            $customer,
            (bool) $validated['is_vip'],
            isset($validated['vip_tier_id']) ? (int) $validated['vip_tier_id'] : null
        );

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Customer VIP profile updated.',
                'customer' => $updatedCustomer,
            ]);
        }

        return redirect()->back()->with('success', 'Profil VIP client mis a jour.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedTierPayload(Request $request): array
    {
        return $request->validate([
            'code' => 'required|string|max:40',
            'name' => 'required|string|max:120',
            'perks' => 'nullable',
            'is_active' => 'nullable|boolean',
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
