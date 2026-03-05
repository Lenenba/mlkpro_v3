<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'channels',
        'consent',
        'audience',
        'templates',
        'tracking',
        'offers',
    ];

    protected $casts = [
        'channels' => 'array',
        'consent' => 'array',
        'audience' => 'array',
        'templates' => 'array',
        'tracking' => 'array',
        'offers' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function defaults(): array
    {
        return [
            'channels' => [
                'enabled' => [
                    'EMAIL' => true,
                    'SMS' => true,
                    'IN_APP' => true,
                ],
                'provider' => [
                    'sms_provider' => 'twilio',
                    'sender_id' => null,
                    'email_from_name' => null,
                ],
                'quiet_hours' => [
                    'timezone' => null,
                    'start' => '21:00',
                    'end' => '08:00',
                ],
                'anti_fatigue' => [
                    'max_messages_per_window' => 2,
                    'window_days' => 7,
                    'same_campaign_cooldown_hours' => 48,
                ],
            ],
            'consent' => [
                'require_explicit' => true,
                'stop_keywords' => ['STOP', 'UNSUBSCRIBE'],
                'default_behavior' => 'deny_without_explicit',
            ],
            'audience' => [
                'default_exclusions' => [
                    'exclude_contacted_last_days' => 0,
                ],
            ],
            'templates' => [
                'allow_campaign_override' => true,
            ],
            'tracking' => [
                'click_tracking_enabled' => true,
                'conversion_events' => [
                    'reservation_created' => true,
                    'invoice_paid' => true,
                    'quote_accepted' => true,
                    'product_purchase' => true,
                ],
            ],
            'offers' => [
                'allowed_modes' => ['PRODUCTS', 'SERVICES', 'MIXED'],
                'default_search_filters' => [
                    'status' => 'active',
                ],
                'selection_strategy' => 'snapshot_on_save',
            ],
        ];
    }
}

