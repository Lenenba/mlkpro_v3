<?php

namespace App\Services\Social\Providers;

use App\Models\SocialAccountConnection;
use Illuminate\Validation\ValidationException;

class XProfilePlatformPublisher extends AbstractOauthPlatformPublisher
{
    public function key(): string
    {
        return SocialAccountConnection::PLATFORM_X;
    }

    public function label(): string
    {
        return 'X Profiles';
    }

    protected function targetType(): string
    {
        return 'profile';
    }

    protected function description(): string
    {
        return 'Broadcast short offer updates and launch messages to connected X profiles.';
    }

    protected function scopes(): array
    {
        return [
            'tweet.read',
            'tweet.write',
            'users.read',
            'offline.access',
        ];
    }

    protected function authorizationBootstrap(SocialAccountConnection $connection): array
    {
        $verifier = $this->pkceCodeVerifier();

        return [
            'query' => [
                'code_challenge' => $this->pkceCodeChallenge($verifier),
                'code_challenge_method' => 'S256',
            ],
            'metadata' => [
                'oauth_code_verifier' => $verifier,
            ],
        ];
    }

    protected function authorizationCodePayload(SocialAccountConnection $connection, string $code): array
    {
        $verifier = trim((string) (($connection->metadata['oauth_code_verifier'] ?? null)));
        if ($verifier === '') {
            throw ValidationException::withMessages([
                'platform' => 'X must restart the OAuth redirect because its PKCE verifier is missing.',
            ]);
        }

        return [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
            'code_verifier' => $verifier,
        ];
    }

    protected function requiresClientSecret(): bool
    {
        return false;
    }

    protected function usesBasicAuthForTokenRequests(): bool
    {
        return $this->clientSecret() !== '';
    }

    protected function defaultAuthorizeUrl(): string
    {
        return 'https://x.com/i/oauth2/authorize';
    }

    protected function defaultTokenUrl(): string
    {
        return 'https://api.x.com/2/oauth2/token';
    }
}
