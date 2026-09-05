<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\Role;
use App\Models\SocialAccountConnection;
use App\Models\SocialBufferConnection;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Social\Buffer\BufferLocalConnectorService;
use App\Services\Social\SocialConnectionDeliveryMutex;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(RefreshDatabase::class);

function bufferOauthOwner(): User
{
    $roleId = (int) Role::query()->firstOrCreate(
        ['name' => 'owner'],
        ['description' => 'Owner role']
    )->id;

    return User::factory()->create([
        'role_id' => $roleId,
        'email' => 'buffer-oauth-owner-'.Str::lower(Str::random(10)).'@example.com',
        'company_type' => 'services',
        'company_sector' => 'service_general',
        'onboarding_completed_at' => now(),
        'company_features' => ['social' => true],
    ]);
}

function bufferOauthMember(User $owner): User
{
    $member = User::factory()->create([
        'email' => 'buffer-oauth-member-'.Str::lower(Str::random(10)).'@example.com',
        'company_type' => $owner->company_type,
        'company_features' => $owner->company_features,
        'onboarding_completed_at' => now(),
    ]);

    TeamMember::query()->create([
        'account_id' => $owner->id,
        'user_id' => $member->id,
        'role' => 'member',
        'permissions' => ['social.manage'],
        'is_active' => true,
    ]);

    return $member;
}

/**
 * @return array<string, string>
 */
function startBufferOauth(TestCase $test, User $owner): array
{
    $response = $test->actingAs($owner)
        ->postJson(route('social.buffer.connect'))
        ->assertOk();
    $query = [];
    parse_str((string) parse_url((string) $response->json('redirect_url'), PHP_URL_QUERY), $query);

    return array_map(fn (mixed $value): string => (string) $value, $query);
}

/**
 * @param  array<string, mixed>  $channelOverrides
 */
function fakeBufferAccountAndChannels(
    string $scope = 'account:read offline_access',
    array $channelOverrides = [],
    string $accountId = 'buffer_account_oauth_1',
): void {
    Http::fake([
        'https://buffer.test/oauth/token' => Http::response([
            'access_token' => 'oauth-access-token',
            'refresh_token' => 'oauth-refresh-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'scope' => $scope,
        ]),
        'https://buffer.test/graphql' => function (Request $request) use (
            $accountId,
            $channelOverrides,
        ) {
            $query = (string) ($request['query'] ?? '');

            if (str_contains($query, 'MalikiaPulseBufferAccount')) {
                return Http::response([
                    'data' => [
                        'account' => [
                            'id' => $accountId,
                            'name' => 'Buffer OAuth Account',
                            'organizations' => [[
                                'id' => 'organization_oauth_1',
                                'name' => 'OAuth Organization',
                            ]],
                        ],
                    ],
                ]);
            }

            if (str_contains($query, 'MalikiaPulseBufferChannels')) {
                return Http::response([
                    'data' => [
                        'channels' => [array_replace([
                            'id' => 'channel_oauth_facebook_1',
                            'organizationId' => 'organization_oauth_1',
                            'name' => 'oauth-page',
                            'displayName' => 'OAuth Page',
                            'service' => 'facebook',
                            'type' => 'page',
                            'isDisconnected' => false,
                            'isLocked' => false,
                            'isQueuePaused' => false,
                            'timezone' => 'America/Toronto',
                            'scopes' => ['channel:read'],
                            'allowedActions' => ['createPost'],
                        ], $channelOverrides)],
                    ],
                ]);
            }

            return Http::response([], 500);
        },
    ]);
}

function fakeBufferOauthMultiChannelCatalog(): void
{
    $channel = fn (array $overrides): array => array_replace([
        'id' => 'channel_sync_facebook',
        'organizationId' => 'organization_oauth_1',
        'name' => 'malikia-pro',
        'displayName' => 'Malikia Pro',
        'service' => 'facebook',
        'type' => 'page',
        'isDisconnected' => false,
        'isLocked' => false,
        'isQueuePaused' => false,
        'timezone' => 'America/Toronto',
        'scopes' => ['channel:read'],
        'allowedActions' => ['createPost', 'schedulePost'],
    ], $overrides);
    $channelsByOrganization = [
        'organization_oauth_1' => [
            $channel([]),
            $channel([
                'id' => 'channel_sync_unsupported',
                'name' => 'unsupported-channel',
                'displayName' => 'Unsupported Channel',
                'service' => 'tiktok',
                'type' => 'profile',
            ]),
        ],
        'organization_oauth_2' => [
            $channel([
                'id' => 'channel_sync_instagram',
                'organizationId' => 'organization_oauth_2',
                'name' => 'malikiapro',
                'displayName' => 'malikiapro',
                'service' => 'instagram',
                'type' => 'business',
            ]),
            $channel([
                'id' => 'channel_sync_linkedin',
                'organizationId' => 'organization_oauth_2',
                'name' => 'malikia-pro',
                'displayName' => 'Malikia Pro',
                'service' => 'linkedin',
                'type' => 'page',
            ]),
            $channel([
                'id' => 'channel_sync_disconnected',
                'organizationId' => 'organization_oauth_2',
                'name' => 'disconnected-x',
                'displayName' => 'Disconnected X',
                'service' => 'twitter',
                'type' => 'profile',
                'isDisconnected' => true,
            ]),
            $channel([
                'id' => 'channel_sync_locked',
                'organizationId' => 'organization_oauth_2',
                'name' => 'locked-facebook',
                'displayName' => 'Locked Facebook',
                'isLocked' => true,
            ]),
        ],
    ];

    Http::fake([
        'https://buffer.test/graphql' => function (Request $request) use ($channelsByOrganization) {
            $query = (string) ($request['query'] ?? '');

            if (str_contains($query, 'MalikiaPulseBufferAccount')) {
                return Http::response([
                    'data' => [
                        'account' => [
                            'id' => 'buffer_account_oauth_1',
                            'name' => 'Buffer OAuth Account',
                            'organizations' => [
                                [
                                    'id' => 'organization_oauth_1',
                                    'name' => 'Primary Organization',
                                ],
                                [
                                    'id' => 'organization_oauth_2',
                                    'name' => 'Secondary Organization',
                                ],
                            ],
                        ],
                    ],
                ]);
            }

            if (str_contains($query, 'MalikiaPulseBufferChannels')) {
                $organizationId = (string) data_get(
                    $request->data(),
                    'variables.input.organizationId',
                );

                if (! array_key_exists($organizationId, $channelsByOrganization)) {
                    return Http::response([], 500);
                }

                return Http::response([
                    'data' => [
                        'channels' => $channelsByOrganization[$organizationId],
                    ],
                ]);
            }

            return Http::response([], 500);
        },
    ]);
}

function bufferOauthManagedChannel(
    User $owner,
    string $platform,
    string $externalAccountId,
    string $service,
    string $bufferAccountId = 'buffer_account_oauth_1',
): SocialAccountConnection {
    return SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => $platform,
        'label' => 'Managed '.$service.' channel',
        'display_name' => 'Managed '.$service.' channel',
        'account_handle' => 'managed-'.$service,
        'external_account_id' => $externalAccountId,
        'delivery_provider' => SocialAccountConnection::DELIVERY_PROVIDER_BUFFER,
        'transport_generation' => SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1,
        'logical_destination_key' => 'ldk:v1:'.hash(
            'sha256',
            $owner->id.':'.$platform.':'.$externalAccountId,
        ),
        'auth_method' => SocialAccountConnection::AUTH_METHOD_OAUTH,
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
        'connected_at' => now(),
        'last_synced_at' => now()->subDay(),
        'metadata' => [
            'connection_flow' => 'buffer_oauth_discovery',
            'oauth_ready' => true,
            'buffer' => [
                'account_id' => $bufferAccountId,
                'organization_id' => 'organization_oauth_2',
                'organization_name' => 'Secondary Organization',
                'channel_service' => $service,
                'channel_type' => 'page',
                'catalog_only' => false,
                'publication_enabled' => true,
                'standalone_destination' => true,
            ],
        ],
    ]);
}

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);

    config()->set('services.buffer.oauth', [
        'client_id' => 'buffer-client-id',
        'client_secret' => 'buffer-client-secret',
        'redirect_uri' => 'https://app.test/integrations/social/buffer/callback',
        'authorize_url' => 'https://auth.buffer.test/auth',
        'token_url' => 'https://buffer.test/oauth/token',
        'scopes' => ['account:read', 'offline_access'],
        'connect_timeout' => 2,
        'timeout' => 5,
    ]);
    config()->set('services.buffer.local_connector', [
        'enabled' => false,
        'owner_id' => null,
        'access_token' => null,
        'api_url' => 'https://buffer.test/graphql',
        'connect_timeout' => 2,
        'timeout' => 5,
    ]);

    Http::preventStrayRequests();
});

it('starts Buffer OAuth with PKCE without persisting plaintext secrets', function () {
    $owner = bufferOauthOwner();

    $response = $this->actingAs($owner)
        ->postJson(route('social.buffer.connect'));

    $response->assertOk()
        ->assertJsonPath('connector.oauth_configured', true)
        ->assertJsonPath('connector.connected', false)
        ->assertJsonMissingPath('connector.access_token')
        ->assertJsonMissingPath('connector.refresh_token');

    $query = [];
    parse_str((string) parse_url((string) $response->json('redirect_url'), PHP_URL_QUERY), $query);
    $connection = SocialBufferConnection::query()->whereBelongsTo($owner)->sole();
    $rawConnection = DB::table('social_buffer_connections')->where('id', $connection->id)->first();
    $expectedChallenge = rtrim(strtr(
        base64_encode(hash('sha256', (string) $connection->oauth_code_verifier, true)),
        '+/',
        '-_'
    ), '=');

    expect($query['client_id'] ?? null)->toBe('buffer-client-id')
        ->and($query['redirect_uri'] ?? null)->toBe('https://app.test/integrations/social/buffer/callback')
        ->and($query['scope'] ?? null)->toBe('account:read offline_access')
        ->and($query['code_challenge_method'] ?? null)->toBe('S256')
        ->and($query['code_challenge'] ?? null)->toBe($expectedChallenge)
        ->and($connection->oauth_state)->toBe('state:'.hash('sha256', (string) ($query['state'] ?? '')))
        ->and($rawConnection?->oauth_code_verifier)->not->toBe($connection->oauth_code_verifier)
        ->and((string) $response->getContent())->not->toContain((string) $connection->oauth_code_verifier)
        ->not->toContain('buffer-client-secret');

    Http::assertNothingSent();
});

it('completes Buffer OAuth through the accounts callback and stores encrypted credentials', function () {
    $owner = bufferOauthOwner();
    config()->set('services.buffer.oauth.redirect_uri', 'https://app.test/social/accounts/callback');
    $query = startBufferOauth($this, $owner);
    fakeBufferAccountAndChannels();

    $this->get(route('social.buffer.oauth.callback.accounts', [
        'state' => $query['state'],
        'code' => 'buffer-authorization-code',
    ]))->assertRedirect(route('social.accounts.index'));

    $connection = SocialBufferConnection::query()->whereBelongsTo($owner)->sole();
    $importedChannel = SocialAccountConnection::query()->byUser($owner->id)->sole();
    $rawConnection = DB::table('social_buffer_connections')->where('id', $connection->id)->first();

    expect($connection->buffer_account_id)->toBe('buffer_account_oauth_1')
        ->and($connection->buffer_account_name)->toBe('Buffer OAuth Account')
        ->and($connection->access_token)->toBe('oauth-access-token')
        ->and($connection->refresh_token)->toBe('oauth-refresh-token')
        ->and($connection->scopes)->toBe(['account:read', 'offline_access'])
        ->and($connection->oauth_state)->toBeNull()
        ->and($connection->oauth_code_verifier)->toBeNull()
        ->and($importedChannel->platform)->toBe(SocialAccountConnection::PLATFORM_FACEBOOK)
        ->and($importedChannel->is_active)->toBeFalse()
        ->and(data_get($importedChannel->metadata, 'buffer.catalog_only'))->toBeTrue()
        ->and(data_get($importedChannel->metadata, 'buffer.publication_enabled'))->toBeFalse()
        ->and($rawConnection?->access_token)->not->toContain('oauth-access-token')
        ->and($rawConnection?->refresh_token)->not->toContain('oauth-refresh-token');

    Http::assertSent(fn (Request $request): bool => (
        $request->url() === 'https://buffer.test/oauth/token'
        && $request->data()['grant_type'] === 'authorization_code'
        && $request->data()['redirect_uri'] === 'https://app.test/social/accounts/callback'
        && $request->data()['client_secret'] === 'buffer-client-secret'
        && $request->data()['code_verifier'] !== ''
    ));
    Http::assertSent(fn (Request $request): bool => (
        $request->url() === 'https://buffer.test/graphql'
        && $request->hasHeader('Authorization', 'Bearer oauth-access-token')
    ));

    $this->get(route('social.buffer.oauth.callback.accounts', [
        'state' => $query['state'],
        'code' => 'replayed-code',
    ]))->assertRedirect(route('social.accounts.index'));

    Http::assertSentCount(4);
});

it('activates every supported imported channel after Buffer grants publishing scopes', function (
    string $platform,
    string $service,
    string $channelType,
) {
    $owner = bufferOauthOwner();
    $channelId = 'channel_oauth_'.$service.'_1';
    config()->set('services.buffer.delivery.enabled', true);
    config()->set('services.buffer.oauth.scopes', [
        'account:read',
        'posts:read',
        'posts:write',
        'offline_access',
    ]);
    $importedChannel = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => $platform,
        'label' => 'OAuth Channel',
        'external_account_id' => $channelId,
        'auth_method' => SocialAccountConnection::AUTH_METHOD_OAUTH,
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => false,
        'connected_at' => now(),
        'metadata' => [
            'connection_flow' => 'buffer_oauth_discovery',
            'oauth_ready' => true,
            'buffer' => [
                'account_id' => 'buffer_account_oauth_1',
                'organization_id' => 'organization_oauth_1',
                'channel_service' => $service,
                'channel_type' => $channelType,
                'catalog_only' => true,
            ],
        ],
    ]);
    $query = startBufferOauth($this, $owner);
    fakeBufferAccountAndChannels(
        'account:read posts:read posts:write offline_access',
        [
            'id' => $channelId,
            'service' => $service,
            'type' => $channelType,
        ],
    );

    $this->get(route('social.buffer.oauth.callback', [
        'state' => $query['state'],
        'code' => 'buffer-publishing-authorization-code',
    ]))->assertRedirect(route('social.accounts.index'));

    $fresh = $importedChannel->fresh();

    expect($fresh->delivery_provider)
        ->toBe(SocialAccountConnection::DELIVERY_PROVIDER_BUFFER)
        ->and($fresh->platform)->toBe($platform)
        ->and($fresh->transport_generation)
        ->toBe(SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1)
        ->and($fresh->logical_destination_key)->toMatch('/\Aldk:v1:[0-9a-f]{64}\z/')
        ->and($fresh->status)->toBe(SocialAccountConnection::STATUS_CONNECTED)
        ->and($fresh->is_active)->toBeTrue()
        ->and(data_get($fresh->metadata, 'buffer.catalog_only'))->toBeFalse()
        ->and(data_get($fresh->metadata, 'buffer.publication_enabled'))->toBeTrue();

    $this->actingAs($owner)
        ->getJson(route('social.accounts.index'))
        ->assertOk()
        ->assertJsonPath('buffer_connector.delivery_enabled', true);
})->with([
    'Facebook' => [SocialAccountConnection::PLATFORM_FACEBOOK, 'facebook', 'page'],
    'Instagram' => [SocialAccountConnection::PLATFORM_INSTAGRAM, 'instagram', 'business'],
    'LinkedIn' => [SocialAccountConnection::PLATFORM_LINKEDIN, 'linkedin', 'page'],
    'X through Buffer Twitter service' => [SocialAccountConnection::PLATFORM_X, 'twitter', 'profile'],
]);

it('shows every synchronized Buffer account on each page load without remote discovery', function () {
    $owner = bufferOauthOwner();
    $otherOwner = bufferOauthOwner();
    config()->set('services.buffer.delivery.enabled', true);

    $activeConnection = bufferOauthManagedChannel(
        $owner,
        SocialAccountConnection::PLATFORM_FACEBOOK,
        'channel_persisted_facebook',
        'facebook',
    );
    $reconnectRequiredConnection = bufferOauthManagedChannel(
        $owner,
        SocialAccountConnection::PLATFORM_INSTAGRAM,
        'channel_persisted_instagram',
        'instagram',
    );
    $reconnectRequiredConnection->forceFill([
        'status' => SocialAccountConnection::STATUS_RECONNECT_REQUIRED,
        'is_active' => false,
        'last_error' => 'Reconnectez Buffer avant la prochaine publication.',
    ])->save();
    $inactiveCatalogConnection = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_LINKEDIN,
        'label' => 'Imported LinkedIn catalog channel',
        'display_name' => 'Imported LinkedIn catalog channel',
        'account_handle' => 'imported-linkedin',
        'external_account_id' => 'channel_persisted_linkedin',
        'auth_method' => SocialAccountConnection::AUTH_METHOD_OAUTH,
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => false,
        'connected_at' => now(),
        'last_synced_at' => now()->subDays(2),
        'metadata' => [
            'connection_flow' => 'buffer_oauth_discovery',
            'oauth_ready' => true,
            'buffer' => [
                'account_id' => 'buffer_account_oauth_1',
                'organization_id' => 'organization_oauth_2',
                'channel_service' => 'linkedin',
                'channel_type' => 'page',
                'catalog_only' => true,
                'publication_enabled' => false,
            ],
        ],
    ]);
    $directConnection = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_X,
        'label' => 'Direct X account',
        'display_name' => 'Direct X account',
        'external_account_id' => 'direct_x_account',
        'auth_method' => SocialAccountConnection::AUTH_METHOD_OAUTH,
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
        'connected_at' => now(),
        'metadata' => [
            'connection_flow' => 'oauth_connected',
            'oauth_ready' => true,
        ],
    ]);
    $otherTenantConnection = bufferOauthManagedChannel(
        $otherOwner,
        SocialAccountConnection::PLATFORM_FACEBOOK,
        'channel_other_tenant',
        'facebook',
    );

    $firstLoad = $this->actingAs($owner)
        ->getJson(route('social.accounts.index'));
    $secondLoad = $this->actingAs($owner)
        ->getJson(route('social.accounts.index'));

    $firstLoad->assertOk()
        ->assertJsonCount(3, 'buffer_connections');
    $secondLoad->assertOk()
        ->assertJsonCount(3, 'buffer_connections');

    $firstConnections = collect($firstLoad->json('buffer_connections'))->keyBy('id');
    $secondConnections = collect($secondLoad->json('buffer_connections'))->keyBy('id');

    expect($firstConnections->keys()->map(fn (mixed $id): int => (int) $id)->sort()->values()->all())
        ->toBe(collect([
            $activeConnection->id,
            $reconnectRequiredConnection->id,
            $inactiveCatalogConnection->id,
        ])->sort()->values()->all())
        ->and($firstConnections->has($directConnection->id))->toBeFalse()
        ->and($firstConnections->has($otherTenantConnection->id))->toBeFalse()
        ->and($firstConnections->get($activeConnection->id)['is_active'])->toBeTrue()
        ->and($firstConnections->get($inactiveCatalogConnection->id)['is_active'])->toBeFalse()
        ->and($firstConnections->get($reconnectRequiredConnection->id)['needs_attention'])->toBeTrue()
        ->and($firstConnections->get($reconnectRequiredConnection->id)['status'])
        ->toBe(SocialAccountConnection::STATUS_RECONNECT_REQUIRED)
        ->and($secondConnections->all())->toBe($firstConnections->all());

    Http::assertNothingSent();
});

it('does not expose synchronized Buffer account management payloads to team members', function () {
    $owner = bufferOauthOwner();
    $member = bufferOauthMember($owner);
    config()->set('services.buffer.delivery.enabled', true);

    bufferOauthManagedChannel(
        $owner,
        SocialAccountConnection::PLATFORM_FACEBOOK,
        'channel_private_to_owner',
        'facebook',
    );

    $this->actingAs($member)
        ->getJson(route('social.accounts.index'))
        ->assertOk()
        ->assertJsonPath('access.can_view', true)
        ->assertJsonPath('access.can_manage_accounts', false)
        ->assertJsonCount(0, 'buffer_connections')
        ->assertJsonPath('buffer_connector', null);

    Http::assertNothingSent();
});

it('syncs all healthy Buffer channels idempotently and exposes them in the composer', function () {
    $owner = bufferOauthOwner();
    config()->set('services.buffer.delivery.enabled', true);
    SocialBufferConnection::factory()->for($owner)->create([
        'buffer_account_id' => 'buffer_account_oauth_1',
        'buffer_account_name' => 'Buffer OAuth Account',
        'access_token' => 'oauth-access-token',
        'scopes' => ['account:read', 'posts:read', 'posts:write', 'offline_access'],
    ]);
    $catalogOnlyInstagram = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_INSTAGRAM,
        'label' => 'Imported Instagram catalog channel',
        'display_name' => 'malikiapro',
        'account_handle' => 'malikiapro',
        'external_account_id' => 'channel_sync_instagram',
        'auth_method' => SocialAccountConnection::AUTH_METHOD_OAUTH,
        'status' => SocialAccountConnection::STATUS_RECONNECT_REQUIRED,
        'is_active' => false,
        'connected_at' => now(),
        'metadata' => [
            'connection_flow' => 'buffer_oauth_discovery',
            'oauth_ready' => true,
            'buffer' => [
                'account_id' => 'buffer_account_oauth_1',
                'organization_id' => 'organization_oauth_2',
                'organization_name' => 'Secondary Organization',
                'channel_service' => 'instagram',
                'channel_type' => 'business',
                'catalog_only' => true,
                'publication_enabled' => false,
                'standalone_destination' => false,
            ],
        ],
    ]);
    fakeBufferOauthMultiChannelCatalog();

    $firstSync = $this->actingAs($owner)
        ->postJson(route('social.buffer.channels.sync'));

    $firstSync->assertOk()
        ->assertJsonPath('message_key', 'social.buffer_connector.messages.sync_success')
        ->assertJsonPath('connector.delivery_enabled', true)
        ->assertJsonPath('synced_count', 3)
        ->assertJsonPath('active_count', 3)
        ->assertJsonPath('skipped_count', 3)
        ->assertJsonCount(3, 'connections')
        ->assertJsonPath('connections.0.platform', SocialAccountConnection::PLATFORM_FACEBOOK)
        ->assertJsonPath('connections.1.platform', SocialAccountConnection::PLATFORM_INSTAGRAM)
        ->assertJsonPath('connections.1.id', $catalogOnlyInstagram->id)
        ->assertJsonPath('connections.2.platform', SocialAccountConnection::PLATFORM_LINKEDIN);
    $firstConnectionIds = collect($firstSync->json('connections'))
        ->pluck('id')
        ->map(fn (mixed $id): int => (int) $id)
        ->all();

    $secondSync = $this->actingAs($owner)
        ->postJson(route('social.buffer.channels.sync'));

    $secondSync->assertOk()
        ->assertJsonPath('synced_count', 3)
        ->assertJsonPath('active_count', 3)
        ->assertJsonPath('skipped_count', 3)
        ->assertJsonCount(3, 'connections');
    $secondConnectionIds = collect($secondSync->json('connections'))
        ->pluck('id')
        ->map(fn (mixed $id): int => (int) $id)
        ->all();
    expect($secondConnectionIds)->toBe($firstConnectionIds);

    $connections = SocialAccountConnection::query()
        ->byUser($owner->id)
        ->orderBy('platform')
        ->get();

    expect($connections)->toHaveCount(3);
    expect($connections->pluck('platform')->all())->toBe([
        SocialAccountConnection::PLATFORM_FACEBOOK,
        SocialAccountConnection::PLATFORM_INSTAGRAM,
        SocialAccountConnection::PLATFORM_LINKEDIN,
    ]);
    expect($connections->every(fn (SocialAccountConnection $connection): bool => (
        $connection->is_active
        && $connection->status === SocialAccountConnection::STATUS_CONNECTED
        && $connection->usesBufferPublishingTransport()
        && data_get($connection->metadata, 'buffer.catalog_only') === false
        && data_get($connection->metadata, 'buffer.publication_enabled') === true
    )))->toBeTrue();
    $promotedInstagram = SocialAccountConnection::query()->findOrFail($catalogOnlyInstagram->id);
    expect((int) $promotedInstagram->id)->toBe((int) $catalogOnlyInstagram->id);
    expect($promotedInstagram->is_active)->toBeTrue()
        ->and($promotedInstagram->status)->toBe(SocialAccountConnection::STATUS_CONNECTED)
        ->and($promotedInstagram->delivery_provider)
        ->toBe(SocialAccountConnection::DELIVERY_PROVIDER_BUFFER)
        ->and($promotedInstagram->transport_generation)
        ->toBe(SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1)
        ->and($promotedInstagram->logical_destination_key)
        ->toMatch('/\Aldk:v1:[0-9a-f]{64}\z/')
        ->and(data_get($promotedInstagram->metadata, 'buffer.catalog_only'))->toBeFalse()
        ->and(data_get($promotedInstagram->metadata, 'buffer.publication_enabled'))->toBeTrue();

    $this->actingAs($owner)
        ->getJson(route('social.composer'))
        ->assertOk()
        ->assertJsonCount(3, 'connected_accounts')
        ->assertJsonPath('connected_accounts.0.platform', SocialAccountConnection::PLATFORM_FACEBOOK)
        ->assertJsonPath('connected_accounts.1.platform', SocialAccountConnection::PLATFORM_INSTAGRAM)
        ->assertJsonPath('connected_accounts.2.platform', SocialAccountConnection::PLATFORM_LINKEDIN);

    Http::assertSentCount(6);
});

it('deactivates Buffer channels that are disconnected locked or absent from the catalog', function () {
    $owner = bufferOauthOwner();
    config()->set('services.buffer.delivery.enabled', true);
    SocialBufferConnection::factory()->for($owner)->create([
        'buffer_account_id' => 'buffer_account_oauth_1',
        'buffer_account_name' => 'Buffer OAuth Account',
        'access_token' => 'oauth-access-token',
        'scopes' => ['account:read', 'posts:read', 'posts:write', 'offline_access'],
    ]);
    $disconnected = bufferOauthManagedChannel(
        $owner,
        SocialAccountConnection::PLATFORM_X,
        'channel_sync_disconnected',
        'twitter',
    );
    $locked = bufferOauthManagedChannel(
        $owner,
        SocialAccountConnection::PLATFORM_FACEBOOK,
        'channel_sync_locked',
        'facebook',
    );
    $removed = bufferOauthManagedChannel(
        $owner,
        SocialAccountConnection::PLATFORM_LINKEDIN,
        'channel_sync_removed',
        'linkedin',
    );
    $previousAccountChannel = bufferOauthManagedChannel(
        $owner,
        SocialAccountConnection::PLATFORM_FACEBOOK,
        'channel_previous_buffer_account',
        'facebook',
        'buffer_account_oauth_previous',
    );
    $unassociatedChannel = bufferOauthManagedChannel(
        $owner,
        SocialAccountConnection::PLATFORM_INSTAGRAM,
        'channel_without_buffer_account',
        'instagram',
        '',
    );
    fakeBufferOauthMultiChannelCatalog();

    $response = $this->actingAs($owner)
        ->postJson(route('social.buffer.channels.sync'));

    $response->assertOk()
        ->assertJsonPath('synced_count', 3)
        ->assertJsonPath('active_count', 3)
        ->assertJsonPath('skipped_count', 3)
        ->assertJsonCount(8, 'connections');
    $connectionsByExternalId = collect($response->json('connections'))
        ->keyBy('external_account_id');

    foreach ([$disconnected, $locked, $removed] as $unavailable) {
        $fresh = $unavailable->fresh();

        expect($fresh->status)->toBe(SocialAccountConnection::STATUS_RECONNECT_REQUIRED)
            ->and($fresh->is_active)->toBeFalse()
            ->and($fresh->last_error)->toContain('catalogue Buffer')
            ->and(data_get($fresh->metadata, 'buffer.publication_enabled'))->toBeFalse()
            ->and($connectionsByExternalId->get($fresh->external_account_id)['is_connected'] ?? null)
            ->toBeFalse();
    }

    expect($previousAccountChannel->fresh()->is_active)->toBeFalse()
        ->and($previousAccountChannel->fresh()->last_error)->toContain('ancien compte Buffer')
        ->and(data_get(
            $previousAccountChannel->fresh()->metadata,
            'buffer.publication_enabled',
        ))->toBeFalse();
    expect($unassociatedChannel->fresh()->is_active)->toBeFalse()
        ->and($unassociatedChannel->fresh()->last_error)->toContain('associé au compte Buffer')
        ->and(data_get(
            $unassociatedChannel->fresh()->metadata,
            'buffer.publication_enabled',
        ))->toBeFalse();

    $this->actingAs($owner)
        ->getJson(route('social.composer'))
        ->assertOk()
        ->assertJsonCount(3, 'connected_accounts');

    Http::assertSentCount(3);
});

it('reconciles a Buffer channel identity when its platform changes for the same external id', function () {
    $owner = bufferOauthOwner();
    config()->set('services.buffer.delivery.enabled', true);
    SocialBufferConnection::factory()->for($owner)->create([
        'buffer_account_id' => 'buffer_account_oauth_1',
        'buffer_account_name' => 'Buffer OAuth Account',
        'access_token' => 'oauth-access-token',
        'scopes' => ['account:read', 'posts:read', 'posts:write', 'offline_access'],
    ]);
    $oldFacebookConnection = bufferOauthManagedChannel(
        $owner,
        SocialAccountConnection::PLATFORM_FACEBOOK,
        'channel_platform_changed',
        'facebook',
    );
    fakeBufferAccountAndChannels(
        channelOverrides: [
            'id' => 'channel_platform_changed',
            'service' => 'instagram',
            'type' => 'business',
        ],
    );

    $response = $this->actingAs($owner)
        ->postJson(route('social.buffer.channels.sync'));

    $response->assertOk()
        ->assertJsonPath('synced_count', 1)
        ->assertJsonPath('active_count', 1)
        ->assertJsonCount(2, 'connections');
    expect($oldFacebookConnection->fresh()->is_active)->toBeFalse()
        ->and($oldFacebookConnection->fresh()->status)
        ->toBe(SocialAccountConnection::STATUS_RECONNECT_REQUIRED);
    $instagramConnection = SocialAccountConnection::query()
        ->byUser($owner->id)
        ->where('platform', SocialAccountConnection::PLATFORM_INSTAGRAM)
        ->where('external_account_id', 'channel_platform_changed')
        ->sole();
    expect($instagramConnection->is_active)->toBeTrue()
        ->and($instagramConnection->status)->toBe(SocialAccountConnection::STATUS_CONNECTED);

    $this->actingAs($owner)
        ->getJson(route('social.buffer.catalog'))
        ->assertOk()
        ->assertJsonPath(
            'organizations.0.channels.0.platform',
            SocialAccountConnection::PLATFORM_INSTAGRAM,
        )
        ->assertJsonPath('organizations.0.channels.0.imported', true)
        ->assertJsonPath('organizations.0.channels.0.publication_enabled', true);

    Http::assertSentCount(4);
});

it('refuses to synchronize while a Buffer connection delivery lock is held', function () {
    $owner = bufferOauthOwner();
    config()->set('services.buffer.delivery.enabled', true);
    SocialBufferConnection::factory()->for($owner)->create([
        'buffer_account_id' => 'buffer_account_oauth_1',
        'buffer_account_name' => 'Buffer OAuth Account',
        'access_token' => 'oauth-access-token',
        'scopes' => ['account:read', 'posts:read', 'posts:write', 'offline_access'],
    ]);
    $connection = bufferOauthManagedChannel(
        $owner,
        SocialAccountConnection::PLATFORM_FACEBOOK,
        'channel_sync_facebook',
        'facebook',
    );
    $lastSyncedAt = $connection->last_synced_at?->toISOString();
    fakeBufferOauthMultiChannelCatalog();
    $mutex = app(SocialConnectionDeliveryMutex::class);
    $deliveryLock = $mutex->acquire((int) $connection->id);
    expect($deliveryLock)->not->toBeNull();

    try {
        $response = $this->actingAs($owner)
            ->postJson(route('social.buffer.channels.sync'));
    } finally {
        $deliveryLock?->release();
    }

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('buffer');
    expect($connection->fresh()->last_synced_at?->toISOString())->toBe($lastSyncedAt);
    $this->assertDatabaseMissing('social_account_connections', [
        'user_id' => $owner->id,
        'external_account_id' => 'channel_sync_instagram',
    ]);
    $tenantLock = $mutex->acquireTenant((int) $owner->id);
    expect($tenantLock)->not->toBeNull();
    $tenantLock?->release();

    Http::assertSentCount(3);
});

it('refuses to activate imported channels while a Buffer connection delivery lock is held', function () {
    $owner = bufferOauthOwner();
    config()->set('services.buffer.delivery.enabled', true);
    SocialBufferConnection::factory()->for($owner)->create([
        'buffer_account_id' => 'buffer_account_oauth_1',
        'buffer_account_name' => 'Buffer OAuth Account',
        'access_token' => 'oauth-access-token',
        'scopes' => ['account:read', 'posts:read', 'posts:write', 'offline_access'],
    ]);
    $connection = bufferOauthManagedChannel(
        $owner,
        SocialAccountConnection::PLATFORM_FACEBOOK,
        'channel_sync_facebook',
        'facebook',
    );
    $connection->forceFill([
        'status' => SocialAccountConnection::STATUS_RECONNECT_REQUIRED,
        'is_active' => false,
        'metadata' => [
            ...$connection->metadata,
            'buffer' => [
                ...data_get($connection->metadata, 'buffer', []),
                'publication_enabled' => false,
            ],
        ],
    ])->save();
    fakeBufferOauthMultiChannelCatalog();
    $mutex = app(SocialConnectionDeliveryMutex::class);
    $deliveryLock = $mutex->acquire((int) $connection->id);
    expect($deliveryLock)->not->toBeNull();

    try {
        expect(fn (): int => app(BufferLocalConnectorService::class)
            ->activateImportedChannels($owner))
            ->toThrow(ValidationException::class);
    } finally {
        $deliveryLock?->release();
    }

    expect($connection->fresh()->is_active)->toBeFalse()
        ->and(data_get($connection->fresh()->metadata, 'buffer.publication_enabled'))->toBeFalse();
    $tenantLock = $mutex->acquireTenant((int) $owner->id);
    expect($tenantLock)->not->toBeNull();
    $tenantLock?->release();

    Http::assertSentCount(3);
});

it('deactivates destinations from the previous Buffer account during OAuth activation', function () {
    $owner = bufferOauthOwner();
    config()->set('services.buffer.delivery.enabled', true);
    SocialBufferConnection::factory()->for($owner)->create([
        'buffer_account_id' => 'buffer_account_oauth_2',
        'buffer_account_name' => 'Replacement Buffer Account',
        'access_token' => 'oauth-access-token',
        'scopes' => ['account:read', 'posts:read', 'posts:write', 'offline_access'],
    ]);
    $previousAccountChannel = bufferOauthManagedChannel(
        $owner,
        SocialAccountConnection::PLATFORM_FACEBOOK,
        'channel_previous_buffer_account',
        'facebook',
        'buffer_account_oauth_1',
    );
    $currentAccountCatalogChannel = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_INSTAGRAM,
        'label' => 'Replacement account Instagram',
        'display_name' => 'Replacement account Instagram',
        'account_handle' => 'replacement-instagram',
        'external_account_id' => 'channel_replacement_instagram',
        'auth_method' => SocialAccountConnection::AUTH_METHOD_OAUTH,
        'status' => SocialAccountConnection::STATUS_RECONNECT_REQUIRED,
        'is_active' => false,
        'connected_at' => now(),
        'metadata' => [
            'connection_flow' => 'buffer_oauth_discovery',
            'oauth_ready' => true,
            'buffer' => [
                'account_id' => 'buffer_account_oauth_2',
                'organization_id' => 'organization_oauth_2',
                'channel_service' => 'instagram',
                'channel_type' => 'business',
                'catalog_only' => true,
                'publication_enabled' => false,
                'standalone_destination' => false,
            ],
        ],
    ]);
    $currentAccountUnavailableChannel = bufferOauthManagedChannel(
        $owner,
        SocialAccountConnection::PLATFORM_LINKEDIN,
        'channel_replacement_unavailable',
        'linkedin',
        'buffer_account_oauth_2',
    );
    $currentAccountUnavailableChannel->forceFill([
        'status' => SocialAccountConnection::STATUS_RECONNECT_REQUIRED,
        'is_active' => false,
        'metadata' => [
            ...$currentAccountUnavailableChannel->metadata,
            'buffer' => [
                ...data_get($currentAccountUnavailableChannel->metadata, 'buffer', []),
                'publication_enabled' => false,
            ],
        ],
    ])->save();
    $directConnection = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_X,
        'label' => 'Direct X connection',
        'external_account_id' => 'direct-x-account',
        ...pulseDirectTransportIdentity(
            $owner,
            SocialAccountConnection::PLATFORM_X,
            'direct-x-account',
        ),
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
        'connected_at' => now(),
    ]);
    fakeBufferAccountAndChannels(
        channelOverrides: [
            'id' => 'channel_replacement_instagram',
            'name' => 'replacement-instagram',
            'displayName' => 'Replacement account Instagram',
            'service' => 'instagram',
            'type' => 'business',
        ],
        accountId: 'buffer_account_oauth_2',
    );

    $activatedCount = app(BufferLocalConnectorService::class)
        ->activateImportedChannels($owner);

    expect($activatedCount)->toBe(1)
        ->and($previousAccountChannel->fresh()->is_active)->toBeFalse()
        ->and($previousAccountChannel->fresh()->last_error)->toContain('ancien compte Buffer')
        ->and(data_get(
            $previousAccountChannel->fresh()->metadata,
            'buffer.publication_enabled',
        ))->toBeFalse()
        ->and($currentAccountCatalogChannel->fresh()->is_active)->toBeTrue()
        ->and(data_get(
            $currentAccountCatalogChannel->fresh()->metadata,
            'buffer.publication_enabled',
        ))->toBeTrue()
        ->and($currentAccountUnavailableChannel->fresh()->is_active)->toBeFalse()
        ->and($currentAccountUnavailableChannel->fresh()->status)
        ->toBe(SocialAccountConnection::STATUS_RECONNECT_REQUIRED)
        ->and($currentAccountUnavailableChannel->fresh()->last_error)
        ->toContain('catalogue Buffer')
        ->and(data_get(
            $currentAccountUnavailableChannel->fresh()->metadata,
            'buffer.publication_enabled',
        ))->toBeFalse()
        ->and($directConnection->fresh()->is_active)->toBeTrue()
        ->and($directConnection->fresh()->status)->toBe(SocialAccountConnection::STATUS_CONNECTED);

    Http::assertSentCount(2);
});

it('rejects a channel discovered from a Buffer account that changed before import locking', function () {
    $owner = bufferOauthOwner();
    config()->set('services.buffer.delivery.enabled', true);
    $bufferConnection = SocialBufferConnection::factory()->for($owner)->create([
        'buffer_account_id' => 'buffer_account_oauth_1',
        'buffer_account_name' => 'Buffer OAuth Account',
        'access_token' => 'oauth-access-token',
        'scopes' => ['account:read', 'posts:read', 'posts:write', 'offline_access'],
    ]);
    Http::fake([
        'https://buffer.test/graphql' => function (Request $request) use ($bufferConnection) {
            $query = (string) ($request['query'] ?? '');

            if (str_contains($query, 'MalikiaPulseBufferAccount')) {
                return Http::response([
                    'data' => [
                        'account' => [
                            'id' => 'buffer_account_oauth_1',
                            'name' => 'Original Buffer Account',
                            'organizations' => [[
                                'id' => 'organization_oauth_1',
                                'name' => 'Original Organization',
                            ]],
                        ],
                    ],
                ]);
            }

            $bufferConnection->forceFill([
                'buffer_account_id' => 'buffer_account_oauth_2',
                'buffer_account_name' => 'Replacement Buffer Account',
            ])->save();

            return Http::response([
                'data' => [
                    'channels' => [[
                        'id' => 'channel_oauth_facebook_1',
                        'organizationId' => 'organization_oauth_1',
                        'name' => 'oauth-page',
                        'displayName' => 'OAuth Page',
                        'service' => 'facebook',
                        'type' => 'page',
                        'isDisconnected' => false,
                        'isLocked' => false,
                        'isQueuePaused' => false,
                        'timezone' => 'America/Toronto',
                        'scopes' => ['channel:read'],
                        'allowedActions' => ['createPost'],
                    ]],
                ],
            ]);
        },
    ]);

    $this->actingAs($owner)
        ->postJson(route('social.buffer.channels.store'), [
            'organization_id' => 'organization_oauth_1',
            'channel_id' => 'channel_oauth_facebook_1',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('buffer');

    expect(SocialAccountConnection::query()->byUser($owner->id)->exists())->toBeFalse();
    Http::assertSentCount(2);
});

it('rolls back every synchronized channel when one conflicts with an existing connection', function () {
    $owner = bufferOauthOwner();
    config()->set('services.buffer.delivery.enabled', true);
    SocialBufferConnection::factory()->for($owner)->create([
        'buffer_account_id' => 'buffer_account_oauth_1',
        'buffer_account_name' => 'Buffer OAuth Account',
        'access_token' => 'oauth-access-token',
        'scopes' => ['account:read', 'posts:read', 'posts:write', 'offline_access'],
    ]);
    $existingConnection = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_LINKEDIN,
        'label' => 'Existing direct LinkedIn page',
        'external_account_id' => 'channel_sync_linkedin',
        ...pulseDirectTransportIdentity(
            $owner,
            SocialAccountConnection::PLATFORM_LINKEDIN,
            'channel_sync_linkedin',
        ),
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
        'connected_at' => now(),
    ]);
    fakeBufferOauthMultiChannelCatalog();

    $this->actingAs($owner)
        ->postJson(route('social.buffer.channels.sync'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('channel_id');

    $this->assertModelExists($existingConnection);
    expect(SocialAccountConnection::query()->byUser($owner->id)->count())->toBe(1);
    $this->assertDatabaseMissing('social_account_connections', [
        'user_id' => $owner->id,
        'external_account_id' => 'channel_sync_facebook',
    ]);
    $this->assertDatabaseMissing('social_account_connections', [
        'user_id' => $owner->id,
        'external_account_id' => 'channel_sync_instagram',
    ]);
    Http::assertSentCount(3);
});

it('does not activate a catalog import whose Buffer service mismatches its platform', function () {
    $owner = bufferOauthOwner();
    config()->set('services.buffer.delivery.enabled', true);
    config()->set('services.buffer.oauth.scopes', [
        'account:read',
        'posts:read',
        'posts:write',
        'offline_access',
    ]);
    $importedChannel = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
        'label' => 'Mismatched OAuth Channel',
        'external_account_id' => 'channel_oauth_mismatched_1',
        'auth_method' => SocialAccountConnection::AUTH_METHOD_OAUTH,
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => false,
        'connected_at' => now(),
        'metadata' => [
            'connection_flow' => 'buffer_oauth_discovery',
            'oauth_ready' => true,
            'buffer' => [
                'account_id' => 'buffer_account_oauth_1',
                'organization_id' => 'organization_oauth_1',
                'channel_service' => 'instagram',
                'channel_type' => 'business',
                'catalog_only' => true,
            ],
        ],
    ]);
    $query = startBufferOauth($this, $owner);
    fakeBufferAccountAndChannels(
        'account:read posts:read posts:write offline_access',
    );

    $this->get(route('social.buffer.oauth.callback', [
        'state' => $query['state'],
        'code' => 'buffer-mismatched-channel-code',
    ]))->assertRedirect(route('social.accounts.index'));

    $fresh = $importedChannel->fresh();

    expect($fresh->delivery_provider)->toBeNull()
        ->and($fresh->transport_generation)->toBeNull()
        ->and($fresh->logical_destination_key)->toBeNull()
        ->and($fresh->is_active)->toBeFalse()
        ->and(data_get($fresh->metadata, 'buffer.catalog_only'))->toBeTrue()
        ->and(data_get($fresh->metadata, 'buffer.publication_enabled'))->not->toBeTrue();
});

it('clears expired and denied Buffer authorization states without contacting Buffer', function (string $mode) {
    $owner = bufferOauthOwner();
    $query = startBufferOauth($this, $owner);
    $connection = SocialBufferConnection::query()->whereBelongsTo($owner)->sole();

    if ($mode === 'expired') {
        $connection->forceFill(['oauth_state_expires_at' => now()->subSecond()])->save();
    }

    $callbackPayload = ['state' => $query['state']];
    $callbackPayload[$mode === 'denied' ? 'error' : 'code'] = $mode === 'denied'
        ? 'access_denied'
        : 'expired-code';

    $this->get(route('social.buffer.oauth.callback', $callbackPayload))
        ->assertRedirect(route('social.accounts.index'));

    $fresh = $connection->fresh();

    expect($fresh->oauth_state)->toBeNull()
        ->and($fresh->oauth_code_verifier)->toBeNull()
        ->and($fresh->oauth_state_expires_at)->toBeNull()
        ->and($fresh->last_error)->not->toBeNull();

    Http::assertNothingSent();
})->with(['expired', 'denied']);

it('rotates Buffer tokens before loading an expired catalog', function () {
    $owner = bufferOauthOwner();
    $connection = SocialBufferConnection::factory()->for($owner)->create([
        'access_token' => 'expired-access-token',
        'refresh_token' => 'single-use-refresh-token',
        'token_expires_at' => now()->subMinute(),
    ]);

    fakeBufferAccountAndChannels();

    $this->actingAs($owner)
        ->getJson(route('social.buffer.catalog'))
        ->assertOk()
        ->assertJsonPath('connector.mode', 'oauth')
        ->assertJsonPath('connector.connected', true)
        ->assertJsonPath('organizations.0.channels.0.name', 'oauth-page');

    $fresh = $connection->fresh();

    expect($fresh->access_token)->toBe('oauth-access-token')
        ->and($fresh->refresh_token)->toBe('oauth-refresh-token')
        ->and($fresh->last_refreshed_at)->not->toBeNull();

    Http::assertSent(fn (Request $request): bool => (
        $request->url() === 'https://buffer.test/oauth/token'
        && $request->data()['grant_type'] === 'refresh_token'
        && $request->data()['refresh_token'] === 'single-use-refresh-token'
    ));
    Http::assertSent(fn (Request $request): bool => (
        $request->url() === 'https://buffer.test/graphql'
        && $request->hasHeader('Authorization', 'Bearer oauth-access-token')
    ));
    Http::assertSentCount(3);
});

it('does not retry a refused Buffer refresh or contact GraphQL', function () {
    $owner = bufferOauthOwner();
    $connection = SocialBufferConnection::factory()->for($owner)->create([
        'access_token' => 'expired-access-token',
        'refresh_token' => 'refused-refresh-token',
        'token_expires_at' => now()->subMinute(),
    ]);
    Http::fake([
        'https://buffer.test/oauth/token' => Http::response([
            'error' => 'invalid_grant',
        ], 400),
    ]);

    $this->actingAs($owner)
        ->getJson(route('social.buffer.catalog'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('buffer');

    $fresh = $connection->fresh();

    expect($fresh->access_token)->toBe('expired-access-token')
        ->and($fresh->refresh_token)->toBe('refused-refresh-token')
        ->and($fresh->last_error)->toContain('Reconnectez');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => (
        $request->url() === 'https://buffer.test/oauth/token'
        && $request->data()['refresh_token'] === 'refused-refresh-token'
    ));
});

it('disconnects Buffer locally while keeping synchronized accounts visible for reconnection', function () {
    $owner = bufferOauthOwner();
    $connection = SocialBufferConnection::factory()->for($owner)->create();
    $managedChannel = bufferOauthManagedChannel(
        $owner,
        SocialAccountConnection::PLATFORM_FACEBOOK,
        'channel_disconnect_facebook',
        'facebook',
    );
    $catalogChannel = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_INSTAGRAM,
        'label' => 'Imported Instagram catalog channel',
        'display_name' => 'Imported Instagram catalog channel',
        'external_account_id' => 'channel_disconnect_instagram',
        'auth_method' => SocialAccountConnection::AUTH_METHOD_OAUTH,
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => false,
        'connected_at' => now(),
        'metadata' => [
            'connection_flow' => 'buffer_oauth_discovery',
            'oauth_ready' => true,
            'buffer' => [
                'account_id' => 'buffer_account_oauth_1',
                'organization_id' => 'organization_oauth_1',
                'channel_service' => 'instagram',
                'channel_type' => 'business',
                'catalog_only' => true,
                'publication_enabled' => false,
            ],
        ],
    ]);
    config()->set('services.buffer.local_connector.enabled', true);
    config()->set('services.buffer.local_connector.owner_id', $owner->id);
    config()->set('services.buffer.local_connector.access_token', 'legacy-token-must-not-return');

    $this->actingAs($owner)
        ->postJson(route('social.buffer.disconnect'))
        ->assertOk()
        ->assertJsonPath('connector.connected', false)
        ->assertJsonPath('connector.can_disconnect', false)
        ->assertJsonCount(2, 'connections')
        ->assertJsonPath('connections.0.id', $managedChannel->id)
        ->assertJsonPath('connections.0.status', SocialAccountConnection::STATUS_RECONNECT_REQUIRED)
        ->assertJsonPath('connections.0.is_active', false)
        ->assertJsonPath('connections.0.needs_attention', true)
        ->assertJsonPath('connections.1.id', $catalogChannel->id)
        ->assertJsonPath('connections.1.status', SocialAccountConnection::STATUS_RECONNECT_REQUIRED)
        ->assertJsonPath('connections.1.is_active', false)
        ->assertJsonPath('connections.1.needs_attention', true);

    $this->assertModelMissing($connection);

    $managedChannel->refresh();
    $catalogChannel->refresh();

    expect($managedChannel->status)->toBe(SocialAccountConnection::STATUS_RECONNECT_REQUIRED)
        ->and($managedChannel->is_active)->toBeFalse()
        ->and($managedChannel->last_error)->toContain('Reconnectez Buffer')
        ->and($catalogChannel->status)->toBe(SocialAccountConnection::STATUS_RECONNECT_REQUIRED)
        ->and($catalogChannel->is_active)->toBeFalse()
        ->and($catalogChannel->last_error)->toContain('Reconnectez Buffer');

    $this->actingAs($owner)
        ->getJson(route('social.buffer.catalog'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('buffer');

    Http::assertNothingSent();
});

it('keeps Buffer OAuth mutations owner only', function () {
    $owner = bufferOauthOwner();
    $member = bufferOauthMember($owner);

    $this->postJson(route('social.buffer.connect'))
        ->assertUnauthorized();

    $this->actingAs($member)
        ->postJson(route('social.buffer.connect'))
        ->assertForbidden();

    SocialBufferConnection::factory()->for($owner)->create();

    $this->actingAs($member)
        ->postJson(route('social.buffer.channels.sync'))
        ->assertForbidden();

    $this->actingAs($member)
        ->postJson(route('social.buffer.disconnect'))
        ->assertForbidden();

    expect(SocialBufferConnection::query()->whereBelongsTo($owner)->exists())->toBeTrue();
    Http::assertNothingSent();
});

it('refuses Buffer OAuth when the client is not configured', function () {
    $owner = bufferOauthOwner();
    config()->set('services.buffer.oauth.client_id', null);

    $this->actingAs($owner)
        ->postJson(route('social.buffer.connect'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('buffer');

    expect(SocialBufferConnection::query()->count())->toBe(0);
    Http::assertNothingSent();
});
