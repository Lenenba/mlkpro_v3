<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Playbook extends Model
{
    use HasFactory;

    public const SCHEDULE_MANUAL = 'manual';

    public const SCHEDULE_DAILY = 'daily';

    public const SCHEDULE_WEEKLY = 'weekly';

    protected $fillable = [
        'user_id',
        'saved_segment_id',
        'created_by_user_id',
        'updated_by_user_id',
        'module',
        'name',
        'action_key',
        'action_payload',
        'schedule_type',
        'schedule_timezone',
        'schedule_day_of_week',
        'schedule_time',
        'next_run_at',
        'last_run_at',
        'is_active',
    ];

    protected $casts = [
        'module' => 'string',
        'action_key' => 'string',
        'action_payload' => 'array',
        'schedule_type' => 'string',
        'schedule_timezone' => 'string',
        'schedule_day_of_week' => 'integer',
        'schedule_time' => 'string',
        'next_run_at' => 'datetime',
        'last_run_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public static function allowedScheduleTypes(): array
    {
        return [
            self::SCHEDULE_MANUAL,
            self::SCHEDULE_DAILY,
            self::SCHEDULE_WEEKLY,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function savedSegment(): BelongsTo
    {
        return $this->belongsTo(SavedSegment::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(PlaybookRun::class);
    }

    public function scopeByUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
