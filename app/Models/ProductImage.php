<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProductImage extends Model
{
    protected $fillable = [
        'product_id',
        'path',
        'is_primary',
        'sort_order',
    ];

    protected $appends = [
        'url',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function getUrlAttribute(): string
    {
        $path = (string) $this->path;
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        if ($path === Product::LEGACY_DEFAULT_IMAGE_PATH) {
            return Product::defaultImageUrlFor(Product::ITEM_TYPE_PRODUCT);
        }

        if (Product::isPublicAssetPath($path)) {
            return asset(ltrim($path, '/'));
        }

        return Storage::url($path);
    }
}
