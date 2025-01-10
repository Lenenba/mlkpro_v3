<?php

namespace App\Utils;

use Illuminate\Support\Facades\Storage;

class FileHandler
{
    /**
     * Handle image upload or assign default image if none provided.
     *
     * @param \Illuminate\Http\Request $request
     * @param string|null $oldFilename
     * @param string $fieldName
     * @param string|null $defaultImagePath
     * @return string
     */
    public static function handleImageUpload($request, string $fieldName = null, ?string $defaultImagePath = null, ?string $oldFilename = null): string
    {
        if ($request->hasFile($fieldName)) {
            // Delete old file if uploading a new one
            return $request->file($fieldName)->store('customers', 'public');
        }

        // Return existing filename or default image
        return $oldFilename ?? $defaultImagePath;
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


}
