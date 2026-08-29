<?php

use App\Data\Social\CreateSocialDeliveryData;
use App\Data\Social\SocialDeliveryResultData;
use App\Services\Social\Buffer\BufferDistributionGateway;
use App\Services\Social\Contracts\SocialDistributionGatewayInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

final class FakeSocialDistributionGateway implements SocialDistributionGatewayInterface
{
    /** @var list<CreateSocialDeliveryData> */
    private array $createdDeliveries = [];

    /** @var list<SocialDeliveryResultData> */
    private array $scriptedResults;

    public function __construct(SocialDeliveryResultData ...$scriptedResults)
    {
        if ($scriptedResults === []) {
            throw new InvalidArgumentException('At least one social delivery result must be scripted.');
        }

        $this->scriptedResults = $scriptedResults;
    }

    public function createPost(CreateSocialDeliveryData $delivery): SocialDeliveryResultData
    {
        $this->createdDeliveries[] = $delivery;

        $result = array_shift($this->scriptedResults);

        if (! $result instanceof SocialDeliveryResultData) {
            throw new LogicException('No social delivery result remains scripted.');
        }

        return $result;
    }

    /** @return list<CreateSocialDeliveryData> */
    public function createdDeliveries(): array
    {
        return $this->createdDeliveries;
    }
}

/**
 * @param  array{
 *     tenantId?: int,
 *     connectionId?: int,
 *     externalOrganizationId?: string,
 *     externalChannelId?: string,
 *     text?: string,
 *     assets?: list<array<string, mixed>>,
 *     linkUrl?: string|null,
 *     idempotencyKey?: string,
 *     correlationKey?: string|null
 * }  $overrides
 */
function immediateSocialDelivery(array $overrides = []): CreateSocialDeliveryData
{
    return CreateSocialDeliveryData::immediate(
        tenantId: $overrides['tenantId'] ?? 41,
        connectionId: $overrides['connectionId'] ?? 73,
        externalOrganizationId: $overrides['externalOrganizationId'] ?? 'organization-test',
        externalChannelId: $overrides['externalChannelId'] ?? 'facebook-channel-test',
        text: $overrides['text'] ?? 'Publication Facebook de test',
        assets: $overrides['assets'] ?? [],
        linkUrl: $overrides['linkUrl'] ?? null,
        idempotencyKey: $overrides['idempotencyKey'] ?? 'delivery-test-001',
        correlationKey: $overrides['correlationKey'] ?? null,
    );
}

beforeEach(function () {
    Http::preventStrayRequests();
});

test('immediate social delivery is explicit and has no schedule', function () {
    $delivery = immediateSocialDelivery(['correlationKey' => 'correlation-test-001']);

    expect($delivery->tenantId)->toBe(41)
        ->and($delivery->connectionId)->toBe(73)
        ->and($delivery->externalOrganizationId)->toBe('organization-test')
        ->and($delivery->externalChannelId)->toBe('facebook-channel-test')
        ->and($delivery->text)->toBe('Publication Facebook de test')
        ->and($delivery->assets)->toBe([])
        ->and($delivery->linkUrl)->toBeNull()
        ->and($delivery->mode)->toBe(CreateSocialDeliveryData::MODE_IMMEDIATE)
        ->and($delivery->scheduledFor)->toBeNull()
        ->and($delivery->idempotencyKey)->toBe('delivery-test-001')
        ->and($delivery->correlationKey)->toBe('correlation-test-001');
});

test('scheduled social delivery stores an immutable UTC instant', function () {
    $scheduledFor = CarbonImmutable::parse('2026-09-03 09:15:00', 'America/Toronto');

    $delivery = CreateSocialDeliveryData::scheduled(
        tenantId: 41,
        connectionId: 73,
        externalOrganizationId: 'organization-test',
        externalChannelId: 'facebook-channel-test',
        text: 'Publication Facebook programmée',
        assets: [],
        linkUrl: null,
        scheduledFor: $scheduledFor,
        idempotencyKey: 'delivery-test-002',
    );

    expect($delivery->mode)->toBe(CreateSocialDeliveryData::MODE_SCHEDULED)
        ->and($delivery->scheduledFor)->toBeInstanceOf(CarbonImmutable::class)
        ->and($delivery->scheduledFor?->format('Y-m-d H:i:s'))->toBe('2026-09-03 13:15:00')
        ->and($delivery->scheduledFor?->getTimezone()->getName())->toBe('UTC')
        ->and($scheduledFor->getTimezone()->getName())->toBe('America/Toronto');
});

test('social delivery preserves ordered Buffer assets and a destination link', function () {
    $assets = [
        [
            'type' => 'image',
            'url' => 'https://cdn.example.com/pulse-cover.jpg',
            'alt_text' => 'Présentation Malikia Pulse',
        ],
        [
            'type' => 'video',
            'url' => 'https://cdn.example.com/pulse-demo.mp4',
            'title' => 'Démo Malikia Pulse',
            'thumbnail_offset' => 2000,
        ],
        [
            'type' => 'document',
            'url' => 'https://cdn.example.com/pulse-guide.pdf',
            'title' => 'Guide Malikia Pulse',
            'thumbnail_url' => 'https://cdn.example.com/pulse-guide-cover.jpg',
        ],
    ];

    $delivery = immediateSocialDelivery([
        'assets' => $assets,
        'linkUrl' => 'https://malikiapro.com/pulse',
    ]);

    expect($delivery->assets)->toBe($assets)
        ->and($delivery->linkUrl)->toBe('https://malikiapro.com/pulse');
});

test('social delivery accepts media-only and link-only content', function () {
    $mediaOnly = immediateSocialDelivery([
        'text' => '',
        'assets' => [[
            'type' => 'image',
            'url' => 'https://cdn.example.com/pulse-cover.jpg',
        ]],
    ]);
    $linkOnly = immediateSocialDelivery([
        'text' => '',
        'linkUrl' => 'https://malikiapro.com/pulse',
    ]);

    expect($mediaOnly->text)->toBe('')
        ->and($mediaOnly->assets)->toBe([[
            'type' => 'image',
            'url' => 'https://cdn.example.com/pulse-cover.jpg',
        ]])
        ->and($linkOnly->text)->toBe('')
        ->and($linkOnly->linkUrl)->toBe('https://malikiapro.com/pulse');
});

test('social delivery rejects invalid tenant routing and idempotency identities', function () {
    expect(fn () => immediateSocialDelivery(['tenantId' => 0]))
        ->toThrow(InvalidArgumentException::class, 'The social delivery tenant ID must be positive.')
        ->and(fn () => immediateSocialDelivery(['connectionId' => -1]))
        ->toThrow(InvalidArgumentException::class, 'The social delivery connection ID must be positive.')
        ->and(fn () => immediateSocialDelivery(['externalOrganizationId' => '  ']))
        ->toThrow(InvalidArgumentException::class, 'The social delivery external organization ID must not be blank.')
        ->and(fn () => immediateSocialDelivery(['externalChannelId' => '']))
        ->toThrow(InvalidArgumentException::class, 'The social delivery external channel ID must not be blank.')
        ->and(fn () => immediateSocialDelivery(['idempotencyKey' => "\t"]))
        ->toThrow(InvalidArgumentException::class, 'The social delivery idempotency key must not be blank.')
        ->and(fn () => immediateSocialDelivery(['correlationKey' => '']))
        ->toThrow(InvalidArgumentException::class, 'The social delivery correlation key must not be blank.');
});

test('social delivery rejects empty or invalid Buffer content', function (array $overrides) {
    expect(fn () => immediateSocialDelivery($overrides))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'empty content' => [[
        'text' => "\n\t",
        'assets' => [],
        'linkUrl' => null,
    ]],
    'assets must be a list' => [[
        'assets' => [
            'type' => 'image',
            'url' => 'https://cdn.example.com/pulse-cover.jpg',
        ],
    ]],
    'unsupported asset type' => [[
        'assets' => [[
            'type' => 'audio',
            'url' => 'https://cdn.example.com/pulse-theme.mp3',
        ]],
    ]],
    'asset URL must use HTTPS' => [[
        'assets' => [[
            'type' => 'image',
            'url' => 'http://cdn.example.com/pulse-cover.jpg',
        ]],
    ]],
    'document title is required' => [[
        'assets' => [[
            'type' => 'document',
            'url' => 'https://cdn.example.com/pulse-guide.pdf',
            'thumbnail_url' => 'https://cdn.example.com/pulse-guide-cover.jpg',
        ]],
    ]],
    'document thumbnail is required' => [[
        'assets' => [[
            'type' => 'document',
            'url' => 'https://cdn.example.com/pulse-guide.pdf',
            'title' => 'Guide Malikia Pulse',
        ]],
    ]],
    'document thumbnail must use HTTPS' => [[
        'assets' => [[
            'type' => 'document',
            'url' => 'https://cdn.example.com/pulse-guide.pdf',
            'title' => 'Guide Malikia Pulse',
            'thumbnail_url' => 'http://cdn.example.com/pulse-guide-cover.jpg',
        ]],
    ]],
    'link URL must use HTTPS' => [[
        'linkUrl' => 'http://malikiapro.com/pulse',
    ]],
]);

test('social delivery results distinguish submitted and unknown outcomes', function () {
    $remoteScheduledFor = CarbonImmutable::parse('2026-09-03 09:15:00', 'America/Toronto');
    $submitted = SocialDeliveryResultData::submitted(
        providerPostId: 'post-test-001',
        providerStatus: 'draft',
        remoteScheduledFor: $remoteScheduledFor,
    );
    $unknown = SocialDeliveryResultData::unknown();

    expect($submitted->status)->toBe(SocialDeliveryResultData::STATUS_SUBMITTED)
        ->and($submitted->providerPostId)->toBe('post-test-001')
        ->and($submitted->providerStatus)->toBe('draft')
        ->and($submitted->remoteScheduledFor?->getTimezone()->getName())->toBe('UTC')
        ->and($unknown->status)->toBe(SocialDeliveryResultData::STATUS_UNKNOWN)
        ->and($unknown->providerPostId)->toBeNull()
        ->and($unknown->providerStatus)->toBeNull()
        ->and($unknown->remoteScheduledFor)->toBeNull();
});

test('submitted social delivery result rejects blank provider identities', function () {
    expect(fn () => SocialDeliveryResultData::submitted(''))
        ->toThrow(InvalidArgumentException::class, 'The social delivery provider post ID must not be blank.')
        ->and(fn () => SocialDeliveryResultData::submitted('post-test-001', '  '))
        ->toThrow(InvalidArgumentException::class, 'The social delivery provider status must not be blank.');
});

test('social delivery data objects remain final readonly and credential free', function () {
    $deliveryReflection = new ReflectionClass(CreateSocialDeliveryData::class);
    $resultReflection = new ReflectionClass(SocialDeliveryResultData::class);
    $deliveryPropertyNames = array_map(
        fn (ReflectionProperty $property): string => $property->getName(),
        $deliveryReflection->getProperties(),
    );
    $resultPropertyNames = array_map(
        fn (ReflectionProperty $property): string => $property->getName(),
        $resultReflection->getProperties(),
    );

    sort($deliveryPropertyNames);
    sort($resultPropertyNames);

    expect($deliveryReflection->isFinal())->toBeTrue()
        ->and($deliveryReflection->isReadOnly())->toBeTrue()
        ->and($deliveryReflection->getConstructor()?->isPrivate())->toBeTrue()
        ->and($deliveryPropertyNames)->toBe([
            'assets',
            'connectionId',
            'correlationKey',
            'externalChannelId',
            'externalOrganizationId',
            'idempotencyKey',
            'linkUrl',
            'mode',
            'scheduledFor',
            'tenantId',
            'text',
        ])
        ->and($resultReflection->isFinal())->toBeTrue()
        ->and($resultReflection->isReadOnly())->toBeTrue()
        ->and($resultReflection->getConstructor()?->isPrivate())->toBeTrue()
        ->and($resultPropertyNames)->toBe([
            'providerPostId',
            'providerStatus',
            'remoteScheduledFor',
            'status',
        ]);
});

test('fake social distribution gateway records exact calls without network traffic', function () {
    $firstDelivery = immediateSocialDelivery();
    $secondDelivery = immediateSocialDelivery(['idempotencyKey' => 'delivery-test-002']);
    $submitted = SocialDeliveryResultData::submitted('post-test-001');
    $unknown = SocialDeliveryResultData::unknown();
    $gateway = new FakeSocialDistributionGateway($submitted, $unknown);

    expect($gateway->createPost($firstDelivery))->toBe($submitted)
        ->and($gateway->createPost($secondDelivery))->toBe($unknown)
        ->and($gateway->createdDeliveries())->toBe([$firstDelivery, $secondDelivery])
        ->and(fn () => $gateway->createPost($firstDelivery))
        ->toThrow(LogicException::class, 'No social delivery result remains scripted.');

    Http::assertNothingSent();
});

test('fake social distribution gateway fails closed without a scripted result', function () {
    expect(fn () => new FakeSocialDistributionGateway)
        ->toThrow(InvalidArgumentException::class, 'At least one social delivery result must be scripted.');
});

test('social distribution gateway resolves to the Buffer implementation', function () {
    expect(app()->bound(SocialDistributionGatewayInterface::class))->toBeTrue()
        ->and(app(SocialDistributionGatewayInterface::class))
        ->toBeInstanceOf(BufferDistributionGateway::class);
});
