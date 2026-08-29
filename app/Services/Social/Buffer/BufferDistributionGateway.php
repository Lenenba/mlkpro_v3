<?php

namespace App\Services\Social\Buffer;

use App\Data\Social\CreateSocialDeliveryData;
use App\Data\Social\SocialDeliveryResultData;
use App\Exceptions\Social\DefinitiveSocialPublishingRejectionException;
use App\Exceptions\Social\RetryableSocialPublishingException;
use App\Models\SocialAccountConnection;
use App\Models\SocialBufferConnection;
use App\Models\User;
use App\Services\Social\Contracts\SocialDistributionGatewayInterface;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonException;
use Throwable;

final class BufferDistributionGateway implements SocialDistributionGatewayInterface
{
    private const CREATE_POST_MUTATION = <<<'GRAPHQL'
        mutation MalikiaPulseBufferCreatePost($input: CreatePostInput!) {
          createPost(input: $input) {
            __typename
            ... on PostActionSuccess {
              post {
                id
                channelId
                channelService
                dueAt
                schedulingType
                sentAt
                sharedNow
                shareMode
                status
              }
            }
            ... on MutationError {
              message
            }
          }
        }
        GRAPHQL;

    private const DEFINITIVE_REJECTION_TYPES = [
        'InvalidInputError',
        'LimitReachedError',
        'NotFoundError',
        'UnauthorizedError',
    ];

    private const MAX_RESPONSE_BYTES = 1_048_576;

    private const PROVIDER_STATUSES = [
        'draft',
        'error',
        'needs_approval',
        'scheduled',
        'sending',
        'sent',
    ];

    public function __construct(
        private readonly BufferOAuthService $oauth,
    ) {}

    public function createPost(CreateSocialDeliveryData $delivery): SocialDeliveryResultData
    {
        try {
            [$owner, $connection] = $this->validatedContext($delivery);
        } catch (InvalidArgumentException $exception) {
            throw new DefinitiveSocialPublishingRejectionException(
                'The Buffer delivery configuration is invalid.',
                previous: $exception,
            );
        }

        try {
            $accessToken = $this->oauth->accessToken($owner);
        } catch (ValidationException $exception) {
            throw RetryableSocialPublishingException::provenSafeForCreateRetry(
                'Buffer authorization was unavailable before submission.',
                previous: $exception,
            );
        }

        try {
            $response = Http::acceptJson()
                ->withToken($accessToken)
                ->connectTimeout($this->connectTimeout())
                ->timeout($this->timeout())
                ->post((string) config('services.buffer.local_connector.api_url'), [
                    'query' => self::CREATE_POST_MUTATION,
                    'variables' => [
                        'input' => $this->createInput($delivery, $connection),
                    ],
                ]);
        } catch (ConnectionException) {
            return SocialDeliveryResultData::unknown();
        }

        $body = $response->body();

        if (strlen($body) > self::MAX_RESPONSE_BYTES) {
            return SocialDeliveryResultData::unknown();
        }

        try {
            $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return SocialDeliveryResultData::unknown();
        }

        if (! is_array($payload)) {
            return SocialDeliveryResultData::unknown();
        }

        $submitted = $response->successful() && $this->hasNoGraphqlErrors($payload)
            ? $this->submittedResult($payload, $delivery, $connection)
            : null;

        if ($submitted !== null) {
            return $submitted;
        }

        $rejectionType = $this->definitiveRejectionType($payload);

        if ($response->successful()
            && $this->hasNoGraphqlErrors($payload)
            && $rejectionType !== null) {
            throw new DefinitiveSocialPublishingRejectionException(
                sprintf('Buffer rejected the social delivery (%s).', $rejectionType),
            );
        }

        return SocialDeliveryResultData::unknown();
    }

    /** @return array<string, mixed> */
    private function createInput(
        CreateSocialDeliveryData $delivery,
        SocialAccountConnection $connection,
    ): array {
        $assets = array_map(
            fn (array $asset): array => $this->bufferAsset($asset),
            $delivery->assets,
        );
        $text = trim($delivery->text);
        $metadata = $this->postMetadata($connection, $delivery->linkUrl, $assets === []);

        if ($delivery->linkUrl !== null && ($assets !== [] || $metadata === [])) {
            $text = $this->textWithLink($text, $delivery->linkUrl);
        }

        $input = [
            'assets' => $assets,
            'channelId' => $delivery->externalChannelId,
            'mode' => $delivery->mode === CreateSocialDeliveryData::MODE_IMMEDIATE
                ? 'shareNow'
                : 'customScheduled',
            'needsApproval' => false,
            'saveToDraft' => false,
            'schedulingType' => 'automatic',
            'text' => $text,
        ];

        if ($metadata !== []) {
            $input['metadata'] = $metadata;
        }

        if ($delivery->scheduledFor !== null) {
            $input['dueAt'] = $delivery->scheduledFor->utc()->toIso8601ZuluString();
        }

        return $input;
    }

    /**
     * @return array{User, SocialAccountConnection}
     */
    private function validatedContext(CreateSocialDeliveryData $delivery): array
    {
        if (! (bool) config('services.buffer.delivery.enabled', false)) {
            throw new InvalidArgumentException('Buffer social delivery is disabled.');
        }

        $owner = User::query()->find($delivery->tenantId);
        $connection = SocialAccountConnection::query()
            ->whereKey($delivery->connectionId)
            ->where('user_id', $delivery->tenantId)
            ->where('external_account_id', $delivery->externalChannelId)
            ->where('delivery_provider', SocialAccountConnection::DELIVERY_PROVIDER_BUFFER)
            ->where('transport_generation', SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1)
            ->where('status', SocialAccountConnection::STATUS_CONNECTED)
            ->where('is_active', true)
            ->first();

        if (! $owner instanceof User
            || ! $connection instanceof SocialAccountConnection
            || (bool) data_get($connection->metadata, 'buffer.catalog_only', false)
            || ! (bool) data_get($connection->metadata, 'buffer.publication_enabled', false)
            || ! (bool) data_get($connection->metadata, 'buffer.standalone_destination', false)
            || ! $this->channelServiceMatchesPlatform(
                data_get($connection->metadata, 'buffer.channel_service'),
                (string) $connection->platform,
            )
            || ! $this->identifiersMatch(
                data_get($connection->metadata, 'buffer.organization_id'),
                $delivery->externalOrganizationId,
            )) {
            throw new InvalidArgumentException('The Buffer social delivery identity is invalid.');
        }

        $oauthConnection = SocialBufferConnection::query()
            ->whereBelongsTo($owner)
            ->first();

        if (! $oauthConnection instanceof SocialBufferConnection
            || ! $this->identifiersMatch(
                data_get($connection->metadata, 'buffer.account_id'),
                $oauthConnection->buffer_account_id,
            )
            || ! in_array('posts:write', (array) $oauthConnection->scopes, true)) {
            throw new InvalidArgumentException('The Buffer social delivery authorization is invalid.');
        }

        return [$owner, $connection];
    }

    private function identifiersMatch(mixed $actual, mixed $expected): bool
    {
        return is_string($actual)
            && is_string($expected)
            && $actual !== ''
            && $expected !== ''
            && hash_equals($expected, $actual);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function submittedResult(
        array $payload,
        CreateSocialDeliveryData $delivery,
        SocialAccountConnection $connection,
    ): ?SocialDeliveryResultData {
        if (data_get($payload, 'data.createPost.__typename') !== 'PostActionSuccess') {
            return null;
        }

        $post = data_get($payload, 'data.createPost.post');

        if (! is_array($post)) {
            return null;
        }

        $providerPostId = $post['id'] ?? null;

        if (! is_string($providerPostId)
            || preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $providerPostId) !== 1
            || ! $this->identifiersMatch($post['channelId'] ?? null, $delivery->externalChannelId)
            || ! $this->channelServiceMatchesPlatform(
                $post['channelService'] ?? null,
                (string) $connection->platform,
            )) {
            return null;
        }

        return SocialDeliveryResultData::submitted(
            providerPostId: $providerPostId,
            providerStatus: $this->providerStatus($post['status'] ?? null),
            remoteScheduledFor: $this->remoteScheduledFor($post['dueAt'] ?? null),
        );
    }

    /**
     * @param  array<string, mixed>  $asset
     * @return array<string, mixed>
     */
    private function bufferAsset(array $asset): array
    {
        $type = (string) $asset['type'];
        $url = (string) $asset['url'];

        if ($type === 'image') {
            $image = ['url' => $url];
            $altText = trim((string) ($asset['alt_text'] ?? ''));

            if ($altText !== '') {
                $image['metadata'] = ['altText' => $altText];
            }

            return ['image' => $image];
        }

        if ($type === 'video') {
            $video = ['url' => $url];
            $metadata = array_filter([
                'thumbnailOffset' => $asset['thumbnail_offset'] ?? null,
                'title' => trim((string) ($asset['title'] ?? '')) ?: null,
            ], fn (mixed $value): bool => $value !== null);

            if ($metadata !== []) {
                $video['metadata'] = $metadata;
            }

            return ['video' => $video];
        }

        return [
            'document' => [
                'thumbnailUrl' => (string) $asset['thumbnail_url'],
                'title' => (string) $asset['title'],
                'url' => $url,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function postMetadata(
        SocialAccountConnection $connection,
        ?string $linkUrl,
        bool $withoutAssets,
    ): array {
        $platform = (string) $connection->platform;

        if ($platform === SocialAccountConnection::PLATFORM_FACEBOOK) {
            return [
                'facebook' => array_filter([
                    'type' => 'post',
                    'linkAttachment' => $linkUrl !== null && $withoutAssets
                        ? ['url' => $linkUrl]
                        : null,
                ], fn (mixed $value): bool => $value !== null),
            ];
        }

        if ($platform === SocialAccountConnection::PLATFORM_LINKEDIN
            && $linkUrl !== null
            && $withoutAssets) {
            return [
                'linkedin' => [
                    'linkAttachment' => ['url' => $linkUrl],
                ],
            ];
        }

        return [];
    }

    private function textWithLink(string $text, string $linkUrl): string
    {
        if (str_contains($text, $linkUrl)) {
            return $text;
        }

        return $text === '' ? $linkUrl : $text."\n\n".$linkUrl;
    }

    private function channelServiceMatchesPlatform(mixed $service, string $platform): bool
    {
        if (! is_string($service)) {
            return false;
        }

        $normalizedService = strtolower(trim($service));

        return match ($platform) {
            SocialAccountConnection::PLATFORM_FACEBOOK => $normalizedService === 'facebook',
            SocialAccountConnection::PLATFORM_INSTAGRAM => $normalizedService === 'instagram',
            SocialAccountConnection::PLATFORM_LINKEDIN => $normalizedService === 'linkedin',
            SocialAccountConnection::PLATFORM_X => in_array($normalizedService, ['twitter', 'x'], true),
            default => false,
        };
    }

    private function providerStatus(mixed $status): ?string
    {
        return is_string($status) && in_array($status, self::PROVIDER_STATUSES, true)
            ? $status
            : null;
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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function definitiveRejectionType(array $payload): ?string
    {
        $responseType = data_get($payload, 'data.createPost.__typename');

        return is_string($responseType)
            && in_array($responseType, self::DEFINITIVE_REJECTION_TYPES, true)
                ? $responseType
                : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hasNoGraphqlErrors(array $payload): bool
    {
        return ! array_key_exists('errors', $payload) || $payload['errors'] === [];
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
