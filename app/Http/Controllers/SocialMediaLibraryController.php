<?php

namespace App\Http\Controllers;

use App\Models\SocialMediaAsset;
use App\Models\User;
use App\Services\Social\SocialAccountConnectionService;
use App\Services\Social\SocialMediaAssetService;
use App\Services\Social\SocialPostService;
use Illuminate\Http\Request;

class SocialMediaLibraryController extends Controller
{
    public function __construct(
        private readonly SocialMediaAssetService $mediaAssetService,
        private readonly SocialPostService $postService,
        private readonly SocialAccountConnectionService $connectionService,
    ) {}

    public function index(Request $request)
    {
        $access = $this->resolveAccess($request->user());
        if (! $access['can_view']) {
            abort(403);
        }

        $filters = $this->filters($request);
        $connectionSummary = $this->connectionService->summaryForOwner($access['owner']);
        $postSummary = $this->postService->summaryForOwner($access['owner']);

        return $this->inertiaOrJson('Social/MediaLibrary', [
            'assets' => $this->mediaAssetService->libraryPayloads($access['owner'], $filters),
            'filters' => $filters,
            'summary' => $this->mediaAssetService->librarySummary($access['owner'], $filters),
            'source_options' => $this->sourceOptions(),
            'origin_options' => $this->originOptions(),
            'workspace_stats' => $this->workspaceStats($connectionSummary, $postSummary),
            'access' => $this->accessPayload($access),
        ]);
    }

    public function store(Request $request)
    {
        $access = $this->resolveAccess($request->user());
        if (! $access['can_manage_posts']) {
            abort(403);
        }

        $request->validate([
            'image_file' => ['required', 'file', 'image', 'max:10240'],
        ]);

        $stored = $this->mediaAssetService->storeLibraryImage(
            $access['owner'],
            $request->user(),
            $request->file('image_file')
        );
        $filters = $this->filters($request);

        return response()->json([
            'message' => 'Pulse media uploaded.',
            'asset' => $stored['library_asset'] ?? null,
            'assets' => $this->mediaAssetService->libraryPayloads($access['owner'], $filters),
            'summary' => $this->mediaAssetService->librarySummary($access['owner'], $filters),
        ], 201);
    }

    /**
     * @return array{source: string, origin: string, search: string}
     */
    private function filters(Request $request): array
    {
        $source = strtolower(trim((string) $request->query('source', $request->input('source', 'all'))));
        $origin = strtolower(trim((string) $request->query('origin', $request->input('origin', 'all'))));

        return [
            'source' => in_array($source, ['all', ...SocialMediaAsset::allowedSources()], true) ? $source : 'all',
            'origin' => in_array($origin, ['all', 'library', 'post', 'template'], true) ? $origin : 'all',
            'search' => trim((string) $request->query('search', $request->input('search', ''))),
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function sourceOptions(): array
    {
        return collect(['all', ...SocialMediaAsset::allowedSources()])
            ->map(fn (string $value): array => ['value' => $value])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function originOptions(): array
    {
        return collect(['all', 'library', 'post', 'template'])
            ->map(fn (string $value): array => ['value' => $value])
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     owner: User,
     *     can_view: bool,
     *     can_manage_posts: bool,
     *     can_publish: bool,
     *     can_submit_for_approval: bool,
     *     can_approve: bool
     * }
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
            return [
                'owner' => $owner,
                'can_view' => true,
                'can_manage_posts' => true,
                'can_publish' => true,
                'can_submit_for_approval' => false,
                'can_approve' => true,
            ];
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

        $canManagePosts = (bool) (
            $membership?->hasPermission('social.manage')
            || $membership?->hasPermission('social.publish')
        );

        $canApprove = (bool) $membership?->hasPermission('social.approve');
        $canPublish = (bool) (
            $membership?->hasPermission('social.publish')
            && $canApprove
        );
        $canSubmitForApproval = (bool) $membership?->hasPermission('social.publish');

        return [
            'owner' => $owner,
            'can_view' => $canView,
            'can_manage_posts' => $canManagePosts,
            'can_publish' => $canPublish,
            'can_submit_for_approval' => $canSubmitForApproval,
            'can_approve' => $canApprove,
        ];
    }

    /**
     * @param  array{
     *     can_view: bool,
     *     can_manage_posts: bool,
     *     can_publish: bool,
     *     can_submit_for_approval: bool,
     *     can_approve: bool
     * }  $access
     * @return array<string, bool>
     */
    private function accessPayload(array $access): array
    {
        return [
            'can_view' => $access['can_view'],
            'can_manage_posts' => $access['can_manage_posts'],
            'can_manage_automations' => $access['can_manage_posts'],
            'can_publish' => $access['can_publish'],
            'can_submit_for_approval' => $access['can_submit_for_approval'],
            'can_approve' => $access['can_approve'],
        ];
    }

    /**
     * @param  array<string, mixed>  $connectionSummary
     * @param  array<string, mixed>  $postSummary
     * @return array<string, int>
     */
    private function workspaceStats(array $connectionSummary, array $postSummary): array
    {
        return [
            'connected_accounts' => (int) ($connectionSummary['connected'] ?? 0),
            'draft_posts' => (int) ($postSummary['drafts'] ?? 0),
            'scheduled_posts' => (int) ($postSummary['scheduled'] ?? 0),
        ];
    }
}
