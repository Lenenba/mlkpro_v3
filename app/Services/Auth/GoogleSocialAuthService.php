<?php

namespace App\Services\Auth;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GoogleSocialAuthService
{
    public function __construct(
        private readonly HttpFactory $http,
    ) {}

    /**
     * @param  array<string, mixed>  $provider
     */
    public function authorizationUrl(array $provider, string $state): string
    {
        $authorizeUrl = trim((string) ($provider['authorize_url'] ?? ''));
        $clientId = trim((string) ($provider['client_id'] ?? ''));
        $redirectUri = trim((string) ($provider['redirect_uri'] ?? ''));
        $scopes = array_values(array_filter((array) ($provider['scopes'] ?? [])));

        if ($authorizeUrl === '' || $clientId === '' || $redirectUri === '' || $scopes === []) {
            throw ValidationException::withMessages([
                'provider' => __('ui.auth.social.provider_not_configured', ['provider' => 'Google']),
            ]);
        }

        return $authorizeUrl.'?'.Arr::query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', $scopes),
            'state' => $state,
        ]);
    }

    /**
     * @param  array<string, mixed>  $provider
     * @param  array<string, mixed>  $pending
     * @return array{profile: array<string, mixed>, tokens: array<string, mixed>}
     *
     * @throws ValidationException|ConnectionException
     */
    public function authenticate(Request $request, array $provider, array $pending): array
    {
        $expectedState = trim((string) ($pending['state'] ?? ''));
        $state = trim((string) $request->query('state', ''));

        if ($expectedState === '' || $state === '' || ! hash_equals($expectedState, $state)) {
            throw ValidationException::withMessages([
                'provider' => __('ui.auth.social.invalid_state'),
            ]);
        }

        $providerError = trim((string) (
            $request->query('error_description')
                ?: $request->query('error_message')
                ?: $request->query('error')
                ?: ''
        ));

        if ($providerError !== '') {
            throw ValidationException::withMessages([
                'provider' => $providerError,
            ]);
        }

        $code = trim((string) $request->query('code', ''));
        if ($code === '') {
            throw ValidationException::withMessages([
                'provider' => __('ui.auth.social.missing_code', ['provider' => 'Google']),
            ]);
        }

        $tokenResponse = $this->http
            ->asForm()
            ->acceptJson()
            ->timeout(15)
            ->post((string) ($provider['token_url'] ?? ''), [
                'code' => $code,
                'client_id' => (string) ($provider['client_id'] ?? ''),
                'client_secret' => (string) ($provider['client_secret'] ?? ''),
                'redirect_uri' => (string) ($provider['redirect_uri'] ?? ''),
                'grant_type' => 'authorization_code',
            ]);

        if (! $tokenResponse->successful()) {
            throw ValidationException::withMessages([
                'provider' => $this->providerMessage(
                    $tokenResponse->json(),
                    __('ui.auth.social.token_exchange_failed', ['provider' => 'Google'])
                ),
            ]);
        }

        $tokenPayload = $tokenResponse->json();
        $accessToken = trim((string) data_get($tokenPayload, 'access_token', ''));

        if ($accessToken === '') {
            throw ValidationException::withMessages([
                'provider' => __('ui.auth.social.token_exchange_failed', ['provider' => 'Google']),
            ]);
        }

        $profileResponse = $this->http
            ->withToken($accessToken)
            ->acceptJson()
            ->timeout(15)
            ->get((string) ($provider['userinfo_url'] ?? ''));

        if (! $profileResponse->successful()) {
            throw ValidationException::withMessages([
                'provider' => $this->providerMessage(
                    $profileResponse->json(),
                    __('ui.auth.social.profile_fetch_failed', ['provider' => 'Google'])
                ),
            ]);
        }

        $profilePayload = $profileResponse->json();
        $providerUserId = trim((string) (
            data_get($profilePayload, 'sub')
                ?: data_get($profilePayload, 'id')
                ?: ''
        ));

        $providerEmail = strtolower(trim((string) data_get($profilePayload, 'email', '')));
        $emailVerified = filter_var(data_get($profilePayload, 'email_verified', false), FILTER_VALIDATE_BOOL);

        if ($providerUserId === '' || $providerEmail === '') {
            throw ValidationException::withMessages([
                'provider' => __('ui.auth.social.profile_incomplete', ['provider' => 'Google']),
            ]);
        }

        return [
            'profile' => [
                'provider_user_id' => $providerUserId,
                'provider_email' => $providerEmail,
                'provider_email_verified' => (bool) $emailVerified,
                'provider_name' => $this->nullableString(data_get($profilePayload, 'name')),
                'provider_avatar_url' => $this->nullableString(data_get($profilePayload, 'picture')),
            ],
            'tokens' => [
                'access_token' => $accessToken,
                'refresh_token' => $this->nullableString(data_get($tokenPayload, 'refresh_token')),
                'id_token' => $this->nullableString(data_get($tokenPayload, 'id_token')),
                'token_type' => $this->nullableString(data_get($tokenPayload, 'token_type')),
                'granted_scopes' => $this->parseScopes((string) data_get($tokenPayload, 'scope', '')),
                'token_expires_at' => $this->tokenExpiresAt(data_get($tokenPayload, 'expires_in')),
            ],
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $resolved = trim((string) $value);

        return $resolved !== '' ? $resolved : null;
    }

    private function tokenExpiresAt(mixed $expiresIn): ?Carbon
    {
        if (! is_numeric($expiresIn)) {
            return null;
        }

        $seconds = (int) $expiresIn;

        return $seconds > 0 ? now()->addSeconds($seconds) : null;
    }

    /**
     * @return array<int, string>
     */
    private function parseScopes(string $rawScopes): array
    {
        return array_values(array_filter(array_map(
            static fn (string $scope): string => trim($scope),
            preg_split('/[\s,]+/', trim($rawScopes)) ?: []
        )));
    }

    /**
     * @param  array<string, mixed>|mixed  $payload
     */
    private function providerMessage(mixed $payload, string $fallback): string
    {
        if (! is_array($payload)) {
            return $fallback;
        }

        $message = data_get($payload, 'error_description')
            ?: data_get($payload, 'error.message')
            ?: data_get($payload, 'error')
            ?: data_get($payload, 'message');

        $resolved = trim((string) $message);

        return $resolved !== '' ? Str::limit($resolved, 200, '') : $fallback;
    }
}
