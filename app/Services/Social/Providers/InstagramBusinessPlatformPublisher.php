<?php

namespace App\Services\Social\Providers;

use App\Models\SocialAccountConnection;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class InstagramBusinessPlatformPublisher extends AbstractOauthPlatformPublisher
{
    public function key(): string
    {
        return SocialAccountConnection::PLATFORM_INSTAGRAM;
    }

    public function label(): string
    {
        return 'Instagram Business';
    }

    protected function targetType(): string
    {
        return 'business_account';
    }

    protected function description(): string
    {
        return 'Publish promotional content to connected Instagram business profiles.';
    }

    protected function scopes(): array
    {
        return [
            'instagram_basic',
            'instagram_content_publish',
            'pages_show_list',
            'business_management',
        ];
    }

    public function refreshCredentials(array $credentials): array
    {
        $accessToken = trim((string) ($credentials['access_token'] ?? ''));
        if ($accessToken === '') {
            throw ValidationException::withMessages([
                'platform' => 'Instagram Business must be reconnected because no access token is available.',
            ]);
        }

        $response = Http::acceptJson()->get(
            (string) config('services.social.instagram.oauth.refresh_url', 'https://graph.instagram.com/refresh_access_token'),
            [
                'grant_type' => 'ig_refresh_token',
                'access_token' => $accessToken,
            ]
        );

        if (! $response->successful()) {
            throw ValidationException::withMessages([
                'platform' => $this->responseMessage($response, 'Instagram Business tokens could not be refreshed.'),
            ]);
        }

        $normalized = $this->normalizeOauthTokenPayload((array) ($response->json() ?? []), $credentials);

        return [
            'credentials' => $normalized['credentials'],
            'permissions' => $this->normalizePermissions((array) ($response->json() ?? [])),
            'status' => SocialAccountConnection::STATUS_CONNECTED,
            'token_expires_at' => $normalized['token_expires_at'],
            'metadata' => [
                ...((array) ($normalized['metadata'] ?? [])),
                'oauth_ready' => true,
            ],
            'message' => 'Instagram Business tokens refreshed.',
        ];
    }

    protected function defaultAuthorizeUrl(): string
    {
        return 'https://www.facebook.com/v23.0/dialog/oauth';
    }

    protected function defaultTokenUrl(): string
    {
        return 'https://graph.facebook.com/v23.0/oauth/access_token';
    }
}
