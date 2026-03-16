<?php

namespace App\Services\Campaigns;

use App\Models\CampaignProspectProviderConnection;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ProspectProviderConnectionService
{
    public function __construct(
        private readonly ProspectProviderRegistry $registry,
    ) {
    }

    /**
     * @return Collection<int, CampaignProspectProviderConnection>
     */
    public function listForOwner(User $owner): Collection
    {
        return CampaignProspectProviderConnection::query()
            ->where('user_id', $owner->id)
            ->orderBy('provider_key')
            ->orderBy('label')
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
            CampaignProspectProviderConnection::STATUS_DRAFT,
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
     */
    public function create(User $owner, array $payload): CampaignProspectProviderConnection
    {
        $providerKey = strtolower(trim((string) ($payload['provider_key'] ?? '')));
        $adapter = $this->registry->adapter($providerKey);
        $credentials = $this->normalizeCredentials($adapter->credentialFields(), (array) ($payload['credentials'] ?? []), true);

        return CampaignProspectProviderConnection::query()->create([
            'user_id' => $owner->id,
            'provider_key' => $providerKey,
            'label' => trim((string) ($payload['label'] ?? $adapter->label())),
            'credentials' => $credentials,
            'status' => CampaignProspectProviderConnection::STATUS_DRAFT,
            'is_active' => true,
            'metadata' => [
                'provider_label' => $adapter->label(),
                'credential_keys' => array_keys($credentials),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(
        User $owner,
        CampaignProspectProviderConnection $connection,
        array $payload
    ): CampaignProspectProviderConnection {
        $this->assertOwnership($owner, $connection);

        $providerKey = strtolower(trim((string) ($payload['provider_key'] ?? $connection->provider_key)));
        $adapter = $this->registry->adapter($providerKey);
        $mergedCredentials = $this->normalizeCredentials(
            $adapter->credentialFields(),
            (array) ($payload['credentials'] ?? []),
            false,
            (array) ($connection->credentials ?? [])
        );

        $connection->forceFill([
            'provider_key' => $providerKey,
            'label' => trim((string) ($payload['label'] ?? $connection->label)),
            'credentials' => $mergedCredentials,
            'metadata' => [
                ...((array) $connection->metadata),
                'provider_label' => $adapter->label(),
                'credential_keys' => array_keys($mergedCredentials),
            ],
        ])->save();

        return $connection->fresh();
    }

    public function validateConnection(User $owner, CampaignProspectProviderConnection $connection): CampaignProspectProviderConnection
    {
        $this->assertOwnership($owner, $connection);

        $adapter = $this->registry->adapter($connection->provider_key);
        $result = $adapter->validateCredentials((array) ($connection->credentials ?? []));
        $validatedAt = Carbon::now();

        $connection->forceFill([
            'status' => (string) ($result['status'] ?? CampaignProspectProviderConnection::STATUS_INVALID),
            'is_active' => (bool) ($result['ok'] ?? false),
            'last_validated_at' => $validatedAt,
            'last_error' => ($result['ok'] ?? false) ? null : (string) ($result['message'] ?? 'Validation failed.'),
            'metadata' => [
                ...((array) $connection->metadata),
                'provider_label' => $adapter->label(),
                'validated_message' => (string) ($result['message'] ?? ''),
            ],
        ])->save();

        return $connection->fresh();
    }

    public function disconnect(User $owner, CampaignProspectProviderConnection $connection): CampaignProspectProviderConnection
    {
        $this->assertOwnership($owner, $connection);

        $connection->forceFill([
            'status' => CampaignProspectProviderConnection::STATUS_DISCONNECTED,
            'is_active' => false,
            'last_error' => null,
        ])->save();

        return $connection->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(CampaignProspectProviderConnection $connection): array
    {
        $adapter = $this->registry->adapter($connection->provider_key);
        $credentials = (array) ($connection->credentials ?? []);

        return [
            'id' => $connection->id,
            'provider_key' => $connection->provider_key,
            'provider_label' => $adapter->label(),
            'label' => $connection->label,
            'status' => $connection->status,
            'is_active' => (bool) $connection->is_active,
            'has_credentials' => $credentials !== [],
            'credential_keys' => array_keys($credentials),
            'credential_fields' => $adapter->credentialFields(),
            'last_validated_at' => optional($connection->last_validated_at)->toIso8601String(),
            'last_error' => $connection->last_error,
            'metadata' => (array) ($connection->metadata ?? []),
        ];
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

    private function assertOwnership(User $owner, CampaignProspectProviderConnection $connection): void
    {
        if ((int) $connection->user_id !== (int) $owner->id) {
            abort(404);
        }
    }
}
