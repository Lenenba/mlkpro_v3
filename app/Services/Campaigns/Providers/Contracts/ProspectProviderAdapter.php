<?php

namespace App\Services\Campaigns\Providers\Contracts;

use App\Models\CampaignProspectProviderConnection;

interface ProspectProviderAdapter
{
    public function key(): string;

    public function label(): string;

    public function authStrategy(): string;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function credentialFields(): array;

    public function beginAuthorization(CampaignProspectProviderConnection $connection, string $state): ?string;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function completeAuthorization(array $payload): array;

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    public function refreshCredentials(array $credentials): array;

    /**
     * @param  array<string, mixed>  $credentials
     * @return array{ok: bool, status: string, message: string, errors?: array<string, string>}
     */
    public function validateCredentials(array $credentials): array;

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $queryContext
     * @return array<int, array<string, mixed>>
     */
    public function fetchPreview(array $credentials, array $queryContext, int $limit = 25): array;

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    public function normalizePreviewRows(array $rows, array $context = []): array;
}
