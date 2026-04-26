<?php

namespace App\Services\Social;

use App\Models\SocialApprovalRequest;
use App\Models\SocialPost;
use App\Models\TeamMember;
use App\Models\User;
use App\Notifications\SocialApprovalRequestedNotification;
use App\Support\NotificationDispatcher;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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

        $submittedPost = $post->fresh($this->postRelationsWithRuleAndOwner());
        $this->notifyApprovers($owner, $submittedPost, $approvalRequest);

        return $submittedPost->fresh($this->postRelations());
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
        $resolvedMode = $this->resolveApprovalMode($payload['mode'] ?? null, $requestedMode);

        if ($resolvedMode === 'scheduled') {
            $post->forceFill([
                'scheduled_for' => $this->resolveScheduledFor($post, $payload, $approvedAt),
            ])->save();
        } else {
            $post->forceFill([
                'scheduled_for' => null,
            ])->save();
        }

        $queuedPost = $resolvedMode === 'scheduled'
            ? $this->publishingService->schedule($owner, $actor, $post)
            : $this->publishingService->publishNow($owner, $actor, $post);

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

        $queuedPost->forceFill([
            'metadata' => $this->mergeApprovalMetadata($queuedPost, [
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
    private function resolveScheduledFor(SocialPost $post, array $payload, Carbon $reference): Carbon
    {
        $candidate = $payload['scheduled_for'] ?? $post->scheduled_for;

        if ($candidate instanceof Carbon) {
            $scheduledFor = $candidate->copy();
        } elseif ($post->scheduled_for instanceof Carbon && $candidate === $post->scheduled_for) {
            $scheduledFor = $post->scheduled_for->copy();
        } else {
            $raw = trim((string) $candidate);
            if ($raw === '') {
                throw ValidationException::withMessages([
                    'scheduled_for' => 'Choose a future date before scheduling this Pulse post.',
                ]);
            }

            $scheduledFor = Carbon::parse($raw);
        }

        if ($scheduledFor->lessThanOrEqualTo($reference)) {
            throw ValidationException::withMessages([
                'scheduled_for' => 'Choose a future date before scheduling this Pulse post.',
            ]);
        }

        return $scheduledFor;
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
