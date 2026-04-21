<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlaybookRun extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELED = 'canceled';

    public const ORIGIN_MANUAL = 'manual';

    public const ORIGIN_SCHEDULED = 'scheduled';

    protected $fillable = [
        'user_id',
        'playbook_id',
        'saved_segment_id',
        'requested_by_user_id',
        'module',
        'action_key',
        'origin',
        'status',
        'selected_count',
        'processed_count',
        'success_count',
        'failed_count',
        'skipped_count',
        'scheduled_for',
        'started_at',
        'finished_at',
        'summary',
    ];

    protected $casts = [
        'module' => 'string',
        'action_key' => 'string',
        'origin' => 'string',
        'status' => 'string',
        'selected_count' => 'integer',
        'processed_count' => 'integer',
        'success_count' => 'integer',
        'failed_count' => 'integer',
        'skipped_count' => 'integer',
        'scheduled_for' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'summary' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function playbook(): BelongsTo
    {
        return $this->belongsTo(Playbook::class);
    }

    public function savedSegment(): BelongsTo
    {
        return $this->belongsTo(SavedSegment::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function scopeByUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
