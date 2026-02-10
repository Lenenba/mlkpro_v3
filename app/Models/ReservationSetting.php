<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'account_id',
        'team_member_id',
        'buffer_minutes',
        'slot_interval_minutes',
        'min_notice_minutes',
        'max_advance_days',
        'cancellation_cutoff_hours',
        'allow_client_cancel',
        'allow_client_reschedule',
    ];

    protected $casts = [
        'buffer_minutes' => 'integer',
        'slot_interval_minutes' => 'integer',
        'min_notice_minutes' => 'integer',
        'max_advance_days' => 'integer',
        'cancellation_cutoff_hours' => 'integer',
        'allow_client_cancel' => 'boolean',
        'allow_client_reschedule' => 'boolean',
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

