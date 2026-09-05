<?php

namespace App\Services\Social;

use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\User;
use App\Notifications\SocialPublicationCompletedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SocialPublicationNotificationService
{
    public function __construct(private readonly SocialOperationalMessageSanitizer $sanitizer) {}

    public function notifyForTenant(int $tenantId, int $postId): void
    {
        DB::transaction(function () use ($tenantId, $postId): void {
            $post = SocialPost::query()->where('user_id', $tenantId)->whereKey($postId)->lockForUpdate()->first();
            if (! $post) {
                return;
            }

            $targets = $post->targets()->with(['socialAccountConnection', 'latestCreateOutbox', 'lastSubmittedRevision'])->orderBy('id')->lockForUpdate()->get();
            if ($targets->isEmpty()) {
                return;
            }

            $results = $targets->map(function (SocialPostTarget $target) use ($post): array {
                $connection = $target->socialAccountConnection;
                if ($connection && (int) $connection->user_id !== (int) $post->user_id) {
                    $connection = null;
                }
                $status = $post->current_editorial_revision === null ? $target->status : $target->delivery_status;
                $needsReview = $status === SocialPost::DELIVERY_STATUS_UNKNOWN
                    || $target->sync_status === SocialPost::SYNC_STATUS_RECONNECT_REQUIRED;

                return [
                    'target_id' => $target->id,
                    'platform' => (string) ($connection?->platform ?? data_get($target->metadata, 'platform', 'unknown')),
                    'account' => (string) ($connection?->label ?? data_get($target->metadata, 'snapshot_label', '')),
                    'status' => $needsReview && $status !== SocialPost::DELIVERY_STATUS_PUBLISHED ? 'unknown' : (string) $status,
                    'error' => $status === SocialPost::DELIVERY_STATUS_PUBLISHED ? null : $this->sanitizer->sanitize(
                        $target->provider_error_message,
                        $target->failure_reason,
                    ),
                    'reconnect_required' => $needsReview && $status !== SocialPost::DELIVERY_STATUS_PUBLISHED
                        && $target->sync_status === SocialPost::SYNC_STATUS_RECONNECT_REQUIRED,
                    'published_at' => $target->published_at?->toIso8601String(),
                    'revision_id' => $target->last_submitted_revision_id,
                    'attempt_id' => $target->latestCreateOutbox?->id,
                ];
            });
            $counts = $results->countBy('status');
            $total = $results->count();
            $published = (int) $counts->get('published', 0);
            $failed = (int) $counts->get('failed', 0);
            $canceled = (int) $counts->get('canceled', 0);
            $unknown = (int) $counts->get('unknown', 0);

            if ($unknown === 0 && $published + $failed + $canceled !== $total) {
                return;
            }

            $outcome = match (true) {
                $unknown > 0 => 'attention',
                $published === $total => 'success',
                $published > 0 => 'partial',
                $canceled === $total => 'canceled',
                default => 'failed',
            };
            $fingerprint = hash('sha256', json_encode([
                $post->current_editorial_revision,
                data_get($post->metadata, 'publish_requested_at'),
                data_get($post->metadata, 'retry_requested_at'),
                $results->map(fn (array $result): array => array_intersect_key($result, array_flip([
                    'target_id', 'status', 'revision_id', 'attempt_id',
                ])))->all(),
            ], JSON_THROW_ON_ERROR));

            if (data_get($post->metadata, 'publication_notification.fingerprint') === $fingerprint) {
                return;
            }

            $snapshot = [
                'tenant_id' => $tenantId,
                'social_post_id' => $postId,
                'excerpt' => Str::limit(Str::squish(strip_tags((string) data_get(
                    data_get($targets->first()?->lastSubmittedRevision?->base_content, 'content_payload', $post->content_payload), 'text', '',
                ))), 160),
                'outcome' => $outcome,
                'counts' => compact('total', 'published', 'failed', 'canceled', 'unknown'),
                'results' => $results->all(),
                'completed_at' => now()->toIso8601String(),
            ];
            $recipients = User::query()->with(['role', 'teamMembership'])->whereIn('id', array_unique([
                $tenantId,
                (int) data_get($post->metadata, 'publish_requested_by_user_id', $post->created_by_user_id),
            ]))->get();

            foreach ($recipients as $recipient) {
                $notification = new SocialPublicationCompletedNotification($snapshot);
                if ($notification->canReceive($recipient)) {
                    $recipient->notify($notification);
                }
            }

            $post->forceFill(['metadata' => array_merge((array) $post->metadata, [
                'publication_notification' => ['fingerprint' => $fingerprint, 'recorded_at' => now()->toIso8601String()],
            ])])->save();
        }, 3);
    }
}
