<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialMediaAsset extends Model
{
    use HasFactory;

    public const MEDIA_TYPE_IMAGE = 'image';

    public const SOURCE_UPLOAD = 'upload';

    public const SOURCE_AI = 'ai';

    public const SOURCE_URL = 'url';

    public const CONTEXT_LIBRARY = 'library';

    protected $fillable = [
        'user_id',
        'created_by_user_id',
        'media_type',
        'source',
        'context',
        'name',
        'url',
        'disk',
        'path',
        'mime_type',
        'size',
        'origin_type',
        'origin_id',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'size' => 'integer',
    ];

    /**
     * @return array<int, string>
     */
    public static function allowedSources(): array
    {
        return [
            self::SOURCE_UPLOAD,
            self::SOURCE_AI,
            self::SOURCE_URL,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopeByUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
