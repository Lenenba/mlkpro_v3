<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\CampaignAutomationRule;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CampaignAutomationController extends Controller
{
    public function index(Request $request)
    {
        [$owner, , $canManage] = $this->resolveAccess($request->user());
        if (!$canManage) {
            abort(403);
        }

        $rules = CampaignAutomationRule::query()
            ->where('user_id', $owner->id)
            ->with('campaign:id,name,status')
            ->orderByDesc('updated_at')
            ->get();

        return response()->json([
            'rules' => $rules,
        ]);
    }

    public function store(Request $request)
    {
        [$owner, , $canManage] = $this->resolveAccess($request->user());
        if (!$canManage) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'campaign_id' => 'nullable|integer',
            'trigger_type' => [
                'required',
                Rule::in([
                    CampaignAutomationRule::TRIGGER_PRODUCT_BACK_IN_STOCK,
                    CampaignAutomationRule::TRIGGER_PROMOTION_CREATED,
                    CampaignAutomationRule::TRIGGER_AFTER_PURCHASE,
                    CampaignAutomationRule::TRIGGER_INACTIVE_CUSTOMER,
                ]),
            ],
            'trigger_config' => 'nullable|array',
            'delay_minutes' => 'nullable|integer|min:0|max:43200',
            'is_active' => 'nullable|boolean',
        ]);

        if (!empty($validated['campaign_id'])) {
            $exists = Campaign::query()
                ->where('user_id', $owner->id)
                ->whereKey((int) $validated['campaign_id'])
                ->exists();
            if (!$exists) {
                abort(422, 'Invalid campaign for this tenant.');
            }
        }

        $rule = CampaignAutomationRule::query()->create([
            'user_id' => $owner->id,
            'campaign_id' => $validated['campaign_id'] ?? null,
            'created_by_user_id' => $request->user()?->id,
            'updated_by_user_id' => $request->user()?->id,
            'name' => (string) $validated['name'],
            'trigger_type' => (string) $validated['trigger_type'],
            'trigger_config' => $validated['trigger_config'] ?? null,
            'delay_minutes' => (int) ($validated['delay_minutes'] ?? 0),
            'is_active' => array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : true,
        ]);

        return response()->json([
            'message' => 'Automation rule created.',
            'rule' => $rule->fresh(['campaign:id,name,status']),
        ], 201);
    }

    public function update(Request $request, CampaignAutomationRule $rule)
    {
        [$owner, , $canManage] = $this->resolveAccess($request->user());
        if (!$canManage) {
            abort(403);
        }

        if ((int) $rule->user_id !== (int) $owner->id) {
            abort(404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'campaign_id' => 'nullable|integer',
            'trigger_type' => [
                'sometimes',
                Rule::in([
                    CampaignAutomationRule::TRIGGER_PRODUCT_BACK_IN_STOCK,
                    CampaignAutomationRule::TRIGGER_PROMOTION_CREATED,
                    CampaignAutomationRule::TRIGGER_AFTER_PURCHASE,
                    CampaignAutomationRule::TRIGGER_INACTIVE_CUSTOMER,
                ]),
            ],
            'trigger_config' => 'nullable|array',
            'delay_minutes' => 'nullable|integer|min:0|max:43200',
            'is_active' => 'nullable|boolean',
        ]);

        if (!empty($validated['campaign_id'])) {
            $exists = Campaign::query()
                ->where('user_id', $owner->id)
                ->whereKey((int) $validated['campaign_id'])
                ->exists();
            if (!$exists) {
                abort(422, 'Invalid campaign for this tenant.');
            }
        }

        $rule->fill($validated);
        $rule->updated_by_user_id = $request->user()?->id;
        $rule->save();

        return response()->json([
            'message' => 'Automation rule updated.',
            'rule' => $rule->fresh(['campaign:id,name,status']),
        ]);
    }

    public function destroy(Request $request, CampaignAutomationRule $rule)
    {
        [$owner, , $canManage] = $this->resolveAccess($request->user());
        if (!$canManage) {
            abort(403);
        }

        if ((int) $rule->user_id !== (int) $owner->id) {
            abort(404);
        }

        $rule->delete();

        return response()->json([
            'message' => 'Automation rule deleted.',
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
        $canView = $canManage || (bool) $membership?->hasPermission('campaigns.view');

        return [$owner, $canView, $canManage];
    }
}
