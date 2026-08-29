<?php

use App\Data\Social\CreateSocialDeliveryData;
use App\Data\Social\SocialDeliveryResultData;
use App\Exceptions\Social\DefinitiveSocialPublishingRejectionException;
use App\Models\SocialAccountConnection;
use App\Models\SocialBufferConnection;
use App\Models\User;
use App\Services\Social\Buffer\BufferDistributionGateway;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * @return array{gateway: BufferDistributionGateway, owner: User, connection: SocialAccountConnection}
 */
function bufferDistributionGatewayFixture(): array
{
    $owner = User::factory()->create();
    $accountId = 'buffer-account-test';
    $organizationId = 'buffer-organization-test';
    $channelId = 'buffer-facebook-channel-test';

    SocialBufferConnection::factory()->for($owner)->create([
        'buffer_account_id' => $accountId,
        'access_token' => 'buffer-access-token-test',
        'scopes' => ['account:read', 'posts:read', 'posts:write', 'offline_access'],
        'token_expires_at' => now()->addHour(),
    ]);

    $connection = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
        'label' => 'Buffer Facebook Page',
        'external_account_id' => $channelId,
        'delivery_provider' => SocialAccountConnection::DELIVERY_PROVIDER_BUFFER,
        'transport_generation' => SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1,
        'logical_destination_key' => 'ldk:v1:'.str_repeat('a', 64),
        'auth_method' => SocialAccountConnection::AUTH_METHOD_OAUTH,
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
        'connected_at' => now(),
        'metadata' => [
            'connection_flow' => 'buffer_oauth',
            'buffer' => [
                'account_id' => $accountId,
                'organization_id' => $organizationId,
                'channel_service' => 'facebook',
                'channel_type' => 'page',
                'catalog_only' => false,
                'publication_enabled' => true,
                'standalone_destination' => true,
            ],
        ],
    ]);

    return [
        'gateway' => app(BufferDistributionGateway::class),
        'owner' => $owner,
        'connection' => $connection,
    ];
}

function immediateBufferDelivery(User $owner, SocialAccountConnection $connection): CreateSocialDeliveryData
{
    return CreateSocialDeliveryData::immediate(
        tenantId: (int) $owner->id,
        connectionId: (int) $connection->id,
        externalOrganizationId: 'buffer-organization-test',
        externalChannelId: 'buffer-facebook-channel-test',
        text: 'Publication Facebook immédiate',
        idempotencyKey: 'buffer-delivery-immediate',
        correlationKey: 'buffer-correlation-immediate',
    );
}

function bufferPostSuccess(
    string $status,
    ?string $dueAt = null,
): array {
    return [
        'data' => [
            'createPost' => [
                '__typename' => 'PostActionSuccess',
                'post' => [
                    'id' => 'buffer-post-test',
                    'channelId' => 'buffer-facebook-channel-test',
                    'channelService' => 'facebook',
                    'dueAt' => $dueAt,
                    'schedulingType' => 'automatic',
                    'sentAt' => null,
                    'sharedNow' => $status !== 'scheduled',
                    'shareMode' => $status === 'scheduled' ? 'customScheduled' : 'shareNow',
                    'status' => $status,
                ],
            ],
        ],
    ];
}

beforeEach(function () {
    config()->set('services.buffer.delivery.enabled', true);
    config()->set('services.buffer.local_connector', [
        'api_url' => 'https://buffer.test/graphql',
        'connect_timeout' => 2,
        'timeout' => 5,
    ]);

    Http::preventStrayRequests();
});

it('submits an immediate Buffer post with the exact safe shareNow input', function () {
    ['gateway' => $gateway, 'owner' => $owner, 'connection' => $connection] = bufferDistributionGatewayFixture();
    Http::fake([
        'https://buffer.test/graphql' => Http::response(bufferPostSuccess('sending')),
    ]);

    $result = $gateway->createPost(immediateBufferDelivery($owner, $connection));

    expect($result->status)->toBe(SocialDeliveryResultData::STATUS_SUBMITTED)
        ->and($result->providerPostId)->toBe('buffer-post-test')
        ->and($result->providerStatus)->toBe('sending')
        ->and($result->remoteScheduledFor)->toBeNull();

    Http::assertSent(function (Request $request): bool {
        $input = data_get($request->data(), 'variables.input');

        return $request->method() === 'POST'
            && $request->url() === 'https://buffer.test/graphql'
            && $request->hasHeader('Authorization', 'Bearer buffer-access-token-test')
            && str_contains((string) $request['query'], 'mutation MalikiaPulseBufferCreatePost')
            && $input === [
                'assets' => [],
                'channelId' => 'buffer-facebook-channel-test',
                'metadata' => ['facebook' => ['type' => 'post']],
                'mode' => 'shareNow',
                'needsApproval' => false,
                'saveToDraft' => false,
                'schedulingType' => 'automatic',
                'text' => 'Publication Facebook immédiate',
            ]
            && ! str_contains($request->body(), 'buffer-delivery-immediate')
            && ! str_contains($request->body(), 'buffer-correlation-immediate');
    });
    Http::assertSentCount(1);
});

it('submits a scheduled Buffer post with customScheduled and an exact UTC dueAt', function () {
    ['gateway' => $gateway, 'owner' => $owner, 'connection' => $connection] = bufferDistributionGatewayFixture();
    $scheduledFor = CarbonImmutable::parse('2026-09-03 09:15:00', 'America/Toronto');
    Http::fake([
        'https://buffer.test/graphql' => Http::response(
            bufferPostSuccess('scheduled', '2026-09-03T13:15:00Z'),
        ),
    ]);

    $result = $gateway->createPost(CreateSocialDeliveryData::scheduled(
        tenantId: (int) $owner->id,
        connectionId: (int) $connection->id,
        externalOrganizationId: 'buffer-organization-test',
        externalChannelId: 'buffer-facebook-channel-test',
        text: 'Publication Facebook programmée',
        scheduledFor: $scheduledFor,
        idempotencyKey: 'buffer-delivery-scheduled',
    ));

    expect($result->status)->toBe(SocialDeliveryResultData::STATUS_SUBMITTED)
        ->and($result->providerStatus)->toBe('scheduled')
        ->and($result->remoteScheduledFor?->toIso8601ZuluString())->toBe('2026-09-03T13:15:00Z');

    Http::assertSent(fn (Request $request): bool => data_get(
        $request->data(),
        'variables.input',
    ) === [
        'assets' => [],
        'channelId' => 'buffer-facebook-channel-test',
        'metadata' => ['facebook' => ['type' => 'post']],
        'mode' => 'customScheduled',
        'needsApproval' => false,
        'saveToDraft' => false,
        'schedulingType' => 'automatic',
        'text' => 'Publication Facebook programmée',
        'dueAt' => '2026-09-03T13:15:00Z',
    ]);
    Http::assertSentCount(1);
});

it('throws a definitive rejection for typed Buffer create errors without retrying', function (string $responseType) {
    ['gateway' => $gateway, 'owner' => $owner, 'connection' => $connection] = bufferDistributionGatewayFixture();
    Http::fake([
        'https://buffer.test/graphql' => Http::response([
            'data' => [
                'createPost' => [
                    '__typename' => $responseType,
                    'message' => 'Provider detail must not cross the gateway.',
                ],
            ],
        ]),
    ]);

    expect(fn () => $gateway->createPost(immediateBufferDelivery($owner, $connection)))
        ->toThrow(
            DefinitiveSocialPublishingRejectionException::class,
            sprintf('Buffer rejected the social delivery (%s).', $responseType),
        );

    Http::assertSentCount(1);
})->with([
    'invalid input' => 'InvalidInputError',
    'limit reached' => 'LimitReachedError',
    'not found' => 'NotFoundError',
    'unauthorized' => 'UnauthorizedError',
]);

it('returns an unknown result after a Buffer timeout without retrying', function () {
    ['gateway' => $gateway, 'owner' => $owner, 'connection' => $connection] = bufferDistributionGatewayFixture();
    $requestCount = 0;
    Http::fake(function () use (&$requestCount) {
        $requestCount++;

        throw new ConnectionException('Simulated Buffer timeout');
    });

    $result = $gateway->createPost(immediateBufferDelivery($owner, $connection));

    expect($result->status)->toBe(SocialDeliveryResultData::STATUS_UNKNOWN)
        ->and($result->providerPostId)->toBeNull()
        ->and($requestCount)->toBe(1);
});

it('returns an unknown result after a Buffer server error without retrying', function () {
    ['gateway' => $gateway, 'owner' => $owner, 'connection' => $connection] = bufferDistributionGatewayFixture();
    Http::fake([
        'https://buffer.test/graphql' => Http::response([
            'errors' => [
                ['message' => 'Internal Buffer failure.'],
            ],
        ], 503),
    ]);

    $result = $gateway->createPost(immediateBufferDelivery($owner, $connection));

    expect($result->status)->toBe(SocialDeliveryResultData::STATUS_UNKNOWN)
        ->and($result->providerPostId)->toBeNull();
    Http::assertSentCount(1);
});

it('rejects a success payload for a different Buffer channel as ambiguous', function () {
    ['gateway' => $gateway, 'owner' => $owner, 'connection' => $connection] = bufferDistributionGatewayFixture();
    $payload = bufferPostSuccess('sending');
    data_set($payload, 'data.createPost.post.channelId', 'another-buffer-channel');
    Http::fake([
        'https://buffer.test/graphql' => Http::response($payload),
    ]);

    $result = $gateway->createPost(immediateBufferDelivery($owner, $connection));

    expect($result->status)->toBe(SocialDeliveryResultData::STATUS_UNKNOWN)
        ->and($result->providerPostId)->toBeNull();
    Http::assertSentCount(1);
});

it('does not trust a success-shaped payload returned with an HTTP failure', function () {
    ['gateway' => $gateway, 'owner' => $owner, 'connection' => $connection] = bufferDistributionGatewayFixture();
    Http::fake([
        'https://buffer.test/graphql' => Http::response(bufferPostSuccess('sending'), 503),
    ]);

    $result = $gateway->createPost(immediateBufferDelivery($owner, $connection));

    expect($result->status)->toBe(SocialDeliveryResultData::STATUS_UNKNOWN)
        ->and($result->providerPostId)->toBeNull();
    Http::assertSentCount(1);
});

it('rejects a disabled local delivery before contacting Buffer', function () {
    ['gateway' => $gateway, 'owner' => $owner, 'connection' => $connection] = bufferDistributionGatewayFixture();
    config()->set('services.buffer.delivery.enabled', false);

    expect(fn () => $gateway->createPost(immediateBufferDelivery($owner, $connection)))
        ->toThrow(
            DefinitiveSocialPublishingRejectionException::class,
            'The Buffer delivery configuration is invalid.',
        );

    Http::assertNothingSent();
});
