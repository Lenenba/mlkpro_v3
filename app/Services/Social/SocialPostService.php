<?php

namespace App\Services\Social;

use App\Models\SocialAccountConnection;
use App\Models\SocialAutomationRule;
use App\Models\SocialPost;
use App\Models\SocialPostRevision;
use App\Models\SocialPostTarget;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SocialPostService
{
    public function __construct(
        private readonly SocialAccountConnectionService $connectionService,
        private readonly SocialPrefillService $prefillService,
        private readonly SocialMediaAssetService $mediaAssetService,
        private readonly SocialPostQualityService $qualityService,
        private readonly SocialAiTraceService $aiTraceService,
        private readonly SocialPostRevisionService $revisionService,
        private readonly SocialScheduledTimeResolver $scheduledTimeResolver,
    ) {}

    /**
     * @return Collection<int, SocialPost>
     */
    public function listDraftsForOwner(User $owner, int $limit = 8): Collection
    {
        return SocialPost::query()
            ->byUser($owner->id)
            ->whereIn('status', [
                SocialPost::STATUS_DRAFT,
                SocialPost::STATUS_SCHEDULED,
                SocialPost::STATUS_PENDING_APPROVAL,
            ])
            ->with([
                'automationRule',
                'targets.socialAccountConnection',
                'latestApprovalRequest.requestedBy',
                'latestApprovalRequest.resolvedBy',
            ])
            ->orderByRaw("case status when 'draft' then 0 when 'pending_approval' then 1 when 'scheduled' then 2 else 3 end")
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function draftPayloads(User $owner, int $limit = 8): array
    {
        return $this->listDraftsForOwner($owner, $limit)
            ->map(fn (SocialPost $post) => $this->payload($post))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, SocialPost>
     */
    public function listHistoryForOwner(User $owner, array $filters = [], int $limit = 24): Collection
    {
        $query = SocialPost::query()
            ->byUser($owner->id)
            ->with([
                'automationRule',
                'targets.socialAccountConnection',
                'latestApprovalRequest.requestedBy',
                'latestApprovalRequest.resolvedBy',
            ])
            ->orderByDesc('updated_at');

        $status = $this->nullableString($filters, 'status');
        if ($status !== null && in_array($status, SocialPost::allowedStatuses(), true)) {
            $query->where('status', $status);
        }

        $platform = $this->nullableString($filters, 'platform');
        if ($platform !== null && in_array($platform, SocialAccountConnection::allowedPlatforms(), true)) {
            $query->whereHas('targets', function ($targetQuery) use ($platform): void {
                $targetQuery->whereHas('socialAccountConnection', function ($connectionQuery) use ($platform): void {
                    $connectionQuery->where('platform', $platform);
                });
            });
        }

        $search = $this->nullableString($filters, 'search');
        if ($search !== null) {
            $like = '%'.$search.'%';

            $query->where(function ($searchQuery) use ($like): void {
                $searchQuery
                    ->where('content_payload->text', 'like', $like)
                    ->orWhere('metadata->link_cta_label', 'like', $like)
                    ->orWhere('link_url', 'like', $like)
                    ->orWhere('failure_reason', 'like', $like);
            });
        }

        return $query
            ->limit(max(1, min(100, $limit)))
            ->get();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function historyPayloads(User $owner, array $filters = [], int $limit = 24): array
    {
        return $this->listHistoryForOwner($owner, $filters, $limit)
            ->map(fn (SocialPost $post) => $this->payload($post))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function calendarPayloads(User $owner, int $limit = 140): array
    {
        return SocialPost::query()
            ->byUser($owner->id)
            ->with([
                'automationRule',
                'targets.socialAccountConnection',
                'latestApprovalRequest.requestedBy',
                'latestApprovalRequest.resolvedBy',
            ])
            ->whereIn('status', [
                SocialPost::STATUS_DRAFT,
                SocialPost::STATUS_SCHEDULED,
                SocialPost::STATUS_PENDING_APPROVAL,
                SocialPost::STATUS_PUBLISHING,
                SocialPost::STATUS_PUBLISHED,
                SocialPost::STATUS_PARTIAL_FAILED,
                SocialPost::STATUS_FAILED,
            ])
            ->latest('updated_at')
            ->limit(max(1, min(240, $limit)))
            ->get()
            ->map(fn (SocialPost $post) => $this->calendarPayload($post))
            ->sortBy(fn (array $payload): string => (string) ($payload['calendar_at'] ?? ''))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function connectedAccountOptions(User $owner): array
    {
        return collect($this->connectionService->listPublishingPayloads($owner))
            ->filter(fn (array $connection): bool => (bool) ($connection['is_connected'] ?? false))
            ->map(function (array $connection): array {
                return [
                    'id' => (int) ($connection['id'] ?? 0),
                    'platform' => (string) ($connection['platform'] ?? ''),
                    'provider_label' => (string) ($connection['provider_label'] ?? ''),
                    'label' => (string) ($connection['label'] ?? ''),
                    'display_name' => $connection['display_name'] ?? null,
                    'account_handle' => $connection['account_handle'] ?? null,
                    'target_type' => $connection['target_type'] ?? null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, int>
     */
    public function summaryForOwner(User $owner): array
    {
        $posts = SocialPost::query()
            ->byUser($owner->id)
            ->get(['status']);

        $statusCounts = collect(SocialPost::allowedStatuses())
            ->mapWithKeys(fn (string $status) => [$status => 0])
            ->all();

        foreach ($posts as $post) {
            $status = (string) $post->status;
            if (! array_key_exists($status, $statusCounts)) {
                $statusCounts[$status] = 0;
            }

            $statusCounts[$status]++;
        }

        return [
            'drafts' => (int) ($statusCounts[SocialPost::STATUS_DRAFT] ?? 0)
                + (int) ($statusCounts[SocialPost::STATUS_PENDING_APPROVAL] ?? 0),
            'scheduled' => (int) ($statusCounts[SocialPost::STATUS_SCHEDULED] ?? 0),
            'pending_approval' => (int) ($statusCounts[SocialPost::STATUS_PENDING_APPROVAL] ?? 0),
            'publishing' => (int) ($statusCounts[SocialPost::STATUS_PUBLISHING] ?? 0),
            'published' => (int) ($statusCounts[SocialPost::STATUS_PUBLISHED] ?? 0),
            'attention' => (int) ($statusCounts[SocialPost::STATUS_PARTIAL_FAILED] ?? 0)
                + (int) ($statusCounts[SocialPost::STATUS_FAILED] ?? 0),
            'total' => $posts->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createDraft(User $owner, User $actor, array $payload): SocialPost
    {
        $targetConnections = $this->resolveTargetConnections($owner, (array) ($payload['target_connection_ids'] ?? []));
        $attributes = $this->postAttributes($owner, $actor, $payload, $targetConnections);
        $postId = DB::transaction(function () use ($actor, $attributes, $targetConnections): int {
            $post = SocialPost::query()->create($attributes);
            $this->syncTargetsFromConnections($post, $targetConnections, $attributes['status']);
            $this->revisionService->capture($post, $actor);

            return (int) $post->id;
        });

        return SocialPost::query()
            ->with(['targets.socialAccountConnection', 'revisions'])
            ->findOrFail($postId);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateDraft(User $owner, User $actor, SocialPost $post, array $payload): SocialPost
    {
        $this->assertOwnership($owner, $post);

        $postId = DB::transaction(function () use ($owner, $actor, $post, $payload): int {
            $lockedPost = $this->lockedPost($owner, $post);
            $this->assertEditable($lockedPost);

            $targetConnections = $this->resolveTargetConnections(
                $owner,
                (array) ($payload['target_connection_ids'] ?? [])
            );
            $attributes = $this->postAttributes($owner, $actor, $payload, $targetConnections);

            $lockedPost->forceFill([
                ...$attributes,
                'created_by_user_id' => $lockedPost->created_by_user_id ?: $actor->id,
            ])->save();

            $this->syncTargetsFromConnections($lockedPost, $targetConnections, $attributes['status']);
            $this->revisionService->capture($lockedPost, $actor);

            return (int) $lockedPost->id;
        });

        return SocialPost::query()
            ->with(['targets.socialAccountConnection'])
            ->findOrFail($postId);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function rescheduleDraft(User $owner, User $actor, SocialPost $post, array $payload): SocialPost
    {
        $this->assertOwnership($owner, $post);

        $postId = DB::transaction(function () use ($owner, $actor, $post, $payload): int {
            $lockedPost = $this->lockedPost($owner, $post);
            $this->assertEditable($lockedPost);

            $scheduledFor = $this->scheduledTimeResolver->resolve(
                $owner,
                $payload['scheduled_for'] ?? null,
            );
            if ($scheduledFor instanceof Carbon && $scheduledFor->lessThanOrEqualTo(now())) {
                throw ValidationException::withMessages([
                    'scheduled_for' => 'Choose a future date before scheduling this Pulse post.',
                ]);
            }

            $status = $scheduledFor instanceof Carbon
                ? SocialPost::STATUS_SCHEDULED
                : SocialPost::STATUS_DRAFT;

            $lockedPost->forceFill([
                'updated_by_user_id' => $actor->id,
                'status' => $status,
                'scheduled_for' => $scheduledFor,
                'metadata' => array_merge((array) ($lockedPost->metadata ?? []), [
                    'calendar_rescheduled_at' => now()->toIso8601String(),
                    'calendar_rescheduled_by_user_id' => $actor->id,
                ]),
            ])->save();

            $this->syncExistingTargetStatus($lockedPost, $status);
            $this->revisionService->capture($lockedPost, $actor);

            return (int) $lockedPost->id;
        });

        return SocialPost::query()
            ->with(['targets.socialAccountConnection'])
            ->findOrFail($postId);
    }

    /**
     * @param  Collection<int, SocialAccountConnection>  $targetConnections
     * @param  array<string, mixed>  $payload
     */
    public function createAutomationDraft(
        User $owner,
        User $actor,
        SocialAutomationRule $rule,
        Collection $targetConnections,
        array $payload
    ): SocialPost {
        if ($targetConnections->isEmpty()) {
            throw ValidationException::withMessages([
                'target_connection_ids' => 'Select at least one connected social account before generating an automated Pulse post.',
            ]);
        }

        $text = trim((string) data_get($payload, 'content_payload.text', ''));
        $mediaPayload = is_array($payload['media_payload'] ?? null) ? $payload['media_payload'] : null;
        $linkUrl = $this->nullableString($payload, 'link_url');

        if ($text === '' && $mediaPayload === null && $linkUrl === null) {
            throw ValidationException::withMessages([
                'content' => 'Generate some text, an image, or a destination link before creating an automated Pulse post.',
            ]);
        }

        $postId = DB::transaction(function () use (
            $actor,
            $linkUrl,
            $mediaPayload,
            $owner,
            $payload,
            $rule,
            $targetConnections,
        ): int {
            $post = SocialPost::query()->create([
                'user_id' => $owner->id,
                'created_by_user_id' => $actor->id,
                'updated_by_user_id' => $actor->id,
                'source_type' => $this->nullableString($payload, 'source_type'),
                'source_id' => data_get($payload, 'source_id') ? (int) data_get($payload, 'source_id') : null,
                'social_automation_rule_id' => $rule->id,
                'content_payload' => $payload['content_payload'] ?? null,
                'media_payload' => $mediaPayload,
                'link_url' => $linkUrl,
                'status' => SocialPost::STATUS_DRAFT,
                'metadata' => $payload['metadata'] ?? null,
            ]);

            $this->syncTargetsFromConnections($post, $targetConnections, SocialPost::STATUS_DRAFT);
            $this->revisionService->capture(
                $post,
                $actor,
                SocialPostRevision::ORIGIN_AUTOMATION,
            );

            return (int) $post->id;
        });

        return SocialPost::query()
            ->with(['targets.socialAccountConnection', 'automationRule', 'revisions'])
            ->findOrFail($postId);
    }

    public function duplicate(User $owner, User $actor, SocialPost $source): SocialPost
    {
        return $this->createEditableCopy($owner, $actor, $source, 'duplicate');
    }

    public function repost(User $owner, User $actor, SocialPost $source): SocialPost
    {
        if ((string) $source->status !== SocialPost::STATUS_PUBLISHED) {
            throw ValidationException::withMessages([
                'post' => 'Only a published Pulse post can be reopened as a repost.',
            ]);
        }

        return $this->createEditableCopy($owner, $actor, $source, 'repost');
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(SocialPost $post): array
    {
        $post->loadMissing([
            'user',
            'automationRule',
            'targets.socialAccountConnection',
            'latestApprovalRequest.requestedBy',
            'latestApprovalRequest.resolvedBy',
        ]);

        $text = trim((string) data_get($post->content_payload, 'text', ''));
        $approvalRequest = $post->latestApprovalRequest;
        $automationRule = $post->automationRule;

        return [
            'id' => $post->id,
            'status' => (string) $post->status,
            'editorial_status' => $post->editorial_status !== null
                ? (string) $post->editorial_status
                : null,
            'delivery_status' => $post->delivery_status !== null
                ? (string) $post->delivery_status
                : null,
            'sync_status' => $post->sync_status !== null
                ? (string) $post->sync_status
                : null,
            'text' => $text !== '' ? $text : null,
            'image_url' => $this->mediaAssetService->imageUrl((array) ($post->media_payload ?? [])),
            'link_url' => $post->link_url,
            'link_cta_label' => $this->linkCtaLabel($post->metadata),
            'source_type' => $post->source_type,
            'source_id' => $post->source_id,
            'social_automation_rule_id' => $post->social_automation_rule_id,
            'automation_rule' => $automationRule
                ? [
                    'id' => $automationRule->id,
                    'name' => $automationRule->name,
                    'is_active' => (bool) $automationRule->is_active,
                ]
                : null,
            'source_label' => data_get($post->metadata, 'source.label'),
            'scheduled_for' => optional($post->scheduled_for)->toIso8601String(),
            'scheduled_local_time' => optional($post->scheduled_local_time)->format('Y-m-d\TH:i'),
            'scheduled_timezone' => $post->scheduled_timezone,
            'published_at' => optional($post->published_at)->toIso8601String(),
            'failed_at' => optional($post->failed_at)->toIso8601String(),
            'failure_reason' => $post->failure_reason,
            'is_queued_publication' => $this->isQueuedPublication($post),
            'is_editable' => $this->isEditable($post),
            'selected_target_connection_ids' => $post->targets
                ->pluck('social_account_connection_id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all(),
            'selected_accounts_count' => $post->targets->count(),
            'targets' => $post->targets
                ->map(function (SocialPostTarget $target) use ($post): array {
                    $connection = $target->socialAccountConnection;

                    return [
                        'id' => $target->id,
                        'social_account_connection_id' => $target->social_account_connection_id,
                        'status' => (string) $target->status,
                        'editorial_status' => $post->editorial_status !== null
                            ? (string) $post->editorial_status
                            : null,
                        'delivery_status' => $target->delivery_status !== null
                            ? (string) $target->delivery_status
                            : null,
                        'sync_status' => $target->sync_status !== null
                            ? (string) $target->sync_status
                            : null,
                        'label' => $connection?->label ?? data_get($target->metadata, 'snapshot_label'),
                        'provider_label' => data_get($target->metadata, 'provider_label'),
                        'platform' => $connection?->platform ?? data_get($target->metadata, 'platform'),
                        'display_name' => $connection?->display_name ?? data_get($target->metadata, 'display_name'),
                        'account_handle' => $connection?->account_handle ?? data_get($target->metadata, 'account_handle'),
                        'published_at' => optional($target->published_at)->toIso8601String(),
                        'failed_at' => optional($target->failed_at)->toIso8601String(),
                        'failure_reason' => $target->failure_reason,
                        'submitted_at' => optional($target->submitted_at)->toIso8601String(),
                        'remote_scheduled_for' => optional($target->remote_scheduled_for)->toIso8601String(),
                        'last_synced_at' => optional($target->last_synced_at)->toIso8601String(),
                        'next_reconcile_at' => optional($target->next_reconcile_at)->toIso8601String(),
                    ];
                })
                ->values()
                ->all(),
            'approval_request' => $approvalRequest
                ? [
                    'id' => $approvalRequest->id,
                    'status' => (string) $approvalRequest->status,
                    'note' => $approvalRequest->note,
                    'requested_at' => optional($approvalRequest->requested_at)->toIso8601String(),
                    'approved_at' => optional($approvalRequest->approved_at)->toIso8601String(),
                    'rejected_at' => optional($approvalRequest->rejected_at)->toIso8601String(),
                    'requested_mode' => (string) data_get($approvalRequest->metadata, 'requested_mode', ''),
                    'requested_by' => $approvalRequest->requestedBy
                        ? [
                            'id' => $approvalRequest->requestedBy->id,
                            'name' => $approvalRequest->requestedBy->name ?: $approvalRequest->requestedBy->email,
                        ]
                        : null,
                    'resolved_by' => $approvalRequest->resolvedBy
                        ? [
                            'id' => $approvalRequest->resolvedBy->id,
                            'name' => $approvalRequest->resolvedBy->name ?: $approvalRequest->resolvedBy->email,
                        ]
                        : null,
                ]
                : null,
            'automation' => array_filter([
                'rule_id' => data_get($post->metadata, 'automation.rule_id'),
                'rule_name_snapshot' => data_get($post->metadata, 'automation.rule_name_snapshot'),
                'generated_at' => data_get($post->metadata, 'automation.generated_at'),
                'generation_mode' => data_get($post->metadata, 'automation.generation_mode'),
                'approval_mode' => data_get($post->metadata, 'automation.approval_mode'),
                'language' => data_get($post->metadata, 'automation.language'),
                'selected_source_type' => data_get($post->metadata, 'automation.selected_source_type'),
                'selected_source_id' => data_get($post->metadata, 'automation.selected_source_id'),
                'selected_source_label' => data_get($post->metadata, 'automation.selected_source_label'),
                'generation_attempt' => data_get($post->metadata, 'automation.generation_attempt'),
            ], fn ($value) => $value !== null),
            'ai_trace' => $this->aiTraceService->payload($post),
            'quality_review' => $this->qualityService->review($post->user, $post),
            'metadata' => (array) ($post->metadata ?? []),
            'updated_at' => optional($post->updated_at)->toIso8601String(),
            'created_at' => optional($post->created_at)->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function calendarPayload(SocialPost $post): array
    {
        $payload = $this->payload($post);
        $calendarAt = $this->calendarDateFor($post);

        return array_merge($payload, [
            'calendar_at' => optional($calendarAt)->toIso8601String(),
            'calendar_bucket' => $this->calendarBucketFor($post),
            'can_reschedule' => $this->isEditable($post),
        ]);
    }

    private function calendarDateFor(SocialPost $post): ?Carbon
    {
        if ((string) $post->status === SocialPost::STATUS_PUBLISHED && $post->published_at instanceof Carbon) {
            return $post->published_at;
        }

        if (in_array((string) $post->status, [
            SocialPost::STATUS_SCHEDULED,
            SocialPost::STATUS_PENDING_APPROVAL,
        ], true) && $post->scheduled_for instanceof Carbon) {
            return $post->scheduled_for;
        }

        if ($post->failed_at instanceof Carbon) {
            return $post->failed_at;
        }

        if ($post->latestApprovalRequest?->requested_at instanceof Carbon) {
            return $post->latestApprovalRequest->requested_at;
        }

        return $post->updated_at;
    }

    private function calendarBucketFor(SocialPost $post): string
    {
        return match ((string) $post->status) {
            SocialPost::STATUS_SCHEDULED => 'scheduled',
            SocialPost::STATUS_PENDING_APPROVAL => 'approval',
            SocialPost::STATUS_PUBLISHED => 'published',
            SocialPost::STATUS_PARTIAL_FAILED, SocialPost::STATUS_FAILED => 'attention',
            SocialPost::STATUS_PUBLISHING => 'publishing',
            default => 'draft',
        };
    }

    private function assertOwnership(User $owner, SocialPost $post): void
    {
        if ((int) $post->user_id !== (int) $owner->id) {
            abort(404);
        }
    }

    private function lockedPost(User $owner, SocialPost $post): SocialPost
    {
        return SocialPost::query()
            ->byUser((int) $owner->id)
            ->whereKey($post->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertEditable(SocialPost $post): void
    {
        if ((string) $post->status === SocialPost::STATUS_PENDING_APPROVAL) {
            throw ValidationException::withMessages([
                'post' => 'This Pulse post is waiting for approval. Reject it first if you need to edit the content again.',
            ]);
        }

        if ($this->isEditable($post)) {
            return;
        }

        throw ValidationException::withMessages([
            'post' => 'This Pulse post is already queued or published. Duplicate it instead of editing the live record.',
        ]);
    }

    private function isQueuedPublication(SocialPost $post): bool
    {
        return (bool) data_get($post->metadata, 'publish_requested_at');
    }

    private function isEditable(SocialPost $post): bool
    {
        return in_array((string) $post->status, [
            SocialPost::STATUS_DRAFT,
            SocialPost::STATUS_SCHEDULED,
        ], true) && ! $this->isQueuedPublication($post);
    }

    private function createEditableCopy(User $owner, User $actor, SocialPost $source, string $mode): SocialPost
    {
        $this->assertOwnership($owner, $source);
        $source->loadMissing(['targets.socialAccountConnection']);

        $originalTargetIds = $source->targets
            ->pluck('social_account_connection_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $recoveredConnections = $originalTargetIds->isNotEmpty()
            ? SocialAccountConnection::query()
                ->byUser($owner->id)
                ->connected()
                ->whereKey($originalTargetIds->all())
                ->get()
            : collect();

        $image = collect((array) ($source->media_payload ?? []))
            ->first(fn (array $item): bool => trim((string) ($item['url'] ?? '')) !== '');

        $copyId = DB::transaction(function () use (
            $actor,
            $image,
            $mode,
            $originalTargetIds,
            $owner,
            $recoveredConnections,
            $source,
        ): int {
            $copy = SocialPost::query()->create([
                'user_id' => $owner->id,
                'created_by_user_id' => $actor->id,
                'updated_by_user_id' => $actor->id,
                'source_type' => $source->source_type,
                'source_id' => $source->source_id,
                'content_payload' => (array) ($source->content_payload ?? []),
                'media_payload' => (array) ($source->media_payload ?? []),
                'link_url' => $source->link_url,
                'status' => SocialPost::STATUS_DRAFT,
                'scheduled_for' => null,
                'published_at' => null,
                'failed_at' => null,
                'failure_reason' => null,
                'metadata' => array_filter([
                    'selected_target_count' => $recoveredConnections->count(),
                    'draft_saved_from' => $mode === 'repost' ? 'social_history_repost' : 'social_history_duplicate',
                    'has_image' => $image !== null,
                    'has_link' => trim((string) ($source->link_url ?? '')) !== '',
                    'link_cta_label' => $this->linkCtaLabel($source->metadata),
                    'copied_from_post_id' => $source->id,
                    'copied_from_status' => (string) $source->status,
                    'copy_mode' => $mode,
                    'repost_of_post_id' => $mode === 'repost' ? $source->id : null,
                    'recovered_target_count' => $recoveredConnections->count(),
                    'missing_target_count' => max(0, $originalTargetIds->count() - $recoveredConnections->count()),
                ], fn ($value) => $value !== null),
            ]);

            $this->syncTargetsFromConnections($copy, $recoveredConnections, SocialPost::STATUS_DRAFT);
            $this->revisionService->capture($copy, $actor);

            return (int) $copy->id;
        });

        return SocialPost::query()
            ->with(['targets.socialAccountConnection', 'revisions'])
            ->findOrFail($copyId);
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return Collection<int, SocialAccountConnection>
     */
    private function resolveTargetConnections(User $owner, array $ids): Collection
    {
        $targetIds = collect($ids)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($targetIds->isEmpty()) {
            throw ValidationException::withMessages([
                'target_connection_ids' => 'Select at least one connected social account before saving this Pulse draft.',
            ]);
        }

        $connections = SocialAccountConnection::query()
            ->byUser($owner->id)
            ->connected()
            ->whereKey($targetIds->all())
            ->get()
            ->keyBy('id');

        if ($connections->count() !== $targetIds->count()) {
            throw ValidationException::withMessages([
                'target_connection_ids' => 'Only active connected social accounts can be selected for this Pulse draft.',
            ]);
        }

        return $targetIds
            ->map(fn (int $id) => $connections->get($id))
            ->filter()
            ->values();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  Collection<int, SocialAccountConnection>  $targetConnections
     * @return array<string, mixed>
     */
    private function postAttributes(User $owner, User $actor, array $payload, Collection $targetConnections): array
    {
        $text = $this->nullableString($payload, 'text');
        $mediaPayload = $this->mediaAssetService->imageMediaPayload($payload);
        $linkUrl = $this->nullableString($payload, 'link_url');
        $linkCtaLabel = $linkUrl !== null ? $this->nullableString($payload, 'link_cta_label') : null;
        $scheduledFor = $this->scheduledTimeResolver->resolve(
            $owner,
            $payload['scheduled_for'] ?? null,
        );
        $source = $this->prefillService->validateSourceReference($owner, $payload);
        $extraMetadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];

        if ($text === null && $mediaPayload === null && $linkUrl === null) {
            throw ValidationException::withMessages([
                'text' => 'Add some text, an image, or a destination link before saving this Pulse draft.',
            ]);
        }

        $status = $scheduledFor ? SocialPost::STATUS_SCHEDULED : SocialPost::STATUS_DRAFT;

        return [
            'user_id' => $owner->id,
            'created_by_user_id' => $actor->id,
            'updated_by_user_id' => $actor->id,
            'source_type' => $source['source_type'],
            'source_id' => $source['source_id'],
            'content_payload' => array_filter([
                'text' => $text,
            ], fn ($value) => $value !== null),
            'media_payload' => $mediaPayload,
            'link_url' => $linkUrl,
            'status' => $status,
            'scheduled_for' => $scheduledFor,
            'published_at' => null,
            'failed_at' => null,
            'failure_reason' => null,
            'metadata' => array_filter(array_merge([
                'selected_target_count' => $targetConnections->count(),
                'draft_saved_from' => $source['source_type'] !== null
                    ? 'social_prefill_'.$source['source_type']
                    : 'social_composer',
                'has_image' => $mediaPayload !== null,
                'has_link' => $linkUrl !== null,
                'link_cta_label' => $linkCtaLabel,
                'source' => $source['source_type'] !== null
                    ? [
                        'type' => $source['source_type'],
                        'id' => $source['source_id'],
                        'label' => $source['source_label'],
                    ]
                    : null,
            ], $extraMetadata), fn ($value) => $value !== null),
        ];
    }

    /**
     * @param  Collection<int, SocialAccountConnection>  $targetConnections
     */
    private function syncTargetsFromConnections(SocialPost $post, Collection $targetConnections, string $postStatus): void
    {
        $targetStatus = $postStatus === SocialPost::STATUS_SCHEDULED
            ? SocialPostTarget::STATUS_SCHEDULED
            : SocialPostTarget::STATUS_PENDING;
        $existingTargets = $post->targets()->lockForUpdate()->get()
            ->keyBy('social_account_connection_id');
        $selectedConnectionIds = $targetConnections->pluck('id')->map(fn (mixed $id): int => (int) $id);

        foreach ($existingTargets as $connectionId => $target) {
            if ($selectedConnectionIds->contains((int) $connectionId)) {
                continue;
            }

            if ($this->targetHasSubmissionHistory($post, $target)) {
                throw ValidationException::withMessages([
                    'target_connection_ids' => 'A submitted Pulse destination cannot be removed from its historical post.',
                ]);
            }

            $target->delete();
        }

        foreach ($targetConnections as $connection) {
            $this->assertConnectionTransportReady($connection);
            $attributes = [
                'delivery_provider' => $connection->delivery_provider,
                'transport_generation' => $connection->transport_generation,
                'logical_destination_key' => $connection->logical_destination_key,
                'status' => $targetStatus,
                'metadata' => [
                    'snapshot_label' => $connection->label,
                    'provider_label' => data_get($connection->metadata, 'provider_label'),
                    'platform' => $connection->platform,
                    'display_name' => $connection->display_name,
                    'account_handle' => $connection->account_handle,
                    'target_type' => data_get($connection->metadata, 'target_type'),
                ],
            ];
            $target = $existingTargets->get($connection->id);

            if ($target) {
                $target->forceFill($attributes)->save();

                continue;
            }

            $post->targets()->create([
                'social_account_connection_id' => $connection->id,
                ...$attributes,
            ]);
        }
    }

    private function targetHasSubmissionHistory(SocialPost $post, SocialPostTarget $target): bool
    {
        return $target->last_submitted_revision_id !== null
            || filled(data_get($target->metadata, 'dispatch_requested_at'))
            || filled(data_get($post->metadata, 'publish_requested_at'))
            || in_array((string) $target->delivery_status, [
                SocialPost::DELIVERY_STATUS_QUEUED,
                SocialPost::DELIVERY_STATUS_SUBMITTED,
                SocialPost::DELIVERY_STATUS_SCHEDULED,
                SocialPost::DELIVERY_STATUS_REMOTE_APPROVAL_REQUIRED,
                SocialPost::DELIVERY_STATUS_PUBLISHING,
                SocialPost::DELIVERY_STATUS_PUBLISHED,
                SocialPost::DELIVERY_STATUS_FAILED,
                SocialPost::DELIVERY_STATUS_UNKNOWN,
                SocialPost::DELIVERY_STATUS_CANCELED,
            ], true)
            || in_array((string) $target->status, [
                SocialPostTarget::STATUS_PUBLISHING,
                SocialPostTarget::STATUS_PUBLISHED,
                SocialPostTarget::STATUS_FAILED,
                SocialPostTarget::STATUS_CANCELED,
            ], true);
    }

    private function syncExistingTargetStatus(SocialPost $post, string $postStatus): void
    {
        $targetStatus = $postStatus === SocialPost::STATUS_SCHEDULED
            ? SocialPostTarget::STATUS_SCHEDULED
            : SocialPostTarget::STATUS_PENDING;

        $post->targets()->update([
            'status' => $targetStatus,
        ]);
    }

    private function assertConnectionTransportReady(SocialAccountConnection $connection): void
    {
        $usesDirectTransport = (string) $connection->delivery_provider
                === SocialAccountConnection::DELIVERY_PROVIDER_DIRECT
            && (string) $connection->transport_generation
                === SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1;
        $usesBufferTransport = (string) $connection->delivery_provider
                === SocialAccountConnection::DELIVERY_PROVIDER_BUFFER
            && (string) $connection->transport_generation
                === SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1;

        if ((! $usesDirectTransport && ! $usesBufferTransport)
            || preg_match('/\Aldk:v1:[0-9a-f]{64}\z/', (string) $connection->logical_destination_key) !== 1) {
            throw ValidationException::withMessages([
                'target_connection_ids' => 'Reconnect this social account before selecting it for a Pulse post.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function nullableString(array $payload, string $key): ?string
    {
        $value = trim((string) ($payload[$key] ?? ''));

        return $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function linkCtaLabel(?array $metadata): ?string
    {
        $value = trim((string) data_get($metadata, 'link_cta_label', ''));

        return $value !== '' ? $value : null;
    }
}
