<?php

namespace App\Services\Social\Buffer;

use App\Models\SocialAccountConnection;
use App\Models\SocialBufferConnection;
use App\Models\User;
use App\Services\Social\SocialConnectionDeliveryMutex;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class BufferLocalConnectorService
{
    /** @var list<string> */
    private const DELIVERY_PLATFORMS = [
        SocialAccountConnection::PLATFORM_FACEBOOK,
        SocialAccountConnection::PLATFORM_INSTAGRAM,
        SocialAccountConnection::PLATFORM_LINKEDIN,
        SocialAccountConnection::PLATFORM_X,
    ];

    public function __construct(
        private readonly BufferGraphqlClient $client,
        private readonly BufferOAuthService $oauth,
        private readonly SocialConnectionDeliveryMutex $deliveryMutex,
    ) {}

    /**
     * @return array{
     *     enabled: bool,
     *     configured: bool,
     *     available: bool,
     *     local_only: bool,
     *     mode: string,
     *     delivery_enabled: bool,
     *     oauth_configured: bool,
     *     connected: bool,
     *     authorizing: bool,
     *     can_connect: bool,
     *     can_disconnect: bool,
     *     account_name: ?string,
     *     token_expires_at: ?string
     * }
     */
    public function status(?User $owner = null): array
    {
        if ($owner !== null && $this->oauth->isConfigured()) {
            $oauthStatus = $this->oauth->status($owner);
            $deliveryAuthorized = $this->deliveryIsAuthorized($owner);

            return [
                'enabled' => true,
                'configured' => true,
                'available' => $oauthStatus['connected'],
                'local_only' => false,
                'mode' => 'oauth',
                'delivery_enabled' => $deliveryAuthorized
                    && $this->hasActiveDeliveryConnection($owner),
                'delivery_authorized' => $deliveryAuthorized,
                ...$oauthStatus,
            ];
        }

        $enabled = (bool) config('services.buffer.local_connector.enabled', false);
        $configuredOwnerId = (int) config('services.buffer.local_connector.owner_id');
        $configured = $this->client->isConfigured() && $configuredOwnerId > 0;
        $ownerMatches = $owner !== null && (int) $owner->id === $configuredOwnerId;
        $available = $this->isLocalEnvironment() && $enabled && $configured && $ownerMatches;

        return [
            'enabled' => $enabled,
            'configured' => $configured,
            'available' => $available,
            'local_only' => true,
            'mode' => 'personal_access_token',
            'delivery_enabled' => false,
            'oauth_configured' => false,
            'connected' => $available,
            'authorizing' => false,
            'can_connect' => false,
            'can_disconnect' => false,
            'account_name' => null,
            'token_expires_at' => null,
        ];
    }

    /**
     * @return array{
     *     connector: array<string, mixed>,
     *     account: array{name: ?string},
     *     organizations: list<array{id: string, name: string, channels: list<array<string, mixed>>}>,
     *     channel_count: int,
     *     imported_count: int
     * }
     */
    public function catalog(User $owner): array
    {
        $this->assertAvailable($owner);

        $accessToken = $this->accessToken($owner);
        $account = $this->client->account($accessToken);
        $importedConnections = SocialAccountConnection::query()
            ->byUser($owner->id)
            ->get([
                'id',
                'platform',
                'external_account_id',
                'delivery_provider',
                'transport_generation',
                'logical_destination_key',
                'status',
                'is_active',
                'metadata',
            ])
            ->filter(fn (SocialAccountConnection $connection): bool => (
                $this->isBufferManagedConnection($connection)
                || (bool) data_get($connection->metadata, 'buffer.catalog_only', false)
            ))
            ->keyBy(fn (SocialAccountConnection $connection): string => (
                $this->channelIdentityKey(
                    (string) $connection->platform,
                    (string) $connection->external_account_id,
                )
            ));
        $organizations = [];
        $channelCount = 0;
        $importedCount = 0;

        foreach ($account['organizations'] as $organization) {
            $channels = [];

            foreach ($this->client->channels($organization['id'], $accessToken) as $channel) {
                if (! hash_equals($organization['id'], $channel['organization_id'])) {
                    throw ValidationException::withMessages([
                        'buffer' => 'Buffer a associé un canal à une organisation inattendue.',
                    ]);
                }

                $platform = $this->platformForService($channel['service']);
                $connection = $platform === null
                    ? null
                    : $importedConnections->get(
                        $this->channelIdentityKey($platform, $channel['id']),
                    );
                $normalized = $this->catalogChannel($channel, $connection);
                $channels[] = $normalized;
                $channelCount++;
                $importedCount += $normalized['imported'] ? 1 : 0;
            }

            $organizations[] = [
                'id' => $organization['id'],
                'name' => $organization['name'],
                'channels' => $channels,
            ];
        }

        return [
            'connector' => $this->status($owner),
            'account' => [
                'name' => $account['name'],
            ],
            'organizations' => $organizations,
            'channel_count' => $channelCount,
            'imported_count' => $importedCount,
        ];
    }

    public function importChannel(
        User $owner,
        string $organizationId,
        string $channelId,
    ): SocialAccountConnection {
        $this->assertAvailable($owner);

        $accessToken = $this->accessToken($owner);
        $account = $this->client->account($accessToken);
        $organization = collect($account['organizations'])
            ->first(fn (array $candidate): bool => hash_equals($candidate['id'], $organizationId));

        if (! is_array($organization)) {
            throw ValidationException::withMessages([
                'organization_id' => 'Cette organisation n’appartient pas au compte Buffer connecté.',
            ]);
        }

        $channel = collect($this->client->channels($organizationId, $accessToken))
            ->first(fn (array $candidate): bool => hash_equals($candidate['id'], $channelId));

        if (! is_array($channel)
            || ! hash_equals($organizationId, (string) ($channel['organization_id'] ?? ''))) {
            throw ValidationException::withMessages([
                'channel_id' => 'Ce canal n’appartient pas à l’organisation Buffer sélectionnée.',
            ]);
        }

        $platform = $this->platformForService($channel['service']);

        if ($platform === null) {
            throw ValidationException::withMessages([
                'channel_id' => 'Ce type de canal Buffer n’est pas encore pris en charge par Pulse.',
            ]);
        }

        if ($channel['is_disconnected']) {
            throw ValidationException::withMessages([
                'channel_id' => 'Reconnectez ce canal dans Buffer avant de l’importer.',
            ]);
        }

        if ($channel['is_locked']) {
            throw ValidationException::withMessages([
                'channel_id' => 'Déverrouillez ce canal dans Buffer avant de l’importer.',
            ]);
        }

        $tenantLock = $this->deliveryMutex->acquireTenant((int) $owner->id);

        if ($tenantLock === null) {
            throw ValidationException::withMessages([
                'buffer' => 'Une publication Pulse est en cours. Réessayez l’import dans un instant.',
            ]);
        }

        $connectionLocks = [];

        try {
            $this->assertAvailable($owner);
            $connectionLocks = $this->acquireBufferConnectionLocks(
                $owner,
                'Une publication Buffer est en cours. Réessayez l’import dans un instant.',
            );

            return DB::transaction(function () use (
                $owner,
                $account,
                $organization,
                $channel,
                $platform,
            ): SocialAccountConnection {
                User::query()->whereKey($owner->id)->lockForUpdate()->firstOrFail();
                $this->assertCurrentBufferAccount($owner, $account['id']);

                return $this->upsertChannel(
                    $owner,
                    $account,
                    $organization,
                    $channel,
                    $platform,
                    $this->deliveryIsAuthorized($owner),
                );
            }, 3);
        } finally {
            $this->releaseConnectionLocks($connectionLocks);
            $tenantLock->release();
        }
    }

    /**
     * @return array{
     *     synced_count: int,
     *     active_count: int,
     *     skipped_count: int,
     *     connections: list<SocialAccountConnection>
     * }
     */
    public function syncAvailableChannels(User $owner): array
    {
        $this->assertAvailable($owner);

        $accessToken = $this->accessToken($owner);
        $account = $this->client->account($accessToken);
        $candidates = [];
        $skippedCount = 0;

        foreach ($account['organizations'] as $organization) {
            foreach ($this->client->channels($organization['id'], $accessToken) as $channel) {
                if (! hash_equals($organization['id'], $channel['organization_id'])) {
                    throw ValidationException::withMessages([
                        'buffer' => 'Buffer a associé un canal à une organisation inattendue.',
                    ]);
                }

                $platform = $this->platformForService($channel['service']);

                if ($platform === null || $channel['is_disconnected'] || $channel['is_locked']) {
                    $skippedCount++;

                    continue;
                }

                $candidates[] = [
                    'organization' => $organization,
                    'channel' => $channel,
                    'platform' => $platform,
                ];
            }
        }

        $tenantLock = $this->deliveryMutex->acquireTenant((int) $owner->id);

        if ($tenantLock === null) {
            throw ValidationException::withMessages([
                'buffer' => 'Une publication Pulse est en cours. Réessayez la synchronisation dans un instant.',
            ]);
        }

        $connectionLocks = [];

        try {
            $this->assertAvailable($owner);
            $connectionLocks = $this->acquireBufferConnectionLocks(
                $owner,
                'Une publication Buffer est en cours. Réessayez la synchronisation dans un instant.',
            );
            $result = DB::transaction(function () use (
                $owner,
                $account,
                $candidates,
            ): array {
                User::query()->whereKey($owner->id)->lockForUpdate()->firstOrFail();
                $this->assertCurrentBufferAccount($owner, $account['id']);
                $deliveryAuthorized = $this->deliveryIsAuthorized($owner);
                $synchronizedConnections = [];

                foreach ($candidates as $candidate) {
                    $synchronizedConnections[] = $this->upsertChannel(
                        $owner,
                        $account,
                        $candidate['organization'],
                        $candidate['channel'],
                        $candidate['platform'],
                        $deliveryAuthorized,
                    );
                }

                $healthyChannelKeys = collect($candidates)
                    ->map(fn (array $candidate): string => $this->channelIdentityKey(
                        $candidate['platform'],
                        $candidate['channel']['id'],
                    ))
                    ->values()
                    ->all();

                return [
                    'synchronized' => $synchronizedConnections,
                    'reconciled' => $this->reconcileUnavailableChannels(
                        $owner,
                        $account['id'],
                        $healthyChannelKeys,
                    ),
                ];
            }, 3);
        } finally {
            $this->releaseConnectionLocks($connectionLocks);
            $tenantLock->release();
        }

        $connections = [
            ...$result['synchronized'],
            ...$result['reconciled'],
        ];

        $activeCount = collect($connections)
            ->filter(fn (SocialAccountConnection $connection): bool => (
                $connection->is_active
                && $connection->status === SocialAccountConnection::STATUS_CONNECTED
                && $connection->usesBufferPublishingTransport()
            ))
            ->count();

        return [
            'synced_count' => count($result['synchronized']),
            'active_count' => $activeCount,
            'skipped_count' => $skippedCount,
            'connections' => $connections,
        ];
    }

    public function activateImportedChannels(User $owner): int
    {
        return $this->syncAvailableChannels($owner)['active_count'];
    }

    /**
     * @return list<Lock>
     */
    private function acquireBufferConnectionLocks(User $owner, string $busyMessage): array
    {
        $locks = [];

        try {
            $connectionIds = SocialAccountConnection::query()
                ->byUser($owner->id)
                ->where(function ($query): void {
                    $query
                        ->where('delivery_provider', SocialAccountConnection::DELIVERY_PROVIDER_BUFFER)
                        ->orWhere('metadata->buffer->catalog_only', true);
                })
                ->orderBy('id')
                ->pluck('id');

            foreach ($connectionIds as $connectionId) {
                $lock = $this->deliveryMutex->acquire((int) $connectionId);

                if ($lock === null) {
                    throw ValidationException::withMessages([
                        'buffer' => $busyMessage,
                    ]);
                }

                $locks[] = $lock;
            }
        } catch (Throwable $exception) {
            $this->releaseConnectionLocks($locks);

            throw $exception;
        }

        return $locks;
    }

    /**
     * @param  list<Lock>  $locks
     */
    private function releaseConnectionLocks(array $locks): void
    {
        foreach (array_reverse($locks) as $lock) {
            $lock->release();
        }
    }

    private function assertCurrentBufferAccount(User $owner, string $bufferAccountId): void
    {
        if (! $this->oauth->isConfigured()) {
            return;
        }

        $currentBufferAccountId = (string) SocialBufferConnection::query()
            ->whereBelongsTo($owner)
            ->lockForUpdate()
            ->value('buffer_account_id');

        if ($currentBufferAccountId === '' || ! hash_equals($currentBufferAccountId, $bufferAccountId)) {
            throw ValidationException::withMessages([
                'buffer' => 'Le compte Buffer connecté a changé. Rechargez les canaux avant de continuer.',
            ]);
        }
    }

    /**
     * @param  list<string>  $healthyChannelKeys
     * @return list<SocialAccountConnection>
     */
    private function reconcileUnavailableChannels(
        User $owner,
        string $bufferAccountId,
        array $healthyChannelKeys,
    ): array {
        $healthyChannels = array_fill_keys($healthyChannelKeys, true);
        $reconciled = [];
        $connections = SocialAccountConnection::query()
            ->byUser($owner->id)
            ->where(function ($query): void {
                $query
                    ->where('delivery_provider', SocialAccountConnection::DELIVERY_PROVIDER_BUFFER)
                    ->orWhere('metadata->buffer->catalog_only', true);
            })
            ->lockForUpdate()
            ->get();

        foreach ($connections as $connection) {
            $connectionBufferAccountId = (string) data_get(
                $connection->metadata,
                'buffer.account_id',
            );
            $externalAccountId = (string) $connection->external_account_id;
            $channelIdentityKey = $this->channelIdentityKey(
                (string) $connection->platform,
                $externalAccountId,
            );
            $belongsToCurrentBufferAccount = $connectionBufferAccountId !== ''
                && hash_equals($bufferAccountId, $connectionBufferAccountId);

            if (($belongsToCurrentBufferAccount
                    && $externalAccountId !== ''
                    && isset($healthyChannels[$channelIdentityKey]))
                || ! $this->isReconciliableBufferConnection($connection)) {
                continue;
            }

            $reconciled[] = $this->deactivateBufferConnection(
                $connection,
                match (true) {
                    $connectionBufferAccountId === '' => 'Ce canal ne peut plus être associé au compte Buffer connecté.',
                    $belongsToCurrentBufferAccount => 'Ce canal n’est plus disponible dans le catalogue Buffer connecté.',
                    default => 'Ce canal appartient à un ancien compte Buffer et doit être reconnecté.',
                },
            );
        }

        return $reconciled;
    }

    private function isReconciliableBufferConnection(
        SocialAccountConnection $connection,
    ): bool {
        $isCatalogConnection = (bool) data_get(
            $connection->metadata,
            'buffer.catalog_only',
            false,
        )
            && $connection->delivery_provider === null
            && $connection->transport_generation === null
            && $connection->logical_destination_key === null;

        return $connection->usesBufferPublishingTransport() || $isCatalogConnection;
    }

    private function deactivateBufferConnection(
        SocialAccountConnection $connection,
        string $message,
    ): SocialAccountConnection {
        $metadata = (array) ($connection->metadata ?? []);
        $bufferMetadata = (array) data_get($metadata, 'buffer', []);

        $connection->forceFill([
            'status' => SocialAccountConnection::STATUS_RECONNECT_REQUIRED,
            'is_active' => false,
            'last_synced_at' => now(),
            'last_error' => $message,
            'metadata' => [
                ...$metadata,
                'buffer' => [
                    ...$bufferMetadata,
                    'publication_enabled' => false,
                ],
            ],
        ])->save();

        return $connection->fresh();
    }

    private function channelIdentityKey(string $platform, string $externalAccountId): string
    {
        return $platform."\0".$externalAccountId;
    }

    /**
     * @param  array{id: string, name: ?string, organizations: list<array{id: string, name: string}>}  $account
     * @param  array{id: string, name: string}  $organization
     * @param  array{
     *     id: string,
     *     organization_id: string,
     *     name: string,
     *     display_name: ?string,
     *     service: string,
     *     type: string,
     *     is_disconnected: bool,
     *     is_locked: bool,
     *     is_queue_paused: bool,
     *     timezone: string,
     *     scopes: list<string>,
     *     allowed_actions: list<string>
     * }  $channel
     */
    private function upsertChannel(
        User $owner,
        array $account,
        array $organization,
        array $channel,
        string $platform,
        bool $deliveryAuthorized,
    ): SocialAccountConnection {
        $connection = SocialAccountConnection::query()
            ->byUser($owner->id)
            ->where('platform', $platform)
            ->where('external_account_id', $channel['id'])
            ->lockForUpdate()
            ->first();

        if ($connection !== null
            && ! $this->isMutableCatalogImport($connection)
            && ! $this->isBufferManagedConnection($connection)) {
            throw ValidationException::withMessages([
                'channel_id' => 'Ce canal est déjà lié à une connexion Pulse qui ne peut pas être remplacée.',
            ]);
        }

        $connection ??= new SocialAccountConnection([
            'user_id' => $owner->id,
            'platform' => $platform,
            'external_account_id' => $channel['id'],
        ]);

        $existingMetadata = (array) ($connection->metadata ?? []);
        $existingBufferMetadata = (array) data_get($existingMetadata, 'buffer', []);
        $canActivateDelivery = $deliveryAuthorized
            && in_array($platform, self::DELIVERY_PLATFORMS, true);
        $hasManagedIdentity = $this->isBufferManagedConnection($connection);
        $logicalDestinationKey = $hasManagedIdentity
            ? (string) $connection->logical_destination_key
            : ($canActivateDelivery ? $this->newLogicalDestinationKey() : null);

        $connection->fill([
            'label' => $this->limit((string) ($channel['display_name'] ?: $channel['name']), 120),
            'display_name' => $this->limit((string) ($channel['display_name'] ?: $channel['name']), 191),
            'account_handle' => $this->limit($channel['name'], 191),
            'delivery_provider' => $hasManagedIdentity || $canActivateDelivery
                ? SocialAccountConnection::DELIVERY_PROVIDER_BUFFER
                : null,
            'transport_generation' => $hasManagedIdentity || $canActivateDelivery
                ? SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1
                : null,
            'logical_destination_key' => $logicalDestinationKey,
            'auth_method' => $this->oauth->isConfigured()
                ? SocialAccountConnection::AUTH_METHOD_OAUTH
                : SocialAccountConnection::AUTH_METHOD_MANUAL,
            'credentials' => null,
            'permissions' => $channel['scopes'],
            'status' => $hasManagedIdentity && ! $canActivateDelivery
                ? SocialAccountConnection::STATUS_RECONNECT_REQUIRED
                : SocialAccountConnection::STATUS_CONNECTED,
            'is_active' => $canActivateDelivery,
            'connected_at' => $connection->connected_at ?? now(),
            'last_synced_at' => now(),
            'last_error' => null,
            'metadata' => [
                ...$existingMetadata,
                'connection_flow' => $this->oauth->isConfigured()
                    ? 'buffer_oauth_discovery'
                    : 'buffer_local_discovery',
                'oauth_ready' => $this->oauth->isConfigured(),
                'buffer' => [
                    ...$existingBufferMetadata,
                    'account_id' => $account['id'],
                    'organization_id' => $organization['id'],
                    'organization_name' => $organization['name'],
                    'channel_service' => $channel['service'],
                    'channel_type' => $channel['type'],
                    'timezone' => $channel['timezone'],
                    'allowed_actions' => $channel['allowed_actions'],
                    'is_queue_paused' => $channel['is_queue_paused'],
                    'credential_source' => $this->oauth->isConfigured()
                        ? 'oauth_account'
                        : 'server_environment',
                    'catalog_only' => ! ($hasManagedIdentity || $canActivateDelivery),
                    'publication_enabled' => $canActivateDelivery,
                    'standalone_destination' => $hasManagedIdentity || $canActivateDelivery,
                ],
            ],
        ]);
        $connection->save();

        return $connection->fresh();
    }

    private function isMutableCatalogImport(SocialAccountConnection $connection): bool
    {
        return (bool) data_get($connection->metadata, 'buffer.catalog_only', false)
            && $connection->logical_destination_key === null
            && $connection->delivery_provider === null
            && $connection->transport_generation === null
            && ! $connection->is_active
            && (array) ($connection->credentials ?? []) === []
            && ! $connection->socialPostTargets()->exists();
    }

    /**
     * @param  array<string, mixed>  $channel
     * @return array<string, mixed>
     */
    private function catalogChannel(
        array $channel,
        ?SocialAccountConnection $connection,
    ): array {
        $platform = $this->platformForService($channel['service']);
        $blockReason = match (true) {
            $platform === null => 'unsupported_service',
            $channel['is_disconnected'] => 'disconnected',
            $channel['is_locked'] => 'locked',
            default => null,
        };

        return [
            'id' => $channel['id'],
            'organization_id' => $channel['organization_id'],
            'name' => $channel['name'],
            'display_name' => $channel['display_name'],
            'service' => $channel['service'],
            'type' => $channel['type'],
            'is_disconnected' => $channel['is_disconnected'],
            'is_locked' => $channel['is_locked'],
            'is_queue_paused' => $channel['is_queue_paused'],
            'timezone' => $channel['timezone'],
            'platform' => $platform,
            'supported' => $platform !== null,
            'can_import' => $blockReason === null,
            'import_block_reason' => $blockReason,
            'imported' => $connection !== null,
            'publication_enabled' => $connection !== null
                && $this->isBufferManagedConnection($connection)
                && $connection->is_active
                && $connection->status === SocialAccountConnection::STATUS_CONNECTED,
        ];
    }

    private function isBufferManagedConnection(SocialAccountConnection $connection): bool
    {
        return (string) $connection->delivery_provider
                === SocialAccountConnection::DELIVERY_PROVIDER_BUFFER
            && (string) $connection->transport_generation
                === SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1
            && preg_match(
                '/\Aldk:v1:[0-9a-f]{64}\z/',
                (string) $connection->logical_destination_key,
            ) === 1
            && $this->bufferServiceMatchesConnection($connection)
            && data_get($connection->metadata, 'buffer.organization_id') !== null;
    }

    private function deliveryIsAuthorized(User $owner): bool
    {
        $requiredScopes = config('services.buffer.delivery.required_scopes', [
            'posts:read',
            'posts:write',
        ]);

        return (bool) config('services.buffer.delivery.enabled', false)
            && $this->oauth->isConfigured()
            && $this->oauth->hasGrantedScopes(
                $owner,
                is_array($requiredScopes) ? array_values($requiredScopes) : [],
            );
    }

    private function hasActiveDeliveryConnection(User $owner): bool
    {
        $bufferAccountId = (string) SocialBufferConnection::query()
            ->whereBelongsTo($owner)
            ->value('buffer_account_id');

        if ($bufferAccountId === '') {
            return false;
        }

        return SocialAccountConnection::query()
            ->byUser($owner->id)
            ->where('delivery_provider', SocialAccountConnection::DELIVERY_PROVIDER_BUFFER)
            ->where(
                'transport_generation',
                SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1,
            )
            ->connected()
            ->get(['platform', 'metadata'])
            ->contains(fn (SocialAccountConnection $connection): bool => (
                $this->bufferServiceMatchesConnection($connection)
                && hash_equals(
                    $bufferAccountId,
                    (string) data_get($connection->metadata, 'buffer.account_id'),
                )
                && (bool) data_get($connection->metadata, 'buffer.publication_enabled', false)
                && (bool) data_get($connection->metadata, 'buffer.standalone_destination', false)
                && ! (bool) data_get($connection->metadata, 'buffer.catalog_only', true)
            ));
    }

    private function newLogicalDestinationKey(): string
    {
        return 'ldk:v1:'.hash('sha256', random_bytes(32));
    }

    private function platformForService(string $service): ?string
    {
        return match (Str::lower(trim($service))) {
            'facebook' => SocialAccountConnection::PLATFORM_FACEBOOK,
            'instagram' => SocialAccountConnection::PLATFORM_INSTAGRAM,
            'linkedin' => SocialAccountConnection::PLATFORM_LINKEDIN,
            'twitter', 'x' => SocialAccountConnection::PLATFORM_X,
            default => null,
        };
    }

    private function bufferServiceMatchesConnection(
        SocialAccountConnection $connection,
    ): bool {
        $service = data_get($connection->metadata, 'buffer.channel_service');

        return is_string($service)
            && $this->platformForService($service) === (string) $connection->platform;
    }

    private function assertAvailable(User $owner): void
    {
        $status = $this->status($owner);

        if (! $status['available']) {
            throw ValidationException::withMessages([
                'buffer' => 'Le connecteur Buffer local est désactivé ou incomplet.',
            ]);
        }
    }

    private function accessToken(User $owner): string
    {
        if ($this->oauth->isConfigured()) {
            return $this->oauth->accessToken($owner);
        }

        return trim((string) config('services.buffer.local_connector.access_token'));
    }

    private function isLocalEnvironment(): bool
    {
        return app()->environment(['local', 'testing']);
    }

    private function limit(string $value, int $length): string
    {
        return mb_substr(trim($value), 0, $length);
    }
}
