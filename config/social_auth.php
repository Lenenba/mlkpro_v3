<?php

$socialAuthBool = static function (string $key, bool $default = false): bool {
    $value = env($key);

    if ($value === null) {
        return $default;
    }

    if (is_bool($value)) {
        return $value;
    }

    return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
};

$socialAuthScopes = static function (string $key, string $default = ''): array {
    $raw = trim((string) env($key, $default));

    if ($raw === '') {
        return [];
    }

    return array_values(array_filter(array_map(
        static fn (string $scope): string => trim($scope),
        preg_split('/[\s,]+/', $raw) ?: []
    )));
};

$appUrl = rtrim((string) env('APP_URL', 'http://localhost'), '/');
$microsoftTenant = trim((string) env('SOCIAL_AUTH_MICROSOFT_TENANT', 'common'));
$microsoftTenant = $microsoftTenant !== '' ? $microsoftTenant : 'common';

return [
    'enabled' => $socialAuthBool('SOCIAL_AUTH_ENABLED', false),
    'default_source' => env('SOCIAL_AUTH_DEFAULT_SOURCE', 'login'),
    'providers' => [
        'google' => [
            'label' => 'Google',
            'icon' => 'google',
            'driver' => 'oidc',
            'enabled' => $socialAuthBool('SOCIAL_AUTH_GOOGLE_ENABLED', false),
            'implemented' => true,
            'contexts' => [
                'login' => true,
                'register' => true,
                'onboarding' => true,
            ],
            'client_id' => env('SOCIAL_AUTH_GOOGLE_CLIENT_ID'),
            'client_secret' => env('SOCIAL_AUTH_GOOGLE_CLIENT_SECRET'),
            'redirect_uri' => env('SOCIAL_AUTH_GOOGLE_REDIRECT_URI', $appUrl.'/auth/social/google/callback'),
            'authorize_url' => env('SOCIAL_AUTH_GOOGLE_AUTHORIZE_URL', 'https://accounts.google.com/o/oauth2/v2/auth'),
            'token_url' => env('SOCIAL_AUTH_GOOGLE_TOKEN_URL', 'https://oauth2.googleapis.com/token'),
            'userinfo_url' => env('SOCIAL_AUTH_GOOGLE_USERINFO_URL', 'https://openidconnect.googleapis.com/v1/userinfo'),
            'scopes' => $socialAuthScopes('SOCIAL_AUTH_GOOGLE_SCOPES', 'openid profile email'),
        ],
        'microsoft' => [
            'label' => 'Microsoft',
            'icon' => 'microsoft',
            'driver' => 'oidc',
            'enabled' => $socialAuthBool('SOCIAL_AUTH_MICROSOFT_ENABLED', false),
            'implemented' => true,
            'contexts' => [
                'login' => true,
                'register' => true,
                'onboarding' => true,
            ],
            'tenant' => $microsoftTenant,
            'client_id' => env('SOCIAL_AUTH_MICROSOFT_CLIENT_ID'),
            'client_secret' => env('SOCIAL_AUTH_MICROSOFT_CLIENT_SECRET'),
            'redirect_uri' => env('SOCIAL_AUTH_MICROSOFT_REDIRECT_URI', $appUrl.'/auth/social/microsoft/callback'),
            'authorize_url' => env(
                'SOCIAL_AUTH_MICROSOFT_AUTHORIZE_URL',
                'https://login.microsoftonline.com/'.$microsoftTenant.'/oauth2/v2.0/authorize'
            ),
            'token_url' => env(
                'SOCIAL_AUTH_MICROSOFT_TOKEN_URL',
                'https://login.microsoftonline.com/'.$microsoftTenant.'/oauth2/v2.0/token'
            ),
            'userinfo_url' => env('SOCIAL_AUTH_MICROSOFT_USERINFO_URL', 'https://graph.microsoft.com/oidc/userinfo'),
            'scopes' => $socialAuthScopes('SOCIAL_AUTH_MICROSOFT_SCOPES', 'openid profile email User.Read'),
        ],
        'facebook' => [
            'label' => 'Facebook',
            'icon' => 'facebook',
            'driver' => 'oauth2',
            'enabled' => $socialAuthBool('SOCIAL_AUTH_FACEBOOK_ENABLED', false),
            'implemented' => true,
            'contexts' => [
                'login' => true,
                'register' => true,
                'onboarding' => true,
            ],
            'client_id' => env('SOCIAL_AUTH_FACEBOOK_CLIENT_ID'),
            'client_secret' => env('SOCIAL_AUTH_FACEBOOK_CLIENT_SECRET'),
            'redirect_uri' => env('SOCIAL_AUTH_FACEBOOK_REDIRECT_URI', $appUrl.'/auth/social/facebook/callback'),
            'authorize_url' => env('SOCIAL_AUTH_FACEBOOK_AUTHORIZE_URL', 'https://www.facebook.com/v23.0/dialog/oauth'),
            'token_url' => env('SOCIAL_AUTH_FACEBOOK_TOKEN_URL', 'https://graph.facebook.com/v23.0/oauth/access_token'),
            'userinfo_url' => env('SOCIAL_AUTH_FACEBOOK_USERINFO_URL', 'https://graph.facebook.com/me?fields=id,name,email,picture'),
            'scopes' => $socialAuthScopes('SOCIAL_AUTH_FACEBOOK_SCOPES', 'email public_profile'),
            'data_deletion' => [
                'delete_local_account' => $socialAuthBool('SOCIAL_AUTH_FACEBOOK_DATA_DELETION_DELETE_LOCAL_ACCOUNT', false),
            ],
        ],
        'linkedin' => [
            'label' => 'LinkedIn',
            'icon' => 'linkedin',
            'driver' => 'oidc',
            'enabled' => $socialAuthBool('SOCIAL_AUTH_LINKEDIN_ENABLED', false),
            'implemented' => true,
            'contexts' => [
                'login' => true,
                'register' => true,
                'onboarding' => true,
            ],
            'client_id' => env('SOCIAL_AUTH_LINKEDIN_CLIENT_ID'),
            'client_secret' => env('SOCIAL_AUTH_LINKEDIN_CLIENT_SECRET'),
            'redirect_uri' => env('SOCIAL_AUTH_LINKEDIN_REDIRECT_URI', $appUrl.'/auth/social/linkedin/callback'),
            'authorize_url' => env('SOCIAL_AUTH_LINKEDIN_AUTHORIZE_URL', 'https://www.linkedin.com/oauth/v2/authorization'),
            'token_url' => env('SOCIAL_AUTH_LINKEDIN_TOKEN_URL', 'https://www.linkedin.com/oauth/v2/accessToken'),
            'userinfo_url' => env('SOCIAL_AUTH_LINKEDIN_USERINFO_URL', 'https://api.linkedin.com/v2/userinfo'),
            'scopes' => $socialAuthScopes('SOCIAL_AUTH_LINKEDIN_SCOPES', 'openid profile email'),
        ],
    ],
];
