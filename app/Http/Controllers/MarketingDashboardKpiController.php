<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Campaigns\DashboardKpiService;
use Illuminate\Http\Request;

class MarketingDashboardKpiController extends Controller
{
    public function __construct(
        private readonly DashboardKpiService $dashboardKpiService,
    ) {
    }

    public function __invoke(Request $request)
    {
        [$owner, $canView] = $this->resolveAccess($request->user());
        if (!$canView) {
            abort(403);
        }

        $validated = $request->validate([
            'range' => 'nullable|string|in:7,30,90,custom',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
        ]);

        $kpis = $this->dashboardKpiService->resolve($owner, $validated);

        return response()->json([
            'kpis' => $kpis,
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
            return [$owner, true];
        }

        $membership = $user->relationLoaded('teamMembership')
            ? $user->teamMembership
            : $user->teamMembership()->first();

        $canView = (bool) (
            $membership?->hasPermission('campaigns.view')
            || $membership?->hasPermission('campaigns.manage')
            || $membership?->hasPermission('campaigns.send')
            || $membership?->hasPermission('sales.manage')
        );

        return [$owner, $canView];
    }
}
