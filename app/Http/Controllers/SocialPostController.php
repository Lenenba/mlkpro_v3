<?php

namespace App\Http\Controllers;

use App\Models\SocialPost;
use App\Models\SocialPostTemplate;
use App\Models\User;
use App\Services\Social\SocialAccountConnectionService;
use App\Services\Social\SocialApprovalService;
use App\Services\Social\SocialMediaAssetService;
use App\Services\Social\SocialPostService;
use App\Services\Social\SocialPrefillService;
use App\Services\Social\SocialPublishingService;
use App\Services\Social\SocialSuggestionService;
use App\Services\Social\SocialTemplateService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SocialPostController extends Controller
{
    public function __construct(
        private readonly SocialPostService $postService,
        private readonly SocialTemplateService $templateService,
        private readonly SocialAccountConnectionService $connectionService,
        private readonly SocialPrefillService $prefillService,
        private readonly SocialSuggestionService $suggestionService,
        private readonly SocialMediaAssetService $mediaAssetService,
    ) {}

    public function index(Request $request)
    {
        $access = $this->resolveAccess($request->user());
        if (! $access['can_view']) {
            abort(403);
        }

        $connectionSummary = $this->connectionService->summaryForOwner($access['owner']);
        $postSummary = $this->postService->summaryForOwner($access['owner']);

        return $this->inertiaOrJson('Social/Index', [
            'connection_summary' => $connectionSummary,
            'post_summary' => $postSummary,
            'workspace_stats' => $this->workspaceStats($connectionSummary, $postSummary),
            'recent_drafts' => $this->postService->draftPayloads($access['owner'], 3),
            'access' => $this->accessPayload($access),
        ]);
    }

    public function composer(Request $request)
    {
        $access = $this->resolveAccess($request->user());
        if (! $access['can_view']) {
            abort(403);
        }

        $connectionSummary = $this->connectionService->summaryForOwner($access['owner']);
        $postSummary = $this->postService->summaryForOwner($access['owner']);

        return $this->inertiaOrJson('Social/Composer', [
            'connected_accounts' => $this->postService->connectedAccountOptions($access['owner']),
            'drafts' => $this->postService->draftPayloads($access['owner']),
            'templates' => $this->templateService->templatePayloads($access['owner']),
            'prefill' => $this->prefillService->resolveComposerPrefill($access['owner'], $request->only([
                'source_type',
                'source_id',
            ])),
            'summary' => $postSummary,
            'workspace_stats' => $this->workspaceStats($connectionSummary, $postSummary),
            'selected_draft_id' => $request->integer('draft') ?: null,
            'selected_template_id' => $request->integer('template') ?: null,
            'access' => $this->accessPayload($access),
        ]);
    }

    public function templates(Request $request)
    {
        $access = $this->resolveAccess($request->user());
        if (! $access['can_view']) {
            abort(403);
        }

        $connectionSummary = $this->connectionService->summaryForOwner($access['owner']);
        $postSummary = $this->postService->summaryForOwner($access['owner']);

        return $this->inertiaOrJson('Social/Templates', [
            'connected_accounts' => $this->postService->connectedAccountOptions($access['owner']),
            'templates' => $this->templateService->templatePayloads($access['owner']),
            'summary' => $postSummary,
            'workspace_stats' => $this->workspaceStats($connectionSummary, $postSummary),
            'selected_template_id' => $request->integer('template') ?: null,
            'access' => $this->accessPayload($access),
        ]);
    }

    public function history(Request $request)
    {
        $access = $this->resolveAccess($request->user());
        if (! $access['can_view']) {
            abort(403);
        }

        $filters = [
            'status' => trim((string) $request->query('status', '')),
            'platform' => trim((string) $request->query('platform', '')),
            'search' => trim((string) $request->query('search', '')),
        ];

        $connectionSummary = $this->connectionService->summaryForOwner($access['owner']);
        $postSummary = $this->postService->summaryForOwner($access['owner']);

        return $this->inertiaOrJson('Social/History', [
            'posts' => $this->postService->historyPayloads($access['owner'], $filters),
            'filters' => $filters,
            'summary' => $postSummary,
            'platform_filters' => collect($this->connectionService->definitions())
                ->map(fn (array $definition): array => [
                    'value' => (string) ($definition['key'] ?? ''),
                    'label' => (string) ($definition['label'] ?? ''),
                ])
                ->filter(fn (array $item): bool => $item['value'] !== '')
                ->values()
                ->all(),
            'status_filters' => collect(SocialPost::allowedStatuses())
                ->map(fn (string $status): array => [
                    'value' => $status,
                ])
                ->values()
                ->all(),
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

        $this->normalizeUrlInputs($request, ['image_url', 'link_url']);

        $validated = $request->validate([
            'text' => ['nullable', 'string', 'max:4000'],
            'image_url' => ['nullable', 'url', 'max:2048'],
            'image_file' => ['nullable', 'file', 'image', 'max:10240'],
            'link_url' => ['nullable', 'url', 'max:2048'],
            'link_cta_label' => ['nullable', 'string', 'max:80'],
            'scheduled_for' => ['nullable', 'date'],
            'source_type' => ['nullable', 'string', Rule::in(SocialPrefillService::allowedSourceTypes())],
            'source_id' => ['nullable', 'integer'],
            'target_connection_ids' => ['required', 'array', 'min:1'],
            'target_connection_ids.*' => ['integer', 'distinct'],
        ]);
        $validated = $this->withStoredImageUpload($request, $access['owner'], $validated, 'posts');

        $draft = $this->postService->createDraft($access['owner'], $request->user(), $validated);

        return response()->json([
            'message' => 'Pulse draft saved.',
            'draft' => $this->postService->payload($draft),
            'drafts' => $this->postService->draftPayloads($access['owner']),
            'summary' => $this->postService->summaryForOwner($access['owner']),
        ], 201);
    }

    public function suggestions(Request $request)
    {
        $access = $this->resolveAccess($request->user());
        if (! $access['can_view']) {
            abort(403);
        }

        $this->normalizeUrlInputs($request, ['image_url', 'link_url']);

        $validated = $request->validate([
            'text' => ['nullable', 'string', 'max:4000'],
            'image_url' => ['nullable', 'url', 'max:2048'],
            'link_url' => ['nullable', 'url', 'max:2048'],
            'source_type' => ['nullable', 'string', Rule::in(SocialPrefillService::allowedSourceTypes())],
            'source_id' => ['nullable', 'integer'],
        ]);

        return response()->json([
            'suggestions' => $this->suggestionService->suggest($access['owner'], $validated, app()->getLocale()),
        ]);
    }

    public function update(Request $request, SocialPost $post)
    {
        $access = $this->resolveAccess($request->user());
        if (! $access['can_manage_posts']) {
            abort(403);
        }

        $this->normalizeUrlInputs($request, ['image_url', 'link_url']);

        $validated = $request->validate([
            'text' => ['nullable', 'string', 'max:4000'],
            'image_url' => ['nullable', 'url', 'max:2048'],
            'image_file' => ['nullable', 'file', 'image', 'max:10240'],
            'link_url' => ['nullable', 'url', 'max:2048'],
            'link_cta_label' => ['nullable', 'string', 'max:80'],
            'scheduled_for' => ['nullable', 'date'],
            'source_type' => ['nullable', 'string', Rule::in(SocialPrefillService::allowedSourceTypes())],
            'source_id' => ['nullable', 'integer'],
            'target_connection_ids' => ['required', 'array', 'min:1'],
            'target_connection_ids.*' => ['integer', 'distinct'],
        ]);
        $validated = $this->withStoredImageUpload($request, $access['owner'], $validated, 'posts');

        $draft = $this->postService->updateDraft($access['owner'], $request->user(), $post, $validated);

        return response()->json([
            'message' => 'Pulse draft updated.',
            'draft' => $this->postService->payload($draft),
            'drafts' => $this->postService->draftPayloads($access['owner']),
            'summary' => $this->postService->summaryForOwner($access['owner']),
        ]);
    }

    public function publish(Request $request, SocialPost $post, SocialPublishingService $publishingService)
    {
        $access = $this->resolveAccess($request->user());
        if (! $access['can_publish']) {
            abort(403);
        }

        $draft = $publishingService->publishNow($access['owner'], $request->user(), $post);

        return response()->json([
            'message' => 'Pulse publication queued.',
            'draft' => $this->postService->payload($draft),
            'drafts' => $this->postService->draftPayloads($access['owner']),
            'summary' => $this->postService->summaryForOwner($access['owner']),
        ], 202);
    }

    public function schedule(Request $request, SocialPost $post, SocialPublishingService $publishingService)
    {
        $access = $this->resolveAccess($request->user());
        if (! $access['can_publish']) {
            abort(403);
        }

        $draft = $publishingService->schedule($access['owner'], $request->user(), $post);

        return response()->json([
            'message' => 'Pulse publication scheduled.',
            'draft' => $this->postService->payload($draft),
            'drafts' => $this->postService->draftPayloads($access['owner']),
            'summary' => $this->postService->summaryForOwner($access['owner']),
        ], 202);
    }

    public function submitApproval(Request $request, SocialPost $post, SocialApprovalService $approvalService)
    {
        $access = $this->resolveAccess($request->user());
        if (! $access['can_submit_for_approval']) {
            abort(403);
        }

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $draft = $approvalService->submit($access['owner'], $request->user(), $post, $validated);

        return response()->json([
            'message' => 'Pulse post submitted for approval.',
            'draft' => $this->postService->payload($draft),
            'drafts' => $this->postService->draftPayloads($access['owner']),
            'summary' => $this->postService->summaryForOwner($access['owner']),
        ], 202);
    }

    public function approve(Request $request, SocialPost $post, SocialApprovalService $approvalService)
    {
        $access = $this->resolveAccess($request->user());
        if (! $access['can_approve']) {
            abort(403);
        }

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $draft = $approvalService->approve($access['owner'], $request->user(), $post, $validated);

        return response()->json([
            'message' => 'Pulse approval completed.',
            'draft' => $this->postService->payload($draft),
            'drafts' => $this->postService->draftPayloads($access['owner']),
            'summary' => $this->postService->summaryForOwner($access['owner']),
        ], 202);
    }

    public function reject(Request $request, SocialPost $post, SocialApprovalService $approvalService)
    {
        $access = $this->resolveAccess($request->user());
        if (! $access['can_approve']) {
            abort(403);
        }

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $draft = $approvalService->reject($access['owner'], $request->user(), $post, $validated);

        return response()->json([
            'message' => 'Pulse approval rejected.',
            'draft' => $this->postService->payload($draft),
            'drafts' => $this->postService->draftPayloads($access['owner']),
            'summary' => $this->postService->summaryForOwner($access['owner']),
        ]);
    }

    public function duplicate(Request $request, SocialPost $post)
    {
        $access = $this->resolveAccess($request->user());
        if (! $access['can_manage_posts']) {
            abort(403);
        }

        $draft = $this->postService->duplicate($access['owner'], $request->user(), $post);
        $missingTargetCount = (int) data_get($draft->metadata, 'missing_target_count', 0);

        return response()->json([
            'message' => $missingTargetCount > 0
                ? 'Pulse post duplicated. Reconnect or reselect the missing targets before publishing.'
                : 'Pulse post duplicated.',
            'draft' => $this->postService->payload($draft),
            'summary' => $this->postService->summaryForOwner($access['owner']),
        ], 201);
    }

    public function storeTemplate(Request $request)
    {
        $access = $this->resolveAccess($request->user());
        if (! $access['can_manage_posts']) {
            abort(403);
        }

        $this->normalizeUrlInputs($request, ['image_url', 'link_url']);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'text' => ['nullable', 'string', 'max:4000'],
            'image_url' => ['nullable', 'url', 'max:2048'],
            'image_file' => ['nullable', 'file', 'image', 'max:10240'],
            'link_url' => ['nullable', 'url', 'max:2048'],
            'link_cta_label' => ['nullable', 'string', 'max:80'],
            'target_connection_ids' => ['nullable', 'array'],
            'target_connection_ids.*' => ['integer', 'distinct'],
        ]);
        $validated = $this->withStoredImageUpload($request, $access['owner'], $validated, 'templates');

        $template = $this->templateService->create($access['owner'], $request->user(), $validated);

        return response()->json([
            'message' => 'Pulse template saved.',
            'template' => $this->templateService->payload($template),
            'templates' => $this->templateService->templatePayloads($access['owner']),
        ], 201);
    }

    public function updateTemplate(Request $request, SocialPostTemplate $template)
    {
        $access = $this->resolveAccess($request->user());
        if (! $access['can_manage_posts']) {
            abort(403);
        }

        $this->normalizeUrlInputs($request, ['image_url', 'link_url']);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'text' => ['nullable', 'string', 'max:4000'],
            'image_url' => ['nullable', 'url', 'max:2048'],
            'image_file' => ['nullable', 'file', 'image', 'max:10240'],
            'link_url' => ['nullable', 'url', 'max:2048'],
            'link_cta_label' => ['nullable', 'string', 'max:80'],
            'target_connection_ids' => ['nullable', 'array'],
            'target_connection_ids.*' => ['integer', 'distinct'],
        ]);
        $validated = $this->withStoredImageUpload($request, $access['owner'], $validated, 'templates');

        $savedTemplate = $this->templateService->update($access['owner'], $request->user(), $template, $validated);

        return response()->json([
            'message' => 'Pulse template updated.',
            'template' => $this->templateService->payload($savedTemplate),
            'templates' => $this->templateService->templatePayloads($access['owner']),
        ]);
    }

    public function destroyTemplate(Request $request, SocialPostTemplate $template)
    {
        $access = $this->resolveAccess($request->user());
        if (! $access['can_manage_posts']) {
            abort(403);
        }

        $this->templateService->delete($access['owner'], $template);

        return response()->json([
            'message' => 'Pulse template deleted.',
            'templates' => $this->templateService->templatePayloads($access['owner']),
        ]);
    }

    public function repost(Request $request, SocialPost $post)
    {
        $access = $this->resolveAccess($request->user());
        if (! $access['can_manage_posts']) {
            abort(403);
        }

        $draft = $this->postService->repost($access['owner'], $request->user(), $post);
        $missingTargetCount = (int) data_get($draft->metadata, 'missing_target_count', 0);

        return response()->json([
            'message' => $missingTargetCount > 0
                ? 'Pulse repost draft created. Reconnect or reselect the missing targets before publishing.'
                : 'Pulse repost draft created.',
            'draft' => $this->postService->payload($draft),
            'summary' => $this->postService->summaryForOwner($access['owner']),
        ], 201);
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

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function withStoredImageUpload(Request $request, User $owner, array $validated, string $context): array
    {
        unset($validated['image_file']);

        $imageFile = $request->file('image_file');
        if (! $imageFile) {
            return $validated;
        }

        $validated['image_upload'] = $this->mediaAssetService->storeUploadedImage($owner, $imageFile, $context);

        return $validated;
    }

    /**
     * @param  array<int, string>  $keys
     */
    private function normalizeUrlInputs(Request $request, array $keys): void
    {
        $normalized = [];

        foreach ($keys as $key) {
            if (! $request->exists($key)) {
                continue;
            }

            $normalized[$key] = $this->normalizeUrlInputValue($request->input($key));
        }

        if ($normalized !== []) {
            $request->merge($normalized);
        }
    }

    private function normalizeUrlInputValue(mixed $value): ?string
    {
        $candidate = trim((string) ($value ?? ''));
        if ($candidate === '') {
            return null;
        }

        if (preg_match('/^[a-z][a-z0-9+\-.]*:/i', $candidate) === 1) {
            return $candidate;
        }

        if (str_starts_with($candidate, '//')) {
            return 'https:'.$candidate;
        }

        if (preg_match('/\s/u', $candidate) === 1 || ! str_contains($candidate, '.')) {
            return $candidate;
        }

        return 'https://'.$candidate;
    }
}
