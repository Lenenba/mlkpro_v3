<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentTipAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_id',
        'user_id',
        'amount',
        'percent',
        'reversed_amount',
    ];

    protected $casts = [
        'payment_id' => 'integer',
        'user_id' => 'integer',
        'amount' => 'decimal:2',
        'percent' => 'decimal:2',
        'reversed_amount' => 'decimal:2',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

