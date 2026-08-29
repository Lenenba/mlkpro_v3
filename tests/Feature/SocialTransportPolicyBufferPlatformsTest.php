<?php

use App\Models\SocialAccountConnection;
use App\Models\SocialBufferConnection;
use App\Models\User;
use App\Services\Social\SocialTransportPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{owner: User, connection: SocialAccountConnection}
 */
function bufferTransportPolicyFixture(
    string $platform,
    string $channelService,
    ?string $metadataAccountId = null,
): array {
    $owner = User::factory()->create();
    $accountId = 'buffer-policy-account-'.$owner->id;

    SocialBufferConnection::factory()->for($owner)->create([
        'buffer_account_id' => $accountId,
        'scopes' => ['account:read', 'posts:read', 'posts:write', 'offline_access'],
    ]);

    $connection = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => $platform,
        'label' => 'Buffer policy channel',
        'external_account_id' => 'buffer-policy-'.$channelService.'-'.$owner->id,
        'delivery_provider' => SocialAccountConnection::DELIVERY_PROVIDER_BUFFER,
        'transport_generation' => SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1,
        'logical_destination_key' => 'ldk:v1:'.hash(
            'sha256',
            'buffer-policy-'.$channelService.'-'.$owner->id,
        ),
        'auth_method' => SocialAccountConnection::AUTH_METHOD_OAUTH,
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
        'connected_at' => now(),
        'metadata' => [
            'connection_flow' => 'buffer_oauth',
            'buffer' => [
                'account_id' => $metadataAccountId ?? $accountId,
                'organization_id' => 'buffer-policy-organization-'.$owner->id,
                'channel_service' => $channelService,
                'channel_type' => 'profile',
                'catalog_only' => false,
                'publication_enabled' => true,
                'standalone_destination' => true,
            ],
        ],
    ]);

    return ['owner' => $owner, 'connection' => $connection];
}

beforeEach(function () {
    config()->set('services.buffer.delivery.enabled', true);
});

it('authorizes exact standalone Buffer identities on every supported platform', function (
    string $platform,
    string $channelService,
) {
    ['owner' => $owner, 'connection' => $connection]
        = bufferTransportPolicyFixture($platform, $channelService);
    $policy = app(SocialTransportPolicyService::class);

    expect($policy->allowsNewSubmission(
        (int) $owner->id,
        SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1,
        (int) $connection->id,
        (string) $connection->logical_destination_key,
    ))->toBeTrue()
        ->and($policy->allowsExistingRemoteEffect(
            (int) $owner->id,
            SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1,
            (int) $connection->id,
            (string) $connection->logical_destination_key,
        ))->toBeTrue();
})->with([
    'Facebook' => [SocialAccountConnection::PLATFORM_FACEBOOK, 'facebook'],
    'Instagram' => [SocialAccountConnection::PLATFORM_INSTAGRAM, 'instagram'],
    'LinkedIn' => [SocialAccountConnection::PLATFORM_LINKEDIN, 'linkedin'],
    'X through Buffer Twitter service' => [SocialAccountConnection::PLATFORM_X, 'twitter'],
    'X service alias' => [SocialAccountConnection::PLATFORM_X, 'x'],
]);

it('rejects mismatched standalone Buffer service and account identities', function (
    string $platform,
    string $channelService,
    ?string $metadataAccountId,
) {
    ['owner' => $owner, 'connection' => $connection]
        = bufferTransportPolicyFixture($platform, $channelService, $metadataAccountId);
    $policy = app(SocialTransportPolicyService::class);

    expect($policy->allowsNewSubmission(
        (int) $owner->id,
        SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1,
        (int) $connection->id,
        (string) $connection->logical_destination_key,
    ))->toBeFalse();
})->with([
    'service does not match platform' => [
        SocialAccountConnection::PLATFORM_FACEBOOK,
        'instagram',
        null,
    ],
    'grant account does not match connection account' => [
        SocialAccountConnection::PLATFORM_LINKEDIN,
        'linkedin',
        'different-buffer-account',
    ],
]);

it('rejects a standalone Buffer identity from another tenant', function () {
    ['connection' => $connection]
        = bufferTransportPolicyFixture(SocialAccountConnection::PLATFORM_X, 'twitter');
    $otherOwner = User::factory()->create();

    expect(app(SocialTransportPolicyService::class)->allowsNewSubmission(
        (int) $otherOwner->id,
        SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1,
        (int) $connection->id,
        (string) $connection->logical_destination_key,
    ))->toBeFalse();
});
