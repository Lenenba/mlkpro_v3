<?php

namespace App\Http\Controllers;

use App\Models\SocialAutomationRule;
use App\Models\SocialPost;
use App\Models\SocialPostTemplate;
use App\Models\User;
use App\Services\Social\SocialAccountConnectionService;
use App\Services\Social\SocialApprovalService;
use App\Services\Social\SocialBrandVoiceService;
use App\Services\Social\SocialMediaAssetService;
use App\Services\Social\SocialPostService;
use App\Services\Social\SocialPrefillService;
use App\Services\Social\SocialPublishingService;
use App\Services\Social\SocialSuggestionService;
use App\Services\Social\SocialTemplateService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Throwable;

class SocialPostController extends Controller
{
    private const MAX_MEDIA_ITEMS = 20;

    private const MAX_IMAGE_UPLOAD_KILOBYTES = 10240;

    private const MAX_MEDIA_UPLOAD_KILOBYTES = 24576;

    private const MAX_TOTAL_UPLOAD_KILOBYTES = 102400;

    private const MEDIA_UPLOAD_EXTENSIONS = 'jpg,jpeg,png,gif,webp,mp4,mov,webm,pdf';

    public function __construct(
        private readonly SocialPostService $postService,
        private readonly SocialTemplateService $templateService,
        private readonly SocialAccountConnectionService $connectionService,
        private readonly SocialPrefillService $prefillService,
        private readonly SocialSuggestionService $suggestionService,
        private readonly SocialMediaAssetService $mediaAssetService,
        private readonly SocialBrandVoiceService $brandVoiceService,
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
            'automation_summary' => [
                'total' => SocialAutomationRule::query()->byUser($access['owner']->id)->count(),
                'active' => SocialAutomationRule::query()->byUser($access['owner']->id)->active()->count(),
            ],
            'approval_summary' => [
                'pending' => SocialPost::query()
                    ->byUser($access['owner']->id)
                    ->where('status', SocialPost::STATUS_PENDING_APPROVAL)
                    ->count(),
            ],
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
        $initialMediaUrl = $this->normalizeUrlInputValue($request->query('image_url'));
        $initialMediaUrl = $this->isValidImageReference($initialMediaUrl) ? $initialMediaUrl : null;

        return $this->inertiaOrJson('Social/Composer', [
            'connected_accounts' => $this->postService->connectedAccountOptions($access['owner']),
            'drafts' => $this->postService->draftPayloads($access['owner']),
            'templates' => $this->templateService->templatePayloads($access['owner']),
            'media_assets' => $this->mediaAssetPayloads($access['owner']),
            'prefill' => $this->prefillService->resolveComposerPrefill($access['owner'], $request->only([
                'source_type',
                'source_id',
            ])),
            'summary' => $postSummary,
            'workspace_stats' => $this->workspaceStats($connectionSummary, $postSummary),
            'selected_draft_id' => $request->integer('draft') ?: null,
            'selected_template_id' => $request->integer('template') ?: null,
            'initial_media_url' => $initialMediaUrl,
            'brand_voice' => $this->brandVoiceService->resolve($access['owner']),
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
            'media_assets' => $this->mediaAssetPayloads($access['owner']),
            'summary' => $postSummary,
            'workspace_stats' => $this->workspaceStats($connectionSummary, $postSummary),
            'selected_template_id' => $request->integer('template') ?: null,
            'access' => $this->accessPayload($access),
        ]);
    }

    public function calendar(Request $request)
    {
        $access = $this->resolveAccess($request->user());
        if (! $access['can_view']) {
            abort(403);
        }

        $connectionSummary = $this->connectionService->summaryForOwner($access['owner']);
        $postSummary = $this->postService->summaryForOwner($access['owner']);

        return $this->inertiaOrJson('Social/Calendar', [
            'calendar_posts' => $this->postService->calendarPayloads($access['owner']),
            'summary' => $postSummary,
            'workspace_stats' => $this->workspaceStats($connectionSummary, $postSummary),
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
        $this->normalizeMediaAssetUrls($request);

        $validated = $request->validate([
            'text' => ['nullable', 'string', 'max:4000'],
            'image_url' => $this->imageUrlRules(),
            'image_file' => ['nullable', 'file', 'image', 'max:10240'],
            ...$this->mediaFileRules($request),
            ...$this->mediaAssetRules($request, $access['owner']),
            'link_url' => $this->linkUrlRules(),
            'link_cta_label' => ['nullable', 'string', 'max:80'],
            'scheduled_for' => ['nullable', 'date'],
            'source_type' => ['nullable', 'string', Rule::in(SocialPrefillService::allowedSourceTypes())],
            'source_id' => ['nullable', 'integer'],
            'target_connection_ids' => ['required', 'array', 'min:1'],
            'target_connection_ids.*' => ['integer', 'distinct'],
        ]);
        $draft = $this->persistWithStoredMediaUploads(
            $request,
            $access['owner'],
            $validated,
            'posts',
            fn (array $payload): SocialPost => $this->postService->createDraft(
                $access['owner'],
                $request->user(),
                $payload,
            ),
        );

        return response()->json([
            'message' => 'Pulse draft saved.',
            'draft' => $this->postService->payload($draft),
            'drafts' => $this->postService->draftPayloads($access['owner']),
            'media_assets' => $this->mediaAssetPayloads($access['owner']),
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
            'image_url' => $this->imageUrlRules(),
            'link_url' => $this->linkUrlRules(),
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

        $this->postService->ensureDraftCanBeUpdated($access['owner'], $post);

        $this->normalizeUrlInputs($request, ['image_url', 'link_url']);
        $this->normalizeMediaAssetUrls($request);

        $validated = $request->validate([
            'text' => ['nullable', 'string', 'max:4000'],
            'image_url' => $this->imageUrlRules(),
            'image_file' => ['nullable', 'file', 'image', 'max:10240'],
            ...$this->mediaFileRules($request),
            ...$this->mediaAssetRules($request, $access['owner']),
            'link_url' => $this->linkUrlRules(),
            'link_cta_label' => ['nullable', 'string', 'max:80'],
            'scheduled_for' => ['nullable', 'date'],
            'source_type' => ['nullable', 'string', Rule::in(SocialPrefillService::allowedSourceTypes())],
            'source_id' => ['nullable', 'integer'],
            'target_connection_ids' => ['required', 'array', 'min:1'],
            'target_connection_ids.*' => ['integer', 'distinct'],
        ]);
        $update = $this->persistWithStoredMediaUploads(
            $request,
            $access['owner'],
            $validated,
            'posts',
            fn (array $payload): array => $this->postService->updateDraftWithPreviousMedia(
                $access['owner'],
                $request->user(),
                $post,
                $payload,
            ),
        );
        $draft = $update['post'];
        $this->mediaAssetService->deleteRemovedUploads(
            $access['owner'],
            $update['previous_media_payload'],
            (array) ($draft->media_payload ?? []),
        );

        return response()->json([
            'message' => 'Pulse draft updated.',
            'draft' => $this->postService->payload($draft),
            'drafts' => $this->postService->draftPayloads($access['owner']),
            'media_assets' => $this->mediaAssetPayloads($access['owner']),
            'summary' => $this->postService->summaryForOwner($access['owner']),
        ]);
    }

    public function reschedule(Request $request, SocialPost $post)
    {
        $access = $this->resolveAccess($request->user());
        if (! $access['can_manage_posts']) {
            abort(403);
        }

        $validated = $request->validate([
            'scheduled_for' => ['nullable', 'date'],
        ]);

        $draft = $this->postService->rescheduleDraft($access['owner'], $request->user(), $post, $validated);

        return response()->json([
            'message' => 'Pulse post rescheduled.',
            'draft' => $this->postService->payload($draft),
            'calendar_posts' => $this->postService->calendarPayloads($access['owner']),
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
            'mode' => ['nullable', 'string', Rule::in(['immediate', 'scheduled'])],
            'scheduled_for' => ['nullable', 'date'],
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
        $this->normalizeMediaAssetUrls($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'text' => ['nullable', 'string', 'max:4000'],
            'image_url' => $this->imageUrlRules(),
            'image_file' => ['nullable', 'file', 'image', 'max:10240'],
            ...$this->mediaFileRules($request),
            ...$this->mediaAssetRules($request, $access['owner']),
            'link_url' => $this->linkUrlRules(),
            'link_cta_label' => ['nullable', 'string', 'max:80'],
            'target_connection_ids' => ['nullable', 'array'],
            'target_connection_ids.*' => ['integer', 'distinct'],
        ]);
        $template = $this->persistWithStoredMediaUploads(
            $request,
            $access['owner'],
            $validated,
            'templates',
            fn (array $payload): SocialPostTemplate => $this->templateService->create(
                $access['owner'],
                $request->user(),
                $payload,
            ),
        );

        return response()->json([
            'message' => 'Pulse template saved.',
            'template' => $this->templateService->payload($template),
            'templates' => $this->templateService->templatePayloads($access['owner']),
            'media_assets' => $this->mediaAssetPayloads($access['owner']),
        ], 201);
    }

    public function updateTemplate(Request $request, SocialPostTemplate $template)
    {
        $access = $this->resolveAccess($request->user());
        if (! $access['can_manage_posts']) {
            abort(403);
        }

        $this->templateService->ensureCanManage($access['owner'], $template);

        $this->normalizeUrlInputs($request, ['image_url', 'link_url']);
        $this->normalizeMediaAssetUrls($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'text' => ['nullable', 'string', 'max:4000'],
            'image_url' => $this->imageUrlRules(),
            'image_file' => ['nullable', 'file', 'image', 'max:10240'],
            ...$this->mediaFileRules($request),
            ...$this->mediaAssetRules($request, $access['owner']),
            'link_url' => $this->linkUrlRules(),
            'link_cta_label' => ['nullable', 'string', 'max:80'],
            'target_connection_ids' => ['nullable', 'array'],
            'target_connection_ids.*' => ['integer', 'distinct'],
        ]);
        $update = $this->persistWithStoredMediaUploads(
            $request,
            $access['owner'],
            $validated,
            'templates',
            fn (array $payload): array => $this->templateService->updateWithPreviousMedia(
                $access['owner'],
                $request->user(),
                $template,
                $payload,
            ),
        );
        $savedTemplate = $update['template'];
        $this->mediaAssetService->deleteRemovedUploads(
            $access['owner'],
            $update['previous_media_payload'],
            (array) ($savedTemplate->media_payload ?? []),
        );

        return response()->json([
            'message' => 'Pulse template updated.',
            'template' => $this->templateService->payload($savedTemplate),
            'templates' => $this->templateService->templatePayloads($access['owner']),
            'media_assets' => $this->mediaAssetPayloads($access['owner']),
        ]);
    }

    public function destroyTemplate(Request $request, SocialPostTemplate $template)
    {
        $access = $this->resolveAccess($request->user());
        if (! $access['can_manage_posts']) {
            abort(403);
        }

        $this->templateService->ensureCanManage($access['owner'], $template);
        $previousMediaPayload = $this->templateService->deleteWithPreviousMedia($access['owner'], $template);
        $this->mediaAssetService->deleteRemovedUploads($access['owner'], $previousMediaPayload);

        return response()->json([
            'message' => 'Pulse template deleted.',
            'templates' => $this->templateService->templatePayloads($access['owner']),
            'media_assets' => $this->mediaAssetPayloads($access['owner']),
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mediaAssetPayloads(User $owner): array
    {
        return $this->mediaAssetService->libraryPayloads($owner, [
            'source' => 'all',
            'origin' => 'all',
            'search' => '',
        ], 24);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function withStoredMediaUploads(Request $request, User $owner, array $validated, string $context): array
    {
        unset($validated['image_file'], $validated['media_files']);

        $clientMediaAssets = $this->mediaAssetService->prepareClientMediaAssets(
            $owner,
            (array) ($validated['media_assets'] ?? []),
        );
        $validated['media_assets'] = $clientMediaAssets['media_assets'];
        $validated['media_uploads'] = $clientMediaAssets['media_uploads'];
        $newMediaUploads = [];

        try {
            $imageFile = $request->file('image_file');
            if ($imageFile instanceof UploadedFile) {
                $validated['image_upload'] = $this->mediaAssetService->storeUploadedImage(
                    $owner,
                    $imageFile,
                    $context
                );
            }

            $mediaFiles = $request->file('media_files', []);
            if ($mediaFiles instanceof UploadedFile) {
                $mediaFiles = [$mediaFiles];
            }

            $nextMediaOrder = count((array) ($request->input('media_assets', [])));
            foreach (is_array($mediaFiles) ? $mediaFiles : [] as $file) {
                if (! $file instanceof UploadedFile) {
                    continue;
                }

                $newMediaUploads[] = [
                    ...$this->mediaAssetService->storeUploadedMedia($owner, $file, $context),
                    '_media_order' => $nextMediaOrder++,
                ];
            }
        } catch (Throwable $exception) {
            $validated['_new_media_uploads'] = $newMediaUploads;
            $this->mediaAssetService->deleteNewUploads($owner, $validated);

            throw $exception;
        }

        $validated['media_uploads'] = [
            ...$validated['media_uploads'],
            ...$newMediaUploads,
        ];
        $validated['_new_media_uploads'] = $newMediaUploads;

        return $validated;
    }

    /**
     * @template TResult
     *
     * @param  array<string, mixed>  $validated
     * @param  Closure(array<string, mixed>): TResult  $persist
     * @return TResult
     */
    private function persistWithStoredMediaUploads(
        Request $request,
        User $owner,
        array $validated,
        string $context,
        Closure $persist,
    ): mixed {
        $payload = $validated;

        try {
            $payload = $this->withStoredMediaUploads($request, $owner, $validated, $context);

            return $persist($payload);
        } catch (Throwable $exception) {
            $this->mediaAssetService->deleteNewUploads($owner, $payload);

            throw $exception;
        }
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

    private function normalizeMediaAssetUrls(Request $request): void
    {
        if (! $request->exists('media_assets')) {
            return;
        }

        $input = $request->input('media_assets');
        if (is_string($input)) {
            $decoded = json_decode($input, true);
            $input = is_array($decoded) ? $decoded : $input;
        }

        if (! is_array($input)) {
            return;
        }

        $mediaAssets = collect($input)
            ->map(function (mixed $asset): mixed {
                if (! is_array($asset)) {
                    return $asset;
                }

                foreach (['url', 'thumbnail_url'] as $key) {
                    if (array_key_exists($key, $asset)) {
                        $asset[$key] = $this->normalizeUrlInputValue($asset[$key]);
                    }
                }

                return $asset;
            })
            ->all();

        $request->merge(['media_assets' => $mediaAssets]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function mediaAssetRules(Request $request, User $owner): array
    {
        $httpsUrl = function (string $attribute, mixed $value, \Closure $fail) use ($request, $owner): void {
            $attributeParts = explode('.', $attribute);
            $assetIndex = isset($attributeParts[1]) ? (int) $attributeParts[1] : -1;
            $mediaAssets = $request->input('media_assets', []);
            $mediaAsset = is_array($mediaAssets) && is_array($mediaAssets[$assetIndex] ?? null)
                ? $mediaAssets[$assetIndex]
                : null;

            if (is_array($mediaAsset) && $this->mediaAssetService->canTrustClientMediaAsset($owner, $mediaAsset)) {
                return;
            }

            if ($value !== null && ! $this->isHttpsUrl($value)) {
                $fail('The '.$attribute.' must be a public HTTPS URL.');
            }
        };

        return [
            'media_assets' => [
                'nullable',
                'array',
                'max:'.self::MAX_MEDIA_ITEMS,
                $this->maximumMediaItemsRule($request),
            ],
            'media_assets.*' => [
                'array',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_array($value) || ($value['type'] ?? null) !== 'document') {
                        return;
                    }

                    if (trim((string) ($value['title'] ?? '')) === '') {
                        $fail('Each document media asset must have a title.');
                    }

                    if (trim((string) ($value['thumbnail_url'] ?? '')) === '') {
                        $fail('Each document media asset must have a thumbnail URL.');
                    }
                },
            ],
            'media_assets.*.type' => ['required', 'string', Rule::in(['image', 'video', 'document'])],
            'media_assets.*.url' => ['required', 'string', 'max:2048', $httpsUrl],
            'media_assets.*.alt_text' => ['nullable', 'string', 'max:1000'],
            'media_assets.*.title' => ['nullable', 'string', 'max:200'],
            'media_assets.*.thumbnail_url' => ['nullable', 'string', 'max:2048', $httpsUrl],
            'media_assets.*.thumbnail_offset' => ['nullable', 'integer', 'min:0'],
            'media_assets.*.source' => ['nullable', 'string', Rule::in(['url', 'upload'])],
            'media_assets.*.disk' => ['nullable', 'string', 'max:32'],
            'media_assets.*.path' => ['nullable', 'string', 'max:512'],
            'media_assets.*.name' => ['nullable', 'string', 'max:255'],
            'media_assets.*.mime_type' => ['nullable', 'string', 'max:191'],
            'media_assets.*.size' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function mediaFileRules(Request $request): array
    {
        return [
            'media_files' => [
                'nullable',
                'array',
                'max:'.self::MAX_MEDIA_ITEMS,
                $this->maximumMediaItemsRule($request),
                $this->maximumUploadSizeRule($request),
            ],
            'media_files.*' => [
                'bail',
                'file',
                'mimes:'.self::MEDIA_UPLOAD_EXTENSIONS,
                'max:'.self::MAX_MEDIA_UPLOAD_KILOBYTES,
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! $value instanceof UploadedFile) {
                        return;
                    }

                    $mimeType = strtolower((string) $value->getMimeType());
                    $size = (int) ($value->getSize() ?? 0);
                    if (
                        str_starts_with($mimeType, 'image/')
                        && $size > self::MAX_IMAGE_UPLOAD_KILOBYTES * 1024
                    ) {
                        $fail('The '.$attribute.' may not be greater than '
                            .self::MAX_IMAGE_UPLOAD_KILOBYTES.' kilobytes.');
                    }
                },
            ],
        ];
    }

    private function maximumMediaItemsRule(Request $request): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($request): void {
            if ($this->totalMediaItems($request) > self::MAX_MEDIA_ITEMS) {
                $fail('The '.$attribute.' and all other media must contain at most '
                    .self::MAX_MEDIA_ITEMS.' items in total.');
            }
        };
    }

    private function maximumUploadSizeRule(Request $request): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($request): void {
            if ($this->totalUploadBytes($request) > self::MAX_TOTAL_UPLOAD_KILOBYTES * 1024) {
                $fail('The '.$attribute.' and primary image may not exceed '
                    .self::MAX_TOTAL_UPLOAD_KILOBYTES.' kilobytes in total.');
            }
        };
    }

    private function totalMediaItems(Request $request): int
    {
        $mediaAssets = $request->input('media_assets', []);
        $assetCount = is_array($mediaAssets) ? count($mediaAssets) : 0;
        $mediaFiles = $request->file('media_files', []);
        $mediaFileCount = $mediaFiles instanceof UploadedFile
            ? 1
            : count(is_array($mediaFiles) ? $mediaFiles : []);

        if ($request->hasFile('image_file')) {
            return $assetCount + $mediaFileCount + 1;
        }

        $primaryImageUrl = trim((string) $request->input('image_url', ''));
        $containsPrimaryImage = $primaryImageUrl !== ''
            && collect(is_array($mediaAssets) ? $mediaAssets : [])->contains(
                fn (mixed $asset): bool => is_array($asset)
                    && ($asset['type'] ?? null) === 'image'
                    && trim((string) ($asset['url'] ?? '')) === $primaryImageUrl
            );

        return $assetCount
            + $mediaFileCount
            + ($primaryImageUrl !== '' && ! $containsPrimaryImage ? 1 : 0);
    }

    private function totalUploadBytes(Request $request): int
    {
        $files = $request->file('media_files', []);
        $files = $files instanceof UploadedFile
            ? [$files]
            : (is_array($files) ? $files : []);

        $imageFile = $request->file('image_file');
        if ($imageFile instanceof UploadedFile) {
            $files[] = $imageFile;
        }

        return collect($files)
            ->filter(fn (mixed $file): bool => $file instanceof UploadedFile)
            ->sum(fn (UploadedFile $file): int => (int) ($file->getSize() ?: 0));
    }

    private function isHttpsUrl(mixed $value): bool
    {
        $parts = parse_url(trim((string) ($value ?? '')));

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && trim((string) ($parts['host'] ?? '')) !== '';
    }

    /**
     * @return array<int, mixed>
     */
    private function linkUrlRules(): array
    {
        return [
            'nullable',
            'string',
            'url',
            'max:2048',
            function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value !== null && ! $this->isHttpsUrl($value)) {
                    $fail('The '.$attribute.' must be a public HTTPS URL.');
                }
            },
        ];
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

        if (str_starts_with($candidate, '/')) {
            return $candidate;
        }

        if (preg_match('/\s/u', $candidate) === 1 || ! str_contains($candidate, '.')) {
            return $candidate;
        }

        return 'https://'.$candidate;
    }

    /**
     * @return array<int, mixed>
     */
    private function imageUrlRules(): array
    {
        return [
            'nullable',
            'string',
            'max:2048',
            function (string $attribute, mixed $value, \Closure $fail): void {
                if (! $this->isValidImageReference($value)) {
                    $fail('The '.$attribute.' must be a valid image URL or Pulse media path.');
                }
            },
        ];
    }

    private function isValidImageReference(mixed $value): bool
    {
        $candidate = trim((string) ($value ?? ''));
        if ($candidate === '') {
            return true;
        }

        return filter_var($candidate, FILTER_VALIDATE_URL) !== false
            || str_starts_with($candidate, '/storage/');
    }
}
