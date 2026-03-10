<?php

namespace App\Models;

use App\Enums\BillingPeriod;
use App\Enums\CurrencyCode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanPrice extends Model
{
    use HasFactory;

    protected $fillable = [
        'plan_id',
        'currency_code',
        'billing_period',
        'amount',
        'stripe_price_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'currency_code' => CurrencyCode::class,
            'billing_period' => BillingPeriod::class,
            'amount' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
