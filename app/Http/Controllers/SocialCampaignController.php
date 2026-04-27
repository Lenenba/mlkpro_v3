<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Social\SocialAccountConnectionService;
use App\Services\Social\SocialCampaignDraftService;
use App\Services\Social\SocialPostService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SocialCampaignController extends Controller
{
    public function __construct(
        private readonly SocialCampaignDraftService $campaignDraftService,
        private readonly SocialPostService $postService,
        private readonly SocialAccountConnectionService $connectionService,
    ) {}

    public function index(Request $request)
    {
        $access = $this->resolveAccess($request->user());
        if (! $access['can_view']) {
            abort(403);
        }

        $owner = $access['owner'];
        $connectionSummary = $this->connectionService->summaryForOwner($owner);
        $postSummary = $this->postService->summaryForOwner($owner);

        return $this->inertiaOrJson('Social/Campaigns', [
            'connected_accounts' => $this->postService->connectedAccountOptions($owner),
            'recent_batches' => $this->campaignDraftService->recentBatches($owner),
            'intention_options' => collect(SocialCampaignDraftService::allowedIntentions())
                ->map(fn (string $value): array => ['value' => $value])
                ->values()
                ->all(),
            'summary' => $postSummary,
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
            'name' => ['nullable', 'string', 'max:140'],
            'intention_type' => ['required', 'string', Rule::in(SocialCampaignDraftService::allowedIntentions())],
            'brief' => ['required', 'string', 'max:1200'],
            'start_date' => ['required', 'date'],
            'post_count' => ['required', 'integer', 'min:2', 'max:8'],
            'duration_days' => ['required', 'integer', 'min:1', 'max:30'],
            'image_url' => $this->imageUrlRules(),
            'link_url' => ['nullable', 'url', 'max:2048'],
            'target_connection_ids' => ['required', 'array', 'min:1'],
            'target_connection_ids.*' => ['integer', 'distinct'],
        ]);

        $result = $this->campaignDraftService->generate($access['owner'], $request->user(), $validated);

        return response()->json([
            'message' => 'Pulse campaign drafts generated.',
            'batch' => $result['batch'],
            'posts' => $result['posts'],
            'recent_batches' => $this->campaignDraftService->recentBatches($access['owner']),
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

        return [
            'owner' => $owner,
            'can_view' => $canView,
            'can_manage_posts' => $canManagePosts,
            'can_publish' => $canPublish,
            'can_submit_for_approval' => (bool) $membership?->hasPermission('social.publish'),
            'can_approve' => $canApprove,
        ];
    }

    /**
     * @param  array<string, mixed>  $access
     * @return array<string, bool>
     */
    private function accessPayload(array $access): array
    {
        return [
            'can_view' => (bool) $access['can_view'],
            'can_manage_posts' => (bool) $access['can_manage_posts'],
            'can_manage_automations' => (bool) $access['can_manage_posts'],
            'can_publish' => (bool) $access['can_publish'],
            'can_submit_for_approval' => (bool) $access['can_submit_for_approval'],
            'can_approve' => (bool) $access['can_approve'],
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
                $candidate = trim((string) ($value ?? ''));
                if ($candidate === '') {
                    return;
                }

                if (filter_var($candidate, FILTER_VALIDATE_URL) === false && ! str_starts_with($candidate, '/storage/')) {
                    $fail('The '.$attribute.' must be a valid image URL or Pulse media path.');
                }
            },
        ];
    }
}
