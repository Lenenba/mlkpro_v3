<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ReservationStatusTransition extends Model
{
    /** @use HasFactory<\Database\Factories\ReservationStatusTransitionFactory> */
    use HasFactory;

    public const EVENT_CREATED = 'created';

    public const EVENT_STATUS_CHANGED = 'status_changed';

    public const EVENT_STATUS_REAFFIRMED = 'status_reaffirmed';

    public const EVENT_SCHEDULE_CHANGED = 'schedule_changed';

    public const EVENT_OUTCOME_REVIEW_REQUESTED = 'outcome_review_requested';

    public const ACTOR_USER = 'user';

    public const ACTOR_SYSTEM = 'system';

    public const ACTOR_INTEGRATION = 'integration';

    protected $fillable = [
        'account_id',
        'reservation_id',
        'event_type',
        'from_status',
        'to_status',
        'actor_type',
        'actor_user_id',
        'source',
        'reason_code',
        'reason',
        'status_version',
        'schedule_version',
        'idempotency_key',
        'metadata',
        'occurred_at',
    ];

    protected $casts = [
        'status_version' => 'integer',
        'schedule_version' => 'integer',
        'metadata' => 'array',
        'occurred_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $transition): void {
            $belongsToTenant = Reservation::query()
                ->forAccount((int) $transition->account_id)
                ->whereKey((int) $transition->reservation_id)
                ->exists();

            if (! $belongsToTenant) {
                throw new LogicException('Reservation status transition tenant does not match its reservation.');
            }
        });

        static::updating(function (): never {
            throw new LogicException('Reservation status transition audit records are immutable.');
        });

        static::deleting(function (): never {
            throw new LogicException('Reservation status transition audit records cannot be deleted.');
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
