<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CampaignRun extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELED = 'canceled';

    public const TRIGGER_MANUAL = 'manual';
    public const TRIGGER_SCHEDULED = 'scheduled';
    public const TRIGGER_AUTOMATION = 'automation';

    protected $fillable = [
        'campaign_id',
        'user_id',
        'triggered_by_user_id',
        'trigger_type',
        'status',
        'idempotency_key',
        'scheduled_for',
        'started_at',
        'completed_at',
        'audience_snapshot',
        'summary',
        'error_message',
    ];

    protected $casts = [
        'scheduled_for' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'audience_snapshot' => 'array',
        'summary' => 'array',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(CampaignMessage::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(CampaignEvent::class);
    }

    public function scopeByUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
