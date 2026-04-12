<?php

return [
    'auth' => [
        'account_suspended' => 'Account suspended. Please contact support.',
        'two_factor_delivery_failed' => 'Unable to deliver a verification code. Please try again.',
        'update_temporary_password' => 'Please update your temporary password.',
        'two_factor' => [
            'challenge_delivery_failed' => 'Unable to deliver a verification code. Please try again.',
            'too_many_attempts' => 'Too many attempts. Try again in :seconds seconds.',
            'invalid_or_expired' => 'Invalid or expired code.',
            'resend_wait' => 'Please wait :seconds seconds before requesting a new code.',
            'app_resend_unavailable' => 'Authenticator app codes cannot be resent.',
            'resend_failed' => 'Unable to send a new code right now.',
            'resent' => 'A new code has been sent.',
            'sms_message' => ':app: verification code :code. Expires in :minutes min.',
        ],
    ],
    'onboarding' => [
        'only_owner' => 'Only the account owner can complete onboarding.',
        'completed' => 'Onboarding completed.',
        'team_passwords' => 'Team passwords: :credentials',
        'checkout_canceled' => 'Checkout canceled.',
        'checkout_session_missing' => 'Checkout session is missing.',
        'sync_subscription_failed' => 'Unable to sync subscription.',
        'billing_not_configured' => 'Billing is not configured yet.',
        'checkout_requires_stripe' => 'Onboarding checkout is only available with Stripe.',
        'checkout_start_failed' => 'Unable to start checkout.',
    ],
];
