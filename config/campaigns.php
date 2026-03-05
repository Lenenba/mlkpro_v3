<?php

return [
    'require_explicit_consent' => env('CAMPAIGNS_REQUIRE_EXPLICIT_CONSENT', true),

    'sms' => [
        'segment_length' => (int) env('CAMPAIGNS_SMS_SEGMENT_LENGTH', 160),
        'max_segments' => (int) env('CAMPAIGNS_SMS_MAX_SEGMENTS', 2),
    ],

    'fatigue' => [
        'max_messages_per_window' => (int) env('CAMPAIGNS_FATIGUE_MAX_MESSAGES', 2),
        'window_days' => (int) env('CAMPAIGNS_FATIGUE_WINDOW_DAYS', 7),
        'same_campaign_cooldown_hours' => (int) env('CAMPAIGNS_SAME_CAMPAIGN_COOLDOWN_HOURS', 48),
    ],

    'quiet_hours' => [
        'start' => env('CAMPAIGNS_QUIET_HOURS_START', '21:00'),
        'end' => env('CAMPAIGNS_QUIET_HOURS_END', '08:00'),
    ],

    'queues' => [
        'dispatch' => env('CAMPAIGNS_QUEUE_DISPATCH', 'campaigns-dispatch'),
        'send' => env('CAMPAIGNS_QUEUE_SEND', 'campaigns-send'),
        'maintenance' => env('CAMPAIGNS_QUEUE_MAINTENANCE', 'campaigns-maintenance'),
    ],

    'rate_limits' => [
        'per_minute_email' => (int) env('CAMPAIGNS_RATE_LIMIT_EMAIL_PER_MINUTE', 300),
        'per_minute_sms' => (int) env('CAMPAIGNS_RATE_LIMIT_SMS_PER_MINUTE', 120),
        'per_minute_in_app' => (int) env('CAMPAIGNS_RATE_LIMIT_IN_APP_PER_MINUTE', 600),
    ],

    'webhooks' => [
        'sms_secret' => env('CAMPAIGNS_SMS_WEBHOOK_SECRET'),
        'email_secret' => env('CAMPAIGNS_EMAIL_WEBHOOK_SECRET'),
    ],
];
