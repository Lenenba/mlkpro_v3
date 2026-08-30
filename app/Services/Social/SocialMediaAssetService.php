<?php

namespace App\Services\Social;

use App\Models\SocialMediaAsset;
use App\Models\SocialPost;
use App\Models\SocialPostRevision;
use App\Models\SocialPostTemplate;
use App\Models\User;
use App\Utils\FileHandler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class SocialMediaAssetService
{
    /**
     * @return array<string, mixed>
     */
    public function storeUploadedImage(User $owner, UploadedFile $file, string $context): array
    {
        $payload = $this->storeUploadedMedia($owner, $file, $context);

        if (($payload['type'] ?? null) !== 'image') {
            $this->deleteOwnedUploadAssets($owner, [$payload]);

            throw new InvalidArgumentException('The uploaded file must be an image.');
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function storeUploadedMedia(User $owner, UploadedFile $file, string $context): array
    {
        $mimeType = Str::lower((string) ($file->getMimeType() ?: $file->getClientMimeType()));
        $mediaType = $this->mediaTypeForMimeType($mimeType);
        if ($mediaType === null) {
            throw new InvalidArgumentException('The uploaded media type is not supported.');
        }

        $path = null;

        try {
            $path = FileHandler::storeFile('social/'.$context.'/'.$owner->id, $file);
            $name = $this->safeUploadName($file->getClientOriginalName());

            return array_filter([
                'type' => $mediaType,
                'url' => Storage::disk('public')->url($path),
                'disk' => 'public',
                'path' => $path,
                'source' => 'upload',
                'name' => $name,
                'mime_type' => $mimeType,
                'size' => $file->getSize(),
                'title' => $mediaType === 'document'
                    ? $this->documentTitleForName($name)
                    : null,
                'thumbnail_url' => $mediaType === 'document'
                    ? url('/brand/social-card.png')
                    : null,
            ], fn (mixed $value): bool => $value !== null && $value !== '');
        } catch (Throwable $exception) {
            if (is_string($path) && $path !== '') {
                Storage::disk('public')->delete($path);
            }

            throw $exception;
        }
    }

    /**
     * Requalify persisted uploads without trusting storage metadata sent by the browser.
     *
     * @param  array<int, array<string, mixed>>  $assets
     * @return array{media_assets: array<int, array<string, mixed>>, media_uploads: array<int, array<string, mixed>>}
     */
    public function prepareClientMediaAssets(User $owner, array $assets): array
    {
        $publicAssets = [];
        $trustedUploads = [];

        foreach (array_values($assets) as $index => $asset) {
            if (! is_array($asset)) {
                continue;
            }

            $trustedUpload = $this->trustedExistingUpload($owner, $asset);
            if ($trustedUpload !== null) {
                $trustedUploads[] = [
                    ...$trustedUpload,
                    '_media_order' => $index,
                ];

                continue;
            }

            $publicAsset = $this->safePublicMediaAsset($asset);
            if ($publicAsset !== null) {
                $publicAssets[] = [
                    ...$publicAsset,
                    '_media_order' => $index,
                ];
            }
        }

        return [
            'media_assets' => $publicAssets,
            'media_uploads' => $trustedUploads,
        ];
    }

    /**
     * @param  array<string, mixed>  $asset
     */
    public function canTrustClientMediaAsset(User $owner, array $asset): bool
    {
        return $this->trustedExistingUpload($owner, $asset) !== null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function deleteNewUploads(User $owner, array $payload): void
    {
        $assets = [];

        if (is_array($payload['image_upload'] ?? null)) {
            $assets[] = $payload['image_upload'];
        }

        foreach ((array) ($payload['_new_media_uploads'] ?? []) as $asset) {
            if (is_array($asset)) {
                $assets[] = $asset;
            }
        }

        $this->deleteOwnedUploadAssets($owner, $assets);
    }

    /**
     * Delete uploads removed from a persisted post or template unless another owned record still references them.
     *
     * @param  array<int, array<string, mixed>>|null  $previousMediaPayload
     * @param  array<int, array<string, mixed>>|null  $currentMediaPayload
     */
    public function deleteRemovedUploads(
        User $owner,
        ?array $previousMediaPayload,
        ?array $currentMediaPayload = null,
    ): void {
        $previousAssets = $this->ownedUploadAssets($owner, (array) $previousMediaPayload);
        $currentPaths = array_fill_keys(array_keys(
            $this->ownedUploadAssets($owner, (array) $currentMediaPayload)
        ), true);
        $candidates = array_diff_key($previousAssets, $currentPaths);

        if ($candidates === []) {
            return;
        }

        $referencedPaths = $this->referencedUploadPaths($owner, $candidates);
        $deletableAssets = array_diff_key($candidates, $referencedPaths);

        $this->deleteOwnedUploadAssets($owner, array_values($deletableAssets));
    }

    /**
     * @return array<string, mixed>
     */
    public function storeLibraryImage(User $owner, User $actor, UploadedFile $file): array
    {
        $payload = $this->storeUploadedImage($owner, $file, SocialMediaAsset::CONTEXT_LIBRARY);

        try {
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
        } catch (Throwable $exception) {
            $this->deleteOwnedUploadAssets($owner, [$payload]);

            throw $exception;
        }

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
            ->filter(fn (array $asset): bool => strtolower((string) ($asset['type'] ?? 'image')) === 'image')
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
    public function mediaPayload(array $payload): ?array
    {
        $media = [];
        $primaryImage = null;
        $uploadedImage = $payload['image_upload'] ?? null;
        if (is_array($uploadedImage) && trim((string) ($uploadedImage['url'] ?? '')) !== '') {
            $primaryImage = [
                'type' => 'image',
                'url' => (string) $uploadedImage['url'],
                'disk' => $uploadedImage['disk'] ?? null,
                'path' => $uploadedImage['path'] ?? null,
                'source' => $uploadedImage['source'] ?? 'upload',
                'name' => $uploadedImage['name'] ?? null,
                'mime_type' => $uploadedImage['mime_type'] ?? null,
                'size' => $uploadedImage['size'] ?? null,
            ];
        } else {
            $imageUrl = trim((string) ($payload['image_url'] ?? ''));
            if ($imageUrl !== '') {
                $primaryImage = [
                    'type' => 'image',
                    'url' => $imageUrl,
                    'source' => 'url',
                ];
            }
        }

        foreach ((array) ($payload['media_assets'] ?? []) as $asset) {
            if (! is_array($asset)) {
                continue;
            }

            $normalized = $this->normalizedMediaAsset($asset);
            if ($normalized !== null) {
                $media[] = $normalized;
            }
        }

        foreach ((array) ($payload['media_uploads'] ?? []) as $asset) {
            if (! is_array($asset)) {
                continue;
            }

            $normalized = $this->normalizedUploadedMediaAsset($asset);
            if ($normalized !== null) {
                $media[] = $normalized;
            }
        }

        $media = collect($media)
            ->sortBy(fn (array $asset, int $index): array => [
                (int) ($asset['_media_order'] ?? $index),
                $index,
            ])
            ->values()
            ->all();

        if ($primaryImage !== null) {
            $primaryKey = 'image|'.(string) $primaryImage['url'];
            $matchingIndex = collect($media)->search(
                fn (array $asset): bool => strtolower((string) $asset['type']).'|'.(string) $asset['url'] === $primaryKey,
            );

            if ($matchingIndex === false) {
                array_unshift($media, $primaryImage);
            } else {
                $media[$matchingIndex] = array_merge($media[$matchingIndex], $primaryImage);
            }
        }

        $uniqueMedia = [];

        foreach ($media as $asset) {
            $key = strtolower((string) $asset['type']).'|'.(string) $asset['url'];

            if (! array_key_exists($key, $uniqueMedia)) {
                $uniqueMedia[$key] = $asset;

                continue;
            }

            $existingSource = $uniqueMedia[$key]['source'] ?? null;
            $uniqueMedia[$key] = array_merge($uniqueMedia[$key], $asset);

            if ($existingSource === 'upload') {
                $uniqueMedia[$key]['source'] = $existingSource;
            }
        }

        $media = array_values($uniqueMedia);

        foreach ($media as &$asset) {
            unset($asset['_media_order']);
        }
        unset($asset);

        return $media === [] ? null : $media;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>|null
     */
    public function imageMediaPayload(array $payload): ?array
    {
        return $this->mediaPayload($payload);
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $mediaPayload
     */
    public function imageUrl(?array $mediaPayload): ?string
    {
        foreach ((array) $mediaPayload as $item) {
            $type = strtolower(trim((string) ($item['type'] ?? 'image')));
            $url = trim((string) ($item['url'] ?? ''));
            if ($type === 'image' && $url !== '') {
                return $url;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $asset
     * @return array<string, mixed>|null
     */
    private function normalizedMediaAsset(array $asset): ?array
    {
        $type = strtolower(trim((string) ($asset['type'] ?? '')));
        $url = trim((string) ($asset['url'] ?? ''));

        if (! in_array($type, ['image', 'video', 'document'], true) || $url === '') {
            return null;
        }

        $normalized = array_filter([
            'type' => $type,
            'url' => $url,
            'source' => trim((string) ($asset['source'] ?? 'url')) ?: 'url',
            'alt_text' => trim((string) ($asset['alt_text'] ?? '')) ?: null,
            'title' => trim((string) ($asset['title'] ?? '')) ?: null,
            'thumbnail_url' => trim((string) ($asset['thumbnail_url'] ?? '')) ?: null,
            'thumbnail_offset' => isset($asset['thumbnail_offset']) && $asset['thumbnail_offset'] !== ''
                ? (int) $asset['thumbnail_offset']
                : null,
            '_media_order' => isset($asset['_media_order']) ? (int) $asset['_media_order'] : null,
        ], fn (mixed $value): bool => $value !== null);

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $asset
     * @return array<string, mixed>|null
     */
    private function normalizedUploadedMediaAsset(array $asset): ?array
    {
        $normalized = $this->normalizedMediaAsset([
            ...$asset,
            'source' => 'upload',
        ]);
        if ($normalized === null) {
            return null;
        }

        return array_filter([
            ...$normalized,
            'disk' => trim((string) ($asset['disk'] ?? '')) ?: null,
            'path' => trim((string) ($asset['path'] ?? '')) ?: null,
            'name' => trim((string) ($asset['name'] ?? '')) ?: null,
            'mime_type' => trim((string) ($asset['mime_type'] ?? '')) ?: null,
            'size' => isset($asset['size']) ? (int) $asset['size'] : null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
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
            'type' => SocialMediaAsset::MEDIA_TYPE_IMAGE,
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
                    'type' => strtolower(trim((string) ($item['type'] ?? 'image'))) ?: 'image',
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

    private function mediaTypeForMimeType(string $mimeType): ?string
    {
        return match ($mimeType) {
            'image/jpeg',
            'image/jpg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/bmp',
            'image/x-ms-bmp' => 'image',
            'video/mp4',
            'video/quicktime',
            'video/webm' => 'video',
            'application/pdf' => 'document',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $asset
     * @return array<string, mixed>|null
     */
    private function trustedExistingUpload(User $owner, array $asset): ?array
    {
        $path = trim((string) ($asset['path'] ?? ''));
        $url = trim((string) ($asset['url'] ?? ''));

        if ((string) ($asset['source'] ?? '') !== 'upload'
            || (string) ($asset['disk'] ?? '') !== 'public'
            || ! $this->isOwnedSocialUploadPath($owner, $path)) {
            return null;
        }

        try {
            if (! Storage::disk('public')->exists($path)
                || ! hash_equals(Storage::disk('public')->url($path), $url)) {
                return null;
            }

            $storedMimeType = Str::lower((string) Storage::disk('public')->mimeType($path));
            $storedSize = Storage::disk('public')->size($path);
        } catch (Throwable) {
            return null;
        }

        $type = strtolower(trim((string) ($asset['type'] ?? '')));
        $pathType = $this->mediaTypeForExtension((string) pathinfo($path, PATHINFO_EXTENSION));
        if ($pathType === null || $type !== $pathType) {
            return null;
        }

        $submittedMimeType = Str::lower(trim((string) ($asset['mime_type'] ?? '')));
        $mimeType = $this->mediaTypeForMimeType($storedMimeType) === $type
            ? $storedMimeType
            : $submittedMimeType;
        if ($this->mediaTypeForMimeType($mimeType) !== $type) {
            return null;
        }

        $name = $this->safeUploadName((string) ($asset['name'] ?? ''));
        $presentation = $this->safeMediaPresentationFields($asset);

        return array_filter([
            ...$presentation,
            'type' => $type,
            'url' => $url,
            'source' => 'upload',
            'disk' => 'public',
            'path' => $path,
            'name' => $name,
            'mime_type' => $mimeType,
            'size' => $storedSize,
            'title' => $type === 'document'
                ? ((string) ($presentation['title'] ?? '') ?: $this->documentTitleForName($name))
                : ($presentation['title'] ?? null),
            'thumbnail_url' => $type === 'document' ? url('/brand/social-card.png') : null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $asset
     * @return array<string, mixed>|null
     */
    private function safePublicMediaAsset(array $asset): ?array
    {
        $type = strtolower(trim((string) ($asset['type'] ?? '')));
        $url = trim((string) ($asset['url'] ?? ''));

        if (! in_array($type, ['image', 'video', 'document'], true) || ! $this->isPublicHttpsUrl($url)) {
            return null;
        }

        return array_filter([
            ...$this->safeMediaPresentationFields($asset),
            'type' => $type,
            'url' => $url,
            'source' => 'url',
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $asset
     * @return array<string, mixed>
     */
    private function safeMediaPresentationFields(array $asset): array
    {
        return [
            'alt_text' => Str::limit(trim((string) ($asset['alt_text'] ?? '')), 1000, '') ?: null,
            'title' => Str::limit(trim((string) ($asset['title'] ?? '')), 200, '') ?: null,
            'thumbnail_url' => $this->isPublicHttpsUrl($asset['thumbnail_url'] ?? null)
                ? trim((string) $asset['thumbnail_url'])
                : null,
            'thumbnail_offset' => isset($asset['thumbnail_offset']) && $asset['thumbnail_offset'] !== ''
                ? max(0, (int) $asset['thumbnail_offset'])
                : null,
        ];
    }

    private function isPublicHttpsUrl(mixed $value): bool
    {
        $parts = parse_url(trim((string) ($value ?? '')));

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && trim((string) ($parts['host'] ?? '')) !== '';
    }

    private function isOwnedSocialUploadPath(User $owner, string $path): bool
    {
        if ($path === '' || str_contains($path, '..') || $path !== ltrim($path, '/')) {
            return false;
        }

        return preg_match(
            '~\Asocial/(?:posts|templates|library)/'.preg_quote((string) $owner->id, '~')
                .'/[A-Za-z0-9]{40}\.[A-Za-z0-9]{1,10}\z~D',
            $path,
        ) === 1;
    }

    private function mediaTypeForExtension(string $extension): ?string
    {
        return match (strtolower($extension)) {
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp' => 'image',
            'mp4', 'mov', 'webm' => 'video',
            'pdf' => 'document',
            default => null,
        };
    }

    private function safeUploadName(string $name): string
    {
        $safeName = basename(str_replace('\\', '/', trim($name)));

        return Str::limit($safeName, 255, '') ?: 'upload';
    }

    private function documentTitleForName(string $name): string
    {
        $title = Str::of($name)
            ->beforeLast('.')
            ->trim()
            ->limit(200, '')
            ->value();

        return $title !== '' ? $title : 'Document';
    }

    /**
     * @param  array<int, array<string, mixed>>  $assets
     * @return array<string, array<string, mixed>>
     */
    private function ownedUploadAssets(User $owner, array $assets): array
    {
        $ownedAssets = [];

        foreach ($assets as $asset) {
            if (! is_array($asset)) {
                continue;
            }

            $path = trim((string) ($asset['path'] ?? ''));
            $url = trim((string) ($asset['url'] ?? ''));
            if ((string) ($asset['source'] ?? '') !== 'upload'
                || (string) ($asset['disk'] ?? '') !== 'public'
                || ! $this->isOwnedSocialUploadPath($owner, $path)) {
                continue;
            }

            try {
                if (! Storage::disk('public')->exists($path)
                    || ! hash_equals(Storage::disk('public')->url($path), $url)) {
                    continue;
                }
            } catch (Throwable) {
                continue;
            }

            $ownedAssets[$path] = [
                'source' => 'upload',
                'disk' => 'public',
                'path' => $path,
                'url' => $url,
            ];
        }

        return $ownedAssets;
    }

    /**
     * @param  array<int, array<string, mixed>>  $assets
     */
    private function deleteOwnedUploadAssets(User $owner, array $assets): void
    {
        $paths = array_keys($this->ownedUploadAssets($owner, $assets));

        if ($paths !== []) {
            Storage::disk('public')->delete($paths);
        }
    }

    /**
     * Scan persisted references lazily while keeping memory bounded by the candidate count.
     *
     * @param  array<string, array<string, mixed>>  $candidates
     * @return array<string, true>
     */
    private function referencedUploadPaths(User $owner, array $candidates): array
    {
        $referencedPaths = [];
        $candidatePathsByUrl = [];

        foreach ($candidates as $path => $asset) {
            $url = trim((string) ($asset['url'] ?? ''));
            if ($url !== '') {
                $candidatePathsByUrl[$url][] = $path;
            }
        }

        $inspectAssets = function (array $assets) use (
            $candidates,
            $candidatePathsByUrl,
            &$referencedPaths,
        ): void {
            foreach ($assets as $asset) {
                if (! is_array($asset)) {
                    continue;
                }

                $path = trim((string) ($asset['path'] ?? ''));
                $url = trim((string) ($asset['url'] ?? ''));
                if (isset($candidates[$path])) {
                    $referencedPaths[$path] = true;
                }
                foreach ($candidatePathsByUrl[$url] ?? [] as $candidatePath) {
                    $referencedPaths[$candidatePath] = true;
                }
            }
        };

        $payloadQueries = [
            [
                SocialPost::query()
                    ->byUser($owner->id)
                    ->whereNotNull('media_payload')
                    ->select(['id', 'media_payload']),
                'media_payload',
                false,
            ],
            [
                SocialPostTemplate::query()
                    ->byUser($owner->id)
                    ->whereNotNull('media_payload')
                    ->select(['id', 'media_payload']),
                'media_payload',
                false,
            ],
            [
                SocialPostRevision::query()
                    ->where('user_id', $owner->id)
                    ->whereNotNull('media_snapshot')
                    ->select(['id', 'media_snapshot']),
                'media_snapshot',
                true,
            ],
        ];

        foreach ($payloadQueries as [$query, $attribute, $isRevisionSnapshot]) {
            foreach ($query->lazyById(100) as $record) {
                $payload = (array) $record->getAttribute($attribute);
                $inspectAssets($isRevisionSnapshot
                    ? (array_is_list($payload) ? $payload : (array) ($payload['items'] ?? []))
                    : $payload);

                if (count($referencedPaths) === count($candidates)) {
                    return $referencedPaths;
                }
            }
        }

        foreach (
            SocialMediaAsset::query()
                ->byUser($owner->id)
                ->select(['id', 'path', 'url'])
                ->lazyById(100) as $asset
        ) {
            $inspectAssets([[
                'path' => $asset->path,
                'url' => $asset->url,
            ]]);

            if (count($referencedPaths) === count($candidates)) {
                return $referencedPaths;
            }
        }

        return $referencedPaths;
    }
}
