<?php

namespace App\Services\Social\Providers;

use App\Models\SocialAccountConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class FacebookPagePlatformPublisher extends AbstractOauthPlatformPublisher
{
    public function key(): string
    {
        return SocialAccountConnection::PLATFORM_FACEBOOK;
    }

    public function label(): string
    {
        return 'Facebook Pages';
    }

    protected function targetType(): string
    {
        return 'page';
    }

    protected function description(): string
    {
        return 'Publish business offers and campaign updates to connected Facebook pages.';
    }

    protected function scopes(): array
    {
        return [
            'pages_show_list',
            'pages_read_engagement',
            'pages_manage_posts',
        ];
    }

    public function refreshCredentials(array $credentials): array
    {
        $accessToken = trim((string) ($credentials['access_token'] ?? ''));
        if ($accessToken === '') {
            throw ValidationException::withMessages([
                'platform' => 'Facebook Pages must be reconnected because no access token is available.',
            ]);
        }

        $response = Http::acceptJson()->get(
            (string) config('services.social.facebook.oauth.refresh_url', $this->defaultTokenUrl()),
            array_filter([
                'grant_type' => 'fb_exchange_token',
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'fb_exchange_token' => $accessToken,
            ], fn ($value) => $value !== null && $value !== '')
        );

        if (! $response->successful()) {
            throw ValidationException::withMessages([
                'platform' => $this->responseMessage($response, 'Facebook Pages tokens could not be refreshed.'),
            ]);
        }

        $normalized = $this->normalizeOauthTokenPayload((array) ($response->json() ?? []), $credentials);

        return [
            'credentials' => $normalized['credentials'],
            'permissions' => $this->normalizePermissions((array) ($response->json() ?? [])),
            'status' => SocialAccountConnection::STATUS_CONNECTED,
            'token_expires_at' => $normalized['token_expires_at'] ?? Carbon::now()->addDays(60),
            'metadata' => [
                ...((array) ($normalized['metadata'] ?? [])),
                'oauth_ready' => true,
            ],
            'message' => 'Facebook Pages tokens refreshed.',
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
