<?php

namespace App\Services\Social;

use App\Models\SocialMediaAsset;
use App\Models\SocialPost;
use App\Models\SocialPostTemplate;
use App\Models\User;
use App\Utils\FileHandler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SocialMediaAssetService
{
    /**
     * @return array<string, mixed>
     */
    public function storeUploadedImage(User $owner, UploadedFile $file, string $context): array
    {
        $path = FileHandler::storeFile('social/'.$context.'/'.$owner->id, $file);

        return [
            'type' => 'image',
            'url' => Storage::disk('public')->url($path),
            'disk' => 'public',
            'path' => $path,
            'source' => 'upload',
            'name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType() ?: $file->getMimeType(),
            'size' => $file->getSize(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function storeLibraryImage(User $owner, User $actor, UploadedFile $file): array
    {
        $payload = $this->storeUploadedImage($owner, $file, SocialMediaAsset::CONTEXT_LIBRARY);

        $asset = SocialMediaAsset::query()->create([
            'user_id' => $owner->id,
            'created_by_user_id' => $actor->id,
            'media_type' => SocialMediaAsset::MEDIA_TYPE_IMAGE,
            'source' => SocialMediaAsset::SOURCE_UPLOAD,
            'context' => SocialMediaAsset::CONTEXT_LIBRARY,
            'name' => $payload['name'] ?? $file->getClientOriginalName(),
            'url' => (string) $payload['url'],
            'disk' => $payload['disk'] ?? null,
            'path' => $payload['path'] ?? null,
            'mime_type' => $payload['mime_type'] ?? null,
            'size' => $payload['size'] ?? null,
        ]);

        return [
            ...$payload,
            'asset_id' => $asset->id,
            'library_asset' => $this->storedAssetPayload($asset),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function libraryPayloads(User $owner, array $filters = [], int $limit = 72): array
    {
        $source = $this->allowedFilter($filters['source'] ?? 'all', SocialMediaAsset::allowedSources());
        $origin = $this->allowedFilter($filters['origin'] ?? 'all', ['library', 'post', 'template']);
        $search = Str::lower(trim((string) ($filters['search'] ?? '')));

        $assets = collect()
            ->merge($this->storedLibraryAssets($owner))
            ->merge($this->postMediaAssets($owner))
            ->merge($this->templateMediaAssets($owner))
            ->filter(fn (array $asset): bool => trim((string) ($asset['url'] ?? '')) !== '')
            ->unique(fn (array $asset): string => (string) ($asset['dedupe_key'] ?? $asset['id']))
            ->filter(fn (array $asset): bool => $source === 'all' || (string) ($asset['source'] ?? '') === $source)
            ->filter(fn (array $asset): bool => $origin === 'all' || (string) ($asset['origin'] ?? '') === $origin)
            ->filter(fn (array $asset): bool => $search === '' || $this->matchesSearch($asset, $search))
            ->sortByDesc(fn (array $asset): string => (string) ($asset['used_at'] ?? $asset['created_at'] ?? ''))
            ->take(max(1, min(120, $limit)))
            ->values();

        return $assets
            ->map(fn (array $asset): array => $this->withoutInternalKeys($asset))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    public function librarySummary(User $owner, array $filters = []): array
    {
        $assets = collect($this->libraryPayloads($owner, $filters, 120));

        return [
            'total' => $assets->count(),
            'uploads' => $assets->where('source', SocialMediaAsset::SOURCE_UPLOAD)->count(),
            'ai' => $assets->where('source', SocialMediaAsset::SOURCE_AI)->count(),
            'urls' => $assets->where('source', SocialMediaAsset::SOURCE_URL)->count(),
            'library' => $assets->where('origin', 'library')->count(),
            'posts' => $assets->where('origin', 'post')->count(),
            'templates' => $assets->where('origin', 'template')->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>|null
     */
    public function imageMediaPayload(array $payload): ?array
    {
        $uploadedImage = $payload['image_upload'] ?? null;
        if (is_array($uploadedImage) && trim((string) ($uploadedImage['url'] ?? '')) !== '') {
            return [[
                'type' => 'image',
                'url' => (string) $uploadedImage['url'],
                'disk' => $uploadedImage['disk'] ?? null,
                'path' => $uploadedImage['path'] ?? null,
                'source' => $uploadedImage['source'] ?? 'upload',
                'name' => $uploadedImage['name'] ?? null,
                'mime_type' => $uploadedImage['mime_type'] ?? null,
                'size' => $uploadedImage['size'] ?? null,
            ]];
        }

        $imageUrl = trim((string) ($payload['image_url'] ?? ''));
        if ($imageUrl === '') {
            return null;
        }

        return [[
            'type' => 'image',
            'url' => $imageUrl,
            'source' => 'url',
        ]];
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $mediaPayload
     */
    public function imageUrl(?array $mediaPayload): ?string
    {
        foreach ((array) $mediaPayload as $item) {
            $url = trim((string) ($item['url'] ?? ''));
            if ($url !== '') {
                return $url;
            }
        }

        return null;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function storedLibraryAssets(User $owner): Collection
    {
        return SocialMediaAsset::query()
            ->byUser($owner->id)
            ->latest('updated_at')
            ->limit(120)
            ->get()
            ->map(fn (SocialMediaAsset $asset): array => $this->storedAssetPayload($asset));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function postMediaAssets(User $owner): Collection
    {
        return SocialPost::query()
            ->byUser($owner->id)
            ->whereNotNull('media_payload')
            ->latest('updated_at')
            ->limit(160)
            ->get()
            ->flatMap(fn (SocialPost $post): array => $this->originMediaPayloads(
                $post,
                'post',
                $this->postLabel($post),
                $post->updated_at?->toIso8601String()
            ));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function templateMediaAssets(User $owner): Collection
    {
        return SocialPostTemplate::query()
            ->byUser($owner->id)
            ->whereNotNull('media_payload')
            ->latest('updated_at')
            ->limit(120)
            ->get()
            ->flatMap(fn (SocialPostTemplate $template): array => $this->originMediaPayloads(
                $template,
                'template',
                $this->templateLabel($template),
                $template->updated_at?->toIso8601String()
            ));
    }

    /**
     * @return array<string, mixed>
     */
    private function storedAssetPayload(SocialMediaAsset $asset): array
    {
        $url = trim((string) $asset->url);

        return [
            'id' => 'asset-'.$asset->id,
            'asset_id' => $asset->id,
            'url' => $url,
            'source' => $this->normalizedSource($asset->source),
            'origin' => $asset->context ?: 'library',
            'origin_id' => $asset->origin_id,
            'origin_label' => $asset->name ?: 'Media #'.$asset->id,
            'name' => $asset->name ?: basename((string) $asset->path),
            'mime_type' => $asset->mime_type,
            'size' => $asset->size,
            'disk' => $asset->disk,
            'path' => $asset->path,
            'used_at' => optional($asset->updated_at)->toIso8601String(),
            'created_at' => optional($asset->created_at)->toIso8601String(),
            'dedupe_key' => $this->dedupeKey([
                'url' => $url,
                'path' => $asset->path,
            ]),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function originMediaPayloads(Model $origin, string $originType, string $originLabel, ?string $usedAt): array
    {
        return collect((array) ($origin->media_payload ?? []))
            ->filter(fn ($item): bool => is_array($item))
            ->map(function (array $item, int $index) use ($origin, $originType, $originLabel, $usedAt): array {
                $url = trim((string) ($item['url'] ?? ''));
                $path = trim((string) ($item['path'] ?? ''));
                $key = $this->dedupeKey([
                    'url' => $url,
                    'path' => $path,
                ]);

                return [
                    'id' => $originType.'-'.$origin->getKey().'-'.$index.'-'.substr(sha1($key), 0, 10),
                    'asset_id' => null,
                    'url' => $url,
                    'source' => $this->normalizedSource($item['source'] ?? null),
                    'origin' => $originType,
                    'origin_id' => $origin->getKey(),
                    'origin_label' => $originLabel,
                    'name' => trim((string) ($item['name'] ?? '')) ?: $originLabel,
                    'mime_type' => $item['mime_type'] ?? null,
                    'size' => isset($item['size']) ? (int) $item['size'] : null,
                    'disk' => $item['disk'] ?? null,
                    'path' => $path !== '' ? $path : null,
                    'used_at' => $usedAt,
                    'created_at' => $usedAt,
                    'dedupe_key' => $key,
                ];
            })
            ->filter(fn (array $asset): bool => trim((string) ($asset['url'] ?? '')) !== '')
            ->values()
            ->all();
    }

    private function postLabel(SocialPost $post): string
    {
        $text = trim((string) data_get($post->content_payload, 'text', ''));
        if ($text !== '') {
            return Str::limit($text, 72, '');
        }

        $sourceLabel = trim((string) data_get($post->metadata, 'source.label', ''));

        return $sourceLabel !== '' ? $sourceLabel : 'Post #'.$post->id;
    }

    private function templateLabel(SocialPostTemplate $template): string
    {
        $name = trim((string) $template->name);
        if ($name !== '') {
            return $name;
        }

        $text = trim((string) data_get($template->content_payload, 'text', ''));

        return $text !== '' ? Str::limit($text, 72, '') : 'Template #'.$template->id;
    }

    /**
     * @param  array<int, string>  $allowed
     */
    private function allowedFilter(mixed $value, array $allowed): string
    {
        $candidate = strtolower(trim((string) $value));

        return in_array($candidate, $allowed, true) ? $candidate : 'all';
    }

    private function normalizedSource(mixed $value): string
    {
        $source = strtolower(trim((string) $value));

        return in_array($source, SocialMediaAsset::allowedSources(), true)
            ? $source
            : SocialMediaAsset::SOURCE_URL;
    }

    /**
     * @param  array<string, mixed>  $asset
     */
    private function matchesSearch(array $asset, string $search): bool
    {
        $haystack = Str::lower(implode(' ', array_filter([
            $asset['name'] ?? null,
            $asset['origin_label'] ?? null,
            $asset['url'] ?? null,
            $asset['source'] ?? null,
            $asset['origin'] ?? null,
        ])));

        return Str::contains($haystack, $search);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dedupeKey(array $payload): string
    {
        $path = trim((string) ($payload['path'] ?? ''));
        if ($path !== '') {
            return 'path:'.$path;
        }

        return 'url:'.trim((string) ($payload['url'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $asset
     * @return array<string, mixed>
     */
    private function withoutInternalKeys(array $asset): array
    {
        unset($asset['dedupe_key']);

        return $asset;
    }
}
