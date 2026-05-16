<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReservationResource extends Model
{
    use HasFactory;

    public const TYPE_CHAIR = 'chair';

    protected $fillable = [
        'account_id',
        'team_member_id',
        'name',
        'type',
        'capacity',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'capacity' => 'integer',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_id');
    }

    public function teamMember(): BelongsTo
    {
        return $this->belongsTo(TeamMember::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(ReservationResourceAllocation::class, 'reservation_resource_id');
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeChairs(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_CHAIR);
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }

    public function isAssigned(): bool
    {
        return ! empty($this->team_member_id);
    }

    public function isTeamMemberCheckedIn(?TeamMemberAttendance $attendance): bool
    {
        return $attendance !== null
            && $attendance->clock_out_at === null
            && (string) ($attendance->current_status ?? TeamMemberAttendance::STATUS_AVAILABLE) !== TeamMemberAttendance::STATUS_OFFLINE;
    }

    public function isAvailableForQueue(?TeamMemberAttendance $attendance, ?ReservationQueueItem $currentItem = null): bool
    {
        return $this->isActive()
            && $this->isAssigned()
            && $this->isTeamMemberCheckedIn($attendance)
            && (string) ($attendance?->current_status ?? TeamMemberAttendance::STATUS_OFFLINE) === TeamMemberAttendance::STATUS_AVAILABLE
            && $currentItem === null;
    }
}
