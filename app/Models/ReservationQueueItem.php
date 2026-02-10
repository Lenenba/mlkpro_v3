<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReservationQueueItem extends Model
{
    use HasFactory;

    public const TYPE_APPOINTMENT = 'appointment';
    public const TYPE_TICKET = 'ticket';

    public const TYPES = [
        self::TYPE_APPOINTMENT,
        self::TYPE_TICKET,
    ];

    public const STATUS_NOT_ARRIVED = 'not_arrived';
    public const STATUS_CHECKED_IN = 'checked_in';
    public const STATUS_PRE_CALLED = 'pre_called';
    public const STATUS_CALLED = 'called';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_IN_SERVICE = 'in_service';
    public const STATUS_DONE = 'done';
    public const STATUS_NO_SHOW = 'no_show';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_LEFT = 'left';

    public const STATUSES = [
        self::STATUS_NOT_ARRIVED,
        self::STATUS_CHECKED_IN,
        self::STATUS_PRE_CALLED,
        self::STATUS_CALLED,
        self::STATUS_SKIPPED,
        self::STATUS_IN_SERVICE,
        self::STATUS_DONE,
        self::STATUS_NO_SHOW,
        self::STATUS_CANCELLED,
        self::STATUS_LEFT,
    ];

    public const ACTIVE_STATUSES = [
        self::STATUS_NOT_ARRIVED,
        self::STATUS_CHECKED_IN,
        self::STATUS_PRE_CALLED,
        self::STATUS_CALLED,
        self::STATUS_SKIPPED,
        self::STATUS_IN_SERVICE,
    ];

    public const CALLABLE_STATUSES = [
        self::STATUS_CHECKED_IN,
        self::STATUS_PRE_CALLED,
        self::STATUS_SKIPPED,
    ];

    protected $fillable = [
        'account_id',
        'reservation_id',
        'client_id',
        'client_user_id',
        'service_id',
        'team_member_id',
        'created_by_user_id',
        'item_type',
        'source',
        'queue_number',
        'status',
        'priority',
        'estimated_duration_minutes',
        'checked_in_at',
        'pre_called_at',
        'called_at',
        'call_expires_at',
        'started_at',
        'finished_at',
        'cancelled_at',
        'left_at',
        'skipped_at',
        'position',
        'eta_minutes',
        'metadata',
    ];

    protected $casts = [
        'priority' => 'integer',
        'estimated_duration_minutes' => 'integer',
        'checked_in_at' => 'datetime',
        'pre_called_at' => 'datetime',
        'called_at' => 'datetime',
        'call_expires_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'left_at' => 'datetime',
        'skipped_at' => 'datetime',
        'position' => 'integer',
        'eta_minutes' => 'integer',
        'metadata' => 'array',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function checkIns(): HasMany
    {
        return $this->hasMany(ReservationCheckIn::class);
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }
}

