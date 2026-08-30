<?php

use App\Data\Social\CreateSocialDeliveryData;
use App\Data\Social\SocialDeliveryResultData;
use App\Exceptions\Social\DefinitiveSocialPublishingRejectionException;
use App\Exceptions\Social\UnpublishableSocialMediaUrlException;
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
function bufferDistributionGatewayFixture(
    string $platform = SocialAccountConnection::PLATFORM_FACEBOOK,
    string $channelService = 'facebook',
): array {
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
        'platform' => $platform,
        'label' => 'Buffer Social Channel',
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
                'channel_service' => $channelService,
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

/**
 * @param  array{
 *     text?: string,
 *     assets?: list<array<string, mixed>>,
 *     linkUrl?: string|null
 * }  $overrides
 */
function immediateBufferDelivery(
    User $owner,
    SocialAccountConnection $connection,
    array $overrides = [],
): CreateSocialDeliveryData {
    return CreateSocialDeliveryData::immediate(
        tenantId: (int) $owner->id,
        connectionId: (int) $connection->id,
        externalOrganizationId: 'buffer-organization-test',
        externalChannelId: 'buffer-facebook-channel-test',
        text: $overrides['text'] ?? 'Publication Facebook immédiate',
        assets: $overrides['assets'] ?? [],
        linkUrl: $overrides['linkUrl'] ?? null,
        idempotencyKey: 'buffer-delivery-immediate',
        correlationKey: 'buffer-correlation-immediate',
    );
}

function bufferPostSuccess(
    string $status,
    ?string $dueAt = null,
    string $channelService = 'facebook',
): array {
    return [
        'data' => [
            'createPost' => [
                '__typename' => 'PostActionSuccess',
                'post' => [
                    'id' => 'buffer-post-test',
                    'channelId' => 'buffer-facebook-channel-test',
                    'channelService' => $channelService,
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
                'mode' => 'shareNow',
                'needsApproval' => false,
                'saveToDraft' => false,
                'schedulingType' => 'automatic',
                'text' => 'Publication Facebook immédiate',
                'metadata' => ['facebook' => ['type' => 'post']],
            ]
            && ! str_contains($request->body(), 'buffer-delivery-immediate')
            && ! str_contains($request->body(), 'buffer-correlation-immediate');
    });
    Http::assertSentCount(1);
});

it('maps ordered Buffer images with optional alt text', function () {
    ['gateway' => $gateway, 'owner' => $owner, 'connection' => $connection] = bufferDistributionGatewayFixture();
    Http::fake([
        'https://buffer.test/graphql' => Http::response(bufferPostSuccess('sending')),
    ]);

    $result = $gateway->createPost(immediateBufferDelivery($owner, $connection, [
        'assets' => [
            [
                'type' => 'image',
                'url' => 'https://cdn.example.com/pulse-cover.jpg',
                'alt_text' => 'Présentation Malikia Pulse',
            ],
            [
                'type' => 'image',
                'url' => 'https://cdn.example.com/pulse-details.jpg',
            ],
        ],
    ]));

    expect($result->status)->toBe(SocialDeliveryResultData::STATUS_SUBMITTED);
    Http::assertSent(fn (Request $request): bool => data_get(
        $request->data(),
        'variables.input.assets',
    ) === [
        [
            'image' => [
                'url' => 'https://cdn.example.com/pulse-cover.jpg',
                'metadata' => [
                    'altText' => 'Présentation Malikia Pulse',
                ],
            ],
        ],
        [
            'image' => [
                'url' => 'https://cdn.example.com/pulse-details.jpg',
            ],
        ],
    ]);
    Http::assertSentCount(1);
});

it('rejects private media hosts before any Buffer request', function (string $url, string $type = 'image') {
    ['owner' => $owner, 'connection' => $connection] = bufferDistributionGatewayFixture();

    expect(fn () => immediateBufferDelivery($owner, $connection, [
        'assets' => [[
            'type' => $type,
            'url' => $url,
        ]],
    ]))->toThrow(
        UnpublishableSocialMediaUrlException::class,
        'configure SOCIAL_MEDIA_PUBLIC_BASE_URL',
    );

    Http::assertNothingSent();
})->with([
    'Herd test domain' => 'https://malikia.test/storage/social/posts/76/photo.png',
    'Herd test domain with trailing dot' => 'https://malikia.test./storage/social/posts/76/photo.png',
    'Herd test domain video' => [
        'https://malikia.test/storage/social/posts/76/video.mp4',
        'video',
    ],
    'localhost' => 'https://localhost/storage/photo.png',
    'localhost with trailing dot' => 'https://localhost./storage/photo.png',
    'private IPv4' => 'https://10.20.30.40/storage/photo.png',
    'private IPv4 with trailing dot' => 'https://127.0.0.1./storage/photo.png',
]);

it('accepts a public IPv6 media host during delivery preflight', function () {
    ['owner' => $owner, 'connection' => $connection] = bufferDistributionGatewayFixture();

    $delivery = immediateBufferDelivery($owner, $connection, [
        'assets' => [[
            'type' => 'image',
            'url' => 'https://[2606:4700:4700::1111]/storage/photo.png',
        ]],
    ]);

    expect($delivery->assets)->toBe([[
        'type' => 'image',
        'url' => 'https://[2606:4700:4700::1111]/storage/photo.png',
    ]]);
    Http::assertNothingSent();
});

it('submits a media-only Buffer video with its metadata', function () {
    ['gateway' => $gateway, 'owner' => $owner, 'connection' => $connection] = bufferDistributionGatewayFixture();
    Http::fake([
        'https://buffer.test/graphql' => Http::response(bufferPostSuccess('sending')),
    ]);

    $result = $gateway->createPost(immediateBufferDelivery($owner, $connection, [
        'text' => '',
        'assets' => [[
            'type' => 'video',
            'url' => 'https://cdn.example.com/pulse-demo.mp4',
            'title' => 'Démo Malikia Pulse',
            'thumbnail_offset' => 2000,
        ]],
    ]));

    expect($result->status)->toBe(SocialDeliveryResultData::STATUS_SUBMITTED);
    Http::assertSent(function (Request $request): bool {
        $input = data_get($request->data(), 'variables.input');

        return data_get($input, 'text') === ''
            && data_get($input, 'assets') === [[
                'video' => [
                    'url' => 'https://cdn.example.com/pulse-demo.mp4',
                    'metadata' => [
                        'thumbnailOffset' => 2000,
                        'title' => 'Démo Malikia Pulse',
                    ],
                ],
            ]];
    });
    Http::assertSentCount(1);
});

it('maps a Buffer document with its required title and thumbnail', function () {
    ['gateway' => $gateway, 'owner' => $owner, 'connection' => $connection] = bufferDistributionGatewayFixture();
    Http::fake([
        'https://buffer.test/graphql' => Http::response(bufferPostSuccess('sending')),
    ]);

    $result = $gateway->createPost(immediateBufferDelivery($owner, $connection, [
        'assets' => [[
            'type' => 'document',
            'url' => 'https://cdn.example.com/pulse-guide.pdf',
            'title' => 'Guide Malikia Pulse',
            'thumbnail_url' => 'https://cdn.example.com/pulse-guide-cover.jpg',
        ]],
    ]));

    expect($result->status)->toBe(SocialDeliveryResultData::STATUS_SUBMITTED);
    Http::assertSent(fn (Request $request): bool => data_get(
        $request->data(),
        'variables.input.assets',
    ) === [[
        'document' => [
            'thumbnailUrl' => 'https://cdn.example.com/pulse-guide-cover.jpg',
            'title' => 'Guide Malikia Pulse',
            'url' => 'https://cdn.example.com/pulse-guide.pdf',
        ],
    ]]);
    Http::assertSentCount(1);
});

it('maps a link-only Buffer post to Facebook link attachment metadata', function () {
    ['gateway' => $gateway, 'owner' => $owner, 'connection' => $connection] = bufferDistributionGatewayFixture();
    Http::fake([
        'https://buffer.test/graphql' => Http::response(bufferPostSuccess('sending')),
    ]);

    $result = $gateway->createPost(immediateBufferDelivery($owner, $connection, [
        'text' => '',
        'linkUrl' => 'https://malikiapro.com/pulse',
    ]));

    expect($result->status)->toBe(SocialDeliveryResultData::STATUS_SUBMITTED);
    Http::assertSent(function (Request $request): bool {
        $input = data_get($request->data(), 'variables.input');

        return data_get($input, 'assets') === []
            && data_get($input, 'text') === ''
            && data_get($input, 'metadata.facebook') === [
                'type' => 'post',
                'linkAttachment' => [
                    'url' => 'https://malikiapro.com/pulse',
                ],
            ];
    });
    Http::assertSentCount(1);
});

it('appends a media link to Buffer text without link attachment metadata', function () {
    ['gateway' => $gateway, 'owner' => $owner, 'connection' => $connection] = bufferDistributionGatewayFixture();
    Http::fake([
        'https://buffer.test/graphql' => Http::response(bufferPostSuccess('sending')),
    ]);

    $result = $gateway->createPost(immediateBufferDelivery($owner, $connection, [
        'text' => 'Publication avec média',
        'assets' => [[
            'type' => 'image',
            'url' => 'https://cdn.example.com/pulse-cover.jpg',
        ]],
        'linkUrl' => 'https://malikiapro.com/pulse',
    ]));

    expect($result->status)->toBe(SocialDeliveryResultData::STATUS_SUBMITTED);
    Http::assertSent(function (Request $request): bool {
        $input = data_get($request->data(), 'variables.input');

        return data_get($input, 'assets') === [[
            'image' => [
                'url' => 'https://cdn.example.com/pulse-cover.jpg',
            ],
        ]]
            && data_get($input, 'metadata.facebook') === ['type' => 'post']
            && data_get($input, 'text') === "Publication avec média\n\nhttps://malikiapro.com/pulse";
    });
    Http::assertSentCount(1);
});

it('submits through every supported Buffer channel identity', function (
    string $platform,
    string $channelService,
    array $expectedMetadata,
    string $expectedText,
) {
    ['gateway' => $gateway, 'owner' => $owner, 'connection' => $connection]
        = bufferDistributionGatewayFixture($platform, $channelService);
    Http::fake([
        'https://buffer.test/graphql' => Http::response(
            bufferPostSuccess('sending', channelService: $channelService),
        ),
    ]);

    $result = $gateway->createPost(immediateBufferDelivery($owner, $connection, [
        'text' => 'Publication Buffer',
        'linkUrl' => 'https://malikiapro.com/pulse',
    ]));

    expect($result->status)->toBe(SocialDeliveryResultData::STATUS_SUBMITTED);
    Http::assertSent(function (Request $request) use ($expectedMetadata, $expectedText): bool {
        $input = (array) data_get($request->data(), 'variables.input', []);

        return ($input['metadata'] ?? []) === $expectedMetadata
            && ($input['text'] ?? null) === $expectedText;
    });
    Http::assertSentCount(1);
})->with([
    'Instagram' => [
        SocialAccountConnection::PLATFORM_INSTAGRAM,
        'instagram',
        [],
        "Publication Buffer\n\nhttps://malikiapro.com/pulse",
    ],
    'LinkedIn' => [
        SocialAccountConnection::PLATFORM_LINKEDIN,
        'linkedin',
        ['linkedin' => ['linkAttachment' => ['url' => 'https://malikiapro.com/pulse']]],
        'Publication Buffer',
    ],
    'X through Twitter service' => [
        SocialAccountConnection::PLATFORM_X,
        'twitter',
        [],
        "Publication Buffer\n\nhttps://malikiapro.com/pulse",
    ],
    'X service alias' => [
        SocialAccountConnection::PLATFORM_X,
        'x',
        [],
        "Publication Buffer\n\nhttps://malikiapro.com/pulse",
    ],
]);

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
        assets: [],
        linkUrl: null,
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
        'mode' => 'customScheduled',
        'needsApproval' => false,
        'saveToDraft' => false,
        'schedulingType' => 'automatic',
        'text' => 'Publication Facebook programmée',
        'metadata' => ['facebook' => ['type' => 'post']],
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

it('turns a typed Buffer media rejection into a safe actionable message', function () {
    ['gateway' => $gateway, 'owner' => $owner, 'connection' => $connection] = bufferDistributionGatewayFixture();
    Http::fake([
        'https://buffer.test/graphql' => Http::response([
            'data' => [
                'createPost' => [
                    '__typename' => 'InvalidInputError',
                    'message' => 'Failed to create post: Failed to fetch image dimensions: Not Found token=secret-value',
                ],
            ],
        ]),
    ]);

    expect(fn () => $gateway->createPost(immediateBufferDelivery($owner, $connection, [
        'assets' => [[
            'type' => 'image',
            'url' => 'https://cdn.example.com/missing-image.png',
        ]],
    ])))->toThrow(
        DefinitiveSocialPublishingRejectionException::class,
        'Buffer could not access the media URL (InvalidInputError). Use a stable public HTTPS URL or configure SOCIAL_MEDIA_PUBLIC_BASE_URL for Pulse uploads.',
    );

    Http::assertSentCount(1);
});

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
