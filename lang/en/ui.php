<?php

return [
    'auth' => [
        'account_suspended' => 'Account suspended. Please contact support.',
        'two_factor_delivery_failed' => 'Unable to deliver a verification code. Please try again.',
        'update_temporary_password' => 'Please update your temporary password.',
        'social' => [
            'provider_not_configured' => ':provider sign-in is not configured yet.',
            'provider_not_ready' => ':provider sign-in is not available yet.',
            'callback_not_ready' => ':provider callback is not available yet.',
            'callback_failed' => ':provider sign-in could not be completed.',
            'invalid_state' => 'This social sign-in request is no longer valid. Start it again.',
            'missing_code' => ':provider did not return the expected authorization code.',
            'token_exchange_failed' => 'Unable to finish :provider authentication.',
            'profile_fetch_failed' => 'Unable to fetch the :provider profile.',
            'profile_incomplete' => ':provider did not return the minimum profile fields required.',
            'email_not_verified' => ':provider did not return a usable verified email address.',
            'provider_already_linked' => 'Another :provider account is already linked to this user.',
            'account_not_available' => 'The local account linked to :provider could not be found.',
        ],
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
