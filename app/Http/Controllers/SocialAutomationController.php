<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\SocialAutomationRule;
use App\Models\SocialAutomationRun;
use App\Models\SocialPost;
use App\Models\SocialPostTemplate;
use App\Models\User;
use App\Services\Demo\DemoWorkspaceCatalog;
use App\Services\Social\SocialAccountConnectionService;
use App\Services\Social\SocialApprovalService;
use App\Services\Social\SocialAutomationRuleService;
use App\Services\Social\SocialAutomationRunnerService;
use App\Services\Social\SocialPostService;
use App\Services\Social\SocialPrefillService;
use App\Support\LocalePreference;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SocialAutomationController extends Controller
{
    public function __construct(
        private readonly SocialAutomationRuleService $ruleService,
        private readonly SocialAutomationRunnerService $runnerService,
        private readonly SocialPostService $postService,
        private readonly SocialAccountConnectionService $connectionService,
        private readonly DemoWorkspaceCatalog $catalog,
    ) {}

    public function automations(Request $request)
    {
        $access = $this->resolveAccess($request->user());
        if (! $access['can_view']) {
            abort(403);
        }

        $owner = $access['owner'];
        $connectionSummary = $this->connectionService->summaryForOwner($owner);
        $postSummary = $this->postService->summaryForOwner($owner);
        $connections = $this->connectionPayloads($owner);
        $rules = SocialAutomationRule::query()
            ->byUser($owner->id)
            ->with('latestRun')
            ->withCount('generatedPosts')
            ->withCount([
                'generatedPosts as pending_approval_posts_count' => fn ($query) => $query->where('status', SocialPost::STATUS_PENDING_APPROVAL),
                'generatedPosts as published_posts_count' => fn ($query) => $query->where('status', SocialPost::STATUS_PUBLISHED),
            ])
            ->orderByDesc('is_active')
            ->orderBy('next_generation_at')
            ->orderByDesc('updated_at')
            ->get();

        $rulePayloads = $rules
            ->map(fn (SocialAutomationRule $rule) => $this->automationRulePayload($rule, $connections))
            ->values()
            ->all();

        return $this->inertiaOrJson('Social/Automations', [
            'rules' => $rulePayloads,
            'summary' => $this->automationSummary($owner, $rules, $rulePayloads),
            'recent_runs' => $this->recentAutomationRuns($owner),
            'content_source_catalog' => $this->contentSourceCatalog($owner),
            'target_connections' => $connections,
            'frequency_options' => collect(SocialAutomationRule::allowedFrequencyTypes())
                ->map(fn (string $value) => ['value' => $value])
                ->values()
                ->all(),
            'approval_mode_options' => collect(SocialAutomationRule::allowedApprovalModes())
                ->map(fn (string $value) => ['value' => $value])
                ->values()
                ->all(),
            'locale_options' => $this->localeOptions(),
            'timezone_options' => $this->timezoneOptions($owner),
            'workspace_stats' => $this->workspaceStats($connectionSummary, $postSummary),
            'access' => $this->accessPayload($access),
        ]);
    }

    public function approvals(Request $request)
    {
        $access = $this->resolveAccess($request->user());
        if (! $access['can_view']) {
            abort(403);
        }

        $owner = $access['owner'];
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'rule_id' => $request->integer('rule_id') ?: null,
            'origin' => in_array((string) $request->query('origin', 'all'), ['all', 'automated', 'manual'], true)
                ? (string) $request->query('origin', 'all')
                : 'all',
            'source_type' => in_array((string) $request->query('source_type', ''), SocialPrefillService::allowedSourceTypes(), true)
                ? (string) $request->query('source_type', '')
                : '',
        ];

        $connectionSummary = $this->connectionService->summaryForOwner($owner);
        $postSummary = $this->postService->summaryForOwner($owner);

        $posts = SocialPost::query()
            ->byUser($owner->id)
            ->where('status', SocialPost::STATUS_PENDING_APPROVAL)
            ->with([
                'automationRule',
                'targets.socialAccountConnection',
                'latestApprovalRequest.requestedBy',
                'latestApprovalRequest.resolvedBy',
            ])
            ->when($filters['rule_id'], fn ($query, $ruleId) => $query->where('social_automation_rule_id', $ruleId))
            ->when($filters['origin'] === 'automated', fn ($query) => $query->whereNotNull('social_automation_rule_id'))
            ->when($filters['origin'] === 'manual', fn ($query) => $query->whereNull('social_automation_rule_id'))
            ->when($filters['source_type'] !== '', fn ($query) => $query->where('source_type', $filters['source_type']))
            ->when($filters['search'] !== '', function ($query) use ($filters): void {
                $like = '%'.$filters['search'].'%';

                $query->where(function ($searchQuery) use ($like): void {
                    $searchQuery
                        ->where('content_payload->text', 'like', $like)
                        ->orWhere('metadata->source->label', 'like', $like)
                        ->orWhere('link_url', 'like', $like);
                });
            })
            ->orderByDesc('updated_at')
            ->limit(40)
            ->get();

        $ruleOptions = SocialAutomationRule::query()
            ->byUser($owner->id)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (SocialAutomationRule $rule) => [
                'value' => $rule->id,
                'label' => $rule->name,
            ])
            ->values()
            ->all();

        return $this->inertiaOrJson('Social/Approvals', [
            'posts' => $posts->map(fn (SocialPost $post) => $this->postService->payload($post))->values()->all(),
            'filters' => $filters,
            'summary' => [
                'pending' => $posts->count(),
                'automated' => $posts->whereNotNull('social_automation_rule_id')->count(),
                'manual' => $posts->whereNull('social_automation_rule_id')->count(),
                'stale' => $posts->filter(function (SocialPost $post): bool {
                    $requestedAt = $post->latestApprovalRequest?->requested_at;

                    return $requestedAt !== null && $requestedAt->lessThanOrEqualTo(now()->subDay());
                })->count(),
            ],
            'rule_filters' => $ruleOptions,
            'source_filters' => $this->approvalSourceOptions(),
            'workspace_stats' => $this->workspaceStats($connectionSummary, $postSummary),
            'access' => $this->accessPayload($access),
        ]);
    }

    public function store(Request $request)
    {
        $access = $this->resolveAccess($request->user());
        if (! $access['can_manage_automations']) {
            abort(403);
        }

        $validated = $this->validateAutomationRule($request);
        $rule = $this->ruleService->create($access['owner'], $request->user(), $validated);

        return response()->json([
            'message' => 'Pulse automation rule saved.',
            'rule' => $this->automationRulePayload($rule, $this->connectionPayloads($access['owner'])),
        ], 201);
    }

    public function update(Request $request, SocialAutomationRule $rule)
    {
        $access = $this->resolveAccess($request->user());
        if (! $access['can_manage_automations']) {
            abort(403);
        }

        $validated = $this->validateAutomationRule($request);
        $savedRule = $this->ruleService->update($access['owner'], $request->user(), $rule, $validated);

        return response()->json([
            'message' => 'Pulse automation rule updated.',
            'rule' => $this->automationRulePayload($savedRule, $this->connectionPayloads($access['owner'])),
        ]);
    }

    public function pause(Request $request, SocialAutomationRule $rule)
    {
        $access = $this->resolveAccess($request->user());
        if (! $access['can_manage_automations']) {
            abort(403);
        }

        $pausedRule = $this->ruleService->pause($access['owner'], $rule);

        return response()->json([
            'message' => 'Pulse automation paused.',
            'rule' => $this->automationRulePayload($pausedRule, $this->connectionPayloads($access['owner'])),
        ]);
    }

    public function resume(Request $request, SocialAutomationRule $rule)
    {
        $access = $this->resolveAccess($request->user());
        if (! $access['can_manage_automations']) {
            abort(403);
        }

        $resumedRule = $this->ruleService->resume($access['owner'], $rule);

        return response()->json([
            'message' => 'Pulse automation resumed.',
            'rule' => $this->automationRulePayload($resumedRule, $this->connectionPayloads($access['owner'])),
        ]);
    }

    public function destroy(Request $request, SocialAutomationRule $rule)
    {
        $access = $this->resolveAccess($request->user());
        if (! $access['can_manage_automations']) {
            abort(403);
        }

        $this->ruleService->delete($access['owner'], $rule);

        return response()->json([
            'message' => 'Pulse automation deleted.',
        ]);
    }

    public function prepareRevision(Request $request, SocialPost $post, SocialApprovalService $approvalService)
    {
        $access = $this->resolveAccess($request->user());
        if (! $access['can_approve']) {
            abort(403);
        }

        $draft = $this->postService->duplicate($access['owner'], $request->user(), $post);
        $approvalService->reject($access['owner'], $request->user(), $post, [
            'note' => 'Revision requested from the Pulse approval inbox.',
        ]);

        return response()->json([
            'message' => 'Pulse approval moved back to revision mode.',
            'draft' => $this->postService->payload($draft),
        ], 201);
    }

    public function regenerate(Request $request, SocialPost $post)
    {
        $access = $this->resolveAccess($request->user());
        if (! $access['can_approve']) {
            abort(403);
        }

        $replacement = $this->runnerService->regeneratePendingApproval($access['owner'], $request->user(), $post);

        return response()->json([
            'message' => 'Pulse Autopilot generated a new approval candidate.',
            'draft' => $this->postService->payload($replacement),
        ], 201);
    }

    /**
     * @return array{
     *     owner: User,
     *     can_view: bool,
     *     can_manage_posts: bool,
     *     can_manage_automations: bool,
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
                'can_manage_automations' => true,
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
            'can_manage_automations' => $canManagePosts,
            'can_publish' => $canPublish,
            'can_submit_for_approval' => $canSubmitForApproval,
            'can_approve' => $canApprove,
        ];
    }

    /**
     * @param  array{
     *     can_view: bool,
     *     can_manage_posts: bool,
     *     can_manage_automations: bool,
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
            'can_manage_automations' => $access['can_manage_automations'],
            'can_publish' => $access['can_publish'],
            'can_submit_for_approval' => $access['can_submit_for_approval'],
            'can_approve' => $access['can_approve'],
        ];
    }

    /**
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
     * @param  array<int, array<string, mixed>>  $connections
     * @return array<string, mixed>
     */
    private function automationRulePayload(SocialAutomationRule $rule, array $connections): array
    {
        $selectedIds = collect((array) $rule->target_connection_ids)
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $selectedConnections = collect($connections)
            ->filter(fn (array $connection): bool => in_array((int) ($connection['id'] ?? 0), $selectedIds, true))
            ->values()
            ->all();

        $health = $this->automationRuleHealthPayload($rule, $selectedConnections);
        $latestRun = $rule->latestRun;

        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'description' => $rule->description,
            'is_active' => (bool) $rule->is_active,
            'frequency_type' => $rule->frequency_type,
            'frequency_interval' => (int) $rule->frequency_interval,
            'scheduled_time' => $rule->scheduled_time,
            'timezone' => $rule->timezone,
            'approval_mode' => $rule->approval_mode,
            'language' => $rule->language,
            'content_sources' => is_array($rule->content_sources) ? $rule->content_sources : [],
            'target_connection_ids' => $selectedIds,
            'target_connections' => $selectedConnections,
            'selected_platforms' => collect($selectedConnections)
                ->pluck('platform')
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'max_posts_per_day' => (int) $rule->max_posts_per_day,
            'min_hours_between_similar_posts' => (int) $rule->min_hours_between_similar_posts,
            'last_generated_at' => optional($rule->last_generated_at)->toIso8601String(),
            'next_generation_at' => optional($rule->next_generation_at)->toIso8601String(),
            'last_error' => $rule->last_error,
            'generated_posts_count' => (int) ($rule->generated_posts_count ?? 0),
            'pending_approval_posts_count' => (int) ($rule->pending_approval_posts_count ?? 0),
            'published_posts_count' => (int) ($rule->published_posts_count ?? 0),
            'health' => $health,
            'latest_run' => $latestRun ? $this->automationRunPayload($latestRun) : null,
            'metadata' => is_array($rule->metadata) ? $rule->metadata : [],
            'updated_at' => optional($rule->updated_at)->toIso8601String(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rulePayloads
     * @return array<string, int>
     */
    private function automationSummary(User $owner, $rules, array $rulePayloads): array
    {
        return [
            'total' => $rules->count(),
            'active' => $rules->where('is_active', true)->count(),
            'paused' => $rules->where('is_active', false)->count(),
            'pending_approvals' => SocialPost::query()
                ->byUser($owner->id)
                ->where('status', SocialPost::STATUS_PENDING_APPROVAL)
                ->whereNotNull('social_automation_rule_id')
                ->count(),
            'attention' => collect($rulePayloads)
                ->filter(fn (array $payload): bool => in_array((string) data_get($payload, 'health.state', 'healthy'), ['warning', 'attention'], true))
                ->count(),
            'auto_paused' => collect($rulePayloads)
                ->filter(fn (array $payload): bool => (bool) data_get($payload, 'health.auto_paused', false))
                ->count(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentAutomationRuns(User $owner): array
    {
        return SocialAutomationRun::query()
            ->byUser($owner->id)
            ->with(['automationRule:id,name', 'post:id,status'])
            ->latest('started_at')
            ->limit(8)
            ->get()
            ->map(fn (SocialAutomationRun $run) => $this->automationRunPayload($run))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $selectedConnections
     * @return array<string, mixed>
     */
    private function automationRuleHealthPayload(SocialAutomationRule $rule, array $selectedConnections): array
    {
        $metadata = is_array($rule->metadata) ? $rule->metadata : [];
        $health = is_array($metadata['health'] ?? null) ? $metadata['health'] : [];
        $reasons = [];
        $state = 'healthy';

        if (collect($selectedConnections)->contains(fn (array $connection): bool => ! (bool) ($connection['is_connected'] ?? false))) {
            $state = 'attention';
            $reasons[] = 'connection_attention';
        }

        if ((bool) ($health['auto_paused'] ?? false)) {
            $state = 'attention';
            $reasons[] = 'auto_paused';
        }

        if (max(0, (int) ($health['consecutive_failures'] ?? 0)) >= 2) {
            $state = 'attention';
            $reasons[] = 'failure_streak';
        }

        if ((int) ($rule->pending_approval_posts_count ?? 0) >= 3) {
            if ($state === 'healthy') {
                $state = 'warning';
            }
            $reasons[] = 'approval_backlog';
        }

        if ($rule->is_active
            && $rule->next_generation_at
            && $rule->next_generation_at->lessThan(now()->subHours(6))) {
            if ($state === 'healthy') {
                $state = 'warning';
            }
            $reasons[] = 'schedule_overdue';
        }

        if (trim((string) $rule->last_error) !== '') {
            if ($state === 'healthy') {
                $state = 'warning';
            }
            $reasons[] = 'last_error';
        }

        return [
            'state' => $state,
            'reasons' => array_values(array_unique($reasons)),
            'auto_paused' => (bool) ($health['auto_paused'] ?? false),
            'auto_paused_at' => $health['auto_paused_at'] ?? null,
            'auto_pause_reason' => $health['auto_pause_reason'] ?? null,
            'consecutive_failures' => max(0, (int) ($health['consecutive_failures'] ?? 0)),
            'last_success_at' => $health['last_success_at'] ?? null,
            'last_guarded_skip_at' => $health['last_guarded_skip_at'] ?? null,
            'last_resumed_at' => $health['last_resumed_at'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function automationRunPayload(SocialAutomationRun $run): array
    {
        return [
            'id' => $run->id,
            'social_automation_rule_id' => $run->social_automation_rule_id,
            'social_post_id' => $run->social_post_id,
            'status' => (string) $run->status,
            'outcome_code' => (string) ($run->outcome_code ?? ''),
            'message' => $run->message,
            'source_type' => $run->source_type,
            'source_id' => $run->source_id,
            'rule' => $run->automationRule
                ? [
                    'id' => $run->automationRule->id,
                    'name' => $run->automationRule->name,
                ]
                : null,
            'post' => $run->post
                ? [
                    'id' => $run->post->id,
                    'status' => (string) $run->post->status,
                ]
                : null,
            'metadata' => is_array($run->metadata) ? $run->metadata : [],
            'started_at' => optional($run->started_at)->toIso8601String(),
            'completed_at' => optional($run->completed_at)->toIso8601String(),
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function approvalSourceOptions(): array
    {
        return collect(SocialPrefillService::allowedSourceTypes())
            ->map(fn (string $value) => [
                'value' => $value,
                'label' => match ($value) {
                    SocialPrefillService::SOURCE_PRODUCT => 'Products',
                    SocialPrefillService::SOURCE_SERVICE => 'Services',
                    SocialPrefillService::SOURCE_PROMOTION => 'Promotions',
                    SocialPrefillService::SOURCE_CAMPAIGN => 'Campaigns',
                    default => 'Pulse templates',
                },
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function connectionPayloads(User $owner): array
    {
        return collect($this->connectionService->listPayloads($owner))
            ->map(fn (array $connection): array => [
                'id' => (int) ($connection['id'] ?? 0),
                'platform' => (string) ($connection['platform'] ?? ''),
                'provider_label' => (string) ($connection['provider_label'] ?? ''),
                'label' => (string) ($connection['label'] ?? ''),
                'display_name' => $connection['display_name'] ?? null,
                'account_handle' => $connection['account_handle'] ?? null,
                'status' => (string) ($connection['status'] ?? ''),
                'is_connected' => (bool) ($connection['is_connected'] ?? false),
                'is_active' => (bool) ($connection['is_active'] ?? false),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function contentSourceCatalog(User $owner): array
    {
        $catalog = [];

        if ($owner->hasCompanyFeature('products')) {
            $catalog[] = [
                'type' => SocialPrefillService::SOURCE_PRODUCT,
                'label' => 'Products',
                'items' => Product::query()
                    ->where('user_id', $owner->id)
                    ->where('item_type', Product::ITEM_TYPE_PRODUCT)
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (Product $product) => [
                        'value' => $product->id,
                        'label' => trim((string) $product->name) !== '' ? $product->name : 'Product #'.$product->id,
                    ])
                    ->values()
                    ->all(),
            ];
        }

        if ($owner->hasCompanyFeature('services')) {
            $catalog[] = [
                'type' => SocialPrefillService::SOURCE_SERVICE,
                'label' => 'Services',
                'items' => Product::query()
                    ->where('user_id', $owner->id)
                    ->where('item_type', Product::ITEM_TYPE_SERVICE)
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (Product $service) => [
                        'value' => $service->id,
                        'label' => trim((string) $service->name) !== '' ? $service->name : 'Service #'.$service->id,
                    ])
                    ->values()
                    ->all(),
            ];
        }

        if ($owner->hasCompanyFeature('promotions')) {
            $catalog[] = [
                'type' => SocialPrefillService::SOURCE_PROMOTION,
                'label' => 'Promotions',
                'items' => Promotion::query()
                    ->forAccount($owner->id)
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (Promotion $promotion) => [
                        'value' => $promotion->id,
                        'label' => trim((string) $promotion->name) !== '' ? $promotion->name : 'Promotion #'.$promotion->id,
                    ])
                    ->values()
                    ->all(),
            ];
        }

        if ($owner->hasCompanyFeature('campaigns')) {
            $catalog[] = [
                'type' => SocialPrefillService::SOURCE_CAMPAIGN,
                'label' => 'Campaigns',
                'items' => Campaign::query()
                    ->where('user_id', $owner->id)
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (Campaign $campaign) => [
                        'value' => $campaign->id,
                        'label' => trim((string) $campaign->name) !== '' ? $campaign->name : 'Campaign #'.$campaign->id,
                    ])
                    ->values()
                    ->all(),
            ];
        }

        $catalog[] = [
            'type' => SocialPrefillService::SOURCE_TEMPLATE,
            'label' => 'Pulse templates',
            'items' => SocialPostTemplate::query()
                ->byUser($owner->id)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (SocialPostTemplate $template) => [
                    'value' => $template->id,
                    'label' => trim((string) $template->name) !== '' ? $template->name : 'Template #'.$template->id,
                ])
                ->values()
                ->all(),
        ];

        return $catalog;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function localeOptions(): array
    {
        return collect(LocalePreference::supported())
            ->map(fn (string $locale) => [
                'value' => $locale,
                'label' => match ($locale) {
                    'fr' => 'Francais',
                    'es' => 'Espanol',
                    default => 'English',
                },
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function timezoneOptions(User $owner): array
    {
        $base = collect($this->catalog->timezones());
        $ownerTimezone = trim((string) ($owner->company_timezone ?: config('app.timezone', 'UTC')));

        if ($ownerTimezone !== '' && ! $base->pluck('value')->contains($ownerTimezone)) {
            $base->prepend([
                'value' => $ownerTimezone,
                'label' => $ownerTimezone,
            ]);
        }

        return $base
            ->unique('value')
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function validateAutomationRule(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
            'frequency_type' => ['required', 'string', Rule::in(SocialAutomationRule::allowedFrequencyTypes())],
            'frequency_interval' => ['nullable', 'integer', 'min:1', 'max:31'],
            'scheduled_time' => ['nullable', 'date_format:H:i'],
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'approval_mode' => ['required', 'string', Rule::in(SocialAutomationRule::allowedApprovalModes())],
            'language' => ['required', 'string', Rule::in(LocalePreference::supported())],
            'target_connection_ids' => ['required', 'array', 'min:1'],
            'target_connection_ids.*' => ['integer', 'distinct'],
            'content_sources' => ['required', 'array', 'min:1'],
            'content_sources.*.type' => ['required', 'string', Rule::in(SocialPrefillService::allowedSourceTypes())],
            'content_sources.*.mode' => ['required', 'string', Rule::in(['all', 'selected_ids'])],
            'content_sources.*.ids' => ['nullable', 'array'],
            'content_sources.*.ids.*' => ['integer', 'distinct'],
            'max_posts_per_day' => ['nullable', 'integer', 'min:1', 'max:20'],
            'min_hours_between_similar_posts' => ['nullable', 'integer', 'min:1', 'max:720'],
        ]);

        $validated['content_sources'] = collect((array) ($validated['content_sources'] ?? []))
            ->map(function (array $item): array {
                return [
                    'type' => $item['type'],
                    'mode' => $item['mode'],
                    'ids' => collect((array) ($item['ids'] ?? []))
                        ->map(fn ($id) => (int) $id)
                        ->filter(fn (int $id): bool => $id > 0)
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();

        return $validated;
    }
}
