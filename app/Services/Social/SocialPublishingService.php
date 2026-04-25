<?php

namespace App\Services\Social;

use App\Jobs\PublishSocialPostTargetJob;
use App\Models\SocialAccountConnection;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\User;
use App\Support\QueueWorkload;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class SocialPublishingService
{
    public function __construct(
        private readonly SocialProviderRegistry $registry,
    ) {}

    public function publishNow(User $owner, User $actor, SocialPost $post): SocialPost
    {
        return $this->queuePublication($owner, $actor, $post, 'immediate');
    }

    public function schedule(User $owner, User $actor, SocialPost $post): SocialPost
    {
        return $this->queuePublication($owner, $actor, $post, 'scheduled');
    }

    public function handleTargetPublication(int $targetId): void
    {
        $target = SocialPostTarget::query()
            ->with([
                'socialPost.targets.socialAccountConnection',
                'socialAccountConnection',
            ])
            ->find($targetId);

        if (! $target || ! $target->socialPost) {
            return;
        }

        $post = $target->socialPost;

        if (! in_array((string) $target->status, [
            SocialPostTarget::STATUS_PENDING,
            SocialPostTarget::STATUS_SCHEDULED,
            SocialPostTarget::STATUS_PUBLISHING,
        ], true)) {
            $this->refreshPostStatus($post);

            return;
        }

        if ($target->status === SocialPostTarget::STATUS_SCHEDULED
            && $post->scheduled_for instanceof Carbon
            && $post->scheduled_for->isFuture()) {
            return;
        }

        $connection = $target->socialAccountConnection;
        if (! $connection || ! $this->connectionCanPublish($connection)) {
            $this->markTargetFailed($target, 'This social account is no longer connected or active for publishing.');
            $this->refreshPostStatus($post);

            return;
        }

        $target->forceFill([
            'status' => SocialPostTarget::STATUS_PUBLISHING,
            'failed_at' => null,
            'failure_reason' => null,
            'metadata' => array_merge((array) ($target->metadata ?? []), [
                'publishing_started_at' => now()->toIso8601String(),
            ]),
        ])->save();

        $this->refreshPostStatus($post->fresh(['targets.socialAccountConnection']));

        try {
            $publisher = $this->registry->publisher((string) $connection->platform);
            $result = $publisher->publish($connection, $this->publishPayload($post, $target, $connection));

            $target->forceFill([
                'status' => SocialPostTarget::STATUS_PUBLISHED,
                'published_at' => $this->resolveDate(data_get($result, 'published_at')) ?? now(),
                'failed_at' => null,
                'failure_reason' => null,
                'metadata' => array_merge((array) ($target->metadata ?? []), [
                    'published_via' => (string) $connection->platform,
                    'provider_post_id' => data_get($result, 'provider_post_id'),
                    'provider_publish_message' => data_get($result, 'message'),
                    ...((array) data_get($result, 'metadata', [])),
                ]),
            ])->save();
        } catch (\Throwable $exception) {
            $message = trim($exception->getMessage()) !== ''
                ? trim($exception->getMessage())
                : 'This Pulse target could not be published.';

            $this->markTargetFailed($target, $message);

            $connection->forceFill([
                'last_error' => $message,
            ])->save();
        }

        $this->refreshPostStatus($post->fresh(['targets.socialAccountConnection']));
    }

    private function queuePublication(User $owner, User $actor, SocialPost $post, string $mode): SocialPost
    {
        $this->assertOwnership($owner, $post);
        $this->assertCanQueue($post);

        $post->loadMissing(['targets.socialAccountConnection']);
        if ($post->targets->isEmpty()) {
            throw ValidationException::withMessages([
                'post' => 'Add at least one connected Pulse target before publishing this post.',
            ]);
        }

        $requestedAt = now();
        $scheduledFor = null;

        if ($mode === 'scheduled') {
            $scheduledFor = $post->scheduled_for;

            if (! $scheduledFor instanceof Carbon) {
                throw ValidationException::withMessages([
                    'scheduled_for' => 'Choose a future date before scheduling this Pulse post.',
                ]);
            }

            if ($scheduledFor->lessThanOrEqualTo($requestedAt)) {
                throw ValidationException::withMessages([
                    'scheduled_for' => 'Choose a future date before scheduling this Pulse post.',
                ]);
            }
        }

        $retryFailedOnly = in_array((string) $post->status, [
            SocialPost::STATUS_FAILED,
            SocialPost::STATUS_PARTIAL_FAILED,
        ], true);

        $dispatchableTargets = collect();

        foreach ($post->targets as $target) {
            if ($retryFailedOnly && $target->status === SocialPostTarget::STATUS_PUBLISHED) {
                continue;
            }

            $connection = $target->socialAccountConnection;
            if (! $connection || ! $this->connectionCanPublish($connection)) {
                $this->markTargetFailed($target, 'This social account is no longer connected or active for publishing.');

                continue;
            }

            $target->forceFill([
                'status' => $mode === 'scheduled'
                    ? SocialPostTarget::STATUS_SCHEDULED
                    : SocialPostTarget::STATUS_PENDING,
                'published_at' => $retryFailedOnly ? $target->published_at : null,
                'failed_at' => null,
                'failure_reason' => null,
                'metadata' => array_merge((array) ($target->metadata ?? []), [
                    'dispatch_mode' => $mode,
                    'dispatch_requested_at' => $requestedAt->toIso8601String(),
                    'queued_for' => $scheduledFor?->toIso8601String(),
                ]),
            ])->save();

            $dispatchableTargets->push($target->fresh(['socialAccountConnection']));
        }

        $post->forceFill([
            'updated_by_user_id' => $actor->id,
            'status' => $mode === 'scheduled'
                ? SocialPost::STATUS_SCHEDULED
                : ($dispatchableTargets->isNotEmpty() ? SocialPost::STATUS_PUBLISHING : SocialPost::STATUS_FAILED),
            'scheduled_for' => $mode === 'scheduled' ? $scheduledFor : null,
            'published_at' => $retryFailedOnly ? $post->published_at : null,
            'failed_at' => null,
            'failure_reason' => null,
            'metadata' => array_merge((array) ($post->metadata ?? []), [
                'publish_mode' => $mode,
                'publish_requested_at' => $requestedAt->toIso8601String(),
                'publish_requested_by_user_id' => $actor->id,
                'queued_targets_count' => $dispatchableTargets->count(),
            ]),
        ])->save();

        foreach ($dispatchableTargets as $target) {
            $dispatch = PublishSocialPostTargetJob::dispatch($target->id)
                ->onQueue(QueueWorkload::queue('social_publish', 'social-publish'));

            if ($mode === 'scheduled' && $scheduledFor instanceof Carbon) {
                $dispatch->delay($scheduledFor);
            }
        }

        return $this->refreshPostStatus($post->fresh(['targets.socialAccountConnection']));
    }

    private function assertOwnership(User $owner, SocialPost $post): void
    {
        if ((int) $post->user_id !== (int) $owner->id) {
            abort(404);
        }
    }

    private function assertCanQueue(SocialPost $post): void
    {
        if ($post->status === SocialPost::STATUS_PUBLISHING) {
            throw ValidationException::withMessages([
                'post' => 'This Pulse post is already being published.',
            ]);
        }

        if ($post->status === SocialPost::STATUS_PUBLISHED) {
            throw ValidationException::withMessages([
                'post' => 'This Pulse post is already published. Duplicate it before posting it again.',
            ]);
        }
    }

    private function connectionCanPublish(SocialAccountConnection $connection): bool
    {
        return (bool) $connection->is_active
            && (string) $connection->status === SocialAccountConnection::STATUS_CONNECTED;
    }

    /**
     * @return array<string, mixed>
     */
    private function publishPayload(
        SocialPost $post,
        SocialPostTarget $target,
        SocialAccountConnection $connection
    ): array {
        $image = collect((array) ($post->media_payload ?? []))
            ->first(fn (array $item): bool => trim((string) ($item['url'] ?? '')) !== '');

        return [
            'post_id' => $post->id,
            'target_id' => $target->id,
            'platform' => $connection->platform,
            'text' => trim((string) data_get($post->content_payload, 'text')),
            'image_url' => trim((string) ($image['url'] ?? '')) ?: null,
            'link_url' => $post->link_url,
            'scheduled_for' => optional($post->scheduled_for)->toIso8601String(),
            'source_type' => $post->source_type,
            'source_id' => $post->source_id,
            'metadata' => [
                'connection_label' => $connection->label,
                'provider_label' => data_get($target->metadata, 'provider_label'),
                'target_type' => data_get($target->metadata, 'target_type'),
                'link_cta_label' => data_get($post->metadata, 'link_cta_label'),
            ],
        ];
    }

    private function markTargetFailed(SocialPostTarget $target, string $message): void
    {
        $target->forceFill([
            'status' => SocialPostTarget::STATUS_FAILED,
            'failed_at' => now(),
            'failure_reason' => $message,
            'metadata' => array_merge((array) ($target->metadata ?? []), [
                'last_publish_error' => $message,
            ]),
        ])->save();
    }

    private function resolveDate(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        $raw = trim((string) $value);

        return $raw !== '' ? Carbon::parse($raw) : null;
    }

    private function refreshPostStatus(SocialPost $post): SocialPost
    {
        $post->loadMissing(['targets.socialAccountConnection']);

        $targets = $post->targets;
        $counts = $this->targetStatusCounts($targets);
        $totalTargets = max(0, $targets->count());
        $failedCount = (int) ($counts[SocialPostTarget::STATUS_FAILED] ?? 0)
            + (int) ($counts[SocialPostTarget::STATUS_CANCELED] ?? 0);
        $publishedCount = (int) ($counts[SocialPostTarget::STATUS_PUBLISHED] ?? 0);
        $publishingCount = (int) ($counts[SocialPostTarget::STATUS_PUBLISHING] ?? 0);
        $scheduledCount = (int) ($counts[SocialPostTarget::STATUS_SCHEDULED] ?? 0);
        $pendingCount = (int) ($counts[SocialPostTarget::STATUS_PENDING] ?? 0);
        $isQueuedPublication = (bool) data_get($post->metadata, 'publish_requested_at');
        $publishMode = (string) data_get($post->metadata, 'publish_mode');

        $status = SocialPost::STATUS_DRAFT;

        if ((string) $post->status === SocialPost::STATUS_PENDING_APPROVAL && ! $isQueuedPublication) {
            $status = SocialPost::STATUS_PENDING_APPROVAL;
        } elseif ($publishingCount > 0) {
            $status = SocialPost::STATUS_PUBLISHING;
        } elseif ($totalTargets > 0 && $publishedCount === $totalTargets) {
            $status = SocialPost::STATUS_PUBLISHED;
        } elseif ($totalTargets > 0 && $failedCount === $totalTargets) {
            $status = SocialPost::STATUS_FAILED;
        } elseif ($failedCount > 0 && ($publishedCount > 0 || $scheduledCount > 0 || $pendingCount > 0)) {
            $status = SocialPost::STATUS_PARTIAL_FAILED;
        } elseif ($scheduledCount > 0) {
            $status = SocialPost::STATUS_SCHEDULED;
        } elseif ($pendingCount > 0 && $isQueuedPublication && $publishMode === 'immediate') {
            $status = SocialPost::STATUS_PUBLISHING;
        }

        $publishedAt = $publishedCount > 0
            ? $targets
                ->pluck('published_at')
                ->filter()
                ->sortByDesc(fn ($date) => $date?->timestamp ?? 0)
                ->first()
            : null;

        $failedAt = $failedCount > 0
            ? $targets
                ->pluck('failed_at')
                ->filter()
                ->sortByDesc(fn ($date) => $date?->timestamp ?? 0)
                ->first()
            : null;

        $failureReason = $failedCount > 0
            ? $this->buildFailureReason($targets, $failedCount)
            : null;

        $post->forceFill([
            'status' => $status,
            'published_at' => $publishedAt,
            'failed_at' => $failedAt,
            'failure_reason' => $failureReason,
            'metadata' => array_merge((array) ($post->metadata ?? []), [
                'status_summary' => [
                    'pending' => $pendingCount,
                    'scheduled' => $scheduledCount,
                    'publishing' => $publishingCount,
                    'published' => $publishedCount,
                    'failed' => $failedCount,
                    'total' => $totalTargets,
                ],
            ]),
        ])->save();

        return $post->fresh(['targets.socialAccountConnection']);
    }

    /**
     * @param  Collection<int, SocialPostTarget>  $targets
     * @return array<string, int>
     */
    private function targetStatusCounts(Collection $targets): array
    {
        return $targets
            ->groupBy(fn (SocialPostTarget $target) => (string) $target->status)
            ->map(fn (Collection $group) => $group->count())
            ->all();
    }

    /**
     * @param  Collection<int, SocialPostTarget>  $targets
     */
    private function buildFailureReason(Collection $targets, int $failedCount): string
    {
        $reasons = $targets
            ->filter(fn (SocialPostTarget $target) => in_array((string) $target->status, [
                SocialPostTarget::STATUS_FAILED,
                SocialPostTarget::STATUS_CANCELED,
            ], true))
            ->pluck('failure_reason')
            ->filter(fn ($reason) => trim((string) $reason) !== '')
            ->values();

        if ($reasons->isEmpty()) {
            return $failedCount === 1
                ? '1 Pulse target failed.'
                : sprintf('%d Pulse targets failed.', $failedCount);
        }

        if ($failedCount === 1) {
            return (string) $reasons->first();
        }

        return sprintf('%d Pulse targets failed. %s', $failedCount, (string) $reasons->first());
    }
}
