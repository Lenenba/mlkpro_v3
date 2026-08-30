<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Jobs\ProcessSocialDeliveryOutboxJob;
use App\Models\SocialAccountConnection;
use App\Models\SocialAutomationRule;
use App\Models\SocialBufferConnection;
use App\Models\SocialDeliveryOutbox;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\User;
use App\Services\Social\SocialAccountConnectionService;
use App\Services\Social\SocialContentQualityChecker;
use App\Services\Social\SocialDeliveryReconciler;
use App\Services\Social\SocialLogicalDestinationKeyService;
use App\Services\Social\SocialPostService;
use App\Services\Social\SocialPublishingService;
use App\Services\Social\SocialTransportPolicyService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @param  array<int, array<string, mixed>>  $mediaPayload
 * @return array{owner:User,post:SocialPost,target:SocialPostTarget}
 */
function pulseBufferMediaPreflightFixture(array $mediaPayload): array
{
    config()->set('services.buffer.delivery.enabled', true);
    config()->set('services.buffer.local_connector', [
        'api_url' => 'https://buffer.test/graphql',
        'connect_timeout' => 2,
        'timeout' => 5,
    ]);
    config()->set('filesystems.disks.public.url', 'https://malikia.test/storage');

    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_timezone' => 'UTC',
    ]);
    $accountId = 'buffer-media-account-'.$owner->id;
    $organizationId = 'buffer-media-organization-'.$owner->id;
    $channelId = 'buffer-media-channel-'.$owner->id;

    SocialBufferConnection::factory()->for($owner)->create([
        'buffer_account_id' => $accountId,
        'access_token' => 'buffer-media-access-token',
        'scopes' => ['account:read', 'posts:read', 'posts:write', 'offline_access'],
        'token_expires_at' => now()->addHour(),
    ]);

    $connection = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
        'label' => 'Buffer media channel',
        'external_account_id' => $channelId,
        'delivery_provider' => SocialAccountConnection::DELIVERY_PROVIDER_BUFFER,
        'transport_generation' => SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1,
        'logical_destination_key' => 'ldk:v1:'.hash('sha256', $channelId),
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
    $post = SocialPost::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'content_payload' => ['text' => 'Publication Buffer avec média'],
        'media_payload' => $mediaPayload,
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

    return compact('owner', 'post', 'target');
}

it('exposes only Buffer imports for new publishing while preserving existing legacy effects', function () {
    config()->set('services.buffer.delivery.enabled', true);

    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_timezone' => 'America/Toronto',
        'onboarding_completed_at' => now(),
        'company_features' => [
            'social' => true,
        ],
    ]);

    SocialBufferConnection::factory()->for($owner)->create([
        'buffer_account_id' => 'buffer-selection-account',
        'scopes' => ['account:read', 'posts:read', 'posts:write', 'offline_access'],
    ]);

    $bufferFacebook = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
        'label' => 'Buffer Facebook Page',
        'external_account_id' => 'buffer-selection-facebook',
        'delivery_provider' => SocialAccountConnection::DELIVERY_PROVIDER_BUFFER,
        'transport_generation' => SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1,
        'logical_destination_key' => 'ldk:v1:'.hash('sha256', 'buffer-selection-facebook'),
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
        'connected_at' => now(),
        'metadata' => [
            'connection_flow' => 'buffer_oauth',
            'buffer' => [
                'catalog_only' => false,
                'publication_enabled' => true,
                'standalone_destination' => true,
            ],
        ],
    ]);
    $bufferInstagram = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_INSTAGRAM,
        'label' => 'Disconnected Buffer Instagram',
        'external_account_id' => 'buffer-selection-instagram',
        'status' => SocialAccountConnection::STATUS_DISCONNECTED,
        'is_active' => false,
        'metadata' => [
            'connection_flow' => 'buffer_oauth_discovery',
            'buffer' => [
                'catalog_only' => true,
                'publication_enabled' => false,
                'standalone_destination' => false,
            ],
        ],
    ]);
    $legacyLinkedIn = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_LINKEDIN,
        'label' => 'Legacy LinkedIn Page',
        'external_account_id' => 'legacy-selection-linkedin',
        ...pulseDirectTransportIdentity(
            $owner,
            SocialAccountConnection::PLATFORM_LINKEDIN,
            'legacy-selection-linkedin',
        ),
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
        'connected_at' => now(),
    ]);

    $connectionService = app(SocialAccountConnectionService::class);
    $publishingIds = collect($connectionService->listPublishingPayloads($owner))
        ->pluck('id')
        ->all();
    $allVisibleIds = collect($connectionService->listPayloads($owner))
        ->pluck('id')
        ->all();
    $connectedOptionIds = collect(app(SocialPostService::class)->connectedAccountOptions($owner))
        ->pluck('id')
        ->all();
    $summary = $connectionService->summaryForOwner($owner);
    $transportPolicy = app(SocialTransportPolicyService::class);

    expect($publishingIds)->toBe([
        (int) $bufferFacebook->id,
        (int) $bufferInstagram->id,
    ])->and($allVisibleIds)->toBe($publishingIds)
        ->and($connectedOptionIds)->toBe([(int) $bufferFacebook->id]);

    expect($summary['configured'])->toBe(2)
        ->and($summary['connected'])->toBe(1)
        ->and($summary['inactive'])->toBe(1)
        ->and($summary['available_platforms'])->toBe([
            SocialAccountConnection::PLATFORM_FACEBOOK,
        ])
        ->and($summary['status_counts'][SocialAccountConnection::STATUS_CONNECTED])->toBe(1)
        ->and($summary['status_counts'][SocialAccountConnection::STATUS_DISCONNECTED])->toBe(1);

    expect($transportPolicy->allowsNewSubmission(
        (int) $owner->id,
        SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1,
        (int) $legacyLinkedIn->id,
        (string) $legacyLinkedIn->logical_destination_key,
    ))->toBeFalse()
        ->and($transportPolicy->allowsExistingRemoteEffect(
            (int) $owner->id,
            SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1,
            (int) $legacyLinkedIn->id,
            (string) $legacyLinkedIn->logical_destination_key,
        ))->toBeTrue();

    $legacyDraft = SocialPost::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'content_payload' => ['text' => 'Historical legacy draft'],
        'status' => SocialPost::STATUS_DRAFT,
    ]);
    SocialPostTarget::query()->create([
        'social_post_id' => $legacyDraft->id,
        'social_account_connection_id' => $legacyLinkedIn->id,
        'delivery_provider' => $legacyLinkedIn->delivery_provider,
        'transport_generation' => $legacyLinkedIn->transport_generation,
        'logical_destination_key' => $legacyLinkedIn->logical_destination_key,
        'status' => SocialPostTarget::STATUS_PENDING,
    ]);
    $postService = app(SocialPostService::class);
    $legacyDraftPayload = $postService->payload($legacyDraft);
    $legacyDraftCopy = $postService->duplicate($owner, $owner, $legacyDraft);

    expect($legacyDraftPayload['selected_target_connection_ids'])->toBe([])
        ->and($legacyDraftPayload['selected_accounts_count'])->toBe(1)
        ->and($legacyDraftPayload['targets'][0]['social_account_connection_id'])->toBe($legacyLinkedIn->id)
        ->and($legacyDraftCopy->targets()->count())->toBe(0)
        ->and(data_get($legacyDraftCopy->metadata, 'missing_target_count'))->toBe(1);

    $rule = SocialAutomationRule::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'name' => 'Historical mixed targets',
        'is_active' => true,
        'frequency_type' => SocialAutomationRule::FREQUENCY_DAILY,
        'frequency_interval' => 1,
        'scheduled_time' => '09:00',
        'timezone' => 'America/Toronto',
        'approval_mode' => SocialAutomationRule::APPROVAL_REQUIRED,
        'language' => 'fr',
        'content_sources' => [
            ['type' => 'template', 'mode' => 'all'],
        ],
        'target_connection_ids' => [
            $legacyLinkedIn->id,
            $bufferInstagram->id,
            $bufferFacebook->id,
        ],
        'max_posts_per_day' => 1,
        'min_hours_between_similar_posts' => 24,
        'next_generation_at' => now()->addDay(),
    ]);
    $targetValidation = app(SocialContentQualityChecker::class)
        ->validateTargets($owner, $rule);

    expect($targetValidation['passes'])->toBeFalse()
        ->and($targetValidation['connections'])->toBeEmpty();

    $this->withoutMiddleware([
        ValidateCsrfToken::class,
        EnsureTwoFactorVerified::class,
    ]);

    $this->actingAs($owner)
        ->get(route('social.composer'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Social/Composer')
            ->where('workspace_stats.connected_accounts', 1)
            ->has('connected_accounts', 1)
            ->where('connected_accounts.0.id', $bufferFacebook->id)
        );

    $this->actingAs($owner)
        ->get(route('social.automations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Social/Automations')
            ->has('target_connections', 2)
            ->where('target_connections.0.id', $bufferFacebook->id)
            ->where('target_connections.1.id', $bufferInstagram->id)
            ->where('rules.0.id', $rule->id)
            ->where('rules.0.target_connection_ids', [$bufferFacebook->id])
        );

    $this->actingAs($owner)
        ->get(route('social.accounts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Social/Accounts')
            ->has('provider_definitions', 0)
            ->has('connections', 0)
            ->where('summary.configured', 2)
            ->where('summary.connected', 1)
        );
});

it('returns 422 when forged direct targets are submitted to new Pulse endpoints', function () {
    config()->set('services.buffer.delivery.enabled', true);

    $this->withoutMiddleware([
        ValidateCsrfToken::class,
        EnsureTwoFactorVerified::class,
    ]);

    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_timezone' => 'UTC',
        'onboarding_completed_at' => now(),
        'company_features' => [
            'social' => true,
        ],
    ]);
    $legacyConnection = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_LINKEDIN,
        'label' => 'Forged Legacy LinkedIn Page',
        'external_account_id' => 'forged-legacy-linkedin',
        ...pulseDirectTransportIdentity(
            $owner,
            SocialAccountConnection::PLATFORM_LINKEDIN,
            'forged-legacy-linkedin',
        ),
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
        'connected_at' => now(),
    ]);

    $draftResponse = $this->actingAs($owner)
        ->postJson(route('social.posts.store'), [
            'text' => 'This legacy target must not create a new Pulse draft.',
            'target_connection_ids' => [$legacyConnection->id],
        ]);

    $draftResponse->assertUnprocessable()
        ->assertJsonValidationErrors('target_connection_ids');
    $this->assertDatabaseCount('social_posts', 0);

    $automationResponse = $this->actingAs($owner)
        ->postJson(route('social.automations.store'), [
            'name' => 'Forged legacy automation',
            'is_active' => true,
            'frequency_type' => SocialAutomationRule::FREQUENCY_DAILY,
            'frequency_interval' => 1,
            'scheduled_time' => '09:00',
            'timezone' => 'UTC',
            'approval_mode' => SocialAutomationRule::APPROVAL_REQUIRED,
            'language' => 'fr',
            'target_connection_ids' => [$legacyConnection->id],
            'content_sources' => [
                [
                    'type' => 'template',
                    'mode' => 'all',
                    'ids' => [],
                ],
            ],
            'max_posts_per_day' => 1,
            'min_hours_between_similar_posts' => 24,
        ]);

    $automationResponse->assertUnprocessable()
        ->assertJsonValidationErrors('target_connection_ids');
    $this->assertDatabaseCount('social_automation_rules', 0);

    $templateResponse = $this->actingAs($owner)
        ->postJson(route('social.templates.store'), [
            'name' => 'Forged legacy template',
            'text' => 'This template must not retain a direct target.',
            'target_connection_ids' => [$legacyConnection->id],
        ]);

    $templateResponse->assertUnprocessable()
        ->assertJsonValidationErrors('target_connection_ids');
    $this->assertDatabaseCount('social_post_templates', 0);

    $this->actingAs($owner)
        ->postJson(route('social.accounts.store'), [
            'platform' => SocialAccountConnection::PLATFORM_X,
            'label' => 'Forbidden direct connection',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('connection');
});

it('fails a local Pulse media URL before Buffer submission without creating an ambiguous outcome', function () {
    Queue::fake([ProcessSocialDeliveryOutboxJob::class]);
    Http::preventStrayRequests();
    config()->set('services.social.media.public_base_url', null);

    $fixture = pulseBufferMediaPreflightFixture([[
        'type' => 'image',
        'url' => 'https://malikia.test/storage/social/posts/76/local-image.png',
        'source' => 'upload',
        'disk' => 'public',
        'path' => 'social/posts/76/local-image.png',
        'mime_type' => 'image/png',
    ]]);
    $queuedPost = app(SocialPublishingService::class)->publishNow(
        $fixture['owner'],
        $fixture['owner'],
        $fixture['post'],
    );
    $outbox = SocialDeliveryOutbox::query()
        ->where('social_post_target_id', $fixture['target']->id)
        ->sole();

    app(SocialPublishingService::class)->handleOutboxPublication($outbox->id);

    expect($outbox->fresh()->status)->toBe(SocialDeliveryOutbox::STATUS_DEAD)
        ->and($outbox->fresh()->last_error_code)->toBe('media_url_not_public')
        ->and($outbox->fresh()->last_error_message)->toContain('SOCIAL_MEDIA_PUBLIC_BASE_URL')
        ->and($outbox->fresh()->request_started_at)->toBeNull()
        ->and($fixture['target']->fresh()->delivery_status)->toBe(SocialPost::DELIVERY_STATUS_FAILED)
        ->and($queuedPost->fresh()->delivery_status)->toBe(SocialPost::DELIVERY_STATUS_FAILED)
        ->and($queuedPost->fresh()->delivery_status)->not->toBe(SocialPost::DELIVERY_STATUS_UNKNOWN);

    Http::assertNothingSent();
});

it('does not treat the legacy document thumbnail path as a first-party image upload', function () {
    Queue::fake([ProcessSocialDeliveryOutboxJob::class]);
    Http::preventStrayRequests();
    config()->set('services.social.media.public_base_url', 'https://cdn.example.com/storage');

    $fixture = pulseBufferMediaPreflightFixture([[
        'type' => 'image',
        'url' => 'https://malikia.test/brand/social-card.png',
        'source' => 'url',
    ]]);
    app(SocialPublishingService::class)->publishNow(
        $fixture['owner'],
        $fixture['owner'],
        $fixture['post'],
    );
    $outbox = SocialDeliveryOutbox::query()
        ->where('social_post_target_id', $fixture['target']->id)
        ->sole();

    app(SocialPublishingService::class)->handleOutboxPublication($outbox->id);

    expect($outbox->fresh()->status)->toBe(SocialDeliveryOutbox::STATUS_DEAD)
        ->and($outbox->fresh()->last_error_code)->toBe('media_url_not_public');
    Http::assertNothingSent();
});

it('rewrites local Pulse storage media to the configured public origin and keeps external URL assets unchanged', function () {
    Queue::fake([ProcessSocialDeliveryOutboxJob::class]);
    Http::preventStrayRequests();
    config()->set('services.social.media.public_base_url', 'https://cdn.example.com/storage');

    $fixture = pulseBufferMediaPreflightFixture([
        [
            'type' => 'image',
            'url' => 'https://malikia.test/storage/social/posts/1/local-image.png',
            'source' => 'upload',
            'disk' => 'public',
            'path' => 'social/posts/1/local-image.png',
            'mime_type' => 'image/png',
        ],
        [
            'type' => 'image',
            'url' => 'https://images.example.com/public-image.png',
            'source' => 'url',
        ],
        [
            'type' => 'video',
            'url' => 'https://malikia.test/storage/social/posts/76/local-video.mp4',
            'source' => 'url',
        ],
        [
            'type' => 'document',
            'url' => 'https://malikia.test/storage/social/posts/76/guide.pdf',
            'source' => 'upload',
            'disk' => 'public',
            'path' => 'social/posts/76/guide.pdf',
            'title' => 'Guide Pulse',
            'thumbnail_url' => 'https://malikia.test/storage/social/system/document-thumbnail.png',
        ],
        [
            'type' => 'document',
            'url' => 'https://documents.example.com/legacy-guide.pdf',
            'source' => 'url',
            'title' => 'Ancien guide Pulse',
            'thumbnail_url' => 'https://malikia.test/brand/social-card.png',
        ],
        [
            'type' => 'document',
            'url' => 'https://documents.example.com/external-guide.pdf',
            'source' => 'url',
            'title' => 'Guide externe',
            'thumbnail_url' => 'https://external.example.com/brand/social-card.png',
        ],
    ]);
    $channelId = 'buffer-media-channel-'.$fixture['owner']->id;
    Http::fake([
        'https://buffer.test/graphql' => Http::response([
            'data' => [
                'createPost' => [
                    '__typename' => 'PostActionSuccess',
                    'post' => [
                        'id' => 'buffer-public-media-post',
                        'channelId' => $channelId,
                        'channelService' => 'facebook',
                        'dueAt' => null,
                        'status' => 'sending',
                    ],
                ],
            ],
        ]),
    ]);
    $queuedPost = app(SocialPublishingService::class)->publishNow(
        $fixture['owner'],
        $fixture['owner'],
        $fixture['post'],
    );
    $outbox = SocialDeliveryOutbox::query()
        ->where('social_post_target_id', $fixture['target']->id)
        ->sole();

    app(SocialPublishingService::class)->handleOutboxPublication($outbox->id);

    expect($outbox->fresh()->status)->toBe(SocialDeliveryOutbox::STATUS_COMPLETED)
        ->and($queuedPost->fresh()->delivery_status)->not->toBe(SocialPost::DELIVERY_STATUS_UNKNOWN);

    Http::assertSent(function (Request $request) use ($channelId): bool {
        $input = (array) data_get($request->data(), 'variables.input', []);

        return data_get($input, 'channelId') === $channelId
            && data_get($input, 'assets') === [
                ['image' => ['url' => 'https://cdn.example.com/storage/social/posts/1/local-image.png']],
                ['image' => ['url' => 'https://images.example.com/public-image.png']],
                ['video' => ['url' => 'https://cdn.example.com/storage/social/posts/76/local-video.mp4']],
                ['document' => [
                    'thumbnailUrl' => 'https://cdn.example.com/storage/social/system/document-thumbnail.png',
                    'title' => 'Guide Pulse',
                    'url' => 'https://cdn.example.com/storage/social/posts/76/guide.pdf',
                ]],
                ['document' => [
                    'thumbnailUrl' => 'https://cdn.example.com/storage/social/system/document-thumbnail.png',
                    'title' => 'Ancien guide Pulse',
                    'url' => 'https://documents.example.com/legacy-guide.pdf',
                ]],
                ['document' => [
                    'thumbnailUrl' => 'https://external.example.com/brand/social-card.png',
                    'title' => 'Guide externe',
                    'url' => 'https://documents.example.com/external-guide.pdf',
                ]],
            ];
    });
    Http::assertSentCount(1);
});

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
        'media_payload' => [
            [
                'type' => 'image',
                'url' => 'https://cdn.example.com/runtime-cover.jpg',
                'alt_text' => 'Runtime cover',
            ],
            [
                'type' => 'image',
                'url' => 'https://cdn.example.com/runtime-details.jpg',
            ],
        ],
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
            && data_get($input, 'text') === 'Publication Buffer runtime sans faux statut publié'
            && data_get($input, 'assets') === [
                [
                    'image' => [
                        'url' => 'https://cdn.example.com/runtime-cover.jpg',
                        'metadata' => ['altText' => 'Runtime cover'],
                    ],
                ],
                [
                    'image' => [
                        'url' => 'https://cdn.example.com/runtime-details.jpg',
                    ],
                ],
            ];
    });
    Http::assertSentCount(1);

    $reconciliationSummary = $this->travel(2)->minutes(
        fn (): array => app(SocialDeliveryReconciler::class)->reconcileDueBufferDeliveries(
            'buffer-runtime-test',
        ),
    );

    expect($reconciliationSummary)->toBe([
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
