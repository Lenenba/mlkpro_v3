<?php

namespace App\Services\Social;

use App\Models\SocialDeliveryOutbox;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use Illuminate\Support\Collection;

final class SocialPostRetryPolicy
{
    /**
     * @param  Collection<int, SocialPostTarget>  $targets
     * @return array<int, int>
     */
    public function retryableTargetIds(
        SocialPost $post,
        Collection $targets,
        bool $lockOutboxes = false,
    ): array {
        $failedTargets = $targets->filter(
            fn (SocialPostTarget $target): bool => $this->targetHasFailed($target),
        );

        if ($failedTargets->isEmpty()) {
            return [];
        }

        $revisionIds = $failedTargets
            ->pluck('current_revision_id')
            ->filter(fn (mixed $id): bool => (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        $outboxesByTarget = $this->latestOutboxesByTarget(
            $failedTargets,
            $revisionIds,
            $lockOutboxes,
        );

        return $failedTargets
            ->filter(function (SocialPostTarget $target) use ($post, $outboxesByTarget): bool {
                $currentRevisionId = (int) $target->current_revision_id;
                $latestOutbox = $outboxesByTarget->get($target->getKey());

                if ($latestOutbox instanceof SocialDeliveryOutbox
                    && (int) $latestOutbox->social_post_revision_id !== $currentRevisionId) {
                    $latestOutbox = null;
                }

                return ! $latestOutbox instanceof SocialDeliveryOutbox
                    || $this->outboxCanBeRecovered($post, $target, $latestOutbox);
            })
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, SocialPostTarget>  $targets
     * @param  Collection<int, int>  $revisionIds
     * @return Collection<int|string, SocialDeliveryOutbox>
     */
    private function latestOutboxesByTarget(
        Collection $targets,
        Collection $revisionIds,
        bool $lockOutboxes,
    ): Collection {
        if (! $lockOutboxes && $targets->every(
            fn (SocialPostTarget $target): bool => $target->relationLoaded('latestCreateOutbox'),
        )) {
            return $targets
                ->filter(
                    fn (SocialPostTarget $target): bool => $target->latestCreateOutbox
                        instanceof SocialDeliveryOutbox,
                )
                ->mapWithKeys(
                    fn (SocialPostTarget $target): array => [
                        $target->getKey() => $target->latestCreateOutbox,
                    ],
                );
        }

        if ($revisionIds->isEmpty()) {
            return collect();
        }

        $query = SocialDeliveryOutbox::query()
            ->whereIn('social_post_target_id', $targets->pluck('id')->all())
            ->whereIn('social_post_revision_id', $revisionIds->all())
            ->where('operation', SocialDeliveryOutbox::OPERATION_CREATE)
            ->orderByDesc('recovery_generation')
            ->orderByDesc('id');

        if ($lockOutboxes) {
            $query->lockForUpdate();
        }

        return $query
            ->get()
            ->groupBy('social_post_target_id')
            ->map(
                fn (Collection $outboxes): ?SocialDeliveryOutbox => $outboxes->first(),
            )
            ->filter();
    }

    /**
     * @param  Collection<int, SocialPostTarget>  $targets
     */
    public function hasAmbiguousOutcome(SocialPost $post, Collection $targets): bool
    {
        return (string) $post->delivery_status === SocialPost::DELIVERY_STATUS_UNKNOWN
            || $targets->contains(
                fn (SocialPostTarget $target): bool => (string) $target->delivery_status
                    === SocialPost::DELIVERY_STATUS_UNKNOWN,
            );
    }

    private function targetHasFailed(SocialPostTarget $target): bool
    {
        return (string) $target->delivery_status === SocialPost::DELIVERY_STATUS_FAILED
            || ($target->delivery_status === null
                && (string) $target->status === SocialPostTarget::STATUS_FAILED);
    }

    private function outboxCanBeRecovered(
        SocialPost $post,
        SocialPostTarget $target,
        SocialDeliveryOutbox $outbox,
    ): bool {
        $identityMatches = (int) $outbox->user_id === (int) $post->user_id
            && (int) $outbox->social_post_target_id === (int) $target->getKey()
            && (int) $outbox->social_post_revision_id === (int) $target->current_revision_id
            && (int) $outbox->social_provider_connection_id
                === (int) $target->social_account_connection_id
            && (string) $outbox->delivery_provider === (string) $target->delivery_provider
            && (string) $outbox->transport_generation === (string) $target->transport_generation
            && hash_equals(
                (string) $outbox->logical_destination_key,
                (string) $target->logical_destination_key,
            );
        $isUnresolvedDead = (string) $outbox->status === SocialDeliveryOutbox::STATUS_DEAD
            && $outbox->reconciliation_resolution === null
            && $outbox->reconciliation_resolved_at === null
            && $outbox->reconciliation_observed_at === null
            && $outbox->reconciliation_resolution_source === null;

        return $identityMatches && $isUnresolvedDead;
    }
}
