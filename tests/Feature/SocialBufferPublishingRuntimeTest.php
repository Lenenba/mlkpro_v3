<?php

use App\Jobs\ProcessSocialDeliveryOutboxJob;
use App\Models\SocialAccountConnection;
use App\Models\SocialBufferConnection;
use App\Models\SocialDeliveryOutbox;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\User;
use App\Services\Social\SocialDeliveryReconciler;
use App\Services\Social\SocialLogicalDestinationKeyService;
use App\Services\Social\SocialPostService;
use App\Services\Social\SocialPublishingService;
use App\Services\Social\SocialTransportPolicyService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('submits a standalone Buffer Facebook target through the outbox without marking it published', function () {
    config()->set('services.buffer.delivery.enabled', true);
    config()->set('services.buffer.local_connector', [
        'api_url' => 'https://buffer.test/graphql',
        'connect_timeout' => 2,
        'timeout' => 5,
    ]);

    Queue::fake();
    Http::preventStrayRequests();
    Http::fakeSequence('https://buffer.test/graphql')
        ->push([
            'data' => [
                'createPost' => [
                    '__typename' => 'PostActionSuccess',
                    'post' => [
                        'id' => 'buffer-runtime-post',
                        'channelId' => 'buffer-facebook-page',
                        'channelService' => 'facebook',
                        'dueAt' => null,
                        'schedulingType' => 'automatic',
                        'sentAt' => null,
                        'sharedNow' => true,
                        'shareMode' => 'shareNow',
                        'status' => 'sending',
                    ],
                ],
            ],
        ])
        ->push([
            'data' => [
                'post' => [
                    'id' => 'buffer-runtime-post',
                    'channelId' => 'buffer-facebook-page',
                    'channelService' => 'facebook',
                    'dueAt' => null,
                    'status' => 'sent',
                ],
            ],
        ]);

    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_timezone' => 'America/Toronto',
    ]);
    $accountId = 'buffer-runtime-account';
    $organizationId = 'buffer-runtime-organization';
    $channelId = 'buffer-facebook-page';
    $logicalDestinationKey = 'ldk:v1:'.hash('sha256', 'buffer-runtime-destination');

    SocialBufferConnection::factory()->for($owner)->create([
        'buffer_account_id' => $accountId,
        'access_token' => 'buffer-runtime-access-token',
        'scopes' => ['account:read', 'posts:read', 'posts:write', 'offline_access'],
        'token_expires_at' => now()->addHour(),
    ]);

    $connection = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
        'label' => 'Buffer Facebook Page',
        'display_name' => 'Buffer Facebook Page',
        'external_account_id' => $channelId,
        'delivery_provider' => SocialAccountConnection::DELIVERY_PROVIDER_BUFFER,
        'transport_generation' => SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1,
        'logical_destination_key' => $logicalDestinationKey,
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
    $legacyConnection = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
        'label' => 'Legacy Facebook Page',
        'external_account_id' => 'legacy-facebook-page',
        'delivery_provider' => SocialAccountConnection::DELIVERY_PROVIDER_DIRECT,
        'transport_generation' => SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1,
        'logical_destination_key' => app(SocialLogicalDestinationKeyService::class)
            ->deriveForLegacyConnection(
                (string) $owner->id,
                SocialAccountConnection::PLATFORM_FACEBOOK,
                'legacy-facebook-page',
            ),
        'auth_method' => SocialAccountConnection::AUTH_METHOD_MANUAL,
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
        'connected_at' => now(),
    ]);

    expect(collect(app(SocialPostService::class)->connectedAccountOptions($owner))->pluck('id')->all())
        ->toBe([(int) $connection->id])
        ->and(app(SocialTransportPolicyService::class)->allowsNewSubmission(
            (int) $owner->id,
            SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1,
            (int) $legacyConnection->id,
            (string) $legacyConnection->logical_destination_key,
        ))->toBeFalse();

    $post = SocialPost::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'content_payload' => [
            'text' => 'Publication Buffer runtime sans faux statut publié',
        ],
        'media_payload' => [],
        'status' => SocialPost::STATUS_DRAFT,
    ]);
    $target = SocialPostTarget::query()->create([
        'social_post_id' => $post->id,
        'social_account_connection_id' => $connection->id,
        'delivery_provider' => $connection->delivery_provider,
        'transport_generation' => $connection->transport_generation,
        'logical_destination_key' => $connection->logical_destination_key,
        'status' => SocialPostTarget::STATUS_PENDING,
    ]);

    app(SocialPublishingService::class)->publishNow($owner, $owner, $post);

    $outbox = SocialDeliveryOutbox::query()
        ->where('social_post_target_id', $target->id)
        ->where('operation', SocialDeliveryOutbox::OPERATION_CREATE)
        ->sole();
    /** @var ProcessSocialDeliveryOutboxJob|null $job */
    $job = null;

    Queue::assertPushed(
        ProcessSocialDeliveryOutboxJob::class,
        function (ProcessSocialDeliveryOutboxJob $queuedJob) use (&$job, $outbox): bool {
            $job = $queuedJob;

            return $queuedJob->outboxId === $outbox->id;
        },
    );

    expect($outbox->status)->toBe(SocialDeliveryOutbox::STATUS_PENDING)
        ->and($outbox->external_organization_id_snapshot)->toBe($organizationId)
        ->and($outbox->external_channel_id_snapshot)->toBe($channelId)
        ->and($job)->toBeInstanceOf(ProcessSocialDeliveryOutboxJob::class);

    if (! $job instanceof ProcessSocialDeliveryOutboxJob) {
        throw new RuntimeException('The Buffer outbox worker was not queued.');
    }

    $job->handle(app(SocialPublishingService::class));

    $completedOutbox = $outbox->fresh();
    $submittedTarget = $target->fresh();
    $submittedPost = $post->fresh();

    expect($completedOutbox->status)->toBe(SocialDeliveryOutbox::STATUS_COMPLETED)
        ->and($completedOutbox->attempts)->toBe(1)
        ->and($completedOutbox->provider_post_id)->toBe('buffer-runtime-post')
        ->and($completedOutbox->submitted_at)->not->toBeNull()
        ->and($submittedTarget->status)->toBe(SocialPostTarget::STATUS_PUBLISHING)
        ->and($submittedTarget->delivery_status)->toBe('sending')
        ->and($submittedTarget->provider_status)->toBe('sending')
        ->and($submittedTarget->provider_post_id)->toBe('buffer-runtime-post')
        ->and($submittedTarget->submitted_at)->not->toBeNull()
        ->and($submittedTarget->published_at)->toBeNull()
        ->and($submittedPost->status)->not->toBe(SocialPost::STATUS_PUBLISHED)
        ->and($submittedPost->published_at)->toBeNull();

    Http::assertSent(function (Request $request): bool {
        $input = data_get($request->data(), 'variables.input');

        return $request->url() === 'https://buffer.test/graphql'
            && $request->hasHeader('Authorization', 'Bearer buffer-runtime-access-token')
            && data_get($input, 'channelId') === 'buffer-facebook-page'
            && data_get($input, 'mode') === 'shareNow'
            && data_get($input, 'saveToDraft') === false
            && data_get($input, 'text') === 'Publication Buffer runtime sans faux statut publié';
    });
    Http::assertSentCount(1);

    Carbon::setTestNow(now()->addMinutes(2));

    expect(app(SocialDeliveryReconciler::class)->reconcileDueBufferDeliveries(
        'buffer-runtime-test',
    ))->toBe([
        'selected' => 1,
        'claimed' => 1,
        'reconciled' => 1,
        'not_applied' => 0,
    ])->and($target->fresh()->status)->toBe(SocialPostTarget::STATUS_PUBLISHED)
        ->and($target->fresh()->delivery_status)->toBe(SocialPost::DELIVERY_STATUS_PUBLISHED)
        ->and($target->fresh()->published_at)->not->toBeNull()
        ->and($post->fresh()->status)->toBe(SocialPost::STATUS_PUBLISHED)
        ->and($post->fresh()->published_at)->not->toBeNull();

    Http::assertSent(fn (Request $request): bool => (
        str_contains((string) $request['query'], 'query MalikiaPulseBufferReadPost')
        && data_get($request->data(), 'variables.input.id') === 'buffer-runtime-post'
    ));

    $job->handle(app(SocialPublishingService::class));

    Http::assertSentCount(2);
});
