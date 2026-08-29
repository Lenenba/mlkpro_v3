<?php

namespace App\Services\Social;

use App\Models\SocialAccountConnection;
use App\Models\SocialDeliveryOutbox;
use App\Models\SocialTransportCutoverMapping;
use App\Models\User;
use App\Services\Social\Contracts\PlatformPublisherInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SocialAccountConnectionService
{
    private const OAUTH_CALLBACK_CLAIM_PREFIX = 'callback:';

    private const OAUTH_CALLBACK_CLAIM_TTL_SECONDS = 120;

    /** @var array<int, int> */
    private array $deliveryMutationLockDepth = [];

    public function __construct(
        private readonly SocialProviderRegistry $registry,
        private readonly SocialLogicalDestinationKeyService $logicalDestinationKeys,
        private readonly SocialConnectionDeliveryMutex $deliveryMutex,
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
    public function directManagementDefinitions(): array
    {
        return $this->bufferOnlyModeEnabled() ? [] : $this->definitions();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPayloads(User $owner): array
    {
        if ($this->bufferOnlyModeEnabled()) {
            return $this->listPublishingPayloads($owner);
        }

        return $this->listForOwner($owner)
            ->map(fn (SocialAccountConnection $connection) => $this->payload($connection))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPublishingPayloads(User $owner): array
    {
        $connections = $this->listForOwner($owner);

        if ($this->bufferOnlyModeEnabled()) {
            $connections = $connections
                ->filter(fn (SocialAccountConnection $connection): bool => $connection->isImportedFromBuffer());
        }

        return $connections
            ->map(fn (SocialAccountConnection $connection) => $this->payload($connection))
            ->values()
            ->all();
    }

    /**
     * Buffer catalog imports have their own read-only manager until delivery is enabled.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listDirectManagementPayloads(User $owner): array
    {
        if ($this->bufferOnlyModeEnabled()) {
            return [];
        }

        return $this->listForOwner($owner)
            ->reject(fn (SocialAccountConnection $connection): bool => (
                (string) $connection->delivery_provider === SocialAccountConnection::DELIVERY_PROVIDER_BUFFER
                || (bool) data_get($connection->metadata, 'buffer.catalog_only', false)
            ))
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

        if ($this->bufferOnlyModeEnabled()) {
            $connections = $connections
                ->filter(fn (SocialAccountConnection $connection): bool => $connection->isImportedFromBuffer());
        }

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
                ->filter(fn (SocialAccountConnection $connection): bool => (
                    $this->isConnectedForPublishing($connection)
                ))
                ->count(),
            'inactive' => $connections
                ->filter(fn (SocialAccountConnection $connection) => ! $connection->is_active)
                ->count(),
            'attention' => collect([
                SocialAccountConnection::STATUS_DRAFT,
                SocialAccountConnection::STATUS_PENDING,
                SocialAccountConnection::STATUS_AUTHORIZING,
                SocialAccountConnection::STATUS_ERROR,
                SocialAccountConnection::STATUS_RECONNECT_REQUIRED,
                SocialAccountConnection::STATUS_EXPIRED,
            ])->sum(fn (string $status) => (int) ($statusCounts[$status] ?? 0)),
            'available_platforms' => $connections
                ->filter(fn (SocialAccountConnection $connection): bool => (
                    $this->isConnectedForPublishing($connection)
                ))
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
        $this->assertDirectManagementEnabled();

        return $this->withTenantMutationLock(
            (int) $owner->id,
            fn (): SocialAccountConnection => $this->createUnderTenantLock($owner, $payload),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createUnderTenantLock(User $owner, array $payload): SocialAccountConnection
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
            'delivery_provider' => null,
            'transport_generation' => null,
            'logical_destination_key' => null,
            'auth_method' => (string) ($publisher->definition()['auth_method'] ?? SocialAccountConnection::AUTH_METHOD_OAUTH),
            'permissions' => [],
            'status' => SocialAccountConnection::STATUS_DRAFT,
            'is_active' => false,
            'metadata' => $this->mergedMetadata(new SocialAccountConnection, $publisher, [
                'connection_flow' => 'oauth_scaffold',
                'oauth_ready' => false,
                'requested_scopes' => array_values($publisher->definition()['scopes'] ?? []),
            ]),
        ])->fresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createTestConnection(User $owner, array $payload): SocialAccountConnection
    {
        $this->assertDirectManagementEnabled();

        return $this->withTenantMutationLock(
            (int) $owner->id,
            function () use ($owner, $payload): SocialAccountConnection {
                $platform = strtolower(trim((string) ($payload['platform'] ?? '')));
                $externalAccountId = $this->nullableString($payload, 'external_account_id')
                    ?? sprintf('pulse-test-%d-%s', $owner->id, $platform);
                $connectionId = SocialAccountConnection::query()
                    ->byUser((int) $owner->id)
                    ->where('platform', $platform)
                    ->where('external_account_id', $externalAccountId)
                    ->value('id');

                if (is_numeric($connectionId)) {
                    return $this->withDeliveryMutationLock(
                        (int) $connectionId,
                        fn (): SocialAccountConnection => $this->createTestConnectionUnderTenantLock(
                            $owner,
                            $payload,
                        ),
                    );
                }

                return $this->createTestConnectionUnderTenantLock($owner, $payload);
            },
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createTestConnectionUnderTenantLock(
        User $owner,
        array $payload,
    ): SocialAccountConnection {
        if (! $this->testConnectionsEnabled()) {
            throw ValidationException::withMessages([
                'platform' => 'Pulse test connections are only available in local or testing environments.',
            ]);
        }

        $platform = strtolower(trim((string) ($payload['platform'] ?? '')));
        $publisher = $this->registry->publisher($platform);
        $externalAccountId = $this->nullableString($payload, 'external_account_id')
            ?? sprintf('pulse-test-%d-%s', $owner->id, $platform);

        return DB::transaction(function () use (
            $externalAccountId,
            $owner,
            $payload,
            $platform,
            $publisher,
        ): SocialAccountConnection {
            User::query()
                ->whereKey($owner->id)
                ->lockForUpdate()
                ->firstOrFail();

            $connection = SocialAccountConnection::query()
                ->byUser($owner->id)
                ->where('platform', $platform)
                ->where('external_account_id', $externalAccountId)
                ->first();

            if ($connection && $this->hasActiveOauthCallbackClaim($connection)) {
                throw ValidationException::withMessages([
                    'platform' => 'This social account is still finishing OAuth. Wait before replacing it with a test connection.',
                ]);
            }

            if (! $connection) {
                $this->ensureUniqueExternalAccountId($owner->id, $platform, $externalAccountId);
                $connection = new SocialAccountConnection([
                    'user_id' => $owner->id,
                    'platform' => $platform,
                ]);
            }

            $definition = $publisher->definition();
            $now = Carbon::now();
            $transportIdentity = $this->directTransportIdentityAttributes(
                (int) $owner->id,
                $platform,
                $externalAccountId,
                $connection,
            );
            $persistedExternalAccountId = $connection->logical_destination_key !== null
                ? (string) $connection->external_account_id
                : $externalAccountId;

            $connection->forceFill([
                'user_id' => $owner->id,
                'platform' => $platform,
                'label' => $this->nullableString($payload, 'label') ?: sprintf('%s test account', $publisher->label()),
                'display_name' => $this->nullableString($payload, 'display_name') ?: sprintf('Pulse test %s', $publisher->label()),
                'account_handle' => $this->nullableString($payload, 'account_handle') ?: '@pulse-test-'.$platform,
                'external_account_id' => $persistedExternalAccountId,
                ...$transportIdentity,
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
                'oauth_code_verifier' => null,
                'oauth_state_expires_at' => null,
                'last_error' => null,
                'metadata' => $this->mergedMetadata($connection, $publisher, [
                    'connection_flow' => 'local_test_connection',
                    'oauth_ready' => true,
                    'test_connection' => true,
                    'provider_target_id' => $persistedExternalAccountId,
                    'publish_fake_mode' => true,
                ]),
            ])->save();

            return $connection->fresh();
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(User $owner, SocialAccountConnection $connection, array $payload): SocialAccountConnection
    {
        $this->assertOwnership($owner, $connection);
        $this->assertDirectManagementConnection($connection);

        return $this->withDeliveryMutationLock(
            (int) $connection->id,
            fn (): SocialAccountConnection => $this->updateUnderDeliveryLock(
                $owner,
                $connection,
                $payload,
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function updateUnderDeliveryLock(
        User $owner,
        SocialAccountConnection $connection,
        array $payload,
    ): SocialAccountConnection {
        $this->assertOwnership($owner, $connection);

        $connection = SocialAccountConnection::query()
            ->byUser((int) $owner->id)
            ->whereKey($connection->id)
            ->firstOrFail();

        $publisher = $this->registry->publisher($connection->platform);
        $externalAccountId = array_key_exists('external_account_id', $payload)
            ? $this->nullableString($payload, 'external_account_id')
            : $connection->external_account_id;

        if ($connection->logical_destination_key !== null) {
            $this->directTransportIdentityAttributes(
                (int) $owner->id,
                (string) $connection->platform,
                $externalAccountId,
                $connection,
            );
            $externalAccountId = $connection->external_account_id;
        }

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
        $this->assertDirectManagementConnection($connection);

        return $this->withDeliveryMutationLock(
            (int) $connection->id,
            fn (): array => $this->authorizeUnderDeliveryLock($owner, $connection),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function authorizeUnderDeliveryLock(
        User $owner,
        SocialAccountConnection $connection,
    ): array {
        $this->assertOwnership($owner, $connection);

        return DB::transaction(function () use ($connection, $owner): array {
            $lockedConnection = SocialAccountConnection::query()
                ->byUser((int) $owner->id)
                ->whereKey($connection->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($this->hasActiveOauthCallbackClaim($lockedConnection)) {
                throw ValidationException::withMessages([
                    'platform' => 'This social account is already finishing OAuth. Wait before starting a new authorization.',
                ]);
            }

            $publisher = $this->registry->publisher($lockedConnection->platform);
            $state = Str::random(64);
            $authorization = $publisher->beginAuthorization($lockedConnection, $state);
            $redirectUrl = trim((string) ($authorization['redirect_url'] ?? ''));

            if ($redirectUrl === '') {
                throw ValidationException::withMessages([
                    'platform' => sprintf('%s did not return an authorization URL.', $publisher->label()),
                ]);
            }

            $lockedConnection->forceFill([
                'auth_method' => (string) ($publisher->definition()['auth_method'] ?? SocialAccountConnection::AUTH_METHOD_OAUTH),
                'status' => SocialAccountConnection::STATUS_PENDING,
                'is_active' => false,
                'oauth_state' => $state,
                'oauth_code_verifier' => $authorization['oauth_code_verifier'] ?? null,
                'oauth_state_expires_at' => Carbon::now()->addMinutes(15),
                'last_error' => null,
                'metadata' => $this->mergedMetadata($lockedConnection, $publisher, [
                    'connection_flow' => 'oauth',
                    'oauth_ready' => false,
                    ...((array) ($authorization['metadata'] ?? [])),
                ]),
            ])->save();

            return [
                'flow' => 'redirect',
                'message' => sprintf('Continue with %s to finish connecting this social account.', $publisher->label()),
                'redirect_url' => $redirectUrl,
                'connection' => $this->payload($lockedConnection->fresh()),
            ];
        }, 3);
    }

    public function refresh(User $owner, SocialAccountConnection $connection): SocialAccountConnection
    {
        $this->assertOwnership($owner, $connection);
        $this->assertDirectManagementConnection($connection);

        return $this->withDeliveryMutationLock(
            (int) $connection->id,
            fn (): SocialAccountConnection => $this->refreshUnderDeliveryLock($owner, $connection),
        );
    }

    private function refreshUnderDeliveryLock(
        User $owner,
        SocialAccountConnection $connection,
    ): SocialAccountConnection {
        $this->assertOwnership($owner, $connection);

        $connection = SocialAccountConnection::query()
            ->byUser((int) $owner->id)
            ->whereKey($connection->id)
            ->firstOrFail();

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
                'oauth_code_verifier' => null,
                'oauth_state_expires_at' => null,
                'last_error' => $message,
                'metadata' => $this->mergedMetadata($connection, $publisher, [
                    'connection_flow' => 'oauth_refresh_failed',
                    'oauth_ready' => false,
                ]),
            ])->save();

            return $connection->fresh();
        } catch (ConnectionException $exception) {
            $connection->forceFill([
                'status' => SocialAccountConnection::STATUS_ERROR,
                'is_active' => false,
                'last_synced_at' => $now,
                'oauth_state' => null,
                'oauth_code_verifier' => null,
                'oauth_state_expires_at' => null,
                'last_error' => 'The provider could not be reached while refreshing this social account.',
                'metadata' => $this->mergedMetadata($connection, $publisher, [
                    'connection_flow' => 'oauth_refresh_failed',
                    'oauth_ready' => false,
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
            'oauth_state' => null,
            'oauth_code_verifier' => null,
            'oauth_state_expires_at' => null,
            'token_expires_at' => $tokenExpiresAt,
            'last_error' => $status === SocialAccountConnection::STATUS_CONNECTED
                ? null
                : (string) ($result['message'] ?? 'Social account refresh failed.'),
            'metadata' => $this->mergedMetadata($connection, $publisher, [
                'connection_flow' => 'oauth_connected',
                'oauth_ready' => $status === SocialAccountConnection::STATUS_CONNECTED,
                ...((array) ($result['metadata'] ?? [])),
            ]),
        ])->save();

        return $connection->fresh();
    }

    public function disconnect(User $owner, SocialAccountConnection $connection): SocialAccountConnection
    {
        $this->assertOwnership($owner, $connection);
        $this->assertDirectManagementConnection($connection);

        return $this->withDeliveryMutationLock(
            (int) $connection->id,
            function () use ($owner, $connection): SocialAccountConnection {
                $connection = SocialAccountConnection::query()
                    ->byUser((int) $owner->id)
                    ->whereKey($connection->id)
                    ->firstOrFail();
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
                    'oauth_code_verifier' => null,
                    'oauth_state_expires_at' => null,
                    'last_error' => null,
                    'metadata' => $this->mergedMetadata($connection, $publisher, [
                        'connection_flow' => 'disconnected',
                        'oauth_ready' => false,
                    ]),
                ])->save();

                return $connection->fresh();
            },
        );
    }

    /**
     * @return array{success: bool, message: string, connection: SocialAccountConnection}
     */
    public function test(User $owner, SocialAccountConnection $connection): array
    {
        $this->assertOwnership($owner, $connection);
        $this->assertDirectManagementConnection($connection);

        return $this->withDeliveryMutationLock(
            (int) $connection->id,
            fn (): array => $this->testUnderDeliveryLock($owner, $connection),
        );
    }

    /**
     * @return array{success: bool, message: string, connection: SocialAccountConnection}
     */
    private function testUnderDeliveryLock(
        User $owner,
        SocialAccountConnection $connection,
    ): array {
        $this->assertOwnership($owner, $connection);

        $connection = SocialAccountConnection::query()
            ->byUser((int) $owner->id)
            ->whereKey($connection->id)
            ->firstOrFail();

        if ($this->hasActiveOauthCallbackClaim($connection)) {
            throw ValidationException::withMessages([
                'connection' => 'This social account is still finishing OAuth and cannot be tested yet.',
            ]);
        }

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
        $this->assertDirectManagementConnection($connection);

        $this->withDeliveryMutationLock((int) $connection->id, function () use ($owner, $connection): void {
            $connection = SocialAccountConnection::query()
                ->byUser((int) $owner->id)
                ->whereKey($connection->id)
                ->firstOrFail();

            if (SocialDeliveryOutbox::query()
                ->where('social_provider_connection_id', $connection->id)
                ->exists()) {
                throw ValidationException::withMessages([
                    'connection' => 'This social connection has delivery history and must be disconnected instead of deleted.',
                ]);
            }

            if (Schema::hasTable('social_transport_cutover_mappings')
                && SocialTransportCutoverMapping::query()
                    ->where('user_id', $owner->id)
                    ->where(function (Builder $query) use ($connection): void {
                        $query
                            ->where('legacy_connection_id', $connection->id)
                            ->orWhere('replacement_connection_id', $connection->id);
                    })
                    ->exists()) {
                throw ValidationException::withMessages([
                    'connection' => 'This social connection belongs to an audited transport mapping and must be disconnected instead of deleted.',
                ]);
            }

            $connection->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function completeAuthorization(string $platform, array $payload): array
    {
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

        $this->assertDirectManagementConnection($connection);

        return $this->withDeliveryMutationLock(
            (int) $connection->id,
            fn (): array => $this->completeAuthorizationUnderDeliveryLock($platform, $payload),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function completeAuthorizationUnderDeliveryLock(string $platform, array $payload): array
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

        $claimMarker = $this->claimOauthCallback($connection, $platform, $state);

        $owner = $connection->relationLoaded('user')
            ? $connection->user
            : $connection->user()->first();

        if (! $owner || ! $owner->hasCompanyFeature('social')) {
            $message = 'Malikia Pulse is disabled for this workspace. Re-enable the social module before reconnecting this account.';

            $connection = $this->finalizeOauthCallback($connection, $claimMarker, [
                'status' => SocialAccountConnection::STATUS_RECONNECT_REQUIRED,
                'is_active' => false,
                'oauth_state' => null,
                'oauth_code_verifier' => null,
                'oauth_state_expires_at' => null,
                'last_error' => $message,
                'metadata' => $this->mergedMetadata($connection, $publisher, [
                    'connection_flow' => 'oauth_blocked_feature_off',
                    'oauth_ready' => false,
                ]),
            ]);

            return [
                'success' => false,
                'message' => $message,
                'redirect_route' => 'dashboard',
                'connection' => $this->payload($connection->fresh()),
            ];
        }

        if ($connection->oauth_state_expires_at && $connection->oauth_state_expires_at->isPast()) {
            $connection = $this->finalizeOauthCallback($connection, $claimMarker, [
                'status' => SocialAccountConnection::STATUS_RECONNECT_REQUIRED,
                'is_active' => false,
                'oauth_state' => null,
                'oauth_code_verifier' => null,
                'oauth_state_expires_at' => null,
                'last_error' => 'The connection request expired before the provider finished authorizing it.',
                'metadata' => $this->mergedMetadata($connection, $publisher, [
                    'connection_flow' => 'oauth_expired',
                    'oauth_ready' => false,
                ]),
            ]);

            return [
                'success' => false,
                'message' => 'The connection request expired before the provider finished authorizing it.',
                'connection' => $this->payload($connection->fresh()),
            ];
        }

        $providerErrorMessage = $this->providerCallbackErrorMessage($payload);
        if ($providerErrorMessage !== '') {
            $connection = $this->finalizeOauthCallback($connection, $claimMarker, [
                'status' => SocialAccountConnection::STATUS_RECONNECT_REQUIRED,
                'is_active' => false,
                'oauth_state' => null,
                'oauth_code_verifier' => null,
                'oauth_state_expires_at' => null,
                'last_error' => $providerErrorMessage,
                'metadata' => $this->mergedMetadata($connection, $publisher, [
                    'connection_flow' => 'oauth_error',
                    'oauth_ready' => false,
                ]),
            ]);

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

            $connection = $this->finalizeOauthCallback($connection, $claimMarker, [
                'status' => SocialAccountConnection::STATUS_RECONNECT_REQUIRED,
                'is_active' => false,
                'oauth_state' => null,
                'oauth_code_verifier' => null,
                'oauth_state_expires_at' => null,
                'last_error' => $message,
                'metadata' => $this->mergedMetadata($connection, $publisher, [
                    'connection_flow' => 'oauth_error',
                    'oauth_ready' => false,
                ]),
            ]);

            return [
                'success' => false,
                'message' => $message,
                'connection' => $this->payload($connection->fresh()),
            ];
        } catch (ConnectionException $exception) {
            $message = 'The provider could not be reached while finishing the social account connection.';

            $connection = $this->finalizeOauthCallback($connection, $claimMarker, [
                'status' => SocialAccountConnection::STATUS_ERROR,
                'is_active' => false,
                'oauth_state' => null,
                'oauth_code_verifier' => null,
                'oauth_state_expires_at' => null,
                'last_error' => $message,
                'metadata' => $this->mergedMetadata($connection, $publisher, [
                    'connection_flow' => 'oauth_error',
                    'oauth_ready' => false,
                ]),
            ]);

            return [
                'success' => false,
                'message' => $message,
                'connection' => $this->payload($connection->fresh()),
            ];
        }

        try {
            $status = (string) ($result['status'] ?? SocialAccountConnection::STATUS_CONNECTED);
            $now = Carbon::now();
            $connection = $this->finalizeOauthCallback(
                $connection,
                $claimMarker,
                function (SocialAccountConnection $claimedConnection) use (
                    $now,
                    $publisher,
                    $result,
                    $status,
                ): array {
                    $externalAccountId = $this->nullableString($result, 'external_account_id')
                        ?? $claimedConnection->external_account_id;

                    if ($status === SocialAccountConnection::STATUS_CONNECTED
                        && $externalAccountId === null) {
                        throw ValidationException::withMessages([
                            'external_account_id' => 'A native social destination identifier is required before this account can be connected.',
                        ]);
                    }

                    $this->ensureUniqueExternalAccountId(
                        (int) $claimedConnection->user_id,
                        (string) $claimedConnection->platform,
                        $externalAccountId,
                        (int) $claimedConnection->id,
                    );
                    $transportIdentity = $this->directTransportIdentityAttributes(
                        (int) $claimedConnection->user_id,
                        (string) $claimedConnection->platform,
                        $externalAccountId,
                        $claimedConnection,
                    );

                    if ($claimedConnection->logical_destination_key !== null) {
                        $externalAccountId = (string) $claimedConnection->external_account_id;
                    }

                    $permissions = array_values((array) (
                        $result['permissions'] ?? $claimedConnection->permissions ?? []
                    ));

                    return [
                        'auth_method' => (string) ($publisher->definition()['auth_method'] ?? SocialAccountConnection::AUTH_METHOD_OAUTH),
                        'credentials' => (array) ($result['credentials'] ?? []),
                        'permissions' => $permissions,
                        'status' => $status,
                        'is_active' => $status === SocialAccountConnection::STATUS_CONNECTED,
                        'display_name' => $this->nullableString($result, 'display_name') ?: $claimedConnection->display_name,
                        'account_handle' => $this->nullableString($result, 'account_handle') ?: $claimedConnection->account_handle,
                        'external_account_id' => $externalAccountId,
                        ...$transportIdentity,
                        'connected_at' => $status === SocialAccountConnection::STATUS_CONNECTED
                            ? ($claimedConnection->connected_at ?? $now)
                            : null,
                        'last_synced_at' => $now,
                        'token_expires_at' => $result['token_expires_at'] ?? null,
                        'last_error' => $status === SocialAccountConnection::STATUS_CONNECTED
                            ? null
                            : (string) ($result['message'] ?? 'Social account connection failed.'),
                        'metadata' => $this->mergedMetadata($claimedConnection, $publisher, [
                            'connection_flow' => 'oauth_connected',
                            'oauth_ready' => $status === SocialAccountConnection::STATUS_CONNECTED,
                            ...((array) ($result['metadata'] ?? [])),
                        ]),
                    ];
                },
            );
        } catch (ValidationException $exception) {
            $message = $this->validationMessage(
                $exception,
                sprintf('%s could not be connected.', $publisher->label())
            );
            $connection = $this->finalizeOauthCallback($connection, $claimMarker, [
                'status' => SocialAccountConnection::STATUS_RECONNECT_REQUIRED,
                'is_active' => false,
                'last_error' => $message,
                'metadata' => $this->mergedMetadata($connection, $publisher, [
                    'connection_flow' => 'oauth_error',
                    'oauth_ready' => false,
                ]),
            ]);

            return [
                'success' => false,
                'message' => $message,
                'connection' => $this->payload($connection),
            ];
        }

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
            'is_connected' => $this->isConnectedForPublishing($connection),
            'needs_attention' => $this->statusNeedsAttention($status),
            'has_credentials' => $credentials !== [],
            'has_refresh_token' => trim((string) ($credentials['refresh_token'] ?? '')) !== '',
            'oauth_pending' => trim((string) ($connection->oauth_state ?? '')) !== '',
            'oauth_callback_active' => $this->hasActiveOauthCallbackClaim($connection),
            'oauth_ready' => (bool) (($connection->metadata['oauth_ready'] ?? false)),
            'short_description' => $definition['short_description'] ?? null,
            'connected_at' => optional($connection->connected_at)->toIso8601String(),
            'last_synced_at' => optional($connection->last_synced_at)->toIso8601String(),
            'token_expires_at' => optional($connection->token_expires_at)->toIso8601String(),
            'last_tested_at' => data_get($connection->metadata, 'last_tested_at'),
            'last_test_status' => data_get($connection->metadata, 'last_test_status'),
            'last_test_message' => data_get($connection->metadata, 'last_test_message'),
            'last_error' => $connection->last_error,
            'metadata' => array_filter([
                'connection_flow' => data_get($connection->metadata, 'connection_flow'),
                'test_connection' => data_get($connection->metadata, 'test_connection'),
            ], fn ($value) => $value !== null),
        ];
    }

    private function claimOauthCallback(
        SocialAccountConnection $connection,
        string $platform,
        string $state
    ): string {
        $claimMarker = self::OAUTH_CALLBACK_CLAIM_PREFIX.Str::random(64);
        $claimed = SocialAccountConnection::query()
            ->whereKey($connection->id)
            ->where('platform', $platform)
            ->where('status', SocialAccountConnection::STATUS_PENDING)
            ->where('oauth_state', $state)
            ->update([
                'status' => SocialAccountConnection::STATUS_AUTHORIZING,
                'is_active' => false,
                'oauth_state' => $claimMarker,
                'oauth_code_verifier' => null,
                'oauth_state_expires_at' => Carbon::now()->addSeconds(self::OAUTH_CALLBACK_CLAIM_TTL_SECONDS),
            ]);

        if ($claimed !== 1) {
            throw ValidationException::withMessages([
                'state' => 'This social account callback is no longer valid or is already being processed.',
            ]);
        }

        return $claimMarker;
    }

    /**
     * @param  array<string, mixed>|callable(SocialAccountConnection): array<string, mixed>  $attributes
     */
    private function finalizeOauthCallback(
        SocialAccountConnection $connection,
        string $claimMarker,
        array|callable $attributes
    ): SocialAccountConnection {
        return DB::transaction(function () use ($attributes, $claimMarker, $connection): SocialAccountConnection {
            User::query()
                ->whereKey((int) $connection->user_id)
                ->lockForUpdate()
                ->firstOrFail();

            $claimedConnection = SocialAccountConnection::query()
                ->whereKey($connection->id)
                ->where('user_id', $connection->user_id)
                ->where('status', SocialAccountConnection::STATUS_AUTHORIZING)
                ->where('oauth_state', $claimMarker)
                ->lockForUpdate()
                ->first();

            if (! $claimedConnection
                || ! hash_equals($claimMarker, (string) $claimedConnection->oauth_state)) {
                throw ValidationException::withMessages([
                    'state' => 'This social account callback was superseded. Start the connection again if needed.',
                ]);
            }

            $resolvedAttributes = is_callable($attributes)
                ? $attributes($claimedConnection)
                : $attributes;

            $claimedConnection->forceFill([
                ...$resolvedAttributes,
                'oauth_state' => null,
                'oauth_code_verifier' => null,
                'oauth_state_expires_at' => null,
            ])->save();

            return $claimedConnection->fresh();
        }, 3);
    }

    private function hasActiveOauthCallbackClaim(SocialAccountConnection $connection): bool
    {
        if ((string) $connection->status !== SocialAccountConnection::STATUS_AUTHORIZING
            || ! str_starts_with((string) $connection->oauth_state, self::OAUTH_CALLBACK_CLAIM_PREFIX)) {
            return false;
        }

        return ! $connection->oauth_state_expires_at instanceof Carbon
            || $connection->oauth_state_expires_at->isFuture();
    }

    private function assertOwnership(User $owner, SocialAccountConnection $connection): void
    {
        if ((int) $connection->user_id !== (int) $owner->id) {
            abort(404);
        }
    }

    private function assertDirectManagementConnection(SocialAccountConnection $connection): void
    {
        $this->assertDirectManagementEnabled();

        if ((string) $connection->delivery_provider === SocialAccountConnection::DELIVERY_PROVIDER_BUFFER
            || (string) $connection->transport_generation === SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1
            || (bool) data_get($connection->metadata, 'buffer.catalog_only', false)) {
            throw ValidationException::withMessages([
                'connection' => 'Manage this Buffer catalog channel from the local Buffer panel.',
            ]);
        }
    }

    private function assertDirectManagementEnabled(): void
    {
        if ($this->bufferOnlyModeEnabled()) {
            throw ValidationException::withMessages([
                'connection' => 'Direct social connections are disabled. Manage social channels through Buffer.',
            ]);
        }
    }

    private function bufferOnlyModeEnabled(): bool
    {
        return (bool) config('services.buffer.delivery.enabled', false);
    }

    private function isConnectedForPublishing(SocialAccountConnection $connection): bool
    {
        if (! $connection->is_active
            || (string) $connection->status !== SocialAccountConnection::STATUS_CONNECTED) {
            return false;
        }

        return ! $this->bufferOnlyModeEnabled() || $connection->usesBufferPublishingTransport();
    }

    private function statusNeedsAttention(string $status): bool
    {
        return in_array($status, [
            SocialAccountConnection::STATUS_DRAFT,
            SocialAccountConnection::STATUS_PENDING,
            SocialAccountConnection::STATUS_AUTHORIZING,
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
            'oauth_state' => null,
            'oauth_code_verifier' => null,
            'oauth_state_expires_at' => null,
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
            ->except(['oauth_code_verifier'])
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

    /**
     * @return array{delivery_provider:?string,transport_generation:?string,logical_destination_key:?string}
     */
    private function directTransportIdentityAttributes(
        int $ownerId,
        string $platform,
        ?string $externalAccountId,
        ?SocialAccountConnection $existingConnection = null,
    ): array {
        if ($externalAccountId === null) {
            if ($existingConnection?->logical_destination_key !== null) {
                throw ValidationException::withMessages([
                    'external_account_id' => 'Create a new social connection to use a different destination.',
                ]);
            }

            return [
                'delivery_provider' => null,
                'transport_generation' => null,
                'logical_destination_key' => null,
            ];
        }

        try {
            $logicalDestinationKey = $this->logicalDestinationKeys->deriveForLegacyConnection(
                (string) $ownerId,
                $platform,
                $externalAccountId,
            );
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages([
                'external_account_id' => 'The social account destination identifier is not valid.',
            ]);
        }

        $this->ensureUniqueLogicalDestinationKey(
            $ownerId,
            $logicalDestinationKey,
            $existingConnection?->id,
        );

        if ($existingConnection?->logical_destination_key !== null) {
            if ((string) $existingConnection->delivery_provider
                    !== SocialAccountConnection::DELIVERY_PROVIDER_DIRECT
                || (string) $existingConnection->transport_generation
                    !== SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1
                || ! hash_equals(
                    (string) $existingConnection->logical_destination_key,
                    $logicalDestinationKey
                )) {
                throw ValidationException::withMessages([
                    'external_account_id' => 'Create a new social connection to use a different destination.',
                ]);
            }

            return [
                'delivery_provider' => (string) $existingConnection->delivery_provider,
                'transport_generation' => (string) $existingConnection->transport_generation,
                'logical_destination_key' => (string) $existingConnection->logical_destination_key,
            ];
        }

        return [
            'delivery_provider' => SocialAccountConnection::DELIVERY_PROVIDER_DIRECT,
            'transport_generation' => SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1,
            'logical_destination_key' => $logicalDestinationKey,
        ];
    }

    private function ensureUniqueLogicalDestinationKey(
        int $ownerId,
        string $logicalDestinationKey,
        ?int $ignoreConnectionId = null,
    ): void {
        $query = SocialAccountConnection::query()
            ->byUser($ownerId)
            ->where('logical_destination_key', $logicalDestinationKey);

        if ($ignoreConnectionId) {
            $query->whereKeyNot($ignoreConnectionId);
        }

        if (! $query->exists()) {
            return;
        }

        throw ValidationException::withMessages([
            'external_account_id' => 'This logical social destination is already connected.',
        ]);
    }

    /**
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    private function withDeliveryMutationLock(int $connectionId, callable $callback): mixed
    {
        if (($this->deliveryMutationLockDepth[$connectionId] ?? 0) > 0) {
            $this->deliveryMutationLockDepth[$connectionId]++;

            try {
                return $callback();
            } finally {
                $this->deliveryMutationLockDepth[$connectionId]--;
            }
        }

        $lock = $this->deliveryMutex->acquire($connectionId);

        if ($lock === null) {
            throw ValidationException::withMessages([
                'connection' => 'A Pulse delivery is using this social connection. Retry this change shortly.',
            ]);
        }

        $this->deliveryMutationLockDepth[$connectionId] = 1;

        try {
            return $callback();
        } finally {
            unset($this->deliveryMutationLockDepth[$connectionId]);
            $lock->release();
        }
    }

    /**
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    private function withTenantMutationLock(int $tenantId, callable $callback): mixed
    {
        $lock = $this->deliveryMutex->acquireTenant($tenantId);

        if ($lock === null) {
            throw ValidationException::withMessages([
                'connection' => 'This Pulse workspace is changing its social connections. Retry shortly.',
            ]);
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }
}
