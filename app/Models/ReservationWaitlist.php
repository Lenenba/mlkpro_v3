<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationWaitlist extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_RELEASED = 'released';
    public const STATUS_BOOKED = 'booked';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_RELEASED,
        self::STATUS_BOOKED,
        self::STATUS_CANCELLED,
        self::STATUS_EXPIRED,
    ];

    public const OPEN_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_RELEASED,
    ];

    protected $fillable = [
        'account_id',
        'client_id',
        'client_user_id',
        'service_id',
        'team_member_id',
        'matched_reservation_id',
        'status',
        'requested_start_at',
        'requested_end_at',
        'duration_minutes',
        'party_size',
        'notes',
        'resource_filters',
        'metadata',
        'released_at',
        'resolved_at',
        'cancelled_at',
    ];

    protected $casts = [
        'requested_start_at' => 'datetime',
        'requested_end_at' => 'datetime',
        'duration_minutes' => 'integer',
        'party_size' => 'integer',
        'resource_filters' => 'array',
        'metadata' => 'array',
        'released_at' => 'datetime',
        'resolved_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'client_id');
    }

    public function clientUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_user_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'service_id');
    }

    public function teamMember(): BelongsTo
    {
        return $this->belongsTo(TeamMember::class);
    }

    public function matchedReservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'matched_reservation_id');
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }
}

