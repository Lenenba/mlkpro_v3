<?php

namespace App\Services\Social\Providers;

use App\Models\SocialAccountConnection;
use App\Services\Social\Contracts\PlatformPublisherInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

abstract class AbstractPlatformPublisher implements PlatformPublisherInterface
{
    public function definition(): array
    {
        return [
            'key' => $this->key(),
            'label' => $this->label(),
            'platform' => $this->key(),
            'target_type' => $this->targetType(),
            'auth_method' => SocialAccountConnection::AUTH_METHOD_OAUTH,
            'supports_multiple_accounts' => true,
            'supports' => $this->supports(),
            'short_description' => $this->description(),
            'scopes' => $this->scopes(),
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function supports(): array
    {
        return [
            'text',
            'image',
            'link',
            'schedule',
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function scopes(): array
    {
        return [];
    }

    public function beginAuthorization(SocialAccountConnection $connection, string $state): array
    {
        throw ValidationException::withMessages([
            'platform' => sprintf('%s does not support OAuth redirects yet.', $this->label()),
        ]);
    }

    public function completeAuthorization(SocialAccountConnection $connection, array $payload): array
    {
        throw ValidationException::withMessages([
            'platform' => sprintf('%s callback handling is not implemented.', $this->label()),
        ]);
    }

    public function refreshCredentials(array $credentials): array
    {
        throw ValidationException::withMessages([
            'platform' => sprintf('%s token refresh is not implemented.', $this->label()),
        ]);
    }

    public function publish(SocialAccountConnection $connection, array $payload): array
    {
        if ($this->publishRunsInFakeMode()) {
            return [
                'provider_post_id' => sprintf('%s-%s', $this->key(), Str::lower((string) Str::ulid())),
                'published_at' => Carbon::now()->toIso8601String(),
                'metadata' => [
                    'transport' => 'fake',
                    'target_id' => $this->publishTargetId($connection),
                    'platform' => $this->key(),
                ],
                'message' => sprintf('%s published.', $this->label()),
            ];
        }

        $publishUrl = $this->publishUrl();
        if ($publishUrl === '') {
            throw ValidationException::withMessages([
                'platform' => sprintf('Configure the publishing endpoint for %s before sending this Pulse post.', $this->label()),
            ]);
        }

        $accessToken = trim((string) data_get($connection->credentials, 'access_token'));
        if ($accessToken === '') {
            throw ValidationException::withMessages([
                'platform' => sprintf('%s must be reconnected before it can publish.', $this->label()),
            ]);
        }

        $response = Http::acceptJson()
            ->timeout($this->publishTimeout())
            ->withToken($accessToken)
            ->post($publishUrl, $this->publishRequestData($connection, $payload));

        if (! $response->successful()) {
            throw ValidationException::withMessages([
                'platform' => $this->publishResponseMessage(
                    $response->json() ?? [],
                    sprintf('%s rejected this Pulse publication.', $this->label())
                ),
            ]);
        }

        return $this->normalizePublishResponse($connection, $payload, (array) ($response->json() ?? []));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function publishRequestData(SocialAccountConnection $connection, array $payload): array
    {
        return array_filter([
            'platform' => $this->key(),
            'target_id' => $this->publishTargetId($connection),
            'connection_id' => $connection->id,
            'text' => trim((string) ($payload['text'] ?? '')) ?: null,
            'image_url' => trim((string) ($payload['image_url'] ?? '')) ?: null,
            'link_url' => trim((string) ($payload['link_url'] ?? '')) ?: null,
            'scheduled_for' => $payload['scheduled_for'] ?? null,
            'source_type' => $payload['source_type'] ?? null,
            'source_id' => $payload['source_id'] ?? null,
            'metadata' => (array) ($payload['metadata'] ?? []),
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    protected function normalizePublishResponse(
        SocialAccountConnection $connection,
        array $payload,
        array $response
    ): array {
        $providerPostId = trim((string) (
            $response['provider_post_id']
            ?? $response['post_id']
            ?? $response['id']
            ?? ''
        ));

        return [
            'provider_post_id' => $providerPostId !== '' ? $providerPostId : null,
            'published_at' => $response['published_at'] ?? Carbon::now()->toIso8601String(),
            'metadata' => array_filter([
                'transport' => 'http',
                'platform' => $this->key(),
                'target_id' => $this->publishTargetId($connection),
                'provider_url' => $response['provider_url'] ?? $response['url'] ?? null,
                'provider_response' => $response !== [] ? $response : null,
            ], fn ($value) => $value !== null && $value !== ''),
            'message' => trim((string) ($response['message'] ?? '')) ?: sprintf('%s published.', $this->label()),
        ];
    }

    protected function publishRunsInFakeMode(): bool
    {
        return (bool) config(
            sprintf('services.social.%s.publish.fake', $this->key()),
            app()->environment(['local', 'testing'])
        );
    }

    protected function publishUrl(): string
    {
        return trim((string) config(sprintf('services.social.%s.publish.url', $this->key()), ''));
    }

    protected function publishTimeout(): int
    {
        return max(5, (int) config(sprintf('services.social.%s.publish.timeout', $this->key()), 20));
    }

    protected function publishTargetId(SocialAccountConnection $connection): string
    {
        $targetId = trim((string) (
            $connection->external_account_id
            ?? data_get($connection->metadata, 'provider_target_id')
            ?? ''
        ));

        if ($targetId === '') {
            throw ValidationException::withMessages([
                'platform' => sprintf('%s is missing its provider target identifier.', $this->label()),
            ]);
        }

        return $targetId;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function publishResponseMessage(array $response, string $fallback): string
    {
        $candidates = [
            data_get($response, 'message'),
            data_get($response, 'error_description'),
            data_get($response, 'error.message'),
            data_get($response, 'error'),
            $fallback,
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return $fallback;
    }

    abstract protected function targetType(): string;

    abstract protected function description(): string;
}
