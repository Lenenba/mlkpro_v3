<?php

namespace App\Services\Campaigns\Providers\Contracts;

interface ProspectProviderAdapter
{
    public function key(): string;

    public function label(): string;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function credentialFields(): array;

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
