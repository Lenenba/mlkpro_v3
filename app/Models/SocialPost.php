<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SocialPost extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_PUBLISHING = 'publishing';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_PARTIAL_FAILED = 'partial_failed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'created_by_user_id',
        'updated_by_user_id',
        'source_type',
        'source_id',
        'content_payload',
        'media_payload',
        'link_url',
        'status',
        'scheduled_for',
        'published_at',
        'failed_at',
        'failure_reason',
        'metadata',
    ];

    protected $casts = [
        'source_id' => 'integer',
        'content_payload' => 'array',
        'media_payload' => 'array',
        'link_url' => 'string',
        'status' => 'string',
        'scheduled_for' => 'datetime',
        'published_at' => 'datetime',
        'failed_at' => 'datetime',
        'failure_reason' => 'string',
        'metadata' => 'array',
    ];

    public static function allowedStatuses(): array
    {
        return [
            self::STATUS_DRAFT,
            self::STATUS_SCHEDULED,
            self::STATUS_PENDING_APPROVAL,
            self::STATUS_PUBLISHING,
            self::STATUS_PUBLISHED,
            self::STATUS_PARTIAL_FAILED,
            self::STATUS_FAILED,
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

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function targets(): HasMany
    {
        return $this->hasMany(SocialPostTarget::class)->orderBy('id');
    }

    public function approvalRequests(): HasMany
    {
        return $this->hasMany(SocialApprovalRequest::class)->latest('id');
    }

    public function latestApprovalRequest(): HasOne
    {
        return $this->hasOne(SocialApprovalRequest::class)->latestOfMany();
    }

    public function scopeByUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
