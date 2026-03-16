<?php

namespace App\Http\Controllers;

use App\Models\CampaignProspectProviderConnection;
use App\Models\User;
use App\Services\Campaigns\ProspectProviderConnectionService;
use App\Services\Campaigns\ProspectProviderRegistry;
use Illuminate\Http\Request;

class MarketingProspectProviderConnectionController extends Controller
{
    public function __construct(
        private readonly ProspectProviderConnectionService $connectionService,
        private readonly ProspectProviderRegistry $registry,
    ) {
    }

    public function manage(Request $request)
    {
        [$owner, $canView, $canManageSecrets, $canUseConnections] = $this->resolveAccess($request->user());
        if (! $canView) {
            abort(403);
        }

        return $this->inertiaOrJson('Campaigns/ProspectProviders', [
            'provider_definitions' => $this->registry->definitions(),
            'provider_connections' => $this->connectionService->listPayloads($owner),
            'provider_summary' => $this->connectionService->summaryForOwner($owner),
            'access' => [
                'can_view' => $canView,
                'can_manage_secrets' => $canManageSecrets,
                'can_use_connections' => $canUseConnections,
            ],
        ]);
    }

    public function index(Request $request)
    {
        [$owner, $canView, $canManageSecrets, $canUseConnections] = $this->resolveAccess($request->user());
        if (! $canView) {
            abort(403);
        }

        return response()->json([
            'provider_definitions' => $this->registry->definitions(),
            'provider_connections' => $this->connectionService->listPayloads($owner),
            'provider_summary' => $this->connectionService->summaryForOwner($owner),
            'access' => [
                'can_view' => $canView,
                'can_manage_secrets' => $canManageSecrets,
                'can_use_connections' => $canUseConnections,
            ],
        ]);
    }

    public function store(Request $request)
    {
        [$owner, , $canManageSecrets] = $this->resolveAccess($request->user());
        if (! $canManageSecrets) {
            abort(403);
        }

        $validated = $request->validate([
            'provider_key' => ['required', 'string', 'max:40', 'in:apollo,lusha,uplead'],
            'label' => ['required', 'string', 'max:120', 'min:1'],
            'credentials' => ['required', 'array'],
        ]);

        $connection = $this->connectionService->create($owner, $validated);

        return response()->json([
            'message' => 'Prospect provider connection created.',
            'provider_connection' => $this->connectionService->payload($connection),
        ], 201);
    }

    public function update(Request $request, CampaignProspectProviderConnection $connection)
    {
        [$owner, , $canManageSecrets] = $this->resolveAccess($request->user());
        if (! $canManageSecrets) {
            abort(403);
        }

        $validated = $request->validate([
            'provider_key' => ['nullable', 'string', 'max:40', 'in:apollo,lusha,uplead'],
            'label' => ['nullable', 'string', 'max:120', 'min:1'],
            'credentials' => ['nullable', 'array'],
        ]);

        $updated = $this->connectionService->update($owner, $connection, $validated);

        return response()->json([
            'message' => 'Prospect provider connection updated.',
            'provider_connection' => $this->connectionService->payload($updated),
        ]);
    }

    public function validateConnection(Request $request, CampaignProspectProviderConnection $connection)
    {
        [$owner, , $canManageSecrets] = $this->resolveAccess($request->user());
        if (! $canManageSecrets) {
            abort(403);
        }

        $validated = $this->connectionService->validateConnection($owner, $connection);

        return response()->json([
            'message' => 'Prospect provider connection validated.',
            'provider_connection' => $this->connectionService->payload($validated),
        ]);
    }

    public function disconnect(Request $request, CampaignProspectProviderConnection $connection)
    {
        [$owner, , $canManageSecrets] = $this->resolveAccess($request->user());
        if (! $canManageSecrets) {
            abort(403);
        }

        $disconnected = $this->connectionService->disconnect($owner, $connection);

        return response()->json([
            'message' => 'Prospect provider connection disconnected.',
            'provider_connection' => $this->connectionService->payload($disconnected),
        ]);
    }

    private function resolveAccess(?User $user): array
    {
        if (! $user) {
            abort(401);
        }

        $ownerId = $user->accountOwnerId();
        $owner = $ownerId === $user->id
            ? $user
            : User::query()->select(['id'])->find($ownerId);
        if (! $owner) {
            abort(403);
        }

        if ($user->id === $owner->id) {
            return [$owner, true, true, true];
        }

        $membership = $user->relationLoaded('teamMembership')
            ? $user->teamMembership
            : $user->teamMembership()->first();

        $canUseConnections = (bool) (
            $membership?->hasPermission('campaigns.view')
            || $membership?->hasPermission('campaigns.manage')
            || $membership?->hasPermission('campaigns.send')
            || $membership?->hasPermission('sales.manage')
        );

        return [$owner, $canUseConnections, false, $canUseConnections];
    }
}
