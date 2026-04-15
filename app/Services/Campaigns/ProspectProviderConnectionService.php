<?php

namespace App\Services\Campaigns;

use App\Models\CampaignProspectProviderConnection;
use App\Models\User;
use App\Services\Campaigns\Providers\Contracts\ProspectProviderAdapter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProspectProviderConnectionService
{
    public function __construct(
        private readonly ProspectProviderRegistry $registry,
    ) {}

    /**
     * @return Collection<int, CampaignProspectProviderConnection>
     */
    public function listForOwner(User $owner): Collection
    {
        return CampaignProspectProviderConnection::query()
            ->where('user_id', $owner->id)
            ->orderBy('provider_key')
            ->orderByDesc('connected_at')
            ->orderByDesc('updated_at')
            ->get();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPayloads(User $owner): array
    {
        return $this->listForOwner($owner)
            ->map(fn (CampaignProspectProviderConnection $connection) => $this->payload($connection))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function cardsPayloads(User $owner): array
    {
        return collect($this->registry->definitions())
            ->map(function (array $definition) use ($owner): array {
                $connection = $this->connectionForProvider($owner, (string) ($definition['key'] ?? ''));
                $displayStatus = $connection
                    ? (string) $connection->status
                    : ((bool) ($definition['setup_required'] ?? false) ? 'setup_required' : 'not_connected');

                return [
                    ...$definition,
                    'connection' => $connection ? $this->payload($connection) : null,
                    'display_status' => $displayStatus,
                    'needs_attention' => in_array($displayStatus, [
                        'setup_required',
                        'pending',
                        'draft',
                        'error',
                        'reconnect_required',
                        'invalid',
                        'expired',
                        'rate_limited',
                    ], true),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function availablePayloadsForAudience(User $owner): array
    {
        return $this->listForOwner($owner)
            ->filter(fn (CampaignProspectProviderConnection $connection) => $connection->is_active
                && $connection->status === CampaignProspectProviderConnection::STATUS_CONNECTED)
            ->map(fn (CampaignProspectProviderConnection $connection) => $this->payload($connection))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function summaryForOwner(User $owner): array
    {
        $connections = $this->listForOwner($owner);
        $statusCounts = collect(CampaignProspectProviderConnection::allowedStatuses())
            ->mapWithKeys(fn (string $status) => [$status => 0])
            ->all();

        foreach ($connections as $connection) {
            $status = (string) $connection->status;
            if (! array_key_exists($status, $statusCounts)) {
                $statusCounts[$status] = 0;
            }

            $statusCounts[$status]++;
        }

        $connectedCount = (int) ($statusCounts[CampaignProspectProviderConnection::STATUS_CONNECTED] ?? 0);
        $attentionCount = collect([
            CampaignProspectProviderConnection::STATUS_PENDING,
            CampaignProspectProviderConnection::STATUS_DRAFT,
            CampaignProspectProviderConnection::STATUS_ERROR,
            CampaignProspectProviderConnection::STATUS_RECONNECT_REQUIRED,
            CampaignProspectProviderConnection::STATUS_INVALID,
            CampaignProspectProviderConnection::STATUS_EXPIRED,
            CampaignProspectProviderConnection::STATUS_RATE_LIMITED,
        ])->sum(fn (string $status) => (int) ($statusCounts[$status] ?? 0));

        return [
            'configured' => $connections->count(),
            'connected' => $connectedCount,
            'attention' => $attentionCount,
            'available_provider_keys' => $connections
                ->filter(fn (CampaignProspectProviderConnection $connection) => $connection->is_active
                    && $connection->status === CampaignProspectProviderConnection::STATUS_CONNECTED)
                ->pluck('provider_key')
                ->unique()
                ->values()
                ->all(),
            'status_counts' => $statusCounts,
            'last_validated_at' => $connections
                ->pluck('last_validated_at')
                ->filter()
                ->max()?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function connect(User $owner, array $payload): array
    {
        $providerKey = strtolower(trim((string) ($payload['provider_key'] ?? '')));
        $adapter = $this->registry->adapter($providerKey);
        $connection = $this->resolveConnectionTarget($owner, $providerKey, $payload['connection_id'] ?? null);
        $creating = ! $connection->exists;

        $label = trim((string) ($payload['label'] ?? $connection->label ?: $this->defaultLabel($adapter)));
        if ($label === '') {
            $label = $this->defaultLabel($adapter);
        }

        if ($adapter->authStrategy() === CampaignProspectProviderConnection::AUTH_METHOD_OAUTH) {
            $state = Str::random(64);

            $connection->forceFill([
                'user_id' => $owner->id,
                'provider_key' => $providerKey,
                'label' => $label,
                'auth_method' => CampaignProspectProviderConnection::AUTH_METHOD_OAUTH,
                'status' => CampaignProspectProviderConnection::STATUS_PENDING,
                'is_active' => false,
                'oauth_state' => $state,
                'oauth_state_expires_at' => Carbon::now()->addMinutes(15),
                'last_error' => null,
                'metadata' => $this->mergedMetadata($connection, $adapter, [
                    'connection_flow' => 'oauth',
                ]),
            ])->save();

            return [
                'created' => $creating,
                'flow' => 'redirect',
                'message' => sprintf('Continue with %s to finish connecting this account.', $adapter->label()),
                'redirect_url' => $adapter->beginAuthorization($connection, $state),
                'provider_connection' => $this->payload($connection->fresh()),
            ];
        }

        $credentials = $this->normalizeCredentials(
            $adapter->credentialFields(),
            (array) ($payload['credentials'] ?? []),
            ! $this->hasSavedCredentials($connection),
            (array) ($connection->credentials ?? [])
        );

        $result = $adapter->validateCredentials($credentials);
        $now = Carbon::now();

        $connection->forceFill([
            'user_id' => $owner->id,
            'provider_key' => $providerKey,
            'label' => $label,
            'auth_method' => CampaignProspectProviderConnection::AUTH_METHOD_API_KEY,
            'credentials' => $credentials,
            'status' => (string) ($result['status'] ?? CampaignProspectProviderConnection::STATUS_ERROR),
            'is_active' => (bool) ($result['ok'] ?? false),
            'last_validated_at' => $now,
            'connected_at' => ($result['ok'] ?? false) ? ($connection->connected_at ?? $now) : null,
            'last_refreshed_at' => $now,
            'token_expires_at' => null,
            'oauth_state' => null,
            'oauth_state_expires_at' => null,
            'external_account_id' => null,
            'external_account_label' => null,
            'last_error' => ($result['ok'] ?? false) ? null : (string) ($result['message'] ?? 'Connection failed.'),
            'metadata' => $this->mergedMetadata($connection, $adapter, [
                'connection_flow' => 'manual_credentials',
            ]),
        ])->save();

        return [
            'created' => $creating,
            'flow' => 'manual',
            'message' => (string) ($result['message'] ?? ($result['ok'] ?? false ? 'Connection validated.' : 'Connection needs attention.')),
            'provider_connection' => $this->payload($connection->fresh()),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(User $owner, CampaignProspectProviderConnection $connection, array $payload): CampaignProspectProviderConnection
    {
        $this->assertOwnership($owner, $connection);

        $providerKey = strtolower(trim((string) ($payload['provider_key'] ?? $connection->provider_key)));
        $adapter = $this->registry->adapter($providerKey);
        $credentials = $this->normalizeCredentials(
            $adapter->credentialFields(),
            (array) ($payload['credentials'] ?? []),
            false,
            (array) ($connection->credentials ?? [])
        );

        $status = $connection->status;
        $isActive = (bool) $connection->is_active;
        if ((array) ($payload['credentials'] ?? []) !== [] && $adapter->authStrategy() === CampaignProspectProviderConnection::AUTH_METHOD_API_KEY) {
            $status = CampaignProspectProviderConnection::STATUS_DRAFT;
            $isActive = false;
        }

        $connection->forceFill([
            'provider_key' => $providerKey,
            'label' => trim((string) ($payload['label'] ?? $connection->label)) ?: $this->defaultLabel($adapter),
            'auth_method' => $adapter->authStrategy(),
            'credentials' => $credentials,
            'status' => $status,
            'is_active' => $isActive,
            'metadata' => $this->mergedMetadata($connection, $adapter),
        ])->save();

        return $connection->fresh();
    }

    public function validateConnection(User $owner, CampaignProspectProviderConnection $connection): CampaignProspectProviderConnection
    {
        return $this->refreshConnection($owner, $connection);
    }

    public function refreshConnection(User $owner, CampaignProspectProviderConnection $connection): CampaignProspectProviderConnection
    {
        $this->assertOwnership($owner, $connection);

        $adapter = $this->registry->adapter($connection->provider_key);
        $now = Carbon::now();

        try {
            $result = $adapter->refreshCredentials((array) ($connection->credentials ?? []));
        } catch (ValidationException $exception) {
            $message = $this->validationMessage($exception, 'Reconnect this provider to continue.');
            $status = $this->statusFromRefreshFailureMessage($message);

            $connection->forceFill([
                'status' => $status,
                'is_active' => false,
                'last_validated_at' => $now,
                'last_refreshed_at' => $now,
                'last_error' => $message,
                'oauth_state' => null,
                'oauth_state_expires_at' => null,
            ])->save();

            return $connection->fresh();
        } catch (ConnectionException $exception) {
            $connection->forceFill([
                'status' => CampaignProspectProviderConnection::STATUS_ERROR,
                'is_active' => false,
                'last_validated_at' => $now,
                'last_refreshed_at' => $now,
                'last_error' => 'The provider could not be reached while refreshing this connection.',
            ])->save();

            return $connection->fresh();
        }

        $status = (string) ($result['status'] ?? CampaignProspectProviderConnection::STATUS_CONNECTED);
        $ok = (bool) ($result['ok'] ?? $status === CampaignProspectProviderConnection::STATUS_CONNECTED);
        $credentials = array_key_exists('credentials', $result)
            ? (array) ($result['credentials'] ?? [])
            : (array) ($connection->credentials ?? []);

        $connection->forceFill([
            'auth_method' => $adapter->authStrategy(),
            'credentials' => $credentials,
            'status' => $status,
            'is_active' => $ok,
            'last_validated_at' => $now,
            'last_refreshed_at' => $now,
            'connected_at' => $ok ? ($connection->connected_at ?? $now) : null,
            'token_expires_at' => $result['token_expires_at'] ?? $connection->token_expires_at,
            'external_account_id' => $result['external_account_id'] ?? $connection->external_account_id,
            'external_account_label' => $result['external_account_label'] ?? $connection->external_account_label,
            'last_error' => $ok ? null : (string) ($result['message'] ?? 'Connection refresh failed.'),
            'metadata' => $this->mergedMetadata($connection, $adapter, (array) ($result['metadata'] ?? [])),
        ])->save();

        return $connection->fresh();
    }

    public function disconnect(User $owner, CampaignProspectProviderConnection $connection): CampaignProspectProviderConnection
    {
        $this->assertOwnership($owner, $connection);

        $adapter = $this->registry->adapter($connection->provider_key);

        $connection->forceFill([
            'auth_method' => $adapter->authStrategy(),
            'credentials' => [],
            'status' => CampaignProspectProviderConnection::STATUS_DISCONNECTED,
            'is_active' => false,
            'connected_at' => null,
            'last_refreshed_at' => null,
            'token_expires_at' => null,
            'oauth_state' => null,
            'oauth_state_expires_at' => null,
            'external_account_id' => null,
            'external_account_label' => null,
            'last_error' => null,
            'metadata' => $this->mergedMetadata($connection, $adapter),
        ])->save();

        return $connection->fresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function completeAuthorization(string $providerKey, array $payload): array
    {
        $adapter = $this->registry->adapter($providerKey);
        $state = trim((string) ($payload['state'] ?? ''));

        if ($adapter->authStrategy() !== CampaignProspectProviderConnection::AUTH_METHOD_OAUTH) {
            abort(404);
        }

        if ($state === '') {
            throw ValidationException::withMessages([
                'state' => 'The provider callback is missing its security state token.',
            ]);
        }

        $connection = CampaignProspectProviderConnection::query()
            ->where('provider_key', $providerKey)
            ->where('oauth_state', $state)
            ->first();

        if (! $connection) {
            throw ValidationException::withMessages([
                'state' => 'This provider callback is no longer valid. Start the connection again.',
            ]);
        }

        if ($connection->oauth_state_expires_at && $connection->oauth_state_expires_at->isPast()) {
            $connection->forceFill([
                'status' => CampaignProspectProviderConnection::STATUS_RECONNECT_REQUIRED,
                'is_active' => false,
                'oauth_state' => null,
                'oauth_state_expires_at' => null,
                'last_error' => 'The connection request expired before the provider finished authorizing it.',
            ])->save();

            return [
                'success' => false,
                'message' => 'The connection request expired before the provider finished authorizing it.',
                'provider_connection' => $this->payload($connection->fresh()),
            ];
        }

        $providerErrorMessage = $this->providerCallbackErrorMessage($payload);
        if ($providerErrorMessage !== '') {
            $connection->forceFill([
                'status' => CampaignProspectProviderConnection::STATUS_RECONNECT_REQUIRED,
                'is_active' => false,
                'oauth_state' => null,
                'oauth_state_expires_at' => null,
                'last_error' => $providerErrorMessage,
            ])->save();

            return [
                'success' => false,
                'message' => $providerErrorMessage,
                'provider_connection' => $this->payload($connection->fresh()),
            ];
        }

        try {
            $result = $adapter->completeAuthorization($payload);
            $message = (string) ($result['message'] ?? sprintf('%s connected.', $adapter->label()));
        } catch (ValidationException $exception) {
            $message = $this->validationMessage($exception, sprintf('%s could not be connected.', $adapter->label()));

            $connection->forceFill([
                'status' => CampaignProspectProviderConnection::STATUS_RECONNECT_REQUIRED,
                'is_active' => false,
                'oauth_state' => null,
                'oauth_state_expires_at' => null,
                'last_error' => $message,
            ])->save();

            return [
                'success' => false,
                'message' => $message,
                'provider_connection' => $this->payload($connection->fresh()),
            ];
        } catch (ConnectionException $exception) {
            $message = 'The provider could not be reached while finishing the connection.';

            $connection->forceFill([
                'status' => CampaignProspectProviderConnection::STATUS_ERROR,
                'is_active' => false,
                'oauth_state' => null,
                'oauth_state_expires_at' => null,
                'last_error' => $message,
            ])->save();

            return [
                'success' => false,
                'message' => $message,
                'provider_connection' => $this->payload($connection->fresh()),
            ];
        }

        $now = Carbon::now();

        $connection->forceFill([
            'auth_method' => CampaignProspectProviderConnection::AUTH_METHOD_OAUTH,
            'credentials' => (array) ($result['credentials'] ?? []),
            'status' => (string) ($result['status'] ?? CampaignProspectProviderConnection::STATUS_CONNECTED),
            'is_active' => true,
            'last_validated_at' => $now,
            'connected_at' => $connection->connected_at ?? $now,
            'last_refreshed_at' => $now,
            'token_expires_at' => $result['token_expires_at'] ?? null,
            'oauth_state' => null,
            'oauth_state_expires_at' => null,
            'external_account_id' => $result['external_account_id'] ?? null,
            'external_account_label' => $result['external_account_label'] ?? null,
            'last_error' => null,
            'metadata' => $this->mergedMetadata($connection, $adapter, (array) ($result['metadata'] ?? [])),
        ])->save();

        return [
            'success' => true,
            'message' => $message,
            'provider_connection' => $this->payload($connection->fresh()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(CampaignProspectProviderConnection $connection): array
    {
        $adapter = $this->registry->adapter($connection->provider_key);
        $definition = $adapter->definition();
        $credentials = (array) ($connection->credentials ?? []);
        $status = (string) $connection->status;

        return [
            'id' => $connection->id,
            'provider_key' => $connection->provider_key,
            'provider_label' => $adapter->label(),
            'label' => $connection->label,
            'auth_method' => $connection->auth_method ?: $adapter->authStrategy(),
            'auth_strategy' => $adapter->authStrategy(),
            'status' => $status,
            'is_active' => (bool) $connection->is_active,
            'is_connected' => $connection->is_active && $status === CampaignProspectProviderConnection::STATUS_CONNECTED,
            'needs_attention' => $this->statusNeedsAttention($status),
            'has_credentials' => $credentials !== [],
            'has_refresh_token' => trim((string) ($credentials['refresh_token'] ?? '')) !== '',
            'credential_keys' => array_keys($credentials),
            'credential_fields' => $definition['credential_fields'] ?? [],
            'short_description' => $definition['short_description'] ?? null,
            'connect_description' => $definition['connect_description'] ?? null,
            'logo_key' => $definition['logo_key'] ?? $connection->provider_key,
            'supports_redirect' => (bool) ($definition['supports_redirect'] ?? false),
            'supports_manual_credentials' => (bool) ($definition['supports_manual_credentials'] ?? false),
            'supports_refresh' => (bool) ($definition['supports_refresh'] ?? false),
            'setup_required' => (bool) ($definition['setup_required'] ?? false),
            'setup_message' => $definition['setup_message'] ?? null,
            'scopes' => $definition['scopes'] ?? [],
            'connected_at' => optional($connection->connected_at)->toIso8601String(),
            'last_validated_at' => optional($connection->last_validated_at)->toIso8601String(),
            'last_refreshed_at' => optional($connection->last_refreshed_at)->toIso8601String(),
            'token_expires_at' => optional($connection->token_expires_at)->toIso8601String(),
            'external_account_id' => $connection->external_account_id,
            'external_account_label' => $connection->external_account_label,
            'last_error' => $connection->last_error,
            'metadata' => (array) ($connection->metadata ?? []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function runtimeCredentials(User $owner, CampaignProspectProviderConnection $connection): array
    {
        $this->assertOwnership($owner, $connection);

        if (! $connection->is_active || $connection->status !== CampaignProspectProviderConnection::STATUS_CONNECTED) {
            throw ValidationException::withMessages([
                'provider_connection_id' => 'The selected provider must be connected before previewing prospects.',
            ]);
        }

        if ($connection->auth_method === CampaignProspectProviderConnection::AUTH_METHOD_OAUTH
            && $connection->token_expires_at
            && $connection->token_expires_at->lte(Carbon::now()->addMinutes(2))) {
            $connection = $this->refreshConnection($owner, $connection);
        }

        if (! $connection->is_active || $connection->status !== CampaignProspectProviderConnection::STATUS_CONNECTED) {
            throw ValidationException::withMessages([
                'provider_connection_id' => 'Reconnect this provider before using it again.',
            ]);
        }

        return (array) ($connection->credentials ?? []);
    }

    private function connectionForProvider(User $owner, string $providerKey): ?CampaignProspectProviderConnection
    {
        return CampaignProspectProviderConnection::query()
            ->where('user_id', $owner->id)
            ->where('provider_key', $providerKey)
            ->orderByDesc('is_active')
            ->orderByDesc('connected_at')
            ->orderByDesc('updated_at')
            ->first();
    }

    private function resolveConnectionTarget(User $owner, string $providerKey, mixed $connectionId): CampaignProspectProviderConnection
    {
        if ((int) $connectionId > 0) {
            $connection = CampaignProspectProviderConnection::query()->findOrFail((int) $connectionId);
            $this->assertOwnership($owner, $connection);

            return $connection;
        }

        return $this->connectionForProvider($owner, $providerKey)
            ?? new CampaignProspectProviderConnection;
    }

    private function hasSavedCredentials(CampaignProspectProviderConnection $connection): bool
    {
        return ((array) ($connection->credentials ?? [])) !== [];
    }

    private function defaultLabel(ProspectProviderAdapter $adapter): string
    {
        return $adapter->authStrategy() === CampaignProspectProviderConnection::AUTH_METHOD_OAUTH
            ? sprintf('%s workspace', $adapter->label())
            : sprintf('%s account', $adapter->label());
    }

    /**
     * @param  array<int, array<string, mixed>>  $fieldDefinitions
     * @param  array<string, mixed>  $incoming
     * @param  array<string, mixed>  $existing
     * @return array<string, string>
     */
    private function normalizeCredentials(
        array $fieldDefinitions,
        array $incoming,
        bool $requireAllFields,
        array $existing = []
    ): array {
        $normalized = $existing;
        $errors = [];

        foreach ($fieldDefinitions as $fieldDefinition) {
            $key = (string) ($fieldDefinition['key'] ?? '');
            if ($key === '') {
                continue;
            }

            $value = trim((string) ($incoming[$key] ?? ''));
            if ($value !== '') {
                $normalized[$key] = $value;
            }

            $required = (bool) ($fieldDefinition['required'] ?? false);
            if ($required && $requireAllFields && trim((string) ($normalized[$key] ?? '')) === '') {
                $errors['credentials.'.$key] = sprintf('%s is required.', (string) ($fieldDefinition['label'] ?? $key));
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return collect($normalized)
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn (string $value) => $value !== '')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function mergedMetadata(
        CampaignProspectProviderConnection $connection,
        ProspectProviderAdapter $adapter,
        array $extra = []
    ): array {
        return [
            ...((array) ($connection->metadata ?? [])),
            ...$extra,
            'provider_label' => $adapter->label(),
            'auth_strategy' => $adapter->authStrategy(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function providerCallbackErrorMessage(array $payload): string
    {
        return trim((string) (
            $payload['error_message']
                ?? $payload['error_description']
                ?? $payload['error']
                ?? ''
        ));
    }

    private function validationMessage(ValidationException $exception, string $fallback): string
    {
        $message = collect($exception->errors())
            ->flatten()
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->map(fn ($value) => trim((string) $value))
            ->first();

        return is_string($message) && $message !== '' ? $message : $fallback;
    }

    private function statusNeedsAttention(string $status): bool
    {
        return in_array($status, [
            CampaignProspectProviderConnection::STATUS_PENDING,
            CampaignProspectProviderConnection::STATUS_DRAFT,
            CampaignProspectProviderConnection::STATUS_ERROR,
            CampaignProspectProviderConnection::STATUS_RECONNECT_REQUIRED,
            CampaignProspectProviderConnection::STATUS_INVALID,
            CampaignProspectProviderConnection::STATUS_EXPIRED,
            CampaignProspectProviderConnection::STATUS_RATE_LIMITED,
        ], true);
    }

    private function statusFromRefreshFailureMessage(string $message): string
    {
        $normalized = Str::lower(trim($message));

        if ($normalized === '') {
            return CampaignProspectProviderConnection::STATUS_ERROR;
        }

        if (str_contains($normalized, 'reconnect')
            || str_contains($normalized, 'authorization')
            || str_contains($normalized, 'refresh token')
            || str_contains($normalized, 'must be reconnected')
            || str_contains($normalized, 're-authorize')) {
            return CampaignProspectProviderConnection::STATUS_RECONNECT_REQUIRED;
        }

        return CampaignProspectProviderConnection::STATUS_ERROR;
    }

    private function assertOwnership(User $owner, CampaignProspectProviderConnection $connection): void
    {
        if ((int) $connection->user_id !== (int) $owner->id) {
            abort(404);
        }
    }
}
