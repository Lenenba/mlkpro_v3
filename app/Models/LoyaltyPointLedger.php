<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyPointLedger extends Model
{
    public const EVENT_ACCRUAL = 'accrual';
    public const EVENT_REFUND = 'refund';
    public const EVENT_REDEMPTION = 'redemption';
    public const EVENT_REDEMPTION_REVERSAL = 'redemption_reversal';

    protected $fillable = [
        'user_id',
        'customer_id',
        'payment_id',
        'event',
        'points',
        'amount',
        'meta',
        'processed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'meta' => 'array',
        'processed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
