<?php

namespace App\Models\Billing;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StripeSubscription extends Model
{
    protected $table = 'stripe_subscriptions';

    protected $fillable = [
        'user_id',
        'stripe_id',
        'stripe_customer_id',
        'price_id',
        'currency_code',
        'plan_code',
        'plan_price_id',
        'billing_period',
        'is_comped',
        'comped_coupon_id',
        'promotion_coupon_id',
        'promotion_discount_percent',
        'assistant_price_id',
        'assistant_item_id',
        'assistant_enabled_at',
        'status',
        'trial_ends_at',
        'ends_at',
        'current_period_end',
    ];

    protected $casts = [
        'is_comped' => 'boolean',
        'currency_code' => 'string',
        'plan_code' => 'string',
        'plan_price_id' => 'integer',
        'billing_period' => 'string',
        'promotion_discount_percent' => 'integer',
        'trial_ends_at' => 'datetime',
        'ends_at' => 'datetime',
        'current_period_end' => 'datetime',
        'assistant_enabled_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
