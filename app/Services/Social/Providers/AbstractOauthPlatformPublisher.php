<?php

namespace App\Services\Social\Providers;

use App\Models\SocialAccountConnection;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

abstract class AbstractOauthPlatformPublisher extends AbstractPlatformPublisher
{
    public function definition(): array
    {
        return [
            ...parent::definition(),
            'supports_redirect' => true,
            'supports_refresh' => $this->supportsRefresh(),
            'connect_button_label' => sprintf('Continue to %s', $this->label()),
            'reconnect_button_label' => sprintf('Reconnect %s', $this->label()),
            'setup_required' => $this->setupRequired(),
            'setup_message' => $this->setupMessage(),
        ];
    }

    public function beginAuthorization(SocialAccountConnection $connection, string $state): array
    {
        if ($this->setupRequired()) {
            throw ValidationException::withMessages([
                'platform' => $this->setupMessage() ?? sprintf('%s OAuth is not configured yet.', $this->label()),
            ]);
        }

        $authorization = $this->authorizationBootstrap($connection);
        $query = array_filter([
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'state' => $state,
            ...((array) ($authorization['query'] ?? [])),
        ], fn ($value) => ! in_array($value, [null, ''], true));

        $scopes = $this->scopes();
        if ($scopes !== []) {
            $query['scope'] = implode($this->scopeSeparator(), $scopes);
        }

        return [
            'redirect_url' => $this->authorizeUrl().'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986),
            'metadata' => (array) ($authorization['metadata'] ?? []),
        ];
    }

    public function completeAuthorization(SocialAccountConnection $connection, array $payload): array
    {
        $code = trim((string) ($payload['code'] ?? ''));
        if ($code === '') {
            throw ValidationException::withMessages([
                'platform' => sprintf('%s did not return an authorization code.', $this->label()),
            ]);
        }

        $tokenPayload = $this->tokenRequest(
            $this->authorizationCodePayload($connection, $code)
        );

        return $this->buildAuthorizationResult($connection, $tokenPayload);
    }

    public function refreshCredentials(array $credentials): array
    {
        if (! $this->supportsRefresh()) {
            throw ValidationException::withMessages([
                'platform' => sprintf('%s tokens cannot be refreshed automatically.', $this->label()),
            ]);
        }

        $refreshToken = trim((string) ($credentials['refresh_token'] ?? ''));
        if ($refreshToken === '') {
            throw ValidationException::withMessages([
                'platform' => sprintf('%s must be reconnected because no refresh token is available.', $this->label()),
            ]);
        }

        $tokenPayload = $this->tokenRequest(
            $this->refreshPayload($credentials)
        );

        return [
            ...$this->buildAuthorizationResult(new SocialAccountConnection([
                'credentials' => $credentials,
            ]), $tokenPayload),
            'message' => sprintf('%s tokens refreshed.', $this->label()),
        ];
    }

    protected function authorizationBootstrap(SocialAccountConnection $connection): array
    {
        return [
            'query' => [],
            'metadata' => [],
        ];
    }

    protected function authorizationCodePayload(SocialAccountConnection $connection, string $code): array
    {
        return [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
        ];
    }

    protected function refreshPayload(array $credentials): array
    {
        return [
            'grant_type' => 'refresh_token',
            'refresh_token' => trim((string) ($credentials['refresh_token'] ?? '')),
            'redirect_uri' => $this->redirectUri(),
        ];
    }

    /**
     * @param  array<string, mixed>  $requestPayload
     * @return array<string, mixed>
     */
    protected function tokenRequest(array $requestPayload, ?string $url = null): array
    {
        $request = Http::asForm()->acceptJson();
        if ($this->usesBasicAuthForTokenRequests() && $this->clientSecret() !== '') {
            $request = $request->withBasicAuth($this->clientId(), $this->clientSecret());
        }

        $response = $request->post($url ?: $this->tokenUrl(), $this->tokenRequestData($requestPayload));
        if (! $response->successful()) {
            throw ValidationException::withMessages([
                'platform' => $this->responseMessage($response, sprintf('%s rejected the token request.', $this->label())),
            ]);
        }

        return (array) ($response->json() ?? []);
    }

    /**
     * @param  array<string, mixed>  $requestPayload
     * @return array<string, mixed>
     */
    protected function tokenRequestData(array $requestPayload): array
    {
        return array_filter([
            'client_id' => $this->usesBasicAuthForTokenRequests() ? null : $this->clientId(),
            'client_secret' => $this->usesBasicAuthForTokenRequests() ? null : $this->clientSecret(),
            ...$requestPayload,
        ], fn ($value) => ! in_array($value, [null, ''], true));
    }

    /**
     * @param  array<string, mixed>  $tokenPayload
     * @return array<string, mixed>
     */
    protected function buildAuthorizationResult(SocialAccountConnection $connection, array $tokenPayload): array
    {
        $normalized = $this->normalizeOauthTokenPayload($tokenPayload, (array) ($connection->credentials ?? []));
        $permissions = $this->normalizePermissions($tokenPayload);

        return [
            'credentials' => $normalized['credentials'],
            'permissions' => $permissions,
            'status' => SocialAccountConnection::STATUS_CONNECTED,
            'token_expires_at' => $normalized['token_expires_at'],
            'metadata' => [
                ...((array) ($normalized['metadata'] ?? [])),
                'oauth_ready' => true,
                'oauth_provider' => $this->key(),
                'granted_scopes' => $permissions,
                'oauth_code_verifier' => null,
            ],
            'message' => sprintf('%s connected.', $this->label()),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $existingCredentials
     * @return array{credentials: array<string, string>, token_expires_at: Carbon|null, metadata: array<string, mixed>}
     */
    protected function normalizeOauthTokenPayload(array $payload, array $existingCredentials = []): array
    {
        $accessToken = trim((string) ($payload['access_token'] ?? ''));
        if ($accessToken === '') {
            throw ValidationException::withMessages([
                'platform' => sprintf('%s did not return an access token.', $this->label()),
            ]);
        }

        $refreshToken = trim((string) ($payload['refresh_token'] ?? ($existingCredentials['refresh_token'] ?? '')));
        $tokenType = trim((string) ($payload['token_type'] ?? ($existingCredentials['token_type'] ?? 'Bearer')));
        $scope = trim((string) ($payload['scope'] ?? ($existingCredentials['scope'] ?? '')));
        $expiresIn = max(0, (int) ($payload['expires_in'] ?? 0));

        return [
            'credentials' => array_filter([
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken !== '' ? $refreshToken : null,
                'token_type' => $tokenType !== '' ? $tokenType : 'Bearer',
                'scope' => $scope !== '' ? $scope : null,
            ], fn ($value) => $value !== null && $value !== ''),
            'token_expires_at' => $expiresIn > 0 ? Carbon::now()->addSeconds($expiresIn) : null,
            'metadata' => array_filter([
                'oauth_scope' => $scope !== '' ? $scope : null,
                'oauth_expires_in' => $expiresIn > 0 ? $expiresIn : null,
            ], fn ($value) => $value !== null && $value !== ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    protected function normalizePermissions(array $payload): array
    {
        $scope = trim((string) ($payload['scope'] ?? ''));
        if ($scope === '') {
            return array_values($this->scopes());
        }

        return collect(preg_split('/[\s,]+/', $scope) ?: [])
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn (string $value) => $value !== '')
            ->unique()
            ->values()
            ->all();
    }

    protected function responseMessage(Response $response, string $fallback): string
    {
        $candidates = [
            data_get($response->json(), 'message'),
            data_get($response->json(), 'error_description'),
            data_get($response->json(), 'error.message'),
            data_get($response->json(), 'error'),
            $fallback,
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return $fallback;
    }

    protected function setupRequired(): bool
    {
        if ($this->clientId() === '' || $this->authorizeUrl() === '' || $this->tokenUrl() === '') {
            return true;
        }

        return $this->requiresClientSecret() && $this->clientSecret() === '';
    }

    protected function setupMessage(): ?string
    {
        if (! $this->setupRequired()) {
            return null;
        }

        return sprintf('Configure the OAuth credentials for %s before connecting this platform.', $this->label());
    }

    protected function supportsRefresh(): bool
    {
        return true;
    }

    protected function requiresClientSecret(): bool
    {
        return true;
    }

    protected function usesBasicAuthForTokenRequests(): bool
    {
        return false;
    }

    protected function scopeSeparator(): string
    {
        return ' ';
    }

    protected function authorizeUrl(): string
    {
        return trim((string) $this->oauthConfig('authorize_url', $this->defaultAuthorizeUrl()));
    }

    protected function tokenUrl(): string
    {
        return trim((string) $this->oauthConfig('token_url', $this->defaultTokenUrl()));
    }

    protected function redirectUri(): string
    {
        $configured = trim((string) $this->oauthConfig('redirect_uri', ''));

        return $configured !== ''
            ? $configured
            : route('social.accounts.oauth.callback', ['platform' => $this->key()]);
    }

    protected function clientId(): string
    {
        return trim((string) $this->oauthConfig('client_id', ''));
    }

    protected function clientSecret(): string
    {
        return trim((string) $this->oauthConfig('client_secret', ''));
    }

    protected function oauthConfig(string $key, mixed $default = null): mixed
    {
        return config(sprintf('services.social.%s.oauth.%s', $this->key(), $key), $default);
    }

    abstract protected function defaultAuthorizeUrl(): string;

    abstract protected function defaultTokenUrl(): string;

    protected function pkceCodeVerifier(): string
    {
        return Str::random(96);
    }

    protected function pkceCodeChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
