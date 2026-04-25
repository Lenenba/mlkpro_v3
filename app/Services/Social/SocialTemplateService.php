<?php

namespace App\Services\Social;

use App\Models\SocialAccountConnection;
use App\Models\SocialPostTemplate;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class SocialTemplateService
{
    public function __construct(
        private readonly SocialMediaAssetService $mediaAssetService,
    ) {}

    /**
     * @return Collection<int, SocialPostTemplate>
     */
    public function listTemplatesForOwner(User $owner, int $limit = 24): Collection
    {
        return SocialPostTemplate::query()
            ->byUser($owner->id)
            ->orderByDesc('updated_at')
            ->limit(max(1, min(100, $limit)))
            ->get();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function templatePayloads(User $owner, int $limit = 24): array
    {
        return $this->listTemplatesForOwner($owner, $limit)
            ->map(fn (SocialPostTemplate $template) => $this->payload($template))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(User $owner, User $actor, array $payload): SocialPostTemplate
    {
        $template = SocialPostTemplate::query()->create(
            $this->templateAttributes($owner, $actor, $payload)
        );

        return $template->fresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(User $owner, User $actor, SocialPostTemplate $template, array $payload): SocialPostTemplate
    {
        $this->assertOwnership($owner, $template);

        $template->forceFill([
            ...$this->templateAttributes($owner, $actor, $payload),
            'created_by_user_id' => $template->created_by_user_id ?: $actor->id,
        ])->save();

        return $template->fresh();
    }

    public function delete(User $owner, SocialPostTemplate $template): void
    {
        $this->assertOwnership($owner, $template);

        $template->delete();
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(SocialPostTemplate $template): array
    {
        $text = trim((string) data_get($template->content_payload, 'text', ''));
        $selectedTargetIds = collect((array) data_get($template->metadata, 'selected_target_connection_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        $targetSnapshots = collect((array) data_get($template->metadata, 'target_snapshots', []))
            ->map(function (array $snapshot): array {
                return [
                    'social_account_connection_id' => (int) ($snapshot['social_account_connection_id'] ?? 0),
                    'label' => $snapshot['label'] ?? null,
                    'provider_label' => $snapshot['provider_label'] ?? null,
                    'platform' => $snapshot['platform'] ?? null,
                    'display_name' => $snapshot['display_name'] ?? null,
                    'account_handle' => $snapshot['account_handle'] ?? null,
                ];
            })
            ->values()
            ->all();

        return [
            'id' => $template->id,
            'name' => $template->name,
            'text' => $text !== '' ? $text : null,
            'image_url' => $this->mediaAssetService->imageUrl((array) ($template->media_payload ?? [])),
            'link_url' => $template->link_url,
            'link_cta_label' => $this->linkCtaLabel($template->metadata),
            'selected_target_connection_ids' => $selectedTargetIds,
            'selected_accounts_count' => count($selectedTargetIds),
            'targets' => $targetSnapshots,
            'metadata' => (array) ($template->metadata ?? []),
            'updated_at' => optional($template->updated_at)->toIso8601String(),
            'created_at' => optional($template->created_at)->toIso8601String(),
        ];
    }

    private function assertOwnership(User $owner, SocialPostTemplate $template): void
    {
        if ((int) $template->user_id !== (int) $owner->id) {
            abort(404);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function templateAttributes(User $owner, User $actor, array $payload): array
    {
        $name = $this->nullableString($payload, 'name');
        $text = $this->nullableString($payload, 'text');
        $mediaPayload = $this->mediaAssetService->imageMediaPayload($payload);
        $linkUrl = $this->nullableString($payload, 'link_url');
        $linkCtaLabel = $linkUrl !== null ? $this->nullableString($payload, 'link_cta_label') : null;
        $targetConnections = $this->resolveTargetConnections($owner, (array) ($payload['target_connection_ids'] ?? []));

        if ($name === null) {
            throw ValidationException::withMessages([
                'name' => 'Give this Pulse template a clear name before saving it.',
            ]);
        }

        if ($text === null && $mediaPayload === null && $linkUrl === null) {
            throw ValidationException::withMessages([
                'text' => 'Add some text, an image, or a destination link before saving this Pulse template.',
            ]);
        }

        return [
            'user_id' => $owner->id,
            'created_by_user_id' => $actor->id,
            'updated_by_user_id' => $actor->id,
            'name' => $name,
            'content_payload' => array_filter([
                'text' => $text,
            ], fn ($value) => $value !== null),
            'media_payload' => $mediaPayload,
            'link_url' => $linkUrl,
            'metadata' => [
                'selected_target_count' => $targetConnections->count(),
                'selected_target_connection_ids' => $targetConnections
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->values()
                    ->all(),
                'link_cta_label' => $linkCtaLabel,
                'target_snapshots' => $targetConnections
                    ->map(function (SocialAccountConnection $connection): array {
                        return [
                            'social_account_connection_id' => (int) $connection->id,
                            'label' => $connection->label,
                            'provider_label' => data_get($connection->metadata, 'provider_label'),
                            'platform' => $connection->platform,
                            'display_name' => $connection->display_name,
                            'account_handle' => $connection->account_handle,
                        ];
                    })
                    ->values()
                    ->all(),
                'has_image' => $mediaPayload !== null,
                'has_link' => $linkUrl !== null,
                'template_saved_from' => 'social_composer',
            ],
        ];
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
            return collect();
        }

        $connections = SocialAccountConnection::query()
            ->byUser($owner->id)
            ->connected()
            ->whereKey($targetIds->all())
            ->get()
            ->keyBy('id');

        if ($connections->count() !== $targetIds->count()) {
            throw ValidationException::withMessages([
                'target_connection_ids' => 'Only active connected social accounts can be saved inside this Pulse template.',
            ]);
        }

        return $targetIds
            ->map(fn (int $id) => $connections->get($id))
            ->filter()
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

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function linkCtaLabel(?array $metadata): ?string
    {
        $value = trim((string) data_get($metadata, 'link_cta_label', ''));

        return $value !== '' ? $value : null;
    }
}
