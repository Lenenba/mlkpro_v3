<?php

use App\Data\Social\ReadSocialDeliveryStatusData;
use App\Data\Social\SocialDeliveryStatusResultData;
use App\Models\SocialAccountConnection;
use App\Models\SocialBufferConnection;
use App\Models\User;
use App\Services\Social\Buffer\BufferDeliveryStatusGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $connectionOverrides
 * @param  array<string, mixed>  $oauthOverrides
 * @return array{gateway: BufferDeliveryStatusGateway, owner: User, connection: SocialAccountConnection}
 */
function bufferDeliveryStatusGatewayFixture(
    array $connectionOverrides = [],
    array $oauthOverrides = [],
    string $platform = SocialAccountConnection::PLATFORM_FACEBOOK,
    string $channelService = 'facebook',
): array {
    $owner = User::factory()->create();
    $accountId = 'buffer-status-account-test';
    $channelId = 'buffer-status-'.$channelService.'-channel-test';

    SocialBufferConnection::factory()->for($owner)->create([
        'buffer_account_id' => $accountId,
        'access_token' => 'buffer-status-access-token-test',
        'scopes' => ['account:read', 'posts:read', 'posts:write', 'offline_access'],
        'token_expires_at' => now()->addHour(),
        ...$oauthOverrides,
    ]);

    $connection = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => $platform,
        'label' => 'Buffer Social Channel',
        'external_account_id' => $channelId,
        'delivery_provider' => SocialAccountConnection::DELIVERY_PROVIDER_BUFFER,
        'transport_generation' => SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1,
        'logical_destination_key' => 'ldk:v1:'.str_repeat('b', 64),
        'auth_method' => SocialAccountConnection::AUTH_METHOD_OAUTH,
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
        'connected_at' => now(),
        'metadata' => [
            'connection_flow' => 'buffer_oauth',
            'buffer' => [
                'account_id' => $accountId,
                'organization_id' => 'buffer-status-organization-test',
                'channel_service' => $channelService,
                'channel_type' => 'page',
                'catalog_only' => false,
                'publication_enabled' => true,
                'standalone_destination' => true,
            ],
        ],
        ...$connectionOverrides,
    ]);

    return [
        'gateway' => app(BufferDeliveryStatusGateway::class),
        'owner' => $owner,
        'connection' => $connection,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function bufferStatusRead(
    User $owner,
    SocialAccountConnection $connection,
    array $overrides = [],
): ReadSocialDeliveryStatusData {
    return new ReadSocialDeliveryStatusData(
        tenantId: $overrides['tenant_id'] ?? (int) $owner->id,
        postId: $overrides['post_id'] ?? 101,
        targetId: $overrides['target_id'] ?? 202,
        connectionId: $overrides['connection_id'] ?? (int) $connection->id,
        providerPostId: $overrides['provider_post_id'] ?? 'buffer-status-post-test',
        deliveryProvider: $overrides['delivery_provider']
            ?? SocialAccountConnection::DELIVERY_PROVIDER_BUFFER,
        transportGeneration: $overrides['transport_generation']
            ?? SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1,
        logicalDestinationKey: $overrides['logical_destination_key']
            ?? (string) $connection->logical_destination_key,
    );
}

/**
 * @return array<string, mixed>
 */
function bufferStatusResponse(
    string $status,
    ?string $dueAt = null,
    string $channelId = 'buffer-status-facebook-channel-test',
    string $channelService = 'facebook',
): array {
    return [
        'data' => [
            'post' => [
                'id' => 'buffer-status-post-test',
                'channelId' => $channelId,
                'channelService' => $channelService,
                'dueAt' => $dueAt,
                'status' => $status,
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
});

it('maps each Buffer provider status to the normalized delivery status', function (
    string $providerStatus,
    string $expectedStatus,
    ?string $dueAt,
    ?string $expectedScheduledFor,
    ?string $expectedErrorCode,
) {
    ['gateway' => $gateway, 'owner' => $owner, 'connection' => $connection]
        = bufferDeliveryStatusGatewayFixture();
    Http::preventStrayRequests();
    Http::fake([
        'https://buffer.test/graphql' => Http::response(
            bufferStatusResponse($providerStatus, $dueAt),
        ),
    ]);

    $result = $gateway->readStatus(bufferStatusRead($owner, $connection));

    expect($result->status)->toBe($expectedStatus)
        ->and($result->observedAt->getTimezone()->getName())->toBe('UTC')
        ->and($result->providerStatus)->toBe($providerStatus)
        ->and($result->remoteScheduledFor?->toIso8601ZuluString())->toBe($expectedScheduledFor)
        ->and($result->errorCode)->toBe($expectedErrorCode)
        ->and($result->errorMessage)->toBe(
            $expectedErrorCode === null
                ? null
                : 'Buffer reported that the remote social delivery failed.',
        );
    Http::assertSentCount(1);
})->with([
    'draft' => [
        'draft',
        SocialDeliveryStatusResultData::STATUS_DRAFT,
        null,
        null,
        null,
    ],
    'needs approval' => [
        'needs_approval',
        SocialDeliveryStatusResultData::STATUS_APPROVAL_REQUIRED,
        null,
        null,
        null,
    ],
    'error' => [
        'error',
        SocialDeliveryStatusResultData::STATUS_ERROR,
        null,
        null,
        'buffer_remote_delivery_failed',
    ],
    'scheduled' => [
        'scheduled',
        SocialDeliveryStatusResultData::STATUS_SCHEDULED,
        '2026-09-03T09:15:00-04:00',
        '2026-09-03T13:15:00Z',
        null,
    ],
    'sending' => [
        'sending',
        SocialDeliveryStatusResultData::STATUS_SENDING,
        null,
        null,
        null,
    ],
    'sent' => [
        'sent',
        SocialDeliveryStatusResultData::STATUS_SENT,
        null,
        null,
        null,
    ],
]);

it('reads the status for every supported Buffer channel identity', function (
    string $platform,
    string $channelService,
) {
    ['gateway' => $gateway, 'owner' => $owner, 'connection' => $connection]
        = bufferDeliveryStatusGatewayFixture(
            platform: $platform,
            channelService: $channelService,
        );
    Http::preventStrayRequests();
    Http::fake([
        'https://buffer.test/graphql' => Http::response(bufferStatusResponse(
            'sent',
            channelId: (string) $connection->external_account_id,
            channelService: $channelService,
        )),
    ]);

    $result = $gateway->readStatus(bufferStatusRead($owner, $connection));

    expect($result->status)->toBe(SocialDeliveryStatusResultData::STATUS_SENT)
        ->and($connection->platform)->toBe($platform);
    Http::assertSentCount(1);
})->with([
    'Facebook' => [SocialAccountConnection::PLATFORM_FACEBOOK, 'facebook'],
    'Instagram' => [SocialAccountConnection::PLATFORM_INSTAGRAM, 'instagram'],
    'LinkedIn' => [SocialAccountConnection::PLATFORM_LINKEDIN, 'linkedin'],
    'X through Buffer Twitter service' => [SocialAccountConnection::PLATFORM_X, 'twitter'],
    'X service alias' => [SocialAccountConnection::PLATFORM_X, 'x'],
]);

it('sends the exact Buffer post query with the OAuth token and remote post ID', function () {
    ['gateway' => $gateway, 'owner' => $owner, 'connection' => $connection]
        = bufferDeliveryStatusGatewayFixture();
    Http::preventStrayRequests();
    Http::fake([
        'https://buffer.test/graphql' => Http::response(bufferStatusResponse('sent')),
    ]);

    $gateway->readStatus(bufferStatusRead($owner, $connection));

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://buffer.test/graphql'
            && $request->hasHeader(
                'Authorization',
                'Bearer buffer-status-access-token-test',
            )
            && str_contains((string) $request['query'], 'query MalikiaPulseBufferReadPost')
            && str_contains((string) $request['query'], 'post(input: $input)')
            && data_get($request->data(), 'variables.input') === [
                'id' => 'buffer-status-post-test',
            ];
    });
    Http::assertSentCount(1);
});

it('returns unknown without retaining an unsupported remote status', function () {
    ['gateway' => $gateway, 'owner' => $owner, 'connection' => $connection]
        = bufferDeliveryStatusGatewayFixture();
    Http::preventStrayRequests();
    Http::fake([
        'https://buffer.test/graphql' => Http::response(
            bufferStatusResponse('new_remote_status'),
        ),
    ]);

    $result = $gateway->readStatus(bufferStatusRead($owner, $connection));

    expect($result->status)->toBe(SocialDeliveryStatusResultData::STATUS_UNKNOWN)
        ->and($result->providerStatus)->toBeNull()
        ->and($result->remoteScheduledFor)->toBeNull()
        ->and($result->errorCode)->toBe('buffer_status_unknown')
        ->and($result->errorMessage)
        ->toBe('The Buffer social delivery status could not be confirmed.')
        ->not->toContain('new_remote_status');
    Http::assertSentCount(1);
});

it('rejects a cross tenant status read before sending an HTTP request', function () {
    ['gateway' => $gateway, 'connection' => $connection]
        = bufferDeliveryStatusGatewayFixture();
    $otherOwner = User::factory()->create();
    Http::preventStrayRequests();
    Http::fake([
        'https://buffer.test/graphql' => Http::response(bufferStatusResponse('sent')),
    ]);

    expect(fn () => $gateway->readStatus(bufferStatusRead(
        $otherOwner,
        $connection,
    )))->toThrow(
        InvalidArgumentException::class,
        'The Buffer social delivery status identity is invalid.',
    );
    Http::assertNothingSent();
});

it('rejects a non Buffer snapshot or mismatched destination identity', function (
    array $connectionOverrides,
    array $requestOverrides,
) {
    ['gateway' => $gateway, 'owner' => $owner, 'connection' => $connection]
        = bufferDeliveryStatusGatewayFixture($connectionOverrides);
    Http::preventStrayRequests();
    Http::fake([
        'https://buffer.test/graphql' => Http::response(bufferStatusResponse('sent')),
    ]);

    expect(fn () => $gateway->readStatus(bufferStatusRead(
        $owner,
        $connection,
        $requestOverrides,
    )))->toThrow(
        InvalidArgumentException::class,
        'The Buffer social delivery status identity is invalid.',
    );
    Http::assertNothingSent();
})->with([
    'mismatched platform and Buffer service' => [
        [
            'platform' => SocialAccountConnection::PLATFORM_INSTAGRAM,
            'metadata' => [
                'buffer' => [
                    'account_id' => 'buffer-status-account-test',
                    'channel_service' => 'facebook',
                ],
            ],
        ],
        [],
    ],
    'non Buffer provider snapshot' => [
        [],
        ['delivery_provider' => SocialAccountConnection::DELIVERY_PROVIDER_DIRECT],
    ],
    'wrong transport generation snapshot' => [
        [],
        ['transport_generation' => SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1],
    ],
    'mismatched logical destination key' => [
        [],
        ['logical_destination_key' => 'ldk:v1:'.str_repeat('c', 64)],
    ],
]);

it('rejects a Buffer OAuth grant without posts read before sending HTTP', function () {
    ['gateway' => $gateway, 'owner' => $owner, 'connection' => $connection]
        = bufferDeliveryStatusGatewayFixture(
            oauthOverrides: ['scopes' => ['account:read', 'posts:write', 'offline_access']],
        );
    Http::preventStrayRequests();
    Http::fake([
        'https://buffer.test/graphql' => Http::response(bufferStatusResponse('sent')),
    ]);

    expect(fn () => $gateway->readStatus(bufferStatusRead(
        $owner,
        $connection,
    )))->toThrow(
        InvalidArgumentException::class,
        'The Buffer social delivery status authorization is invalid.',
    );
    Http::assertNothingSent();
});

it('rejects a response for a different remote post or Buffer channel identity', function (
    string $path,
    string $value,
) {
    ['gateway' => $gateway, 'owner' => $owner, 'connection' => $connection]
        = bufferDeliveryStatusGatewayFixture();
    $payload = bufferStatusResponse('sent');
    data_set($payload, $path, $value);
    Http::preventStrayRequests();
    Http::fake([
        'https://buffer.test/graphql' => Http::response($payload),
    ]);

    expect(fn () => $gateway->readStatus(bufferStatusRead(
        $owner,
        $connection,
    )))->toThrow(
        RuntimeException::class,
        'Buffer social delivery status could not be read.',
    );
    Http::assertSentCount(1);
})->with([
    'different post ID' => ['data.post.id', 'different-buffer-post'],
    'different channel ID' => ['data.post.channelId', 'different-buffer-channel'],
    'different channel service' => ['data.post.channelService', 'instagram'],
]);

it('raises a safe read failure for HTTP and GraphQL errors without retrying', function (
    int $httpStatus,
    array $payload,
) {
    ['gateway' => $gateway, 'owner' => $owner, 'connection' => $connection]
        = bufferDeliveryStatusGatewayFixture();
    Http::preventStrayRequests();
    Http::fake([
        'https://buffer.test/graphql' => Http::response($payload, $httpStatus),
    ]);

    expect(fn () => $gateway->readStatus(bufferStatusRead(
        $owner,
        $connection,
    )))->toThrow(
        RuntimeException::class,
        'Buffer social delivery status could not be read.',
    );
    Http::assertSentCount(1);
})->with([
    'HTTP server error' => [
        503,
        ['message' => 'Remote password=hunter2 must never be retained.'],
    ],
    'GraphQL error' => [
        200,
        [
            'errors' => [
                ['message' => 'Remote token=private-value must never be retained.'],
            ],
            'data' => null,
        ],
    ],
]);

it('raises one safe read failure after a Buffer connection exception', function () {
    ['gateway' => $gateway, 'owner' => $owner, 'connection' => $connection]
        = bufferDeliveryStatusGatewayFixture();
    $requestCount = 0;
    Http::preventStrayRequests();
    Http::fake([
        'https://buffer.test/graphql' => function () use (&$requestCount) {
            $requestCount++;

            throw new ConnectionException(
                'Remote timeout included token=private-value.',
            );
        },
    ]);

    expect(fn () => $gateway->readStatus(bufferStatusRead(
        $owner,
        $connection,
    )))->toThrow(
        RuntimeException::class,
        'Buffer social delivery status could not be read.',
    );
    expect($requestCount)->toBe(1);
});
