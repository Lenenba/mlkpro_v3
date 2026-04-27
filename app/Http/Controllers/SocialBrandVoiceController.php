<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Social\SocialAccountConnectionService;
use App\Services\Social\SocialBrandVoiceService;
use App\Services\Social\SocialPostService;
use App\Support\LocalePreference;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SocialBrandVoiceController extends Controller
{
    public function __construct(
        private readonly SocialBrandVoiceService $brandVoiceService,
        private readonly SocialPostService $postService,
        private readonly SocialAccountConnectionService $connectionService,
    ) {}

    public function edit(Request $request)
    {
        $access = $this->resolveAccess($request->user());
        if (! $access['can_view']) {
            abort(403);
        }

        $connectionSummary = $this->connectionService->summaryForOwner($access['owner']);
        $postSummary = $this->postService->summaryForOwner($access['owner']);

        return $this->inertiaOrJson('Social/BrandVoice', [
            'brand_voice' => $this->brandVoiceService->resolve($access['owner']),
            'tone_options' => collect(SocialBrandVoiceService::allowedTones())
                ->map(fn (string $value): array => ['value' => $value])
                ->values()
                ->all(),
            'language_options' => collect(LocalePreference::supported())
                ->map(fn (string $value): array => [
                    'value' => $value,
                    'label' => match ($value) {
                        'fr' => 'Francais',
                        'es' => 'Espanol',
                        default => 'English',
                    },
                ])
                ->values()
                ->all(),
            'workspace_stats' => $this->workspaceStats($connectionSummary, $postSummary),
            'access' => $this->accessPayload($access),
        ]);
    }

    public function update(Request $request)
    {
        $access = $this->resolveAccess($request->user());
        if (! $access['can_manage_posts']) {
            abort(403);
        }

        $validated = $request->validate([
            'tone' => ['required', 'string', Rule::in(SocialBrandVoiceService::allowedTones())],
            'language' => ['required', 'string', Rule::in(LocalePreference::supported())],
            'style_notes' => ['nullable', 'string', 'max:700'],
            'words_to_avoid' => ['nullable', 'array'],
            'words_to_avoid.*' => ['nullable', 'string', 'max:80'],
            'preferred_hashtags' => ['nullable', 'array'],
            'preferred_hashtags.*' => ['nullable', 'string', 'max:80'],
            'preferred_ctas' => ['nullable', 'array'],
            'preferred_ctas.*' => ['nullable', 'string', 'max:160'],
            'sample_phrase' => ['nullable', 'string', 'max:240'],
        ]);

        $brandVoice = $this->brandVoiceService->update($access['owner'], $validated);

        return response()->json([
            'message' => 'Pulse brand voice updated.',
            'brand_voice' => $brandVoice,
        ]);
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
