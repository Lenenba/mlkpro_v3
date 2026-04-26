<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'paddle' => [
        'seller_id' => env('PADDLE_SELLER_ID'),
        'client_side_token' => env('PADDLE_CLIENT_SIDE_TOKEN'),
        'api_key' => env('PADDLE_AUTH_CODE') ?? env('PADDLE_API_KEY'),
        'webhook_secret' => env('PADDLE_WEBHOOK_SECRET'),
        'sandbox' => env('PADDLE_SANDBOX', false),
    ],

    'stripe' => [
        'enabled' => env('STRIPE_ENABLED', false),
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'connect_enabled' => env('STRIPE_CONNECT_ENABLED', false),
        'connect_fee_percent' => env('STRIPE_CONNECT_FEE_PERCENT', 1.5),
        'ai_usage_price' => env('STRIPE_AI_USAGE_PRICE'),
        'ai_usage_unit' => env('STRIPE_AI_USAGE_UNIT', 'requests'),
        'ai_usage_unit_size' => env('STRIPE_AI_USAGE_UNIT_SIZE', 1),
        'ai_credit_price' => env('STRIPE_AI_CREDIT_PRICE'),
        'ai_credit_pack' => env('STRIPE_AI_CREDIT_PACK', 100),
        'comped_coupon_id' => env('STRIPE_COMPED_COUPON_ID'),
    ],

    'serpapi' => [
        'key' => env('SERPAPI_API_KEY'),
    ],

    'geoapify' => [
        'key' => env('GEOAPIFY_API_KEY', env('VITE_GEOAPIFY_KEY')),
    ],

    'twilio' => [
        'sid' => env('TWILIO_SID'),
        'token' => env('TWILIO_AUTH_TOKEN'),
        'from' => env('TWILIO_FROM'),
        'whatsapp_from' => env('TWILIO_WHATSAPP_FROM'),
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'social_creative_model' => env('OPENAI_SOCIAL_CREATIVE_MODEL', env('OPENAI_MODEL', 'gpt-4o-mini')),
        'social_creative_timeout' => env('OPENAI_SOCIAL_CREATIVE_TIMEOUT', 45),
        'plan_scan_model' => env('OPENAI_PLAN_SCAN_MODEL', 'gpt-4.1-mini'),
        'plan_scan_fallback_model' => env('OPENAI_PLAN_SCAN_FALLBACK_MODEL', 'gpt-4.1'),
        'plan_scan_cache_ttl' => env('OPENAI_PLAN_SCAN_CACHE_TTL', 1440),
        'plan_scan_primary_input_cost_per_1m' => env('OPENAI_PLAN_SCAN_PRIMARY_INPUT_COST_PER_1M'),
        'plan_scan_primary_output_cost_per_1m' => env('OPENAI_PLAN_SCAN_PRIMARY_OUTPUT_COST_PER_1M'),
        'plan_scan_fallback_input_cost_per_1m' => env('OPENAI_PLAN_SCAN_FALLBACK_INPUT_COST_PER_1M'),
        'plan_scan_fallback_output_cost_per_1m' => env('OPENAI_PLAN_SCAN_FALLBACK_OUTPUT_COST_PER_1M'),
        'image_model' => env('OPENAI_IMAGE_MODEL', 'gpt-image-1'),
        'image_size' => env('OPENAI_IMAGE_SIZE', '1024x1024'),
        'image_quality' => env('OPENAI_IMAGE_QUALITY'),
        'image_background' => env('OPENAI_IMAGE_BACKGROUND'),
        'image_output_format' => env('OPENAI_IMAGE_OUTPUT_FORMAT', 'png'),
        'image_timeout' => env('OPENAI_IMAGE_TIMEOUT', 120),
        'plan_scan_timeout' => env('OPENAI_PLAN_SCAN_TIMEOUT', 90),
    ],

    'apollo' => [
        'oauth' => [
            'client_id' => env('APOLLO_OAUTH_CLIENT_ID'),
            'client_secret' => env('APOLLO_OAUTH_CLIENT_SECRET'),
            'redirect_uri' => env('APOLLO_OAUTH_REDIRECT_URI'),
            'authorize_url' => env('APOLLO_OAUTH_AUTHORIZE_URL', 'https://app.apollo.io/#/oauth/authorize'),
            'token_url' => env('APOLLO_OAUTH_TOKEN_URL', 'https://app.apollo.io/api/v1/oauth/token'),
            'profile_url' => env('APOLLO_OAUTH_PROFILE_URL', 'https://app.apollo.io/api/v1/users/api_profile'),
            'scopes' => array_values(array_filter(array_map(
                static fn (string $scope): string => trim($scope),
                preg_split('/[\s,]+/', (string) env('APOLLO_OAUTH_SCOPES', '')) ?: []
            ))),
        ],
    ],

    'social' => [
        'allow_test_connections' => env('SOCIAL_ALLOW_TEST_CONNECTIONS'),

        'facebook' => [
            'oauth' => [
                'client_id' => env('SOCIAL_FACEBOOK_CLIENT_ID'),
                'client_secret' => env('SOCIAL_FACEBOOK_CLIENT_SECRET'),
                'redirect_uri' => env('SOCIAL_FACEBOOK_REDIRECT_URI'),
                'authorize_url' => env('SOCIAL_FACEBOOK_AUTHORIZE_URL', 'https://www.facebook.com/v23.0/dialog/oauth'),
                'token_url' => env('SOCIAL_FACEBOOK_TOKEN_URL', 'https://graph.facebook.com/v23.0/oauth/access_token'),
                'refresh_url' => env('SOCIAL_FACEBOOK_REFRESH_URL', 'https://graph.facebook.com/v23.0/oauth/access_token'),
            ],
            'publish' => [
                'url' => env('SOCIAL_FACEBOOK_PUBLISH_URL'),
                'timeout' => env('SOCIAL_FACEBOOK_PUBLISH_TIMEOUT', 20),
            ],
        ],
        'instagram' => [
            'oauth' => [
                'client_id' => env('SOCIAL_INSTAGRAM_CLIENT_ID'),
                'client_secret' => env('SOCIAL_INSTAGRAM_CLIENT_SECRET'),
                'redirect_uri' => env('SOCIAL_INSTAGRAM_REDIRECT_URI'),
                'authorize_url' => env('SOCIAL_INSTAGRAM_AUTHORIZE_URL', 'https://www.facebook.com/v23.0/dialog/oauth'),
                'token_url' => env('SOCIAL_INSTAGRAM_TOKEN_URL', 'https://graph.facebook.com/v23.0/oauth/access_token'),
                'refresh_url' => env('SOCIAL_INSTAGRAM_REFRESH_URL', 'https://graph.instagram.com/refresh_access_token'),
            ],
            'publish' => [
                'url' => env('SOCIAL_INSTAGRAM_PUBLISH_URL'),
                'timeout' => env('SOCIAL_INSTAGRAM_PUBLISH_TIMEOUT', 20),
            ],
        ],
        'linkedin' => [
            'oauth' => [
                'client_id' => env('SOCIAL_LINKEDIN_CLIENT_ID'),
                'client_secret' => env('SOCIAL_LINKEDIN_CLIENT_SECRET'),
                'redirect_uri' => env('SOCIAL_LINKEDIN_REDIRECT_URI'),
                'authorize_url' => env('SOCIAL_LINKEDIN_AUTHORIZE_URL', 'https://www.linkedin.com/oauth/v2/authorization'),
                'token_url' => env('SOCIAL_LINKEDIN_TOKEN_URL', 'https://www.linkedin.com/oauth/v2/accessToken'),
            ],
            'publish' => [
                'url' => env('SOCIAL_LINKEDIN_PUBLISH_URL'),
                'timeout' => env('SOCIAL_LINKEDIN_PUBLISH_TIMEOUT', 20),
            ],
        ],
        'x' => [
            'oauth' => [
                'client_id' => env('SOCIAL_X_CLIENT_ID'),
                'client_secret' => env('SOCIAL_X_CLIENT_SECRET'),
                'redirect_uri' => env('SOCIAL_X_REDIRECT_URI'),
                'authorize_url' => env('SOCIAL_X_AUTHORIZE_URL', 'https://x.com/i/oauth2/authorize'),
                'token_url' => env('SOCIAL_X_TOKEN_URL', 'https://api.x.com/2/oauth2/token'),
            ],
            'publish' => [
                'url' => env('SOCIAL_X_PUBLISH_URL'),
                'timeout' => env('SOCIAL_X_PUBLISH_TIMEOUT', 20),
            ],
        ],
    ],

    'rate_limits' => [
        'api_per_user' => env('API_RATE_LIMIT_PER_MINUTE', 120),
        'public_signed_per_minute' => env('PUBLIC_SIGNED_RATE_LIMIT_PER_MINUTE', 30),
        'public_lead_lookup_per_minute' => env('PUBLIC_LEAD_LOOKUP_RATE_LIMIT_PER_MINUTE', 20),
        'public_lead_submit_per_minute' => env('PUBLIC_LEAD_SUBMIT_RATE_LIMIT_PER_MINUTE', 6),
        'public_kiosk_per_minute' => env('PUBLIC_KIOSK_RATE_LIMIT_PER_MINUTE', 40),
        'ai_images_per_minute' => env('AI_IMAGES_RATE_LIMIT_PER_MINUTE', 6),
        'register_per_minute' => env('REGISTER_RATE_LIMIT_PER_MINUTE', 10),
    ],

];
