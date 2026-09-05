<?php

use App\Data\Social\ReadSocialDeliveryStatusData;
use App\Data\Social\SocialDeliveryStatusResultData;
use App\Services\Social\Buffer\BufferDeliveryStatusGateway;
use App\Services\Social\Contracts\SocialDeliveryStatusGatewayInterface;
use Carbon\CarbonImmutable;
use Tests\Support\FakeSocialDeliveryStatusGateway;

uses(Tests\TestCase::class);

it('keeps the status gateway deterministic and structurally read only', function () {
    $observedAt = CarbonImmutable::parse('2026-08-28 12:00:00', 'UTC');
    $result = SocialDeliveryStatusResultData::observed(
        SocialDeliveryStatusResultData::STATUS_SENT,
        $observedAt,
        'complete',
    );
    $gateway = new FakeSocialDeliveryStatusGateway($result);
    $request = new ReadSocialDeliveryStatusData(
        tenantId: 10,
        postId: 20,
        targetId: 30,
        connectionId: 40,
        providerPostId: 'remote-post-1',
        deliveryProvider: 'delivery_gateway',
        transportGeneration: 'generation_v1',
        logicalDestinationKey: 'ldk:v1:'.str_repeat('a', 64),
    );

    expect($gateway->readStatus($request))->toBe($result)
        ->and($gateway->reads)->toHaveCount(1)
        ->and($gateway->reads[0])->toBe($request)
        ->and(method_exists($gateway, 'createPost'))->toBeFalse()
        ->and(method_exists(SocialDeliveryStatusGatewayInterface::class, 'createPost'))->toBeFalse()
        ->and(get_class_methods(SocialDeliveryStatusGatewayInterface::class))->toBe(['readStatus'])
        ->and(app(SocialDeliveryStatusGatewayInterface::class))
        ->toBeInstanceOf(BufferDeliveryStatusGateway::class);
});
