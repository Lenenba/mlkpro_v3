<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportBufferChannelRequest;
use App\Models\User;
use App\Services\Social\Buffer\BufferLocalConnectorService;
use App\Services\Social\SocialAccountConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SocialBufferController extends Controller
{
    public function __construct(
        private readonly BufferLocalConnectorService $bufferConnector,
        private readonly SocialAccountConnectionService $connectionService,
    ) {}

    public function catalog(Request $request): JsonResponse
    {
        [$owner, , $canManageAccounts] = $this->resolveAccess($request->user());

        if (! $canManageAccounts) {
            abort(403);
        }

        return response()->json($this->bufferConnector->catalog($owner));
    }

    public function store(ImportBufferChannelRequest $request): JsonResponse
    {
        [$owner, , $canManageAccounts] = $this->resolveAccess($request->user());

        if (! $canManageAccounts) {
            abort(403);
        }

        $connection = $this->bufferConnector->importChannel(
            $owner,
            $request->string('organization_id')->toString(),
            $request->string('channel_id')->toString(),
        );

        return response()->json([
            'message_key' => 'social.buffer_connector.messages.import_success',
            'connection' => $this->connectionService->payload($connection),
        ], 201);
    }

    /**
     * @return array{0: User, 1: bool, 2: bool}
     */
    private function resolveAccess(?User $user): array
    {
        if (! $user) {
            abort(401);
        }

        $ownerId = $user->accountOwnerId();
        $owner = $ownerId === $user->id
            ? $user
            : User::query()->find($ownerId);

        if (! $owner) {
            abort(403);
        }

        if ((int) $user->id === (int) $owner->id) {
            return [$owner, true, true];
        }

        $membership = $user->relationLoaded('teamMembership')
            ? $user->teamMembership
            : $user->teamMembership()->first();

        $canView = (bool) (
            $membership?->hasPermission('social.view')
            || $membership?->hasPermission('social.manage')
            || $membership?->hasPermission('social.publish')
            || $membership?->hasPermission('social.approve')
        );

        return [$owner, $canView, false];
    }
}
