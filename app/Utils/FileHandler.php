<?php

namespace App\Utils;

use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;

class FileHandler
{
    private const MAX_IMAGE_SIZE = 1600;

    /**
     * Handle image upload or assign default image if none provided.
     *
     * @param \Illuminate\Http\Request $request
     * @param string|null $oldFilename
     * @param string $fieldName
     * @param string|null $defaultImagePath
     * @return string
     */
    public static function handleImageUpload(string $folderName, $request, string $fieldName = null, ?string $defaultImagePath = null, ?string $oldFilename = null): string
    {
        if ($request->hasFile($fieldName)) {
            // Delete old file if uploading a new one
            $path = $request->file($fieldName)->store($folderName, 'public');
            self::resizeImageIfNeeded(Storage::disk('public')->path($path));
            return $path;
        }

        // Return existing filename or default image
        return $oldFilename ?? $defaultImagePath;
    }

    /**
     * Handle multiple image uploads.
     *
     * @param string $folderName
     * @param \Illuminate\Http\Request $request
     * @param string $fieldName
     * @return array<int, string>
     */
    public static function handleMultipleImageUpload(string $folderName, $request, string $fieldName): array
    {
        if (!$request->hasFile($fieldName)) {
            return [];
        }

        $paths = [];
        foreach ($request->file($fieldName) as $file) {
            if (!$file instanceof UploadedFile) {
                continue;
            }

            $path = $file->store($folderName, 'public');
            self::resizeImageIfNeeded(Storage::disk('public')->path($path));
            $paths[] = $path;
        }

        return $paths;
    }

    /**
     * Delete a file if it exists, but do not delete the default image.
     *
     * @param string|null $filePath
     * @param string|null $defaultImagePath
     * @return void
     */
    public static function deleteFile(?string $filePath, ?string $defaultImagePath): void
    {
        if ($filePath && $filePath !== $defaultImagePath && Storage::disk('public')->exists($filePath)) {
            Storage::disk('public')->delete($filePath);
        }
    }

    private static function resizeImageIfNeeded(string $path): void
    {
        if (!extension_loaded('gd') || !is_file($path)) {
            return;
        }

        $info = @getimagesize($path);
        if (!$info || $info[0] <= self::MAX_IMAGE_SIZE && $info[1] <= self::MAX_IMAGE_SIZE) {
            return;
        }

        [$width, $height, $type] = $info;
        $scale = min(self::MAX_IMAGE_SIZE / $width, self::MAX_IMAGE_SIZE / $height);
        $newWidth = (int) max(1, round($width * $scale));
        $newHeight = (int) max(1, round($height * $scale));

        $source = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null,
            default => null,
        };

        if (!$source) {
            return;
        }

        $destination = imagecreatetruecolor($newWidth, $newHeight);
        imagecopyresampled($destination, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        switch ($type) {
            case IMAGETYPE_JPEG:
                imagejpeg($destination, $path, 85);
                break;
            case IMAGETYPE_PNG:
                imagepng($destination, $path, 6);
                break;
            case IMAGETYPE_WEBP:
                if (function_exists('imagewebp')) {
                    imagewebp($destination, $path, 80);
                }
                break;
        }

        imagedestroy($destination);
        imagedestroy($source);
    }

}
