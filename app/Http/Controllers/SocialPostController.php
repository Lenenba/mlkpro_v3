<?php

namespace App\Http\Controllers;

use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\SocialAccountConnectionService;
use App\Services\Social\SocialPublishingService;
use App\Services\Social\SocialPostService;
use Illuminate\Http\Request;

class SocialPostController extends Controller
{
    public function __construct(
        private readonly SocialPostService $postService,
        private readonly SocialAccountConnectionService $connectionService,
    ) {}

    public function index(Request $request)
    {
        [$owner, $canView, $canManagePosts, $canPublish] = $this->resolveAccess($request->user());
        if (! $canView) {
            abort(403);
        }

        $connectionSummary = $this->connectionService->summaryForOwner($owner);
        $postSummary = $this->postService->summaryForOwner($owner);

        return $this->inertiaOrJson('Social/Index', [
            'connection_summary' => $connectionSummary,
            'post_summary' => $postSummary,
            'workspace_stats' => $this->workspaceStats($connectionSummary, $postSummary),
            'recent_drafts' => $this->postService->draftPayloads($owner, 3),
            'access' => [
                'can_view' => $canView,
                'can_manage_posts' => $canManagePosts,
                'can_publish' => $canPublish,
            ],
        ]);
    }

    public function composer(Request $request)
    {
        [$owner, $canView, $canManagePosts, $canPublish] = $this->resolveAccess($request->user());
        if (! $canView) {
            abort(403);
        }

        $connectionSummary = $this->connectionService->summaryForOwner($owner);
        $postSummary = $this->postService->summaryForOwner($owner);

        return $this->inertiaOrJson('Social/Composer', [
            'connected_accounts' => $this->postService->connectedAccountOptions($owner),
            'drafts' => $this->postService->draftPayloads($owner),
            'summary' => $postSummary,
            'workspace_stats' => $this->workspaceStats($connectionSummary, $postSummary),
            'selected_draft_id' => $request->integer('draft') ?: null,
            'access' => [
                'can_view' => $canView,
                'can_manage_posts' => $canManagePosts,
                'can_publish' => $canPublish,
            ],
        ]);
    }

    public function store(Request $request)
    {
        [$owner, , $canManagePosts] = $this->resolveAccess($request->user());
        if (! $canManagePosts) {
            abort(403);
        }

        $validated = $request->validate([
            'text' => ['nullable', 'string', 'max:4000'],
            'image_url' => ['nullable', 'url', 'max:2048'],
            'link_url' => ['nullable', 'url', 'max:2048'],
            'scheduled_for' => ['nullable', 'date'],
            'target_connection_ids' => ['required', 'array', 'min:1'],
            'target_connection_ids.*' => ['integer', 'distinct'],
        ]);

        $draft = $this->postService->createDraft($owner, $request->user(), $validated);

        return response()->json([
            'message' => 'Pulse draft saved.',
            'draft' => $this->postService->payload($draft),
            'drafts' => $this->postService->draftPayloads($owner),
            'summary' => $this->postService->summaryForOwner($owner),
        ], 201);
    }

    public function update(Request $request, SocialPost $post)
    {
        [$owner, , $canManagePosts] = $this->resolveAccess($request->user());
        if (! $canManagePosts) {
            abort(403);
        }

        $validated = $request->validate([
            'text' => ['nullable', 'string', 'max:4000'],
            'image_url' => ['nullable', 'url', 'max:2048'],
            'link_url' => ['nullable', 'url', 'max:2048'],
            'scheduled_for' => ['nullable', 'date'],
            'target_connection_ids' => ['required', 'array', 'min:1'],
            'target_connection_ids.*' => ['integer', 'distinct'],
        ]);

        $draft = $this->postService->updateDraft($owner, $request->user(), $post, $validated);

        return response()->json([
            'message' => 'Pulse draft updated.',
            'draft' => $this->postService->payload($draft),
            'drafts' => $this->postService->draftPayloads($owner),
            'summary' => $this->postService->summaryForOwner($owner),
        ]);
    }

    public function publish(Request $request, SocialPost $post, SocialPublishingService $publishingService)
    {
        [$owner, , , $canPublish] = $this->resolveAccess($request->user());
        if (! $canPublish) {
            abort(403);
        }

        $draft = $publishingService->publishNow($owner, $request->user(), $post);

        return response()->json([
            'message' => 'Pulse publication queued.',
            'draft' => $this->postService->payload($draft),
            'drafts' => $this->postService->draftPayloads($owner),
            'summary' => $this->postService->summaryForOwner($owner),
        ], 202);
    }

    public function schedule(Request $request, SocialPost $post, SocialPublishingService $publishingService)
    {
        [$owner, , , $canPublish] = $this->resolveAccess($request->user());
        if (! $canPublish) {
            abort(403);
        }

        $draft = $publishingService->schedule($owner, $request->user(), $post);

        return response()->json([
            'message' => 'Pulse publication scheduled.',
            'draft' => $this->postService->payload($draft),
            'drafts' => $this->postService->draftPayloads($owner),
            'summary' => $this->postService->summaryForOwner($owner),
        ], 202);
    }

    /**
     * @return array{0: User, 1: bool, 2: bool, 3: bool}
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
            return [$owner, true, true, true];
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

        $canPublish = (bool) $membership?->hasPermission('social.publish');

        return [$owner, $canView, $canManagePosts, $canPublish];
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
