<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialPostTarget extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_PUBLISHING = 'publishing';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELED = 'canceled';

    protected $fillable = [
        'social_post_id',
        'social_account_connection_id',
        'status',
        'published_at',
        'failed_at',
        'failure_reason',
        'metadata',
    ];

    protected $casts = [
        'status' => 'string',
        'published_at' => 'datetime',
        'failed_at' => 'datetime',
        'failure_reason' => 'string',
        'metadata' => 'array',
    ];

    public static function allowedStatuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_SCHEDULED,
            self::STATUS_PUBLISHING,
            self::STATUS_PUBLISHED,
            self::STATUS_FAILED,
            self::STATUS_CANCELED,
        ];
    }

    public function socialPost(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class);
    }

    public function socialAccountConnection(): BelongsTo
    {
        return $this->belongsTo(SocialAccountConnection::class, 'social_account_connection_id');
    }
}
