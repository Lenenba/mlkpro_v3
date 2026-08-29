<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\Role;
use App\Models\SocialAccountConnection;
use App\Models\SocialBufferConnection;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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

function fakeBufferAccountAndChannels(
    string $scope = 'account:read offline_access',
): void {
    Http::fake([
        'https://buffer.test/oauth/token' => Http::response([
            'access_token' => 'oauth-access-token',
            'refresh_token' => 'oauth-refresh-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'scope' => $scope,
        ]),
        'https://buffer.test/graphql' => function (Request $request) {
            $query = (string) ($request['query'] ?? '');

            if (str_contains($query, 'MalikiaPulseBufferAccount')) {
                return Http::response([
                    'data' => [
                        'account' => [
                            'id' => 'buffer_account_oauth_1',
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
            }

            return Http::response([], 500);
        },
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
    $rawConnection = DB::table('social_buffer_connections')->where('id', $connection->id)->first();

    expect($connection->buffer_account_id)->toBe('buffer_account_oauth_1')
        ->and($connection->buffer_account_name)->toBe('Buffer OAuth Account')
        ->and($connection->access_token)->toBe('oauth-access-token')
        ->and($connection->refresh_token)->toBe('oauth-refresh-token')
        ->and($connection->scopes)->toBe(['account:read', 'offline_access'])
        ->and($connection->oauth_state)->toBeNull()
        ->and($connection->oauth_code_verifier)->toBeNull()
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

    Http::assertSentCount(2);
});

it('activates an imported Facebook channel after Buffer grants publishing scopes', function () {
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
        'label' => 'OAuth Page',
        'external_account_id' => 'channel_oauth_facebook_1',
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
                'channel_service' => 'facebook',
                'channel_type' => 'page',
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
        'code' => 'buffer-publishing-authorization-code',
    ]))->assertRedirect(route('social.accounts.index'));

    $fresh = $importedChannel->fresh();

    expect($fresh->delivery_provider)
        ->toBe(SocialAccountConnection::DELIVERY_PROVIDER_BUFFER)
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

it('disconnects Buffer locally and removes every OAuth secret', function () {
    $owner = bufferOauthOwner();
    $connection = SocialBufferConnection::factory()->for($owner)->create();
    config()->set('services.buffer.local_connector.enabled', true);
    config()->set('services.buffer.local_connector.owner_id', $owner->id);
    config()->set('services.buffer.local_connector.access_token', 'legacy-token-must-not-return');

    $this->actingAs($owner)
        ->postJson(route('social.buffer.disconnect'))
        ->assertOk()
        ->assertJsonPath('connector.connected', false)
        ->assertJsonPath('connector.can_disconnect', false);

    $this->assertModelMissing($connection);

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
