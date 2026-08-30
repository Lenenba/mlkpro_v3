<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportBufferChannelRequest;
use App\Models\SocialAccountConnection;
use App\Models\User;
use App\Services\Social\Buffer\BufferLocalConnectorService;
use App\Services\Social\Buffer\BufferOAuthService;
use App\Services\Social\SocialAccountConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SocialBufferController extends Controller
{
    public function __construct(
        private readonly BufferLocalConnectorService $bufferConnector,
        private readonly BufferOAuthService $bufferOAuth,
        private readonly SocialAccountConnectionService $connectionService,
    ) {}

    public function connect(Request $request): JsonResponse
    {
        [$owner, , $canManageAccounts] = $this->resolveAccess($request->user());

        if (! $canManageAccounts) {
            abort(403);
        }

        return response()->json($this->bufferOAuth->beginAuthorization($owner));
    }

    public function callback(Request $request): RedirectResponse
    {
        try {
            $result = $this->bufferOAuth->completeAuthorization($request->query());
            $owner = User::query()->findOrFail($result['owner_id']);
            $this->bufferConnector->activateImportedChannels($owner);
        } catch (ValidationException $exception) {
            return redirect()
                ->route('social.accounts.index')
                ->with('error', $this->validationMessage($exception));
        }

        return redirect()
            ->route('social.accounts.index')
            ->with('success', $result['message']);
    }

    public function disconnect(Request $request): JsonResponse
    {
        [$owner, , $canManageAccounts] = $this->resolveAccess($request->user());

        if (! $canManageAccounts) {
            abort(403);
        }

        $this->bufferOAuth->disconnect($owner);

        return response()->json([
            'message_key' => 'social.buffer_connector.messages.disconnect_success',
            'connector' => $this->bufferConnector->status($owner),
        ]);
    }

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
            'connector' => $this->bufferConnector->status($owner),
            'connection' => $this->connectionService->payload($connection),
        ], 201);
    }

    public function sync(Request $request): JsonResponse
    {
        [$owner, , $canManageAccounts] = $this->resolveAccess($request->user());

        if (! $canManageAccounts) {
            abort(403);
        }

        $result = $this->bufferConnector->syncAvailableChannels($owner);

        return response()->json([
            'message_key' => 'social.buffer_connector.messages.sync_success',
            'connector' => $this->bufferConnector->status($owner),
            'synced_count' => $result['synced_count'],
            'active_count' => $result['active_count'],
            'skipped_count' => $result['skipped_count'],
            'connections' => collect($result['connections'])
                ->map(fn (SocialAccountConnection $connection): array => (
                    $this->connectionService->payload($connection)
                ))
                ->values()
                ->all(),
        ]);
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

    private function validationMessage(ValidationException $exception): string
    {
        return collect($exception->errors())
            ->flatten()
            ->first(fn (mixed $message): bool => is_string($message) && trim($message) !== '')
            ?: 'La connexion Buffer a échoué.';
    }
}
