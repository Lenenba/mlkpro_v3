<?php

namespace App\Http\Controllers;

use App\Models\SocialAccountConnection;
use App\Models\User;
use App\Services\Social\SocialAccountConnectionService;
use App\Services\Social\SocialPostService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SocialAccountConnectionController extends Controller
{
    public function __construct(
        private readonly SocialAccountConnectionService $connectionService,
        private readonly SocialPostService $postService,
    ) {}

    public function index(Request $request)
    {
        [$owner, $canView, $canManageAccounts] = $this->resolveAccess($request->user());
        if (! $canView) {
            abort(403);
        }

        $postSummary = $this->postService->summaryForOwner($owner);

        return $this->inertiaOrJson('Social/Accounts', [
            'provider_definitions' => $this->connectionService->definitions(),
            'connections' => $this->connectionService->listPayloads($owner),
            'summary' => $this->connectionService->summaryForOwner($owner),
            'workspace_stats' => [
                'connected_accounts' => (int) $this->connectionService->summaryForOwner($owner)['connected'],
                'draft_posts' => (int) ($postSummary['drafts'] ?? 0),
                'scheduled_posts' => (int) ($postSummary['scheduled'] ?? 0),
            ],
            'access' => [
                'can_view' => $canView,
                'can_manage_accounts' => $canManageAccounts,
            ],
        ]);
    }

    public function store(Request $request)
    {
        [$owner, , $canManageAccounts] = $this->resolveAccess($request->user());
        if (! $canManageAccounts) {
            abort(403);
        }

        $validated = $request->validate([
            'platform' => ['required', 'string', 'max:40', Rule::in(SocialAccountConnection::allowedPlatforms())],
            'label' => ['nullable', 'string', 'max:120', 'min:1'],
            'display_name' => ['nullable', 'string', 'max:191'],
            'account_handle' => ['nullable', 'string', 'max:191'],
            'external_account_id' => ['nullable', 'string', 'max:191'],
        ]);

        $connection = $this->connectionService->create($owner, $validated);

        return response()->json([
            'message' => 'Social account draft saved. Continue with OAuth to finish the connection.',
            'connection' => $this->connectionService->payload($connection),
        ], 201);
    }

    public function update(Request $request, SocialAccountConnection $connection)
    {
        [$owner, , $canManageAccounts] = $this->resolveAccess($request->user());
        if (! $canManageAccounts) {
            abort(403);
        }

        $validated = $request->validate([
            'label' => ['nullable', 'string', 'max:120', 'min:1'],
            'display_name' => ['nullable', 'string', 'max:191'],
            'account_handle' => ['nullable', 'string', 'max:191'],
            'external_account_id' => ['nullable', 'string', 'max:191'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $updated = $this->connectionService->update($owner, $connection, $validated);

        return response()->json([
            'message' => 'Social account connection updated.',
            'connection' => $this->connectionService->payload($updated),
        ]);
    }

    public function beginAuthorization(Request $request, SocialAccountConnection $connection)
    {
        [$owner, , $canManageAccounts] = $this->resolveAccess($request->user());
        if (! $canManageAccounts) {
            abort(403);
        }

        $result = $this->connectionService->authorize($owner, $connection);

        return response()->json($result);
    }

    public function refresh(Request $request, SocialAccountConnection $connection)
    {
        [$owner, , $canManageAccounts] = $this->resolveAccess($request->user());
        if (! $canManageAccounts) {
            abort(403);
        }

        $refreshed = $this->connectionService->refresh($owner, $connection);

        return response()->json([
            'message' => 'Social account tokens refreshed.',
            'connection' => $this->connectionService->payload($refreshed),
        ]);
    }

    public function testConnection(Request $request, SocialAccountConnection $connection)
    {
        [$owner, , $canManageAccounts] = $this->resolveAccess($request->user());
        if (! $canManageAccounts) {
            abort(403);
        }

        $result = $this->connectionService->test($owner, $connection);

        return response()->json([
            'message' => $result['message'],
            'result' => [
                'success' => (bool) $result['success'],
            ],
            'connection' => $this->connectionService->payload($result['connection']),
        ]);
    }

    public function disconnect(Request $request, SocialAccountConnection $connection)
    {
        [$owner, , $canManageAccounts] = $this->resolveAccess($request->user());
        if (! $canManageAccounts) {
            abort(403);
        }

        $disconnected = $this->connectionService->disconnect($owner, $connection);

        return response()->json([
            'message' => 'Social account connection disconnected.',
            'connection' => $this->connectionService->payload($disconnected),
        ]);
    }

    public function destroy(Request $request, SocialAccountConnection $connection)
    {
        [$owner, , $canManageAccounts] = $this->resolveAccess($request->user());
        if (! $canManageAccounts) {
            abort(403);
        }

        $this->connectionService->destroy($owner, $connection);

        return response()->json([
            'message' => 'Social account connection deleted.',
        ]);
    }

    public function oauthCallback(Request $request, string $platform)
    {
        try {
            $result = $this->connectionService->completeAuthorization($platform, $request->query());
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())
                ->flatten()
                ->filter(fn ($value) => is_string($value) && trim($value) !== '')
                ->map(fn ($value) => trim((string) $value))
                ->first() ?: 'The social account callback could not be processed.';

            return redirect()
                ->route('social.accounts.index')
                ->with('error', $message);
        }

        $redirectRoute = trim((string) ($result['redirect_route'] ?? 'social.accounts.index'));

        return redirect()
            ->route($redirectRoute !== '' ? $redirectRoute : 'social.accounts.index')
            ->with(($result['success'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'Social account updated.'));
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
