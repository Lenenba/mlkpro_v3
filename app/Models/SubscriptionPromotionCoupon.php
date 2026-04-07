<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubscriptionPromotionCoupon extends Model
{
    use HasFactory;

    protected $fillable = [
        'discount_percent',
        'stripe_coupon_id',
        'name',
        'metadata',
        'synced_at',
    ];

    protected $casts = [
        'discount_percent' => 'integer',
        'metadata' => 'array',
        'synced_at' => 'datetime',
    ];
}
