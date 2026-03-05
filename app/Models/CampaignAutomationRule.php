<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignAutomationRule extends Model
{
    use HasFactory;

    public const TRIGGER_PRODUCT_BACK_IN_STOCK = 'product_back_in_stock';
    public const TRIGGER_PROMOTION_CREATED = 'promotion_created';
    public const TRIGGER_AFTER_PURCHASE = 'after_purchase';
    public const TRIGGER_INACTIVE_CUSTOMER = 'inactive_customer';

    protected $fillable = [
        'user_id',
        'campaign_id',
        'created_by_user_id',
        'updated_by_user_id',
        'name',
        'trigger_type',
        'trigger_config',
        'delay_minutes',
        'is_active',
        'last_triggered_at',
    ];

    protected $casts = [
        'trigger_config' => 'array',
        'is_active' => 'boolean',
        'delay_minutes' => 'integer',
        'last_triggered_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
