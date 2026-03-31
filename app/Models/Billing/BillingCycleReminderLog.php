<?php

namespace App\Models\Billing;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingCycleReminderLog extends Model
{
    protected $fillable = [
        'user_id',
        'provider',
        'provider_subscription_id',
        'billing_date',
        'days_before',
        'reminder_key',
        'payload',
        'sent_at',
    ];

    protected $casts = [
        'billing_date' => 'datetime',
        'payload' => 'array',
        'sent_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
