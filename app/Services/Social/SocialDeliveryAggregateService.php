<?php

namespace App\Services\Social;

use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class SocialDeliveryAggregateService
{
    private const TARGET_DELIVERY_SENDING = 'sending';

    public function __construct(private readonly SocialPublicationNotificationService $notifications) {}

    public function refreshForTenant(int $tenantId, int $postId): bool
    {
        if ($tenantId <= 0 || $postId <= 0) {
            return false;
        }

        return DB::transaction(function () use ($tenantId, $postId): bool {
            $post = SocialPost::query()
                ->whereKey($postId)
                ->where('user_id', $tenantId)
                ->lockForUpdate()
                ->first();

            if (! $post || $post->current_editorial_revision === null) {
                return false;
            }

            $targets = $post->targets()->lockForUpdate()->get();
            $deliveryCounts = $this->attributeCounts($targets, 'delivery_status');
            $syncCounts = $this->attributeCounts($targets, 'sync_status');
            $deliveryStatus = $this->aggregateDeliveryStatus($targets);
            $syncStatus = $this->aggregateSyncStatus($targets);
            $failedTargets = $targets->filter(
                fn (SocialPostTarget $target): bool => (string) $target->delivery_status
                    === SocialPost::DELIVERY_STATUS_FAILED,
            );
            $publishedTargets = $targets->filter(
                fn (SocialPostTarget $target): bool => (string) $target->delivery_status
                    === SocialPost::DELIVERY_STATUS_PUBLISHED,
            );

            $post->forceFill([
                'status' => $this->legacyStatus($post, $deliveryStatus),
                'delivery_status' => $deliveryStatus,
                'sync_status' => $syncStatus,
                'delivery_status_source' => SocialPost::STATUS_SOURCE_DERIVED,
                'sync_status_source' => SocialPost::STATUS_SOURCE_DERIVED,
                'delivery_aggregated_at' => now(),
                'published_at' => $this->latestTargetDate($publishedTargets, 'published_at'),
                'failed_at' => $this->latestTargetDate($failedTargets, 'failed_at'),
                'failure_reason' => $failedTargets->isNotEmpty()
                    ? $this->failureReason($failedTargets)
                    : null,
                'metadata' => array_merge((array) ($post->metadata ?? []), [
                    'status_summary' => [
                        'pending' => (int) ($deliveryCounts[SocialPost::DELIVERY_STATUS_NOT_SUBMITTED] ?? 0)
                            + (int) ($deliveryCounts[SocialPost::DELIVERY_STATUS_QUEUED] ?? 0)
                            + (int) ($deliveryCounts[SocialPost::DELIVERY_STATUS_SUBMITTED] ?? 0),
                        'scheduled' => (int) ($deliveryCounts[SocialPost::DELIVERY_STATUS_SCHEDULED] ?? 0)
                            + (int) ($deliveryCounts[SocialPost::DELIVERY_STATUS_REMOTE_APPROVAL_REQUIRED] ?? 0),
                        'publishing' => (int) ($deliveryCounts[self::TARGET_DELIVERY_SENDING] ?? 0),
                        'published' => (int) ($deliveryCounts[SocialPost::DELIVERY_STATUS_PUBLISHED] ?? 0),
                        'failed' => (int) ($deliveryCounts[SocialPost::DELIVERY_STATUS_FAILED] ?? 0),
                        'canceled' => (int) ($deliveryCounts[SocialPost::DELIVERY_STATUS_CANCELED] ?? 0),
                        'unknown' => (int) ($deliveryCounts[SocialPost::DELIVERY_STATUS_UNKNOWN] ?? 0),
                        'total' => $targets->count(),
                    ],
                    'delivery_status_summary' => $deliveryCounts,
                    'sync_status_summary' => $syncCounts,
                ]),
            ])->save();

            $this->notifications->notifyForTenant($tenantId, $postId);

            return true;
        }, 3);
    }

    /**
     * @param  Collection<int, SocialPostTarget>  $targets
     */
    private function aggregateDeliveryStatus(Collection $targets): string
    {
        if ($targets->isEmpty()) {
            return SocialPost::DELIVERY_STATUS_NOT_SUBMITTED;
        }

        $allowed = [
            SocialPost::DELIVERY_STATUS_NOT_SUBMITTED,
            SocialPost::DELIVERY_STATUS_QUEUED,
            SocialPost::DELIVERY_STATUS_SUBMITTED,
            SocialPost::DELIVERY_STATUS_SCHEDULED,
            SocialPost::DELIVERY_STATUS_REMOTE_APPROVAL_REQUIRED,
            self::TARGET_DELIVERY_SENDING,
            SocialPost::DELIVERY_STATUS_PUBLISHED,
            SocialPost::DELIVERY_STATUS_FAILED,
            SocialPost::DELIVERY_STATUS_UNKNOWN,
            SocialPost::DELIVERY_STATUS_CANCELED,
        ];
        $statuses = $targets->map(
            fn (SocialPostTarget $target): string => (string) $target->delivery_status,
        );

        if ($statuses->contains(fn (string $status): bool => ! in_array($status, $allowed, true))
            || $statuses->contains(SocialPost::DELIVERY_STATUS_UNKNOWN)) {
            return SocialPost::DELIVERY_STATUS_UNKNOWN;
        }

        $nonCanceled = $statuses->reject(
            fn (string $status): bool => $status === SocialPost::DELIVERY_STATUS_CANCELED,
        );
        $failedCount = $nonCanceled->filter(
            fn (string $status): bool => $status === SocialPost::DELIVERY_STATUS_FAILED,
        )->count();

        if ($failedCount > 0 && $failedCount < $nonCanceled->count()) {
            return SocialPost::DELIVERY_STATUS_PARTIAL_FAILED;
        }

        if ($nonCanceled->isNotEmpty() && $failedCount === $nonCanceled->count()) {
            return SocialPost::DELIVERY_STATUS_FAILED;
        }

        if ($nonCanceled->contains(self::TARGET_DELIVERY_SENDING)) {
            return SocialPost::DELIVERY_STATUS_PUBLISHING;
        }

        foreach ([
            SocialPost::DELIVERY_STATUS_REMOTE_APPROVAL_REQUIRED,
            SocialPost::DELIVERY_STATUS_SCHEDULED,
            SocialPost::DELIVERY_STATUS_SUBMITTED,
            SocialPost::DELIVERY_STATUS_QUEUED,
        ] as $status) {
            if ($nonCanceled->contains($status)) {
                return $status;
            }
        }

        if ($nonCanceled->isNotEmpty()
            && $nonCanceled->every(
                fn (string $status): bool => $status === SocialPost::DELIVERY_STATUS_PUBLISHED,
            )) {
            return SocialPost::DELIVERY_STATUS_PUBLISHED;
        }

        return $nonCanceled->isEmpty()
            ? SocialPost::DELIVERY_STATUS_CANCELED
            : SocialPost::DELIVERY_STATUS_NOT_SUBMITTED;
    }

    /**
     * @param  Collection<int, SocialPostTarget>  $targets
     */
    private function aggregateSyncStatus(Collection $targets): string
    {
        if ($targets->isEmpty()) {
            return SocialPost::SYNC_STATUS_PENDING;
        }

        $statuses = $targets->map(
            fn (SocialPostTarget $target): string => (string) $target->sync_status,
        );

        if ($statuses->contains(
            fn (string $status): bool => ! in_array($status, [
                SocialPost::SYNC_STATUS_PENDING,
                SocialPost::SYNC_STATUS_SYNCED,
                SocialPost::SYNC_STATUS_ERROR,
                SocialPost::SYNC_STATUS_RECONNECT_REQUIRED,
            ], true),
        )) {
            return SocialPost::SYNC_STATUS_ERROR;
        }

        foreach ([
            SocialPost::SYNC_STATUS_RECONNECT_REQUIRED,
            SocialPost::SYNC_STATUS_ERROR,
            SocialPost::SYNC_STATUS_PENDING,
        ] as $status) {
            if ($statuses->contains($status)) {
                return $status;
            }
        }

        return SocialPost::SYNC_STATUS_SYNCED;
    }

    private function legacyStatus(SocialPost $post, string $deliveryStatus): string
    {
        return match ($deliveryStatus) {
            SocialPost::DELIVERY_STATUS_QUEUED,
            SocialPost::DELIVERY_STATUS_SUBMITTED,
            SocialPost::DELIVERY_STATUS_REMOTE_APPROVAL_REQUIRED,
            SocialPost::DELIVERY_STATUS_PUBLISHING => SocialPost::STATUS_PUBLISHING,
            SocialPost::DELIVERY_STATUS_SCHEDULED => SocialPost::STATUS_SCHEDULED,
            SocialPost::DELIVERY_STATUS_PUBLISHED => SocialPost::STATUS_PUBLISHED,
            SocialPost::DELIVERY_STATUS_PARTIAL_FAILED => SocialPost::STATUS_PARTIAL_FAILED,
            SocialPost::DELIVERY_STATUS_FAILED,
            SocialPost::DELIVERY_STATUS_UNKNOWN,
            SocialPost::DELIVERY_STATUS_CANCELED => SocialPost::STATUS_FAILED,
            default => (string) $post->status === SocialPost::STATUS_PENDING_APPROVAL
                && ! data_get($post->metadata, 'publish_requested_at')
                    ? SocialPost::STATUS_PENDING_APPROVAL
                    : SocialPost::STATUS_DRAFT,
        };
    }

    /**
     * @param  Collection<int, SocialPostTarget>  $targets
     * @return array<string, int>
     */
    private function attributeCounts(Collection $targets, string $attribute): array
    {
        return $targets
            ->countBy(fn (SocialPostTarget $target): string => (string) $target->{$attribute})
            ->map(fn (int $count): int => $count)
            ->all();
    }

    /**
     * @param  Collection<int, SocialPostTarget>  $targets
     */
    private function latestTargetDate(Collection $targets, string $attribute): mixed
    {
        return $targets
            ->pluck($attribute)
            ->filter()
            ->sortDesc()
            ->first();
    }

    /**
     * @param  Collection<int, SocialPostTarget>  $targets
     */
    private function failureReason(Collection $targets): string
    {
        $reason = $targets
            ->pluck('provider_error_message')
            ->merge($targets->pluck('failure_reason'))
            ->filter(fn (mixed $message): bool => trim((string) $message) !== '')
            ->first();

        return trim((string) $reason) !== ''
            ? (string) $reason
            : 'One or more social deliveries failed.';
    }
}
