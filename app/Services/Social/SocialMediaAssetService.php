<?php

namespace App\Services\Social;

use App\Models\User;
use App\Utils\FileHandler;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

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
}
