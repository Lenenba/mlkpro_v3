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

    'twilio' => [
        'sid' => env('TWILIO_SID'),
        'token' => env('TWILIO_AUTH_TOKEN'),
        'from' => env('TWILIO_FROM'),
        'whatsapp_from' => env('TWILIO_WHATSAPP_FROM'),
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'image_model' => env('OPENAI_IMAGE_MODEL', 'gpt-image-1'),
        'image_size' => env('OPENAI_IMAGE_SIZE', '1024x1024'),
        'image_quality' => env('OPENAI_IMAGE_QUALITY'),
        'image_background' => env('OPENAI_IMAGE_BACKGROUND'),
        'image_output_format' => env('OPENAI_IMAGE_OUTPUT_FORMAT', 'png'),
        'image_timeout' => env('OPENAI_IMAGE_TIMEOUT', 120),
    ],

    'rate_limits' => [
        'api_per_user' => env('API_RATE_LIMIT_PER_MINUTE', 120),
        'public_signed_per_minute' => env('PUBLIC_SIGNED_RATE_LIMIT_PER_MINUTE', 30),
        'register_per_minute' => env('REGISTER_RATE_LIMIT_PER_MINUTE', 10),
    ],

];
