<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AvailabilityException extends Model
{
    use HasFactory;

    public const TYPE_CLOSED = 'closed';
    public const TYPE_OPEN = 'open';

    public const TYPES = [
        self::TYPE_CLOSED,
        self::TYPE_OPEN,
    ];

    protected $fillable = [
        'account_id',
        'team_member_id',
        'date',
        'start_time',
        'end_time',
        'type',
        'reason',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_id');
    }

    public function teamMember(): BelongsTo
    {
        return $this->belongsTo(TeamMember::class);
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }
}

