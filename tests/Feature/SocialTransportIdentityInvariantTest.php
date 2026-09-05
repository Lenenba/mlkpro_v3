<?php

use App\Models\SocialAccountConnection;
use App\Models\SocialDeliveryOutbox;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\User;
use App\Services\Social\SocialAccountConnectionService;
use App\Services\Social\SocialDeliveryOutboxService;
use App\Services\Social\SocialLogicalDestinationKeyService;
use App\Services\Social\SocialPostRevisionService;
use App\Services\Social\SocialPostService;
use App\Services\Social\SocialPublishingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

function wp2bCanonicalIdentity(User $owner, string $platform, string $externalAccountId): array
{
    return [
        'delivery_provider' => SocialAccountConnection::DELIVERY_PROVIDER_DIRECT,
        'transport_generation' => SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1,
        'logical_destination_key' => app(SocialLogicalDestinationKeyService::class)
            ->deriveForLegacyConnection((string) $owner->id, $platform, $externalAccountId),
    ];
}

function createWp2bIdentifiedConnection(
    User $owner,
    string $platform = SocialAccountConnection::PLATFORM_FACEBOOK,
    string $externalAccountId = 'facebook-page-001',
): SocialAccountConnection {
    return SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => $platform,
        'label' => 'Identified direct destination',
        'external_account_id' => $externalAccountId,
        ...wp2bCanonicalIdentity($owner, $platform, $externalAccountId),
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
        'credentials' => ['access_token' => 'test-token'],
    ]);
}

function createWp2bIdentifiedPost(User $owner): SocialPost
{
    return SocialPost::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'content_payload' => ['text' => 'Identified target post'],
        'status' => SocialPost::STATUS_DRAFT,
    ]);
}

it('rejects partial identities and freezes connection routing fields after assignment', function () {
    $owner = User::factory()->create();

    expect(fn () => SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
        'label' => 'Partial identity',
        'delivery_provider' => SocialAccountConnection::DELIVERY_PROVIDER_DIRECT,
    ]))->toThrow(LogicException::class, 'must be complete');

    $connection = createWp2bIdentifiedConnection($owner);
    $connection->forceFill([
        'label' => 'Mutable label',
        'status' => SocialAccountConnection::STATUS_DISCONNECTED,
        'credentials' => [],
    ])->save();

    expect($connection->fresh()->label)->toBe('Mutable label');

    $mutations = [
        'user_id' => User::factory()->create()->id,
        'platform' => SocialAccountConnection::PLATFORM_LINKEDIN,
        'external_account_id' => 'another-page',
        'delivery_provider' => 'buffer',
        'transport_generation' => 'buffer_v1',
        'logical_destination_key' => 'ldk:v1:'.str_repeat('f', 64),
    ];

    foreach ($mutations as $field => $value) {
        expect(function () use ($connection, $field, $value): void {
            $connection->fresh()->forceFill([$field => $value])->save();
        })->toThrow(LogicException::class, 'cannot be changed');
    }

    expect($connection->fresh()->toArray())
        ->not->toHaveKeys(['delivery_provider', 'transport_generation', 'logical_destination_key']);
});

it('rejects a forged direct logical destination key', function () {
    $owner = User::factory()->create();

    expect(fn () => SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
        'label' => 'Forged destination',
        'external_account_id' => 'facebook-page-001',
        'delivery_provider' => SocialAccountConnection::DELIVERY_PROVIDER_DIRECT,
        'transport_generation' => SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1,
        'logical_destination_key' => 'ldk:v1:'.str_repeat('f', 64),
    ]))->toThrow(LogicException::class, 'canonical logical destination key');
});

it('freezes a target destination while leaving its delivery status mutable', function () {
    $owner = User::factory()->create();
    $connection = createWp2bIdentifiedConnection($owner);
    $post = createWp2bIdentifiedPost($owner);

    expect(fn () => SocialPostTarget::query()->create([
        'social_post_id' => $post->id,
        'social_account_connection_id' => $connection->id,
        'delivery_provider' => SocialAccountConnection::DELIVERY_PROVIDER_DIRECT,
        'status' => SocialPostTarget::STATUS_PENDING,
    ]))->toThrow(LogicException::class, 'must be complete');

    $target = SocialPostTarget::query()->create([
        'social_post_id' => $post->id,
        'social_account_connection_id' => $connection->id,
        'delivery_provider' => $connection->delivery_provider,
        'transport_generation' => $connection->transport_generation,
        'logical_destination_key' => $connection->logical_destination_key,
        'status' => SocialPostTarget::STATUS_PENDING,
    ]);
    $target->forceFill(['status' => SocialPostTarget::STATUS_SCHEDULED])->save();

    expect($target->fresh()->status)->toBe(SocialPostTarget::STATUS_SCHEDULED);

    $otherPost = createWp2bIdentifiedPost($owner);
    $otherConnection = createWp2bIdentifiedConnection(
        $owner,
        SocialAccountConnection::PLATFORM_LINKEDIN,
        'linkedin-page-002',
    );
    $mutations = [
        'social_post_id' => $otherPost->id,
        'social_account_connection_id' => $otherConnection->id,
        'delivery_provider' => 'buffer',
        'transport_generation' => 'buffer_v1',
        'logical_destination_key' => $otherConnection->logical_destination_key,
    ];

    foreach ($mutations as $field => $value) {
        expect(function () use ($target, $field, $value): void {
            $target->fresh()->forceFill([$field => $value])->save();
        })->toThrow(LogicException::class, 'cannot be changed');
    }

    expect($target->fresh()->toArray())
        ->not->toHaveKeys(['delivery_provider', 'transport_generation', 'logical_destination_key']);
});

it('rejects cross-tenant and mismatched identified target snapshots while tolerating null legacy identity', function () {
    $postOwner = User::factory()->create();
    $connectionOwner = User::factory()->create();
    $crossTenantConnection = createWp2bIdentifiedConnection($connectionOwner);
    $post = createWp2bIdentifiedPost($postOwner);

    expect(fn () => SocialPostTarget::query()->create([
        'social_post_id' => $post->id,
        'social_account_connection_id' => $crossTenantConnection->id,
        'status' => SocialPostTarget::STATUS_PENDING,
    ]))->toThrow(LogicException::class, 'same tenant');

    expect(fn () => SocialPostTarget::query()->create([
        'social_post_id' => $post->id,
        'social_account_connection_id' => $crossTenantConnection->id,
        'delivery_provider' => $crossTenantConnection->delivery_provider,
        'transport_generation' => $crossTenantConnection->transport_generation,
        'logical_destination_key' => $crossTenantConnection->logical_destination_key,
        'status' => SocialPostTarget::STATUS_PENDING,
    ]))->toThrow(LogicException::class, 'same tenant');

    $matchingConnection = createWp2bIdentifiedConnection(
        $postOwner,
        SocialAccountConnection::PLATFORM_FACEBOOK,
        'matching-page',
    );
    $otherConnection = createWp2bIdentifiedConnection(
        $postOwner,
        SocialAccountConnection::PLATFORM_LINKEDIN,
        'other-page',
    );

    expect(fn () => SocialPostTarget::query()->create([
        'social_post_id' => $post->id,
        'social_account_connection_id' => $matchingConnection->id,
        'delivery_provider' => $otherConnection->delivery_provider,
        'transport_generation' => $otherConnection->transport_generation,
        'logical_destination_key' => $otherConnection->logical_destination_key,
        'status' => SocialPostTarget::STATUS_PENDING,
    ]))->toThrow(LogicException::class, 'exactly match');

    $legacyTarget = SocialPostTarget::query()->create([
        'social_post_id' => $post->id,
        'social_account_connection_id' => $matchingConnection->id,
        'status' => SocialPostTarget::STATUS_PENDING,
    ]);

    expect($legacyTarget->logical_destination_key)->toBeNull();
});

it('assigns direct identity only when a native destination is trusted and refuses identity changes', function () {
    config()->set('services.social.allow_test_connections', true);
    $owner = User::factory()->create();
    $service = app(SocialAccountConnectionService::class);
    $draft = $service->create($owner, [
        'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
        'label' => 'OAuth draft',
        'external_account_id' => 'unverified-page',
    ]);

    expect($draft->delivery_provider)->toBeNull()
        ->and($draft->transport_generation)->toBeNull()
        ->and($draft->logical_destination_key)->toBeNull();

    $connection = $service->createTestConnection($owner, [
        'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
        'external_account_id' => 'trusted-test-page',
    ]);

    expect($connection->delivery_provider)->toBe(SocialAccountConnection::DELIVERY_PROVIDER_DIRECT)
        ->and($connection->transport_generation)->toBe(SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1)
        ->and($connection->logical_destination_key)->toMatch('/\Aldk:v1:[0-9a-f]{64}\z/');

    try {
        $service->update($owner, $connection, [
            'external_account_id' => 'different-page',
        ]);
        $this->fail('The identified connection accepted a different native destination.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['external_account_id'][0] ?? null)
            ->toBe('Create a new social connection to use a different destination.');
    }

    expect($service->update($owner, $connection, ['label' => 'Renamed destination'])->label)
        ->toBe('Renamed destination');
});

it('rejects a normalized logical destination duplicate within one tenant', function () {
    config()->set('services.social.allow_test_connections', true);
    $owner = User::factory()->create();
    createWp2bIdentifiedConnection(
        $owner,
        SocialAccountConnection::PLATFORM_FACEBOOK,
        ' duplicate-page ',
    );

    try {
        app(SocialAccountConnectionService::class)->createTestConnection($owner, [
            'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
            'external_account_id' => 'duplicate-page',
        ]);
        $this->fail('The normalized logical destination duplicate was accepted.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['external_account_id'][0] ?? null)
            ->toBe('This logical social destination is already connected.');
    }

    $otherOwner = User::factory()->create();
    $otherTenantConnection = app(SocialAccountConnectionService::class)->createTestConnection($otherOwner, [
        'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
        'external_account_id' => 'duplicate-page',
    ]);

    expect($otherTenantConnection->is_active)->toBeTrue();
});

it('copies connection routing into each new target and recreates editable destinations', function () {
    config()->set('services.social.allow_test_connections', true);
    $owner = User::factory()->create();
    $connections = app(SocialAccountConnectionService::class);
    $firstConnection = $connections->createTestConnection($owner, [
        'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
        'external_account_id' => 'first-page',
    ]);
    $secondConnection = $connections->createTestConnection($owner, [
        'platform' => SocialAccountConnection::PLATFORM_LINKEDIN,
        'external_account_id' => 'second-page',
    ]);
    $posts = app(SocialPostService::class);
    $post = $posts->createDraft($owner, $owner, [
        'text' => 'A business-driven Pulse draft',
        'target_connection_ids' => [$firstConnection->id],
    ]);
    $firstTarget = $post->targets->sole();

    expect($firstTarget->delivery_provider)->toBe($firstConnection->delivery_provider)
        ->and($firstTarget->transport_generation)->toBe($firstConnection->transport_generation)
        ->and($firstTarget->logical_destination_key)->toBe($firstConnection->logical_destination_key);

    $updated = $posts->updateDraft($owner, $owner, $post, [
        'text' => 'A business-driven Pulse draft',
        'target_connection_ids' => [$secondConnection->id],
    ]);
    $secondTarget = $updated->targets->sole();

    expect($secondTarget->id)->not->toBe($firstTarget->id)
        ->and($secondTarget->social_account_connection_id)->toBe($secondConnection->id)
        ->and($secondTarget->logical_destination_key)->toBe($secondConnection->logical_destination_key)
        ->and(SocialPostTarget::query()->whereKey($firstTarget->id)->exists())->toBeFalse();
});

it('refuses a target whose persisted transport snapshot no longer matches the direct connection', function () {
    $owner = User::factory()->create();
    $connection = createWp2bIdentifiedConnection($owner);
    $post = createWp2bIdentifiedPost($owner);
    $target = SocialPostTarget::query()->create([
        'social_post_id' => $post->id,
        'social_account_connection_id' => $connection->id,
        'delivery_provider' => $connection->delivery_provider,
        'transport_generation' => $connection->transport_generation,
        'logical_destination_key' => $connection->logical_destination_key,
        'status' => SocialPostTarget::STATUS_PENDING,
    ]);
    $revisions = app(SocialPostRevisionService::class);
    $revision = $revisions->capture($post, $owner);
    $revisions->approveDirectly($post, $owner, now());
    $target->refresh()->forceFill([
        'last_submitted_revision_id' => $revision->id,
        'delivery_status' => SocialPost::DELIVERY_STATUS_QUEUED,
    ])->save();
    $outbox = DB::transaction(fn (): SocialDeliveryOutbox => app(SocialDeliveryOutboxService::class)
        ->createForTarget(
            $owner,
            $target->fresh(),
            $revision->refresh(),
            $connection->fresh(),
            [
                'post_id' => $post->id,
                'target_id' => $target->id,
                'revision_id' => $revision->id,
                'platform' => $connection->platform,
                'text' => 'Identified target post',
            ],
            now(),
        ));
    DB::table('social_post_targets')->where('id', $target->id)->update([
        'logical_destination_key' => 'ldk:v1:'.str_repeat('a', 64),
    ]);

    app(SocialPublishingService::class)->handleOutboxPublication((int) $outbox->id);

    expect($target->fresh()->status)->toBe(SocialPostTarget::STATUS_FAILED)
        ->and($target->fresh()->failure_reason)
        ->toContain('no longer matches its tenant, revision, destination, or transport snapshot')
        ->and($outbox->fresh()->status)->toBe(SocialDeliveryOutbox::STATUS_DEAD);
});
