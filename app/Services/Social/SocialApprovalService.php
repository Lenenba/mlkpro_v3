<?php

namespace App\Services\Social;

use App\Models\SocialApprovalRequest;
use App\Models\SocialPost;
use App\Models\SocialPostRevision;
use App\Models\TeamMember;
use App\Models\User;
use App\Notifications\SocialApprovalRequestedNotification;
use App\Support\NotificationDispatcher;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SocialApprovalService
{
    public function __construct(
        private readonly SocialPublishingService $publishingService,
        private readonly SocialPostRevisionService $revisionService,
        private readonly SocialScheduledTimeResolver $scheduledTimeResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function submit(User $owner, User $actor, SocialPost $post, array $payload = []): SocialPost
    {
        $this->assertOwnership($owner, $post);
        $this->assertActorBelongsToWorkspace($owner, $actor);

        $postId = DB::transaction(function () use ($owner, $actor, $post, $payload): int {
            $lockedPost = $this->lockedPost($owner, $post);
            $this->assertSubmittable($lockedPost);
            $lockedPost->load(['targets.socialAccountConnection']);

            if ($lockedPost->targets->isEmpty()) {
                throw ValidationException::withMessages([
                    'post' => 'Add at least one connected Pulse target before submitting this post for approval.',
                ]);
            }

            if ((string) $this->latestApprovalRequestForUpdate($lockedPost)?->status === SocialApprovalRequest::STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'post' => 'This Pulse post is already waiting for approval.',
                ]);
            }

            $requestedAt = now();
            $requestedMode = $lockedPost->scheduled_for ? 'scheduled' : 'immediate';
            $note = $this->nullableString($payload, 'note');
            $revision = $this->revisionService->ensureCurrent($lockedPost, $actor);

            $approvalRequest = $lockedPost->approvalRequests()->create([
                'social_post_revision_id' => $revision->id,
                'requested_by_user_id' => $actor->id,
                'status' => SocialApprovalRequest::STATUS_PENDING,
                'note' => $note,
                'requested_at' => $requestedAt,
                'metadata' => array_filter([
                    'requested_mode' => $requestedMode,
                    'scheduled_for' => optional($lockedPost->scheduled_for)->toIso8601String(),
                ], fn ($value) => $value !== null),
            ]);

            $lockedPost->forceFill([
                'updated_by_user_id' => $actor->id,
                'status' => SocialPost::STATUS_PENDING_APPROVAL,
                'editorial_status' => SocialPost::EDITORIAL_STATUS_PENDING_APPROVAL,
                'editorial_status_source' => SocialPost::STATUS_SOURCE_EXPLICIT,
                'failed_at' => null,
                'failure_reason' => null,
                'metadata' => $this->mergeApprovalMetadata($lockedPost, [
                    'status' => SocialApprovalRequest::STATUS_PENDING,
                    'request_id' => $approvalRequest->id,
                    'requested_at' => $requestedAt->toIso8601String(),
                    'requested_by_user_id' => $actor->id,
                    'requested_mode' => $requestedMode,
                    'note' => $note,
                    'approved_at' => null,
                    'approved_by_user_id' => null,
                    'rejected_at' => null,
                    'rejected_by_user_id' => null,
                ], [
                    'publish_mode',
                    'publish_requested_at',
                    'publish_requested_by_user_id',
                    'queued_targets_count',
                ]),
            ])->save();

            $this->notifyApproversAfterCommit(
                (int) $owner->id,
                (int) $lockedPost->id,
                (int) $approvalRequest->id
            );

            return (int) $lockedPost->id;
        });

        return SocialPost::query()
            ->with($this->postRelations())
            ->findOrFail($postId);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function approve(User $owner, User $actor, SocialPost $post, array $payload = []): SocialPost
    {
        $this->assertOwnership($owner, $post);
        $this->assertActorBelongsToWorkspace($owner, $actor);

        $postId = DB::transaction(function () use ($owner, $actor, $post, $payload): int {
            $lockedPost = $this->lockedPost($owner, $post);
            $lockedPost->load(['targets.socialAccountConnection']);
            $approvalRequest = $this->pendingApprovalRequestForUpdate($lockedPost);
            $approvalRevision = $this->currentApprovalRevisionForUpdate(
                $lockedPost,
                $approvalRequest,
                $actor,
            );
            $approvedAt = now();
            $note = $this->nullableString($payload, 'note') ?? $approvalRequest->note;
            $requestedMode = (string) data_get(
                $approvalRequest->metadata,
                'requested_mode',
                $lockedPost->scheduled_for ? 'scheduled' : 'immediate'
            );
            $resolvedMode = $this->resolveApprovalMode($payload['mode'] ?? null, $requestedMode);
            $resolvedScheduledFor = $resolvedMode === 'scheduled'
                ? $this->resolveScheduledFor($owner, $lockedPost, $payload, $approvedAt)
                : null;

            if (! $this->scheduledInstantsMatch($lockedPost->scheduled_for, $resolvedScheduledFor)) {
                $lockedPost->forceFill([
                    'scheduled_for' => $resolvedScheduledFor,
                ])->save();
                $approvalRevision = $this->revisionService->capture(
                    $lockedPost,
                    $actor,
                    $approvalRevision->origin,
                );
                $approvalRequest->forceFill([
                    'social_post_revision_id' => $approvalRevision->id,
                ])->save();
            }

            $this->revisionService->approve($lockedPost, $approvalRequest, $actor, $approvedAt);

            $approvalRequest->forceFill([
                'status' => SocialApprovalRequest::STATUS_APPROVED,
                'resolved_by_user_id' => $actor->id,
                'approved_at' => $approvedAt,
                'rejected_at' => null,
                'note' => $note,
                'metadata' => array_merge((array) ($approvalRequest->metadata ?? []), [
                    'resolved_mode' => $resolvedMode,
                ]),
            ])->save();

            $lockedPost->forceFill([
                'status' => $resolvedMode === 'scheduled'
                    ? SocialPost::STATUS_SCHEDULED
                    : SocialPost::STATUS_DRAFT,
                'metadata' => $this->mergeApprovalMetadata($lockedPost, [
                    'status' => SocialApprovalRequest::STATUS_APPROVED,
                    'request_id' => $approvalRequest->id,
                    'requested_mode' => $requestedMode,
                    'resolved_mode' => $resolvedMode,
                    'approved_at' => $approvedAt->toIso8601String(),
                    'approved_by_user_id' => $actor->id,
                    'rejected_at' => null,
                    'rejected_by_user_id' => null,
                    'note' => $note,
                ]),
            ])->save();

            $queuedPost = $resolvedMode === 'scheduled'
                ? $this->publishingService->schedule($owner, $actor, $lockedPost)
                : $this->publishingService->publishNow($owner, $actor, $lockedPost);

            return (int) $queuedPost->id;
        });

        return SocialPost::query()
            ->with($this->postRelations())
            ->findOrFail($postId);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function reject(User $owner, User $actor, SocialPost $post, array $payload = []): SocialPost
    {
        $this->assertOwnership($owner, $post);
        $this->assertActorBelongsToWorkspace($owner, $actor);

        $postId = DB::transaction(function () use ($owner, $actor, $post, $payload): int {
            $lockedPost = $this->lockedPost($owner, $post);
            $approvalRequest = $this->pendingApprovalRequestForUpdate($lockedPost);
            $this->currentApprovalRevisionForUpdate($lockedPost, $approvalRequest, $actor);
            $rejectedAt = now();
            $note = $this->nullableString($payload, 'note');
            $requestedMode = (string) data_get(
                $approvalRequest->metadata,
                'requested_mode',
                $lockedPost->scheduled_for ? 'scheduled' : 'immediate'
            );
            $restoredStatus = $requestedMode === 'scheduled'
                ? SocialPost::STATUS_SCHEDULED
                : SocialPost::STATUS_DRAFT;

            $approvalRequest->forceFill([
                'status' => SocialApprovalRequest::STATUS_REJECTED,
                'resolved_by_user_id' => $actor->id,
                'approved_at' => null,
                'rejected_at' => $rejectedAt,
                'note' => $note,
            ])->save();

            $this->revisionService->reject($lockedPost);

            $lockedPost->forceFill([
                'updated_by_user_id' => $actor->id,
                'status' => $restoredStatus,
                'failed_at' => null,
                'failure_reason' => null,
                'metadata' => $this->mergeApprovalMetadata($lockedPost, [
                    'status' => SocialApprovalRequest::STATUS_REJECTED,
                    'request_id' => $approvalRequest->id,
                    'requested_mode' => $requestedMode,
                    'approved_at' => null,
                    'approved_by_user_id' => null,
                    'rejected_at' => $rejectedAt->toIso8601String(),
                    'rejected_by_user_id' => $actor->id,
                    'note' => $note,
                ]),
            ])->save();

            return (int) $lockedPost->id;
        });

        return SocialPost::query()
            ->with($this->postRelations())
            ->findOrFail($postId);
    }

    private function assertOwnership(User $owner, SocialPost $post): void
    {
        if ((int) $post->user_id !== (int) $owner->id) {
            abort(404);
        }
    }

    private function assertActorBelongsToWorkspace(User $owner, User $actor): void
    {
        if ($actor->accountOwnerId() !== (int) $owner->id) {
            abort(404);
        }
    }

    private function assertSubmittable(SocialPost $post): void
    {
        if (filled(data_get($post->metadata, 'publish_requested_at'))) {
            throw ValidationException::withMessages([
                'post' => 'This Pulse post has already been queued for publication.',
            ]);
        }

        if ((string) $post->status === SocialPost::STATUS_PENDING_APPROVAL) {
            throw ValidationException::withMessages([
                'post' => 'This Pulse post is already waiting for approval.',
            ]);
        }

        if ((string) $post->status === SocialPost::STATUS_PUBLISHING) {
            throw ValidationException::withMessages([
                'post' => 'This Pulse post is already being published.',
            ]);
        }

        if ((string) $post->status === SocialPost::STATUS_PUBLISHED) {
            throw ValidationException::withMessages([
                'post' => 'This Pulse post is already published. Duplicate it before creating a new approval request.',
            ]);
        }

        if (! in_array((string) $post->status, [
            SocialPost::STATUS_DRAFT,
            SocialPost::STATUS_SCHEDULED,
        ], true)) {
            throw ValidationException::withMessages([
                'post' => 'Only a draft or scheduled Pulse post can be submitted for approval.',
            ]);
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

    private function latestApprovalRequestForUpdate(SocialPost $post): ?SocialApprovalRequest
    {
        return SocialApprovalRequest::query()
            ->where('social_post_id', $post->id)
            ->latest('id')
            ->lockForUpdate()
            ->first();
    }

    private function pendingApprovalRequestForUpdate(SocialPost $post): SocialApprovalRequest
    {
        $approvalRequest = $this->latestApprovalRequestForUpdate($post);

        if (! $approvalRequest || (string) $approvalRequest->status !== SocialApprovalRequest::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'post' => 'This Pulse post has no pending approval request.',
            ]);
        }

        return $approvalRequest;
    }

    private function currentApprovalRevisionForUpdate(
        SocialPost $post,
        SocialApprovalRequest $approvalRequest,
        User $actor,
    ): SocialPostRevision {
        $requestedRevision = SocialPostRevision::query()
            ->whereKey($approvalRequest->social_post_revision_id)
            ->where('social_post_id', $post->id)
            ->where('user_id', $post->user_id)
            ->lockForUpdate()
            ->first();

        if (! $requestedRevision) {
            throw $this->staleApprovalException();
        }

        $currentRevision = $this->revisionService->ensureCurrent(
            $post,
            $actor,
            $requestedRevision->origin,
        );

        if ((int) $currentRevision->id !== (int) $requestedRevision->id
            || (int) $requestedRevision->revision_number !== (int) $post->current_editorial_revision
            || ! hash_equals((string) $requestedRevision->payload_hash, (string) $post->payload_hash)) {
            throw $this->staleApprovalException();
        }

        return $requestedRevision;
    }

    private function staleApprovalException(): ValidationException
    {
        return ValidationException::withMessages([
            'post' => 'This approval request no longer matches the current Pulse revision. Submit the current revision for approval again.',
        ]);
    }

    private function notifyApproversAfterCommit(int $ownerId, int $postId, int $approvalRequestId): void
    {
        DB::afterCommit(function () use ($ownerId, $postId, $approvalRequestId): void {
            $owner = User::query()->find($ownerId);
            $post = SocialPost::query()
                ->with($this->postRelationsWithRuleAndOwner())
                ->find($postId);
            $approvalRequest = SocialApprovalRequest::query()->find($approvalRequestId);

            if (! $owner || ! $post || ! $approvalRequest) {
                return;
            }

            $this->notifyApprovers($owner, $post, $approvalRequest);
        });
    }

    private function resolveApprovalMode(mixed $candidate, string $fallback): string
    {
        $value = strtolower(trim((string) $candidate));

        return in_array($value, ['immediate', 'scheduled'], true)
            ? $value
            : $fallback;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveScheduledFor(
        User $owner,
        SocialPost $post,
        array $payload,
        Carbon $reference,
    ): Carbon {
        $candidate = $payload['scheduled_for'] ?? $post->scheduled_for;
        $scheduledFor = $this->scheduledTimeResolver->resolve($owner, $candidate);

        if (! $scheduledFor || $scheduledFor->lessThanOrEqualTo($reference)) {
            throw ValidationException::withMessages([
                'scheduled_for' => 'Choose a future date before scheduling this Pulse post.',
            ]);
        }

        return $scheduledFor;
    }

    private function scheduledInstantsMatch(?Carbon $first, ?Carbon $second): bool
    {
        if ($first === null || $second === null) {
            return $first === $second;
        }

        return $first->equalTo($second);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<int, string>  $except
     * @return array<string, mixed>
     */
    private function mergeApprovalMetadata(SocialPost $post, array $overrides, array $except = []): array
    {
        $metadata = Arr::except((array) ($post->metadata ?? []), $except);
        $metadata['approval'] = array_filter(array_merge(
            (array) ($metadata['approval'] ?? []),
            $overrides
        ), fn ($value) => $value !== null);

        return $metadata;
    }

    /**
     * @return array<int, string>
     */
    private function postRelations(): array
    {
        return [
            'targets.socialAccountConnection',
            'latestApprovalRequest.requestedBy',
            'latestApprovalRequest.resolvedBy',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function postRelationsWithRuleAndOwner(): array
    {
        return [
            'user',
            'automationRule',
            'targets.socialAccountConnection',
            'latestApprovalRequest.requestedBy',
            'latestApprovalRequest.resolvedBy',
        ];
    }

    private function notifyApprovers(User $owner, SocialPost $post, SocialApprovalRequest $approvalRequest): void
    {
        foreach ($this->approvalRecipients($owner) as $recipient) {
            NotificationDispatcher::send(
                $recipient,
                new SocialApprovalRequestedNotification($post, $approvalRequest),
                [
                    'user_id' => $owner->id,
                    'social_post_id' => $post->id,
                    'social_approval_request_id' => $approvalRequest->id,
                ]
            );
        }
    }

    /**
     * @return Collection<int, User>
     */
    private function approvalRecipients(User $owner): Collection
    {
        $recipients = collect([$owner]);

        TeamMember::query()
            ->forAccount($owner->id)
            ->active()
            ->with('user')
            ->get()
            ->each(function (TeamMember $member) use ($recipients): void {
                if (! $member->hasPermission('social.approve')) {
                    return;
                }

                if ($member->user instanceof User) {
                    $recipients->push($member->user);
                }
            });

        return $recipients
            ->filter(fn (User $user): bool => trim((string) $user->email) !== '')
            ->unique(fn (User $user): int => (int) $user->id)
            ->values();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function nullableString(array $payload, string $key): ?string
    {
        $value = trim((string) ($payload[$key] ?? ''));

        return $value !== '' ? $value : null;
    }
}
