<?php

namespace App\Services\Social;

use App\Models\SocialApprovalRequest;
use App\Models\SocialPost;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class SocialApprovalService
{
    public function __construct(
        private readonly SocialPublishingService $publishingService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function submit(User $owner, User $actor, SocialPost $post, array $payload = []): SocialPost
    {
        $this->assertOwnership($owner, $post);
        $this->assertSubmittable($post);

        $post->loadMissing(['targets.socialAccountConnection', 'latestApprovalRequest']);

        if ($post->targets->isEmpty()) {
            throw ValidationException::withMessages([
                'post' => 'Add at least one connected Pulse target before submitting this post for approval.',
            ]);
        }

        if ((string) $post->latestApprovalRequest?->status === SocialApprovalRequest::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'post' => 'This Pulse post is already waiting for approval.',
            ]);
        }

        $requestedAt = now();
        $requestedMode = $post->scheduled_for ? 'scheduled' : 'immediate';
        $note = $this->nullableString($payload, 'note');

        $approvalRequest = $post->approvalRequests()->create([
            'requested_by_user_id' => $actor->id,
            'status' => SocialApprovalRequest::STATUS_PENDING,
            'note' => $note,
            'requested_at' => $requestedAt,
            'metadata' => array_filter([
                'requested_mode' => $requestedMode,
                'scheduled_for' => optional($post->scheduled_for)->toIso8601String(),
            ], fn ($value) => $value !== null),
        ]);

        $post->forceFill([
            'updated_by_user_id' => $actor->id,
            'status' => SocialPost::STATUS_PENDING_APPROVAL,
            'failed_at' => null,
            'failure_reason' => null,
            'metadata' => $this->mergeApprovalMetadata($post, [
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

        return $post->fresh($this->postRelations());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function approve(User $owner, User $actor, SocialPost $post, array $payload = []): SocialPost
    {
        $this->assertOwnership($owner, $post);

        $post->loadMissing($this->postRelations());
        $approvalRequest = $this->pendingApprovalRequest($post);
        $approvedAt = now();
        $note = $this->nullableString($payload, 'note') ?? $approvalRequest->note;
        $requestedMode = (string) data_get(
            $approvalRequest->metadata,
            'requested_mode',
            $post->scheduled_for ? 'scheduled' : 'immediate'
        );

        $queuedPost = $requestedMode === 'scheduled'
            ? $this->publishingService->schedule($owner, $actor, $post)
            : $this->publishingService->publishNow($owner, $actor, $post);

        $approvalRequest->forceFill([
            'status' => SocialApprovalRequest::STATUS_APPROVED,
            'resolved_by_user_id' => $actor->id,
            'approved_at' => $approvedAt,
            'rejected_at' => null,
            'note' => $note,
            'metadata' => array_merge((array) ($approvalRequest->metadata ?? []), [
                'resolved_mode' => $requestedMode,
            ]),
        ])->save();

        $queuedPost->forceFill([
            'metadata' => $this->mergeApprovalMetadata($queuedPost, [
                'status' => SocialApprovalRequest::STATUS_APPROVED,
                'request_id' => $approvalRequest->id,
                'requested_mode' => $requestedMode,
                'approved_at' => $approvedAt->toIso8601String(),
                'approved_by_user_id' => $actor->id,
                'rejected_at' => null,
                'rejected_by_user_id' => null,
                'note' => $note,
            ]),
        ])->save();

        return $queuedPost->fresh($this->postRelations());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function reject(User $owner, User $actor, SocialPost $post, array $payload = []): SocialPost
    {
        $this->assertOwnership($owner, $post);

        $post->loadMissing($this->postRelations());
        $approvalRequest = $this->pendingApprovalRequest($post);
        $rejectedAt = now();
        $note = $this->nullableString($payload, 'note');
        $requestedMode = (string) data_get(
            $approvalRequest->metadata,
            'requested_mode',
            $post->scheduled_for ? 'scheduled' : 'immediate'
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

        $post->forceFill([
            'updated_by_user_id' => $actor->id,
            'status' => $restoredStatus,
            'failed_at' => null,
            'failure_reason' => null,
            'metadata' => $this->mergeApprovalMetadata($post, [
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

        return $post->fresh($this->postRelations());
    }

    private function assertOwnership(User $owner, SocialPost $post): void
    {
        if ((int) $post->user_id !== (int) $owner->id) {
            abort(404);
        }
    }

    private function assertSubmittable(SocialPost $post): void
    {
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

    private function pendingApprovalRequest(SocialPost $post): SocialApprovalRequest
    {
        $approvalRequest = $post->latestApprovalRequest;

        if (! $approvalRequest || (string) $approvalRequest->status !== SocialApprovalRequest::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'post' => 'This Pulse post has no pending approval request.',
            ]);
        }

        return $approvalRequest;
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
     * @param  array<string, mixed>  $payload
     */
    private function nullableString(array $payload, string $key): ?string
    {
        $value = trim((string) ($payload[$key] ?? ''));

        return $value !== '' ? $value : null;
    }
}
