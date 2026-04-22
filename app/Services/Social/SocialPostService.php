<?php

namespace App\Services\Social;

use App\Models\SocialAccountConnection;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class SocialPostService
{
    public function __construct(
        private readonly SocialAccountConnectionService $connectionService,
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
            ])
            ->with(['targets.socialAccountConnection'])
            ->orderByRaw("case status when 'draft' then 0 when 'scheduled' then 1 else 2 end")
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
     * @return array<int, array<string, mixed>>
     */
    public function connectedAccountOptions(User $owner): array
    {
        return collect($this->connectionService->listPayloads($owner))
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
            'drafts' => (int) ($statusCounts[SocialPost::STATUS_DRAFT] ?? 0),
            'scheduled' => (int) ($statusCounts[SocialPost::STATUS_SCHEDULED] ?? 0),
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

        $post = SocialPost::query()->create($attributes);
        $this->syncTargets($post, $targetConnections, $attributes['status']);

        return $post->fresh(['targets.socialAccountConnection']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateDraft(User $owner, User $actor, SocialPost $post, array $payload): SocialPost
    {
        $this->assertOwnership($owner, $post);
        $this->assertEditable($post);

        $targetConnections = $this->resolveTargetConnections($owner, (array) ($payload['target_connection_ids'] ?? []));
        $attributes = $this->postAttributes($owner, $actor, $payload, $targetConnections);

        $post->forceFill([
            ...$attributes,
            'created_by_user_id' => $post->created_by_user_id ?: $actor->id,
        ])->save();

        $this->syncTargets($post, $targetConnections, $attributes['status']);

        return $post->fresh(['targets.socialAccountConnection']);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(SocialPost $post): array
    {
        $post->loadMissing(['targets.socialAccountConnection']);

        $text = trim((string) data_get($post->content_payload, 'text', ''));
        $image = collect((array) ($post->media_payload ?? []))
            ->first(fn (array $item): bool => trim((string) ($item['url'] ?? '')) !== '');

        return [
            'id' => $post->id,
            'status' => (string) $post->status,
            'text' => $text !== '' ? $text : null,
            'image_url' => trim((string) ($image['url'] ?? '')) ?: null,
            'link_url' => $post->link_url,
            'source_type' => $post->source_type,
            'source_id' => $post->source_id,
            'scheduled_for' => optional($post->scheduled_for)->toIso8601String(),
            'published_at' => optional($post->published_at)->toIso8601String(),
            'failed_at' => optional($post->failed_at)->toIso8601String(),
            'failure_reason' => $post->failure_reason,
            'selected_target_connection_ids' => $post->targets
                ->pluck('social_account_connection_id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all(),
            'selected_accounts_count' => $post->targets->count(),
            'targets' => $post->targets
                ->map(function (SocialPostTarget $target): array {
                    $connection = $target->socialAccountConnection;

                    return [
                        'id' => $target->id,
                        'social_account_connection_id' => $target->social_account_connection_id,
                        'status' => (string) $target->status,
                        'label' => $connection?->label ?? data_get($target->metadata, 'snapshot_label'),
                        'provider_label' => $connection?->label
                            ? data_get($target->metadata, 'provider_label')
                            : data_get($target->metadata, 'provider_label'),
                        'platform' => $connection?->platform ?? data_get($target->metadata, 'platform'),
                        'display_name' => $connection?->display_name ?? data_get($target->metadata, 'display_name'),
                        'account_handle' => $connection?->account_handle ?? data_get($target->metadata, 'account_handle'),
                        'published_at' => optional($target->published_at)->toIso8601String(),
                        'failed_at' => optional($target->failed_at)->toIso8601String(),
                        'failure_reason' => $target->failure_reason,
                    ];
                })
                ->values()
                ->all(),
            'metadata' => (array) ($post->metadata ?? []),
            'updated_at' => optional($post->updated_at)->toIso8601String(),
            'created_at' => optional($post->created_at)->toIso8601String(),
        ];
    }

    private function assertOwnership(User $owner, SocialPost $post): void
    {
        if ((int) $post->user_id !== (int) $owner->id) {
            abort(404);
        }
    }

    private function assertEditable(SocialPost $post): void
    {
        $publishRequestedAt = data_get($post->metadata, 'publish_requested_at');
        if (! $publishRequestedAt) {
            return;
        }

        throw ValidationException::withMessages([
            'post' => 'This Pulse post is already queued or published. Duplicate it instead of editing the live record.',
        ]);
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
        $imageUrl = $this->nullableString($payload, 'image_url');
        $linkUrl = $this->nullableString($payload, 'link_url');
        $scheduledFor = $this->nullableDateTime($payload, 'scheduled_for');

        if ($text === null && $imageUrl === null && $linkUrl === null) {
            throw ValidationException::withMessages([
                'text' => 'Add some text, an image link, or a destination link before saving this Pulse draft.',
            ]);
        }

        $status = $scheduledFor ? SocialPost::STATUS_SCHEDULED : SocialPost::STATUS_DRAFT;

        return [
            'user_id' => $owner->id,
            'created_by_user_id' => $actor->id,
            'updated_by_user_id' => $actor->id,
            'content_payload' => array_filter([
                'text' => $text,
            ], fn ($value) => $value !== null),
            'media_payload' => $imageUrl !== null
                ? [[
                    'type' => 'image',
                    'url' => $imageUrl,
                ]]
                : null,
            'link_url' => $linkUrl,
            'status' => $status,
            'scheduled_for' => $scheduledFor,
            'published_at' => null,
            'failed_at' => null,
            'failure_reason' => null,
            'metadata' => [
                'selected_target_count' => $targetConnections->count(),
                'draft_saved_from' => 'social_composer',
                'has_image' => $imageUrl !== null,
                'has_link' => $linkUrl !== null,
            ],
        ];
    }

    /**
     * @param  Collection<int, SocialAccountConnection>  $targetConnections
     */
    private function syncTargets(SocialPost $post, Collection $targetConnections, string $postStatus): void
    {
        $post->targets()->delete();

        $targetStatus = $postStatus === SocialPost::STATUS_SCHEDULED
            ? SocialPostTarget::STATUS_SCHEDULED
            : SocialPostTarget::STATUS_PENDING;

        $post->targets()->createMany(
            $targetConnections
                ->map(function (SocialAccountConnection $connection) use ($targetStatus): array {
                    return [
                        'social_account_connection_id' => $connection->id,
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
                })
                ->values()
                ->all()
        );
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
     * @param  array<string, mixed>  $payload
     */
    private function nullableDateTime(array $payload, string $key): ?Carbon
    {
        $value = $this->nullableString($payload, $key);

        return $value !== null ? Carbon::parse($value) : null;
    }
}
