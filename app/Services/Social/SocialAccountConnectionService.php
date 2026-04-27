<?php

namespace App\Services\Social;

use App\Models\SocialAccountConnection;
use App\Models\User;
use App\Services\Social\Contracts\PlatformPublisherInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SocialAccountConnectionService
{
    public function __construct(
        private readonly SocialProviderRegistry $registry,
    ) {}

    /**
     * @return Collection<int, SocialAccountConnection>
     */
    public function listForOwner(User $owner): Collection
    {
        return SocialAccountConnection::query()
            ->byUser($owner->id)
            ->orderBy('platform')
            ->orderByDesc('is_active')
            ->orderBy('label')
            ->orderByDesc('updated_at')
            ->get();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function definitions(): array
    {
        return collect($this->registry->definitions())
            ->map(fn (array $definition): array => [
                ...$definition,
                'test_connection_enabled' => $this->testConnectionsEnabled(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPayloads(User $owner): array
    {
        return $this->listForOwner($owner)
            ->map(fn (SocialAccountConnection $connection) => $this->payload($connection))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function summaryForOwner(User $owner): array
    {
        $connections = $this->listForOwner($owner);
        $statusCounts = collect(SocialAccountConnection::allowedStatuses())
            ->mapWithKeys(fn (string $status) => [$status => 0])
            ->all();

        foreach ($connections as $connection) {
            $status = (string) $connection->status;
            if (! array_key_exists($status, $statusCounts)) {
                $statusCounts[$status] = 0;
            }

            $statusCounts[$status]++;
        }

        return [
            'configured' => $connections->count(),
            'connected' => $connections
                ->filter(fn (SocialAccountConnection $connection) => $connection->is_active
                    && $connection->status === SocialAccountConnection::STATUS_CONNECTED)
                ->count(),
            'inactive' => $connections
                ->filter(fn (SocialAccountConnection $connection) => ! $connection->is_active)
                ->count(),
            'attention' => collect([
                SocialAccountConnection::STATUS_DRAFT,
                SocialAccountConnection::STATUS_PENDING,
                SocialAccountConnection::STATUS_ERROR,
                SocialAccountConnection::STATUS_RECONNECT_REQUIRED,
                SocialAccountConnection::STATUS_EXPIRED,
            ])->sum(fn (string $status) => (int) ($statusCounts[$status] ?? 0)),
            'available_platforms' => $connections
                ->filter(fn (SocialAccountConnection $connection) => $connection->is_active
                    && $connection->status === SocialAccountConnection::STATUS_CONNECTED)
                ->pluck('platform')
                ->unique()
                ->values()
                ->all(),
            'status_counts' => $statusCounts,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(User $owner, array $payload): SocialAccountConnection
    {
        $platform = strtolower(trim((string) ($payload['platform'] ?? '')));
        $publisher = $this->registry->publisher($platform);
        $externalAccountId = $this->nullableString($payload, 'external_account_id');

        $this->ensureUniqueExternalAccountId($owner->id, $platform, $externalAccountId);

        return SocialAccountConnection::query()->create([
            'user_id' => $owner->id,
            'platform' => $platform,
            'label' => $this->nullableString($payload, 'label') ?: $this->defaultLabel($publisher),
            'display_name' => $this->nullableString($payload, 'display_name'),
            'account_handle' => $this->nullableString($payload, 'account_handle'),
            'external_account_id' => $externalAccountId,
            'auth_method' => (string) ($publisher->definition()['auth_method'] ?? SocialAccountConnection::AUTH_METHOD_OAUTH),
            'permissions' => [],
            'status' => SocialAccountConnection::STATUS_DRAFT,
            'is_active' => false,
            'metadata' => $this->mergedMetadata(new SocialAccountConnection, $publisher, [
                'connection_flow' => 'oauth_scaffold',
                'oauth_ready' => false,
                'oauth_code_verifier' => null,
                'requested_scopes' => array_values($publisher->definition()['scopes'] ?? []),
            ]),
        ])->fresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createTestConnection(User $owner, array $payload): SocialAccountConnection
    {
        if (! $this->testConnectionsEnabled()) {
            throw ValidationException::withMessages([
                'platform' => 'Pulse test connections are only available in local or testing environments.',
            ]);
        }

        $platform = strtolower(trim((string) ($payload['platform'] ?? '')));
        $publisher = $this->registry->publisher($platform);
        $externalAccountId = $this->nullableString($payload, 'external_account_id')
            ?: sprintf('pulse-test-%d-%s', $owner->id, $platform);

        $connection = SocialAccountConnection::query()
            ->byUser($owner->id)
            ->where('platform', $platform)
            ->where('external_account_id', $externalAccountId)
            ->first();

        if (! $connection) {
            $this->ensureUniqueExternalAccountId($owner->id, $platform, $externalAccountId);
            $connection = new SocialAccountConnection([
                'user_id' => $owner->id,
                'platform' => $platform,
            ]);
        }

        $definition = $publisher->definition();
        $now = Carbon::now();

        $connection->forceFill([
            'user_id' => $owner->id,
            'platform' => $platform,
            'label' => $this->nullableString($payload, 'label') ?: sprintf('%s test account', $publisher->label()),
            'display_name' => $this->nullableString($payload, 'display_name') ?: sprintf('Pulse test %s', $publisher->label()),
            'account_handle' => $this->nullableString($payload, 'account_handle') ?: '@pulse-test-'.$platform,
            'external_account_id' => $externalAccountId,
            'auth_method' => SocialAccountConnection::AUTH_METHOD_MANUAL,
            'credentials' => [
                'access_token' => 'pulse-test-token-'.$platform,
                'token_type' => 'Bearer',
            ],
            'permissions' => array_values($definition['scopes'] ?? []),
            'status' => SocialAccountConnection::STATUS_CONNECTED,
            'is_active' => true,
            'connected_at' => $connection->connected_at ?: $now,
            'last_synced_at' => $now,
            'token_expires_at' => $now->copy()->addYear(),
            'oauth_state' => null,
            'oauth_state_expires_at' => null,
            'last_error' => null,
            'metadata' => $this->mergedMetadata($connection, $publisher, [
                'connection_flow' => 'local_test_connection',
                'oauth_ready' => true,
                'oauth_code_verifier' => null,
                'test_connection' => true,
                'provider_target_id' => $externalAccountId,
                'publish_fake_mode' => true,
            ]),
        ])->save();

        return $connection->fresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(User $owner, SocialAccountConnection $connection, array $payload): SocialAccountConnection
    {
        $this->assertOwnership($owner, $connection);

        $publisher = $this->registry->publisher($connection->platform);
        $externalAccountId = array_key_exists('external_account_id', $payload)
            ? $this->nullableString($payload, 'external_account_id')
            : $connection->external_account_id;

        $this->ensureUniqueExternalAccountId($owner->id, $connection->platform, $externalAccountId, $connection->id);

        $requestedIsActive = array_key_exists('is_active', $payload)
            ? (bool) $payload['is_active']
            : (bool) $connection->is_active;

        $connection->forceFill([
            'label' => array_key_exists('label', $payload)
                ? ($this->nullableString($payload, 'label') ?: $this->defaultLabel($publisher))
                : $connection->label,
            'display_name' => array_key_exists('display_name', $payload)
                ? $this->nullableString($payload, 'display_name')
                : $connection->display_name,
            'account_handle' => array_key_exists('account_handle', $payload)
                ? $this->nullableString($payload, 'account_handle')
                : $connection->account_handle,
            'external_account_id' => $externalAccountId,
            'is_active' => $connection->status === SocialAccountConnection::STATUS_CONNECTED
                ? $requestedIsActive
                : false,
            'metadata' => $this->mergedMetadata($connection, $publisher),
        ])->save();

        return $connection->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function authorize(User $owner, SocialAccountConnection $connection): array
    {
        $this->assertOwnership($owner, $connection);

        $publisher = $this->registry->publisher($connection->platform);
        $state = Str::random(64);
        $authorization = $publisher->beginAuthorization($connection, $state);
        $redirectUrl = trim((string) ($authorization['redirect_url'] ?? ''));

        if ($redirectUrl === '') {
            throw ValidationException::withMessages([
                'platform' => sprintf('%s did not return an authorization URL.', $publisher->label()),
            ]);
        }

        $connection->forceFill([
            'auth_method' => (string) ($publisher->definition()['auth_method'] ?? SocialAccountConnection::AUTH_METHOD_OAUTH),
            'status' => SocialAccountConnection::STATUS_PENDING,
            'is_active' => false,
            'oauth_state' => $state,
            'oauth_state_expires_at' => Carbon::now()->addMinutes(15),
            'last_error' => null,
            'metadata' => $this->mergedMetadata($connection, $publisher, [
                'connection_flow' => 'oauth',
                'oauth_ready' => false,
                ...((array) ($authorization['metadata'] ?? [])),
            ]),
        ])->save();

        return [
            'flow' => 'redirect',
            'message' => sprintf('Continue with %s to finish connecting this social account.', $publisher->label()),
            'redirect_url' => $redirectUrl,
            'connection' => $this->payload($connection->fresh()),
        ];
    }

    public function refresh(User $owner, SocialAccountConnection $connection): SocialAccountConnection
    {
        $this->assertOwnership($owner, $connection);

        $publisher = $this->registry->publisher($connection->platform);
        $now = Carbon::now();

        try {
            $result = $publisher->refreshCredentials((array) ($connection->credentials ?? []));
        } catch (ValidationException $exception) {
            $message = $this->validationMessage($exception, 'Reconnect this social account to continue.');
            $status = $this->statusFromRefreshFailureMessage($message);

            $connection->forceFill([
                'status' => $status,
                'is_active' => false,
                'last_synced_at' => $now,
                'oauth_state' => null,
                'oauth_state_expires_at' => null,
                'last_error' => $message,
                'metadata' => $this->mergedMetadata($connection, $publisher, [
                    'connection_flow' => 'oauth_refresh_failed',
                    'oauth_ready' => false,
                    'oauth_code_verifier' => null,
                ]),
            ])->save();

            return $connection->fresh();
        } catch (ConnectionException $exception) {
            $connection->forceFill([
                'status' => SocialAccountConnection::STATUS_ERROR,
                'is_active' => false,
                'last_synced_at' => $now,
                'last_error' => 'The provider could not be reached while refreshing this social account.',
                'metadata' => $this->mergedMetadata($connection, $publisher, [
                    'connection_flow' => 'oauth_refresh_failed',
                    'oauth_ready' => false,
                    'oauth_code_verifier' => null,
                ]),
            ])->save();

            return $connection->fresh();
        }

        $status = (string) ($result['status'] ?? SocialAccountConnection::STATUS_CONNECTED);
        $credentials = array_key_exists('credentials', $result)
            ? (array) ($result['credentials'] ?? [])
            : (array) ($connection->credentials ?? []);
        $permissions = array_values((array) ($result['permissions'] ?? $connection->permissions ?? []));
        $tokenExpiresAt = $result['token_expires_at'] ?? $connection->token_expires_at;

        $connection->forceFill([
            'auth_method' => (string) ($publisher->definition()['auth_method'] ?? SocialAccountConnection::AUTH_METHOD_OAUTH),
            'credentials' => $credentials,
            'permissions' => $permissions,
            'status' => $status,
            'is_active' => $status === SocialAccountConnection::STATUS_CONNECTED,
            'connected_at' => $status === SocialAccountConnection::STATUS_CONNECTED
                ? ($connection->connected_at ?? $now)
                : null,
            'last_synced_at' => $now,
            'token_expires_at' => $tokenExpiresAt,
            'last_error' => $status === SocialAccountConnection::STATUS_CONNECTED
                ? null
                : (string) ($result['message'] ?? 'Social account refresh failed.'),
            'metadata' => $this->mergedMetadata($connection, $publisher, [
                'connection_flow' => 'oauth_connected',
                'oauth_ready' => $status === SocialAccountConnection::STATUS_CONNECTED,
                'oauth_code_verifier' => null,
                ...((array) ($result['metadata'] ?? [])),
            ]),
        ])->save();

        return $connection->fresh();
    }

    public function disconnect(User $owner, SocialAccountConnection $connection): SocialAccountConnection
    {
        $this->assertOwnership($owner, $connection);

        $publisher = $this->registry->publisher($connection->platform);

        $connection->forceFill([
            'credentials' => [],
            'permissions' => [],
            'status' => SocialAccountConnection::STATUS_DISCONNECTED,
            'is_active' => false,
            'connected_at' => null,
            'last_synced_at' => null,
            'token_expires_at' => null,
            'oauth_state' => null,
            'oauth_state_expires_at' => null,
            'last_error' => null,
            'metadata' => $this->mergedMetadata($connection, $publisher, [
                'connection_flow' => 'disconnected',
                'oauth_ready' => false,
                'oauth_code_verifier' => null,
            ]),
        ])->save();

        return $connection->fresh();
    }

    /**
     * @return array{success: bool, message: string, connection: SocialAccountConnection}
     */
    public function test(User $owner, SocialAccountConnection $connection): array
    {
        $this->assertOwnership($owner, $connection);

        $publisher = $this->registry->publisher($connection->platform);
        $testedAt = Carbon::now();
        $accessToken = trim((string) data_get($connection->credentials, 'access_token'));
        $refreshToken = trim((string) data_get($connection->credentials, 'refresh_token'));
        $supportsRefresh = (bool) ($publisher->definition()['supports_refresh'] ?? false);

        if ($accessToken === '') {
            return $this->finalizeTestResult(
                $connection,
                $publisher,
                false,
                sprintf('%s must be reconnected before the connection can be tested.', $publisher->label()),
                $testedAt,
                SocialAccountConnection::STATUS_RECONNECT_REQUIRED
            );
        }

        if ($connection->token_expires_at instanceof Carbon
            && $connection->token_expires_at->isPast()
            && (! $supportsRefresh || $refreshToken === '')
        ) {
            return $this->finalizeTestResult(
                $connection,
                $publisher,
                false,
                sprintf('%s token expired and must be refreshed or reconnected.', $publisher->label()),
                $testedAt,
                SocialAccountConnection::STATUS_EXPIRED
            );
        }

        if ((string) $connection->status !== SocialAccountConnection::STATUS_CONNECTED) {
            return $this->finalizeTestResult(
                $connection,
                $publisher,
                false,
                sprintf('%s is not connected yet. Finish OAuth before testing this account.', $publisher->label()),
                $testedAt
            );
        }

        if ($supportsRefresh && $refreshToken !== '') {
            $refreshed = $this->refresh($owner, $connection);

            if ((string) $refreshed->status === SocialAccountConnection::STATUS_CONNECTED) {
                return $this->finalizeTestResult(
                    $refreshed,
                    $publisher,
                    true,
                    sprintf('%s connection is valid. The access token was refreshed successfully.', $publisher->label()),
                    $testedAt
                );
            }

            return $this->finalizeTestResult(
                $refreshed,
                $publisher,
                false,
                trim((string) $refreshed->last_error) !== ''
                    ? trim((string) $refreshed->last_error)
                    : sprintf('%s connection test failed.', $publisher->label()),
                $testedAt,
                (string) $refreshed->status
            );
        }

        return $this->finalizeTestResult(
            $connection,
            $publisher,
            true,
            sprintf('%s connection looks valid and ready to publish.', $publisher->label()),
            $testedAt
        );
    }

    public function destroy(User $owner, SocialAccountConnection $connection): void
    {
        $this->assertOwnership($owner, $connection);
        $connection->delete();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function completeAuthorization(string $platform, array $payload): array
    {
        $publisher = $this->registry->publisher($platform);
        $state = trim((string) ($payload['state'] ?? ''));

        if ($state === '') {
            throw ValidationException::withMessages([
                'state' => 'The provider callback is missing its security state token.',
            ]);
        }

        $connection = SocialAccountConnection::query()
            ->where('platform', $platform)
            ->where('oauth_state', $state)
            ->first();

        if (! $connection) {
            throw ValidationException::withMessages([
                'state' => 'This social account callback is no longer valid. Start the connection again.',
            ]);
        }

        $owner = $connection->relationLoaded('user')
            ? $connection->user
            : $connection->user()->first();

        if (! $owner || ! $owner->hasCompanyFeature('social')) {
            $message = 'Malikia Pulse is disabled for this workspace. Re-enable the social module before reconnecting this account.';

            $connection->forceFill([
                'status' => SocialAccountConnection::STATUS_RECONNECT_REQUIRED,
                'is_active' => false,
                'oauth_state' => null,
                'oauth_state_expires_at' => null,
                'last_error' => $message,
                'metadata' => $this->mergedMetadata($connection, $publisher, [
                    'connection_flow' => 'oauth_blocked_feature_off',
                    'oauth_ready' => false,
                    'oauth_code_verifier' => null,
                ]),
            ])->save();

            return [
                'success' => false,
                'message' => $message,
                'redirect_route' => 'dashboard',
                'connection' => $this->payload($connection->fresh()),
            ];
        }

        if ($connection->oauth_state_expires_at && $connection->oauth_state_expires_at->isPast()) {
            $connection->forceFill([
                'status' => SocialAccountConnection::STATUS_RECONNECT_REQUIRED,
                'is_active' => false,
                'oauth_state' => null,
                'oauth_state_expires_at' => null,
                'last_error' => 'The connection request expired before the provider finished authorizing it.',
                'metadata' => $this->mergedMetadata($connection, $publisher, [
                    'connection_flow' => 'oauth_expired',
                    'oauth_ready' => false,
                    'oauth_code_verifier' => null,
                ]),
            ])->save();

            return [
                'success' => false,
                'message' => 'The connection request expired before the provider finished authorizing it.',
                'connection' => $this->payload($connection->fresh()),
            ];
        }

        $providerErrorMessage = $this->providerCallbackErrorMessage($payload);
        if ($providerErrorMessage !== '') {
            $connection->forceFill([
                'status' => SocialAccountConnection::STATUS_RECONNECT_REQUIRED,
                'is_active' => false,
                'oauth_state' => null,
                'oauth_state_expires_at' => null,
                'last_error' => $providerErrorMessage,
                'metadata' => $this->mergedMetadata($connection, $publisher, [
                    'connection_flow' => 'oauth_error',
                    'oauth_ready' => false,
                    'oauth_code_verifier' => null,
                ]),
            ])->save();

            return [
                'success' => false,
                'message' => $providerErrorMessage,
                'connection' => $this->payload($connection->fresh()),
            ];
        }

        try {
            $result = $publisher->completeAuthorization($connection, $payload);
            $message = (string) ($result['message'] ?? sprintf('%s connected.', $publisher->label()));
        } catch (ValidationException $exception) {
            $message = $this->validationMessage($exception, sprintf('%s could not be connected.', $publisher->label()));

            $connection->forceFill([
                'status' => SocialAccountConnection::STATUS_RECONNECT_REQUIRED,
                'is_active' => false,
                'oauth_state' => null,
                'oauth_state_expires_at' => null,
                'last_error' => $message,
                'metadata' => $this->mergedMetadata($connection, $publisher, [
                    'connection_flow' => 'oauth_error',
                    'oauth_ready' => false,
                    'oauth_code_verifier' => null,
                ]),
            ])->save();

            return [
                'success' => false,
                'message' => $message,
                'connection' => $this->payload($connection->fresh()),
            ];
        } catch (ConnectionException $exception) {
            $message = 'The provider could not be reached while finishing the social account connection.';

            $connection->forceFill([
                'status' => SocialAccountConnection::STATUS_ERROR,
                'is_active' => false,
                'oauth_state' => null,
                'oauth_state_expires_at' => null,
                'last_error' => $message,
                'metadata' => $this->mergedMetadata($connection, $publisher, [
                    'connection_flow' => 'oauth_error',
                    'oauth_ready' => false,
                    'oauth_code_verifier' => null,
                ]),
            ])->save();

            return [
                'success' => false,
                'message' => $message,
                'connection' => $this->payload($connection->fresh()),
            ];
        }

        $externalAccountId = $this->nullableString($result, 'external_account_id') ?: $connection->external_account_id;
        $this->ensureUniqueExternalAccountId($connection->user_id, $connection->platform, $externalAccountId, $connection->id);

        $now = Carbon::now();
        $permissions = array_values((array) ($result['permissions'] ?? $connection->permissions ?? []));

        $status = (string) ($result['status'] ?? SocialAccountConnection::STATUS_CONNECTED);

        $connection->forceFill([
            'auth_method' => (string) ($publisher->definition()['auth_method'] ?? SocialAccountConnection::AUTH_METHOD_OAUTH),
            'credentials' => (array) ($result['credentials'] ?? []),
            'permissions' => $permissions,
            'status' => $status,
            'is_active' => $status === SocialAccountConnection::STATUS_CONNECTED,
            'display_name' => $this->nullableString($result, 'display_name') ?: $connection->display_name,
            'account_handle' => $this->nullableString($result, 'account_handle') ?: $connection->account_handle,
            'external_account_id' => $externalAccountId,
            'connected_at' => $status === SocialAccountConnection::STATUS_CONNECTED
                ? ($connection->connected_at ?? $now)
                : null,
            'last_synced_at' => $now,
            'token_expires_at' => $result['token_expires_at'] ?? null,
            'oauth_state' => null,
            'oauth_state_expires_at' => null,
            'last_error' => $status === SocialAccountConnection::STATUS_CONNECTED
                ? null
                : (string) ($result['message'] ?? 'Social account connection failed.'),
            'metadata' => $this->mergedMetadata($connection, $publisher, [
                'connection_flow' => 'oauth_connected',
                'oauth_ready' => $status === SocialAccountConnection::STATUS_CONNECTED,
                'oauth_code_verifier' => null,
                ...((array) ($result['metadata'] ?? [])),
            ]),
        ])->save();

        return [
            'success' => true,
            'message' => $message,
            'connection' => $this->payload($connection->fresh()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(SocialAccountConnection $connection): array
    {
        $publisher = $this->registry->publisher($connection->platform);
        $definition = $publisher->definition();
        $status = (string) $connection->status;
        $credentials = (array) ($connection->credentials ?? []);

        return [
            'id' => $connection->id,
            'platform' => $connection->platform,
            'provider_label' => $definition['label'] ?? $publisher->label(),
            'label' => $connection->label,
            'display_name' => $connection->display_name,
            'account_handle' => $connection->account_handle,
            'external_account_id' => $connection->external_account_id,
            'auth_method' => $connection->auth_method ?: ($definition['auth_method'] ?? SocialAccountConnection::AUTH_METHOD_OAUTH),
            'target_type' => $definition['target_type'] ?? null,
            'supports' => array_values($definition['supports'] ?? []),
            'supports_redirect' => (bool) ($definition['supports_redirect'] ?? false),
            'supports_refresh' => (bool) ($definition['supports_refresh'] ?? false),
            'setup_required' => (bool) ($definition['setup_required'] ?? false),
            'setup_message' => $definition['setup_message'] ?? null,
            'requested_scopes' => array_values($definition['scopes'] ?? []),
            'permissions' => array_values((array) ($connection->permissions ?? [])),
            'status' => $status,
            'is_active' => (bool) $connection->is_active,
            'is_connected' => $connection->is_active && $status === SocialAccountConnection::STATUS_CONNECTED,
            'needs_attention' => $this->statusNeedsAttention($status),
            'has_credentials' => $credentials !== [],
            'has_refresh_token' => trim((string) ($credentials['refresh_token'] ?? '')) !== '',
            'oauth_pending' => trim((string) ($connection->oauth_state ?? '')) !== '',
            'oauth_ready' => (bool) (($connection->metadata['oauth_ready'] ?? false)),
            'short_description' => $definition['short_description'] ?? null,
            'connected_at' => optional($connection->connected_at)->toIso8601String(),
            'last_synced_at' => optional($connection->last_synced_at)->toIso8601String(),
            'token_expires_at' => optional($connection->token_expires_at)->toIso8601String(),
            'last_tested_at' => data_get($connection->metadata, 'last_tested_at'),
            'last_test_status' => data_get($connection->metadata, 'last_test_status'),
            'last_test_message' => data_get($connection->metadata, 'last_test_message'),
            'last_error' => $connection->last_error,
            'metadata' => (array) ($connection->metadata ?? []),
        ];
    }

    private function assertOwnership(User $owner, SocialAccountConnection $connection): void
    {
        if ((int) $connection->user_id !== (int) $owner->id) {
            abort(404);
        }
    }

    private function statusNeedsAttention(string $status): bool
    {
        return in_array($status, [
            SocialAccountConnection::STATUS_DRAFT,
            SocialAccountConnection::STATUS_PENDING,
            SocialAccountConnection::STATUS_ERROR,
            SocialAccountConnection::STATUS_RECONNECT_REQUIRED,
            SocialAccountConnection::STATUS_EXPIRED,
        ], true);
    }

    private function statusFromRefreshFailureMessage(string $message): string
    {
        $normalized = Str::lower(trim($message));

        if ($normalized === '') {
            return SocialAccountConnection::STATUS_ERROR;
        }

        if (str_contains($normalized, 'reconnect')
            || str_contains($normalized, 'authorization')
            || str_contains($normalized, 'refresh token')
            || str_contains($normalized, 'pkce')
            || str_contains($normalized, 'must be reconnected')
            || str_contains($normalized, 're-authorize')) {
            return SocialAccountConnection::STATUS_RECONNECT_REQUIRED;
        }

        return SocialAccountConnection::STATUS_ERROR;
    }

    private function defaultLabel(PlatformPublisherInterface $publisher): string
    {
        return sprintf('%s connection', $publisher->label());
    }

    /**
     * @return array{success: bool, message: string, connection: SocialAccountConnection}
     */
    private function finalizeTestResult(
        SocialAccountConnection $connection,
        PlatformPublisherInterface $publisher,
        bool $success,
        string $message,
        Carbon $testedAt,
        ?string $status = null
    ): array {
        $nextStatus = $status ?: (string) $connection->status;

        $connection->forceFill([
            'status' => $nextStatus,
            'is_active' => $success
                ? ((string) $nextStatus === SocialAccountConnection::STATUS_CONNECTED)
                : false,
            'last_synced_at' => $success ? $testedAt : $connection->last_synced_at,
            'last_error' => $success ? null : $message,
            'metadata' => $this->mergedMetadata($connection, $publisher, [
                'last_tested_at' => $testedAt->toIso8601String(),
                'last_test_status' => $success ? 'success' : 'failed',
                'last_test_message' => $message,
            ]),
        ])->save();

        return [
            'success' => $success,
            'message' => $message,
            'connection' => $connection->fresh(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function nullableString(array $payload, string $key): ?string
    {
        $value = trim((string) ($payload[$key] ?? ''));

        return $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function mergedMetadata(
        SocialAccountConnection $connection,
        PlatformPublisherInterface $publisher,
        array $extra = []
    ): array {
        $definition = $publisher->definition();

        return collect([
            ...((array) ($connection->metadata ?? [])),
            ...$extra,
            'provider_label' => $definition['label'] ?? $publisher->label(),
            'target_type' => $definition['target_type'] ?? null,
            'supports' => array_values($definition['supports'] ?? []),
            'requested_scopes' => array_values($definition['scopes'] ?? []),
        ])
            ->reject(fn ($value) => $value === null)
            ->all();
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

    private function testConnectionsEnabled(): bool
    {
        $configured = config('services.social.allow_test_connections');

        if ($configured !== null) {
            return filter_var($configured, FILTER_VALIDATE_BOOLEAN);
        }

        return app()->environment(['local', 'testing']);
    }

    private function ensureUniqueExternalAccountId(
        int $ownerId,
        string $platform,
        ?string $externalAccountId,
        ?int $ignoreConnectionId = null
    ): void {
        if ($externalAccountId === null) {
            return;
        }

        $query = SocialAccountConnection::query()
            ->where('user_id', $ownerId)
            ->where('platform', $platform)
            ->where('external_account_id', $externalAccountId);

        if ($ignoreConnectionId) {
            $query->whereKeyNot($ignoreConnectionId);
        }

        if (! $query->exists()) {
            return;
        }

        throw ValidationException::withMessages([
            'external_account_id' => 'This social account is already connected for the selected platform.',
        ]);
    }
}
