<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingPeriod extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'open';

    public const STATUS_IN_REVIEW = 'in_review';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_REOPENED = 'reopened';

    protected $fillable = [
        'user_id',
        'period_key',
        'start_date',
        'end_date',
        'status',
        'closed_at',
        'closed_by',
        'reopened_at',
        'reopened_by',
        'meta',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'closed_at' => 'datetime',
        'reopened_at' => 'datetime',
        'meta' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
