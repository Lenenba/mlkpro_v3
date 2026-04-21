<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Queries\CRM\BuildSalesManagerDashboardData;
use Illuminate\Http\Request;

class SalesManagerDashboardController extends Controller
{
    public function __construct(
        private readonly BuildSalesManagerDashboardData $salesManagerDashboardData,
    ) {}

    public function index(Request $request)
    {
        $actor = $request->user();
        if (! $actor || $actor->isClient() || $actor->isSuperadmin() || $actor->isPlatformAdmin()) {
            abort(403);
        }

        $ownerId = $actor->accountOwnerId();
        $owner = (int) $ownerId === (int) $actor->id
            ? $actor
            : User::query()->find($ownerId);

        if (! $owner) {
            abort(403);
        }

        if ((string) $owner->company_type === 'products') {
            abort(404);
        }

        if ((int) $actor->id !== (int) $owner->id) {
            $membership = $actor->relationLoaded('teamMembership')
                ? $actor->teamMembership
                : $actor->teamMembership()->first();

            if (! $membership || ! $membership->hasPermission('sales.manage')) {
                abort(403);
            }
        }

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'reference_time' => ['nullable', 'date'],
        ]);

        $payload = $this->salesManagerDashboardData->execute($owner->id, $validated);

        return $this->inertiaOrJson('CRM/ManagerDashboard', $payload);
    }
}
