<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialAutomationRun extends Model
{
    use HasFactory;

    public const STATUS_GENERATED = 'generated';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'user_id',
        'social_automation_rule_id',
        'social_post_id',
        'status',
        'outcome_code',
        'message',
        'source_type',
        'source_id',
        'metadata',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'social_automation_rule_id' => 'integer',
        'social_post_id' => 'integer',
        'status' => 'string',
        'outcome_code' => 'string',
        'message' => 'string',
        'source_type' => 'string',
        'source_id' => 'integer',
        'metadata' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public static function allowedStatuses(): array
    {
        return [
            self::STATUS_GENERATED,
            self::STATUS_SKIPPED,
            self::STATUS_ERROR,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function automationRule(): BelongsTo
    {
        return $this->belongsTo(SocialAutomationRule::class, 'social_automation_rule_id');
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class, 'social_post_id');
    }

    public function scopeByUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
