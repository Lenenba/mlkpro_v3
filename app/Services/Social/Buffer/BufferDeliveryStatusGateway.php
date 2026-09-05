<?php

namespace App\Services\Social\Buffer;

use App\Data\Social\ReadSocialDeliveryStatusData;
use App\Data\Social\SocialDeliveryStatusResultData;
use App\Models\SocialAccountConnection;
use App\Models\SocialBufferConnection;
use App\Models\User;
use App\Services\Social\Contracts\SocialDeliveryStatusGatewayInterface;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

final class BufferDeliveryStatusGateway implements SocialDeliveryStatusGatewayInterface
{
    /** @var list<string> */
    private const DELIVERY_PLATFORMS = [
        SocialAccountConnection::PLATFORM_FACEBOOK,
        SocialAccountConnection::PLATFORM_INSTAGRAM,
        SocialAccountConnection::PLATFORM_LINKEDIN,
        SocialAccountConnection::PLATFORM_X,
    ];

    private const READ_POST_QUERY = <<<'GRAPHQL'
        query MalikiaPulseBufferReadPost($input: PostInput!) {
          post(input: $input) {
            id
            channelId
            channelService
            dueAt
            status
          }
        }
        GRAPHQL;

    private const MAX_RESPONSE_BYTES = 1_048_576;

    private const PROVIDER_STATUS_MAP = [
        'draft' => SocialDeliveryStatusResultData::STATUS_DRAFT,
        'error' => SocialDeliveryStatusResultData::STATUS_ERROR,
        'needs_approval' => SocialDeliveryStatusResultData::STATUS_APPROVAL_REQUIRED,
        'scheduled' => SocialDeliveryStatusResultData::STATUS_SCHEDULED,
        'sending' => SocialDeliveryStatusResultData::STATUS_SENDING,
        'sent' => SocialDeliveryStatusResultData::STATUS_SENT,
    ];

    public function __construct(
        private readonly BufferOAuthService $oauth,
    ) {}

    public function readStatus(
        ReadSocialDeliveryStatusData $delivery,
    ): SocialDeliveryStatusResultData {
        [$owner, $connection] = $this->validatedContext($delivery);
        $accessToken = $this->oauth->accessToken($owner);
        $this->authorizedOAuthConnection($owner, $connection);

        try {
            $response = Http::acceptJson()
                ->withToken($accessToken)
                ->connectTimeout($this->connectTimeout())
                ->timeout($this->timeout())
                ->post((string) config('services.buffer.local_connector.api_url'), [
                    'query' => self::READ_POST_QUERY,
                    'variables' => [
                        'input' => [
                            'id' => $delivery->providerPostId,
                        ],
                    ],
                ]);
        } catch (ConnectionException) {
            $this->statusReadFailed();
        }

        if ($response->status() !== 200) {
            $this->statusReadFailed();
        }

        $body = $response->body();

        if (strlen($body) > self::MAX_RESPONSE_BYTES) {
            $this->statusReadFailed();
        }

        try {
            $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->statusReadFailed();
        }

        if (! is_array($payload)) {
            $this->statusReadFailed();
        }

        if (array_key_exists('errors', $payload) && $payload['errors'] !== []) {
            $this->statusReadFailed();
        }

        $post = data_get($payload, 'data.post');

        if (! is_array($post)) {
            $this->statusReadFailed();
        }

        if (! $this->responseIdentityMatches($post, $delivery, $connection)) {
            $this->statusReadFailed();
        }

        return $this->mappedResult($post);
    }

    /**
     * @return array{User, SocialAccountConnection}
     */
    private function validatedContext(
        ReadSocialDeliveryStatusData $delivery,
    ): array {
        if (! (bool) config('services.buffer.delivery.enabled', false)) {
            throw new InvalidArgumentException('Buffer social delivery status reads are disabled.');
        }

        if ($delivery->deliveryProvider !== SocialAccountConnection::DELIVERY_PROVIDER_BUFFER
            || $delivery->transportGeneration !== SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1
            || preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $delivery->providerPostId) !== 1) {
            throw new InvalidArgumentException('The Buffer social delivery status identity is invalid.');
        }

        $owner = User::query()->find($delivery->tenantId);
        $connection = SocialAccountConnection::query()
            ->whereKey($delivery->connectionId)
            ->where('user_id', $delivery->tenantId)
            ->whereIn('platform', self::DELIVERY_PLATFORMS)
            ->where('delivery_provider', SocialAccountConnection::DELIVERY_PROVIDER_BUFFER)
            ->where('transport_generation', SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1)
            ->where('status', SocialAccountConnection::STATUS_CONNECTED)
            ->where('is_active', true)
            ->first();

        if (! $owner instanceof User
            || ! $connection instanceof SocialAccountConnection
            || ! $this->identifiersMatch(
                $connection->logical_destination_key,
                $delivery->logicalDestinationKey,
            )
            || ! $this->channelServiceMatchesConnection(
                data_get($connection->metadata, 'buffer.channel_service'),
                $connection,
            )) {
            throw new InvalidArgumentException('The Buffer social delivery status identity is invalid.');
        }

        $this->authorizedOAuthConnection($owner, $connection);

        return [$owner, $connection];
    }

    private function authorizedOAuthConnection(
        User $owner,
        SocialAccountConnection $connection,
    ): SocialBufferConnection {
        $oauthConnection = SocialBufferConnection::query()
            ->whereBelongsTo($owner)
            ->first();

        if (! $oauthConnection instanceof SocialBufferConnection
            || ! $oauthConnection->isConnected()
            || ! $this->identifiersMatch(
                data_get($connection->metadata, 'buffer.account_id'),
                $oauthConnection->buffer_account_id,
            )
            || ! in_array('posts:read', (array) $oauthConnection->scopes, true)) {
            throw new InvalidArgumentException('The Buffer social delivery status authorization is invalid.');
        }

        return $oauthConnection;
    }

    /**
     * @param  array<string, mixed>  $post
     */
    private function responseIdentityMatches(
        array $post,
        ReadSocialDeliveryStatusData $delivery,
        SocialAccountConnection $connection,
    ): bool {
        $providerPostId = $post['id'] ?? null;

        return is_string($providerPostId)
            && preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $providerPostId) === 1
            && $this->identifiersMatch($providerPostId, $delivery->providerPostId)
            && $this->identifiersMatch(
                $post['channelId'] ?? null,
                $connection->external_account_id,
            )
            && $this->channelServiceMatchesConnection(
                $post['channelService'] ?? null,
                $connection,
            );
    }

    private function channelServiceMatchesConnection(
        mixed $service,
        SocialAccountConnection $connection,
    ): bool {
        if (! is_string($service)) {
            return false;
        }

        $platform = match (Str::lower(trim($service))) {
            'facebook' => SocialAccountConnection::PLATFORM_FACEBOOK,
            'instagram' => SocialAccountConnection::PLATFORM_INSTAGRAM,
            'linkedin' => SocialAccountConnection::PLATFORM_LINKEDIN,
            'twitter', 'x' => SocialAccountConnection::PLATFORM_X,
            default => null,
        };

        return $platform === (string) $connection->platform;
    }

    /**
     * @param  array<string, mixed>  $post
     */
    private function mappedResult(array $post): SocialDeliveryStatusResultData
    {
        $providerStatus = $post['status'] ?? null;

        if (! is_string($providerStatus)
            || ! array_key_exists($providerStatus, self::PROVIDER_STATUS_MAP)) {
            return $this->unknownResult('buffer_status_unknown');
        }

        $status = self::PROVIDER_STATUS_MAP[$providerStatus];

        return SocialDeliveryStatusResultData::observed(
            status: $status,
            observedAt: CarbonImmutable::now('UTC'),
            providerStatus: $providerStatus,
            remoteScheduledFor: $status === SocialDeliveryStatusResultData::STATUS_SCHEDULED
                ? $this->remoteScheduledFor($post['dueAt'] ?? null)
                : null,
            errorCode: $status === SocialDeliveryStatusResultData::STATUS_ERROR
                ? 'buffer_remote_delivery_failed'
                : null,
            errorMessage: $status === SocialDeliveryStatusResultData::STATUS_ERROR
                ? 'Buffer reported that the remote social delivery failed.'
                : null,
        );
    }

    private function unknownResult(string $errorCode): SocialDeliveryStatusResultData
    {
        return SocialDeliveryStatusResultData::observed(
            status: SocialDeliveryStatusResultData::STATUS_UNKNOWN,
            observedAt: CarbonImmutable::now('UTC'),
            errorCode: $errorCode,
            errorMessage: 'The Buffer social delivery status could not be confirmed.',
        );
    }

    private function statusReadFailed(): never
    {
        throw new RuntimeException('Buffer social delivery status could not be read.');
    }

    private function remoteScheduledFor(mixed $dueAt): ?CarbonImmutable
    {
        if (! is_string($dueAt) || $dueAt === '' || strlen($dueAt) > 64) {
            return null;
        }

        try {
            return CarbonImmutable::parse($dueAt)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function identifiersMatch(mixed $actual, mixed $expected): bool
    {
        return is_string($actual)
            && is_string($expected)
            && $actual !== ''
            && $expected !== ''
            && hash_equals($expected, $actual);
    }

    private function connectTimeout(): int
    {
        return max(1, min(10, (int) config('services.buffer.local_connector.connect_timeout', 5)));
    }

    private function timeout(): int
    {
        return max(1, min(30, (int) config('services.buffer.local_connector.timeout', 10)));
    }
}
