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
        'is_comped',
        'comped_coupon_id',
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
