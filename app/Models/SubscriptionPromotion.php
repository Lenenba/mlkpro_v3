<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubscriptionPromotion extends Model
{
    use HasFactory;

    public const GLOBAL_KEY = 'global';

    protected $fillable = [
        'key',
        'name',
        'is_enabled',
        'monthly_discount_percent',
        'yearly_discount_percent',
        'monthly_stripe_coupon_id',
        'yearly_stripe_coupon_id',
        'last_synced_at',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'monthly_discount_percent' => 'integer',
        'yearly_discount_percent' => 'integer',
        'last_synced_at' => 'datetime',
    ];
}
