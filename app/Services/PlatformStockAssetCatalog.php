<?php

namespace App\Services;

use App\Support\PublicPageStockImages;
use Illuminate\Support\Collection;

class PlatformStockAssetCatalog
{
    public function all(?string $locale = null): Collection
    {
        return collect(PublicPageStockImages::libraryAssets($locale))
            ->map(fn (array $asset) => $this->mapAsset($asset))
            ->unique('url')
            ->values();
    }

    private function mapAsset(array $asset): array
    {
        $url = (string) ($asset['url'] ?? '');
        $publicPath = public_path(ltrim($url, '/'));
        $mime = $this->mimeForPath($publicPath, $url);
        $size = is_file($publicPath) ? (int) filesize($publicPath) : 0;

        return [
            'id' => (string) ($asset['id'] ?? $url),
            'name' => (string) ($asset['name'] ?? 'Platform image'),
            'url' => $url,
            'mime' => $mime,
            'size' => $size,
            'tags' => array_values(array_unique(array_map(
                fn ($tag) => trim(mb_strtolower((string) $tag)),
                is_array($asset['tags'] ?? null) ? $asset['tags'] : []
            ))),
            'alt' => (string) ($asset['alt'] ?? ''),
            'is_image' => str_starts_with($mime, 'image/'),
            'created_at' => null,
            'is_system' => true,
        ];
    }

    private function mimeForPath(string $publicPath, string $url): string
    {
        if (is_file($publicPath)) {
            $mime = mime_content_type($publicPath);
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }

        return match (strtolower(pathinfo($url, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'gif' => 'image/gif',
            'avif' => 'image/avif',
            default => 'application/octet-stream',
        };
    }
}
