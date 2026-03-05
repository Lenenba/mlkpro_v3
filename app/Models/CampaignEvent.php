<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignEvent extends Model
{
    use HasFactory;

    public const EVENT_QUEUED = 'QUEUED';
    public const EVENT_SENT = 'SENT';
    public const EVENT_DELIVERED = 'DELIVERED';
    public const EVENT_OPENED = 'OPENED';
    public const EVENT_CLICKED = 'CLICK';
    public const EVENT_CONVERTED = 'CONVERSION';
    public const EVENT_FAILED = 'FAILED';
    public const EVENT_SKIPPED = 'SKIPPED';
    public const EVENT_UNSUBSCRIBE = 'UNSUBSCRIBE';

    protected $fillable = [
        'campaign_id',
        'campaign_run_id',
        'campaign_recipient_id',
        'user_id',
        'customer_id',
        'channel',
        'event_type',
        'provider_message_id',
        'conversion_type',
        'conversion_id',
        'occurred_at',
        'metadata',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(CampaignRun::class, 'campaign_run_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(CampaignRecipient::class, 'campaign_recipient_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
