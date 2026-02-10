<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'sale_id',
        'customer_id',
        'user_id',
        'amount',
        'tip_amount',
        'tip_reversed_amount',
        'tip_type',
        'tip_percent',
        'tip_base_amount',
        'charged_total',
        'tip_assignee_user_id',
        'tip_reversed_at',
        'tip_reversal_rule',
        'tip_reversal_reason',
        'method',
        'provider',
        'status',
        'reference',
        'provider_reference',
        'notes',
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'tip_amount' => 'decimal:2',
        'tip_reversed_amount' => 'decimal:2',
        'tip_percent' => 'decimal:2',
        'tip_base_amount' => 'decimal:2',
        'charged_total' => 'decimal:2',
        'tip_assignee_user_id' => 'integer',
        'tip_reversed_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tipAssignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tip_assignee_user_id');
    }

    public function tipAllocations(): HasMany
    {
        return $this->hasMany(PaymentTipAllocation::class);
    }

    public function getTipNetAmountAttribute(): float
    {
        $tip = (float) ($this->tip_amount ?? 0);
        $reversed = (float) ($this->tip_reversed_amount ?? 0);

        return max(0, round($tip - $reversed, 2));
    }
}
