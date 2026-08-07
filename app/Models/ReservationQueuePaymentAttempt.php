<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationQueuePaymentAttempt extends Model
{
    public const STATUS_PREPARING = 'preparing';

    public const STATUS_OPEN = 'open';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'public_id',
        'active_key',
        'account_id',
        'reservation_queue_item_id',
        'invoice_id',
        'payment_id',
        'provider',
        'status',
        'request_fingerprint',
        'idempotency_key',
        'stripe_checkout_session_id',
        'stripe_payment_intent_id',
        'stripe_account_id',
        'checkout_url',
        'amount',
        'tip_amount',
        'tip_type',
        'tip_percent',
        'tip_base_amount',
        'tip_assignee_user_id',
        'charged_total',
        'currency_code',
        'expires_at',
        'completed_at',
        'cancelled_at',
        'last_verified_at',
        'last_error',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'tip_amount' => 'decimal:2',
        'tip_percent' => 'decimal:2',
        'tip_base_amount' => 'decimal:2',
        'charged_total' => 'decimal:2',
        'tip_assignee_user_id' => 'integer',
        'expires_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'last_verified_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_id');
    }

    public function queueItem(): BelongsTo
    {
        return $this->belongsTo(ReservationQueueItem::class, 'reservation_queue_item_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_PREPARING, self::STATUS_OPEN], true)
            && $this->active_key !== null;
    }
}
