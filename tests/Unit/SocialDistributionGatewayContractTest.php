<?php

use App\Data\Social\CreateSocialDeliveryData;
use App\Data\Social\SocialDeliveryResultData;
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
        scheduledFor: $scheduledFor,
        idempotencyKey: 'delivery-test-002',
    );

    expect($delivery->mode)->toBe(CreateSocialDeliveryData::MODE_SCHEDULED)
        ->and($delivery->scheduledFor)->toBeInstanceOf(CarbonImmutable::class)
        ->and($delivery->scheduledFor?->format('Y-m-d H:i:s'))->toBe('2026-09-03 13:15:00')
        ->and($delivery->scheduledFor?->getTimezone()->getName())->toBe('UTC')
        ->and($scheduledFor->getTimezone()->getName())->toBe('America/Toronto');
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
        ->and(fn () => immediateSocialDelivery(['text' => "\n\t"]))
        ->toThrow(InvalidArgumentException::class, 'The social delivery text must not be blank.')
        ->and(fn () => immediateSocialDelivery(['idempotencyKey' => "\t"]))
        ->toThrow(InvalidArgumentException::class, 'The social delivery idempotency key must not be blank.')
        ->and(fn () => immediateSocialDelivery(['correlationKey' => '']))
        ->toThrow(InvalidArgumentException::class, 'The social delivery correlation key must not be blank.');
});

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
            'connectionId',
            'correlationKey',
            'externalChannelId',
            'externalOrganizationId',
            'idempotencyKey',
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

test('social distribution gateway has no application container binding', function () {
    expect(app()->bound(SocialDistributionGatewayInterface::class))->toBeFalse();
});
