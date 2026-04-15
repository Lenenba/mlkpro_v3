<?php

namespace App\Services\Campaigns\Providers;

use App\Models\CampaignProspectProviderConnection;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

abstract class AbstractOauthProspectProviderAdapter extends AbstractApiKeyProspectProviderAdapter
{
    public function authStrategy(): string
    {
        return CampaignProspectProviderConnection::AUTH_METHOD_OAUTH;
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => $this->key(),
            'label' => $this->label(),
            'logo_key' => $this->key(),
            'auth_strategy' => $this->authStrategy(),
            'short_description' => $this->shortDescription(),
            'connect_description' => $this->connectDescription(),
            'credential_fields' => [],
            'supports_redirect' => true,
            'supports_manual_credentials' => false,
            'supports_refresh' => true,
            'scopes' => $this->scopes(),
            'connect_button_label' => sprintf('Continue to %s', $this->label()),
            'reconnect_button_label' => sprintf('Reconnect %s', $this->label()),
            'setup_required' => $this->setupRequired(),
            'setup_message' => $this->setupMessage(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function credentialFields(): array
    {
        return [];
    }

    /**
     * @return array<int, string>
     */
    public function scopes(): array
    {
        return [];
    }

    public function setupRequired(): bool
    {
        return false;
    }

    public function setupMessage(): ?string
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array{ok: bool, status: string, message: string, errors?: array<string, string>}
     */
    public function validateCredentials(array $credentials): array
    {
        if (trim((string) ($credentials['access_token'] ?? '')) === '') {
            return [
                'ok' => false,
                'status' => CampaignProspectProviderConnection::STATUS_RECONNECT_REQUIRED,
                'message' => sprintf('%s must be reconnected before it can be used.', $this->label()),
            ];
        }

        return [
            'ok' => true,
            'status' => CampaignProspectProviderConnection::STATUS_CONNECTED,
            'message' => sprintf('%s connection validated.', $this->label()),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function completeAuthorization(array $payload): array
    {
        throw ValidationException::withMessages([
            'provider' => sprintf('%s callback handling is not implemented.', $this->label()),
        ]);
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    public function refreshCredentials(array $credentials): array
    {
        throw ValidationException::withMessages([
            'provider' => sprintf('%s token refresh is not implemented.', $this->label()),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function normalizeOauthTokenPayload(array $payload): array
    {
        $accessToken = trim((string) ($payload['access_token'] ?? ''));
        $refreshToken = trim((string) ($payload['refresh_token'] ?? ''));
        $tokenType = trim((string) ($payload['token_type'] ?? 'Bearer'));
        $scope = trim((string) ($payload['scope'] ?? ''));
        $expiresIn = max(0, (int) ($payload['expires_in'] ?? 0));

        if ($accessToken === '') {
            throw ValidationException::withMessages([
                'provider' => sprintf('%s did not return an access token.', $this->label()),
            ]);
        }

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
     * @return array{external_account_id: string|null, external_account_label: string|null, metadata: array<string, mixed>}
     */
    protected function normalizeExternalAccount(array $profile): array
    {
        $accountId = $this->firstNonEmptyString([
            Arr::get($profile, 'user.id'),
            Arr::get($profile, 'person.id'),
            Arr::get($profile, 'id'),
        ]);
        $email = $this->firstNonEmptyString([
            Arr::get($profile, 'user.email'),
            Arr::get($profile, 'email'),
        ]);
        $fullName = $this->firstNonEmptyString([
            Arr::get($profile, 'user.name'),
            Arr::get($profile, 'user.full_name'),
            Arr::get($profile, 'name'),
            trim(sprintf(
                '%s %s',
                (string) Arr::get($profile, 'user.first_name', Arr::get($profile, 'first_name', '')),
                (string) Arr::get($profile, 'user.last_name', Arr::get($profile, 'last_name', ''))
            )),
        ]);

        return [
            'external_account_id' => $accountId !== '' ? $accountId : null,
            'external_account_label' => $email !== ''
                ? ($fullName !== '' ? sprintf('%s (%s)', $fullName, $email) : $email)
                : ($fullName !== '' ? $fullName : null),
            'metadata' => array_filter([
                'external_account_email' => $email !== '' ? Str::lower($email) : null,
                'external_account_name' => $fullName !== '' ? $fullName : null,
            ], fn ($value) => $value !== null && $value !== ''),
        ];
    }

    protected function responseMessage(Response $response, string $fallback): string
    {
        return $this->firstNonEmptyString([
            Arr::get($response->json(), 'message'),
            Arr::get($response->json(), 'error_description'),
            Arr::get($response->json(), 'error'),
            Arr::get($response->json(), 'errors.0.message'),
            Arr::get($response->json(), 'errors.message'),
            $fallback,
        ]);
    }

    /**
     * @param  array<int, mixed>  $candidates
     */
    protected function firstNonEmptyString(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
