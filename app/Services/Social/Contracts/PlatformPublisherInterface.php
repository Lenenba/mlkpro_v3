<?php

namespace App\Services\Social\Contracts;

use App\Models\SocialAccountConnection;

interface PlatformPublisherInterface
{
    public function key(): string;

    public function label(): string;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array;

    /**
     * @return array{redirect_url: string, metadata?: array<string, mixed>}
     */
    public function beginAuthorization(SocialAccountConnection $connection, string $state): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function completeAuthorization(SocialAccountConnection $connection, array $payload): array;

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    public function refreshCredentials(array $credentials): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function publish(SocialAccountConnection $connection, array $payload): array;
}
