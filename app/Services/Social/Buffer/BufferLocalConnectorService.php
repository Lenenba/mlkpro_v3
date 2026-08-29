<?php

namespace App\Services\Social\Buffer;

use App\Models\SocialAccountConnection;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class BufferLocalConnectorService
{
    public function __construct(
        private readonly BufferGraphqlClient $client,
    ) {}

    /**
     * @return array{
     *     enabled: bool,
     *     configured: bool,
     *     available: bool,
     *     local_only: true,
     *     mode: string,
     *     delivery_enabled: false
     * }
     */
    public function status(?User $owner = null): array
    {
        $enabled = (bool) config('services.buffer.local_connector.enabled', false);
        $configuredOwnerId = (int) config('services.buffer.local_connector.owner_id');
        $configured = $this->client->isConfigured() && $configuredOwnerId > 0;
        $ownerMatches = $owner !== null && (int) $owner->id === $configuredOwnerId;

        return [
            'enabled' => $enabled,
            'configured' => $configured,
            'available' => $this->isLocalEnvironment() && $enabled && $configured && $ownerMatches,
            'local_only' => true,
            'mode' => 'personal_access_token',
            'delivery_enabled' => false,
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

        $account = $this->client->account();
        $importedConnections = SocialAccountConnection::query()
            ->byUser($owner->id)
            ->get(['id', 'external_account_id', 'metadata'])
            ->filter(fn (SocialAccountConnection $connection): bool => (
                (bool) data_get($connection->metadata, 'buffer.catalog_only', false)
            ))
            ->keyBy('external_account_id');
        $organizations = [];
        $channelCount = 0;
        $importedCount = 0;

        foreach ($account['organizations'] as $organization) {
            $channels = [];

            foreach ($this->client->channels($organization['id']) as $channel) {
                if (! hash_equals($organization['id'], $channel['organization_id'])) {
                    throw ValidationException::withMessages([
                        'buffer' => 'Buffer a associé un canal à une organisation inattendue.',
                    ]);
                }

                $connection = $importedConnections->get($channel['id']);
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

        $account = $this->client->account();
        $organization = collect($account['organizations'])
            ->first(fn (array $candidate): bool => hash_equals($candidate['id'], $organizationId));

        if (! is_array($organization)) {
            throw ValidationException::withMessages([
                'organization_id' => 'Cette organisation n’appartient pas au compte Buffer connecté.',
            ]);
        }

        $channel = collect($this->client->channels($organizationId))
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

        return DB::transaction(function () use (
            $owner,
            $account,
            $organization,
            $channel,
            $platform,
        ): SocialAccountConnection {
            User::query()->whereKey($owner->id)->lockForUpdate()->firstOrFail();

            $connection = SocialAccountConnection::query()
                ->byUser($owner->id)
                ->where('platform', $platform)
                ->where('external_account_id', $channel['id'])
                ->lockForUpdate()
                ->first();

            if ($connection !== null && ! $this->isMutableCatalogImport($connection)) {
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

            $connection->fill([
                'label' => $this->limit((string) ($channel['display_name'] ?: $channel['name']), 120),
                'display_name' => $this->limit((string) ($channel['display_name'] ?: $channel['name']), 191),
                'account_handle' => $this->limit($channel['name'], 191),
                'auth_method' => SocialAccountConnection::AUTH_METHOD_MANUAL,
                'credentials' => null,
                'permissions' => $channel['scopes'],
                'status' => SocialAccountConnection::STATUS_CONNECTED,
                'is_active' => false,
                'connected_at' => $connection->connected_at ?? now(),
                'last_synced_at' => now(),
                'last_error' => null,
                'metadata' => [
                    ...$existingMetadata,
                    'connection_flow' => 'buffer_local_discovery',
                    'oauth_ready' => false,
                    'buffer' => [
                        'account_id' => $account['id'],
                        'organization_id' => $organization['id'],
                        'organization_name' => $organization['name'],
                        'channel_service' => $channel['service'],
                        'channel_type' => $channel['type'],
                        'timezone' => $channel['timezone'],
                        'allowed_actions' => $channel['allowed_actions'],
                        'is_queue_paused' => $channel['is_queue_paused'],
                        'credential_source' => 'server_environment',
                        'catalog_only' => true,
                    ],
                ],
            ]);
            $connection->save();

            return $connection->fresh();
        });
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
        ];
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

    private function assertAvailable(User $owner): void
    {
        $status = $this->status($owner);

        if (! $status['available']) {
            throw ValidationException::withMessages([
                'buffer' => 'Le connecteur Buffer local est désactivé ou incomplet.',
            ]);
        }
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
