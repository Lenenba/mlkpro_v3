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
        'vip',
    ];

    protected $casts = [
        'channels' => 'array',
        'consent' => 'array',
        'audience' => 'array',
        'templates' => 'array',
        'tracking' => 'array',
        'offers' => 'array',
        'vip' => 'array',
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
                    'vip_max_messages_per_window' => null,
                    'vip_window_days' => null,
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
                'source_logic_default' => 'UNION',
            ],
            'templates' => [
                'allow_campaign_override' => true,
                'brand_profile' => [
                    'name' => null,
                    'tagline' => null,
                    'description' => null,
                    'logo_url' => null,
                    'website_url' => null,
                    'contact_url' => null,
                    'support_url' => null,
                    'booking_url' => null,
                    'contact_email' => null,
                    'reply_to_email' => null,
                    'phone' => null,
                    'address_line_1' => null,
                    'address_line_2' => null,
                    'city' => null,
                    'province' => null,
                    'country' => null,
                    'postal_code' => null,
                    'primary_color' => '#0F766E',
                    'secondary_color' => '#0F172A',
                    'accent_color' => '#F59E0B',
                    'surface_color' => '#F8FAFC',
                    'hero_background_color' => '#ECFEFF',
                    'footer_background_color' => '#0F172A',
                    'text_color' => '#0F172A',
                    'muted_color' => '#475569',
                    'facebook_url' => null,
                    'instagram_url' => null,
                    'linkedin_url' => null,
                    'youtube_url' => null,
                    'tiktok_url' => null,
                    'whatsapp_url' => null,
                    'footer_note' => null,
                ],
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
            'vip' => [
                'automation' => [
                    'enabled' => false,
                    'evaluation_window_days' => 365,
                    'minimum_total_spend' => null,
                    'minimum_paid_orders' => null,
                    'default_tier_code' => null,
                    'preserve_existing_tier' => true,
                    'downgrade_when_not_eligible' => false,
                    'excluded_customer_ids' => [],
                    'tier_rules' => [],
                ],
            ],
        ];
    }
}
