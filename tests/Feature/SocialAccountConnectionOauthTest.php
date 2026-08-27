<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\Role;
use App\Models\SocialAccountConnection;
use App\Models\User;
use App\Services\Social\SocialAccountConnectionService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function pulseOauthRoleId(string $name): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $name.' role']
    )->id;
}

function pulseOauthOwner(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role_id' => pulseOauthRoleId('owner'),
        'email' => 'pulse-oauth-owner-'.Str::lower(Str::random(10)).'@example.com',
        'company_type' => 'services',
        'company_sector' => 'service_general',
        'onboarding_completed_at' => now(),
        'company_features' => [
            'social' => true,
        ],
    ], $overrides));
}

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);

    config()->set('services.social.linkedin.oauth.client_id', 'linkedin-client');
    config()->set('services.social.linkedin.oauth.client_secret', 'linkedin-secret');
    config()->set('services.social.linkedin.oauth.authorize_url', 'https://www.linkedin.com/oauth/v2/authorization');
    config()->set('services.social.linkedin.oauth.token_url', 'https://linkedin.test/oauth/token');
    config()->set('services.social.linkedin.oauth.redirect_uri', 'https://app.test/integrations/social/linkedin/callback');

    config()->set('services.social.x.oauth.client_id', 'x-client');
    config()->set('services.social.x.oauth.client_secret', 'x-secret');
    config()->set('services.social.x.oauth.authorize_url', 'https://x.com/i/oauth2/authorize');
    config()->set('services.social.x.oauth.token_url', 'https://x.test/oauth2/token');
    config()->set('services.social.x.oauth.redirect_uri', 'https://app.test/integrations/social/x/callback');
});

it('starts a social oauth redirect and persists pending state for linkedin', function () {
    $owner = pulseOauthOwner();
    $connection = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_LINKEDIN,
        'label' => 'LinkedIn HQ',
        'status' => SocialAccountConnection::STATUS_DRAFT,
        'is_active' => false,
        'metadata' => [
            'connection_flow' => 'oauth_scaffold',
            'oauth_ready' => false,
        ],
    ]);

    $response = $this->actingAs($owner)
        ->postJson(route('social.accounts.authorize', $connection));

    $response->assertOk()
        ->assertJsonPath('flow', 'redirect')
        ->assertJsonPath('connection.status', SocialAccountConnection::STATUS_PENDING);

    $fresh = $connection->fresh();

    expect($fresh->oauth_state)->not->toBeNull()
        ->and($fresh->oauth_state_expires_at)->toBeInstanceOf(Carbon::class)
        ->and($fresh->status)->toBe(SocialAccountConnection::STATUS_PENDING)
        ->and($fresh->is_active)->toBeFalse()
        ->and($response->json('redirect_url'))->toContain('linkedin.com/oauth/v2/authorization')
        ->and($response->json('redirect_url'))->toContain('state='.$fresh->oauth_state);
});

it('adds a pkce verifier when starting the x oauth redirect', function () {
    $owner = pulseOauthOwner();
    $connection = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_X,
        'label' => 'Launch profile',
        'status' => SocialAccountConnection::STATUS_DRAFT,
        'is_active' => false,
        'metadata' => [
            'connection_flow' => 'oauth_scaffold',
            'oauth_ready' => false,
        ],
    ]);

    $response = $this->actingAs($owner)
        ->postJson(route('social.accounts.authorize', $connection));

    $response->assertOk()
        ->assertJsonMissingPath('connection.oauth_code_verifier')
        ->assertJsonMissingPath('connection.metadata.oauth_code_verifier');

    $fresh = $connection->fresh();
    $rawVerifier = (string) DB::table('social_account_connections')
        ->where('id', $connection->id)
        ->value('oauth_code_verifier');
    $redirectQuery = [];
    parse_str((string) parse_url((string) $response->json('redirect_url'), PHP_URL_QUERY), $redirectQuery);
    $expectedChallenge = rtrim(strtr(
        base64_encode(hash('sha256', (string) $fresh->oauth_code_verifier, true)),
        '+/',
        '-_'
    ), '=');

    expect($fresh->oauth_code_verifier)->not->toBeNull()
        ->and($fresh->metadata)->not->toHaveKey('oauth_code_verifier')
        ->and($rawVerifier)->not->toContain((string) $fresh->oauth_code_verifier)
        ->and($redirectQuery['code_challenge'] ?? null)->toBe($expectedChallenge)
        ->and($response->json('redirect_url'))->toContain('code_challenge_method=S256');
});

it('uses the encrypted x pkce verifier once and clears it after the callback succeeds', function () {
    $owner = pulseOauthOwner();
    $connection = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_X,
        'label' => 'Launch profile',
        'status' => SocialAccountConnection::STATUS_PENDING,
        'is_active' => false,
        'oauth_state' => 'x-state-123',
        'oauth_code_verifier' => 'x-pkce-verifier-123',
        'oauth_state_expires_at' => now()->addMinutes(10),
        'metadata' => [
            'connection_flow' => 'oauth',
            'oauth_ready' => false,
        ],
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'https://x.test/oauth2/token' => Http::response([
            'access_token' => 'x-access-token',
            'refresh_token' => 'x-refresh-token',
            'expires_in' => 7200,
            'scope' => 'tweet.read tweet.write users.read offline.access',
            'token_type' => 'Bearer',
        ]),
    ]);

    $this->actingAs($owner)
        ->get(route('social.accounts.oauth.callback', [
            'platform' => SocialAccountConnection::PLATFORM_X,
            'state' => 'x-state-123',
            'code' => 'x-auth-code',
        ]))
        ->assertRedirect(route('social.accounts.index'));

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://x.test/oauth2/token'
        && $request->data()['code_verifier'] === 'x-pkce-verifier-123');

    $fresh = $connection->fresh();

    expect($fresh->oauth_code_verifier)->toBeNull()
        ->and($fresh->credentials['access_token'] ?? null)->toBe('x-access-token')
        ->and($fresh->metadata)->not->toHaveKey('oauth_code_verifier');
});

it('claims an x oauth callback before exchanging the authorization code', function () {
    $owner = pulseOauthOwner();
    $connection = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_X,
        'label' => 'Single use X profile',
        'status' => SocialAccountConnection::STATUS_PENDING,
        'is_active' => false,
        'oauth_state' => 'x-single-use-state',
        'oauth_code_verifier' => 'x-single-use-verifier',
        'oauth_state_expires_at' => now()->addMinutes(10),
        'metadata' => ['connection_flow' => 'oauth'],
    ]);
    $service = app(SocialAccountConnectionService::class);
    $requestCount = 0;
    $overlappingCallbackWasRejected = false;
    $overlappingRestartWasRejected = false;

    Http::preventStrayRequests();
    Http::fake(function (Request $request) use (
        &$overlappingCallbackWasRejected,
        &$overlappingRestartWasRejected,
        &$requestCount,
        $connection,
        $owner,
        $service
    ) {
        $requestCount++;

        if ($requestCount === 1) {
            try {
                $service->completeAuthorization(SocialAccountConnection::PLATFORM_X, [
                    'state' => 'x-single-use-state',
                    'code' => 'overlapping-code',
                ]);
            } catch (ValidationException) {
                $overlappingCallbackWasRejected = true;
            }

            try {
                $service->authorize($owner, $connection);
            } catch (ValidationException) {
                $overlappingRestartWasRejected = true;
            }
        }

        return Http::response([
            'access_token' => 'single-use-access-token',
            'refresh_token' => 'single-use-refresh-token',
            'expires_in' => 7200,
            'scope' => 'tweet.read tweet.write users.read offline.access',
            'token_type' => 'Bearer',
        ]);
    });

    $result = $service->completeAuthorization(SocialAccountConnection::PLATFORM_X, [
        'state' => 'x-single-use-state',
        'code' => 'winning-code',
    ]);

    expect($result['success'])->toBeTrue()
        ->and($overlappingCallbackWasRejected)->toBeTrue()
        ->and($overlappingRestartWasRejected)->toBeTrue()
        ->and($requestCount)->toBe(1)
        ->and($connection->fresh()->oauth_state)->toBeNull()
        ->and($connection->fresh()->oauth_code_verifier)->toBeNull()
        ->and($connection->fresh()->oauth_state_expires_at)->toBeNull();
});

it('exposes whether an authorizing oauth callback claim is still active', function () {
    $owner = pulseOauthOwner();
    $connection = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_X,
        'label' => 'Recoverable X profile',
        'status' => SocialAccountConnection::STATUS_AUTHORIZING,
        'is_active' => false,
        'oauth_state' => 'callback:active-claim',
        'oauth_state_expires_at' => now()->addMinute(),
        'metadata' => ['connection_flow' => 'oauth'],
    ]);
    $service = app(SocialAccountConnectionService::class);

    expect($service->payload($connection)['oauth_callback_active'])->toBeTrue();

    $connection->forceFill([
        'oauth_state_expires_at' => now()->subSecond(),
    ])->save();

    expect($service->payload($connection->fresh())['oauth_callback_active'])->toBeFalse();
});

it('does not let a superseded oauth callback overwrite a newer authorization flow', function () {
    $owner = pulseOauthOwner();
    $connection = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_X,
        'label' => 'Superseded X profile',
        'status' => SocialAccountConnection::STATUS_PENDING,
        'is_active' => false,
        'oauth_state' => 'x-old-state',
        'oauth_code_verifier' => 'x-old-verifier',
        'oauth_state_expires_at' => now()->addMinutes(10),
        'metadata' => ['connection_flow' => 'oauth'],
    ]);

    Http::preventStrayRequests();
    Http::fake(function (Request $request) use ($connection) {
        expect($request->data()['code_verifier'] ?? null)->toBe('x-old-verifier')
            ->and(DB::table('social_account_connections')
                ->where('id', $connection->id)
                ->value('oauth_code_verifier'))->toBeNull();

        $connection->fresh()->forceFill([
            'status' => SocialAccountConnection::STATUS_PENDING,
            'oauth_state' => 'x-new-state',
            'oauth_code_verifier' => 'x-new-verifier',
            'oauth_state_expires_at' => now()->addMinutes(10),
        ])->save();

        return Http::response([
            'access_token' => 'obsolete-access-token',
            'refresh_token' => 'obsolete-refresh-token',
            'expires_in' => 7200,
            'scope' => 'tweet.read tweet.write users.read offline.access',
            'token_type' => 'Bearer',
        ]);
    });

    expect(fn () => app(SocialAccountConnectionService::class)->completeAuthorization(
        SocialAccountConnection::PLATFORM_X,
        [
            'state' => 'x-old-state',
            'code' => 'x-old-code',
        ]
    ))->toThrow(ValidationException::class);

    $fresh = $connection->fresh();

    expect($fresh->status)->toBe(SocialAccountConnection::STATUS_PENDING)
        ->and($fresh->oauth_state)->toBe('x-new-state')
        ->and($fresh->oauth_code_verifier)->toBe('x-new-verifier')
        ->and((array) ($fresh->credentials ?? []))->toBe([]);
    Http::assertSentCount(1);
});

it('accepts a legacy x pkce verifier during the migration compatibility window and then scrubs it', function () {
    $owner = pulseOauthOwner();
    $connection = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_X,
        'label' => 'Legacy rollout profile',
        'status' => SocialAccountConnection::STATUS_PENDING,
        'is_active' => false,
        'oauth_state' => 'x-legacy-state-123',
        'oauth_state_expires_at' => now()->addMinutes(10),
        'metadata' => [
            'connection_flow' => 'oauth',
            'oauth_code_verifier' => 'legacy-x-pkce-verifier-123',
        ],
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'https://x.test/oauth2/token' => Http::response([
            'access_token' => 'legacy-x-access-token',
            'refresh_token' => 'legacy-x-refresh-token',
            'expires_in' => 7200,
            'scope' => 'tweet.read tweet.write users.read offline.access',
            'token_type' => 'Bearer',
        ]),
    ]);

    $this->actingAs($owner)
        ->get(route('social.accounts.oauth.callback', [
            'platform' => SocialAccountConnection::PLATFORM_X,
            'state' => 'x-legacy-state-123',
            'code' => 'x-legacy-auth-code',
        ]))
        ->assertRedirect(route('social.accounts.index'));

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://x.test/oauth2/token'
        && $request->data()['code_verifier'] === 'legacy-x-pkce-verifier-123');

    $fresh = $connection->fresh();

    expect($fresh->oauth_code_verifier)->toBeNull()
        ->and($fresh->metadata)->not->toHaveKey('oauth_code_verifier');
});

it('completes the oauth callback and persists encrypted social credentials', function () {
    $owner = pulseOauthOwner();
    $connection = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_LINKEDIN,
        'label' => 'LinkedIn HQ',
        'status' => SocialAccountConnection::STATUS_PENDING,
        'is_active' => false,
        'oauth_state' => 'social-state-123',
        'oauth_state_expires_at' => now()->addMinutes(10),
        'metadata' => [
            'connection_flow' => 'oauth',
            'oauth_ready' => false,
        ],
    ]);

    Http::fake([
        'https://linkedin.test/oauth/token' => Http::response([
            'access_token' => 'linkedin-access-token',
            'refresh_token' => 'linkedin-refresh-token',
            'expires_in' => 3600,
            'scope' => 'rw_organization_admin w_organization_social',
            'token_type' => 'Bearer',
        ], 200),
    ]);

    $this->actingAs($owner)
        ->get(route('social.accounts.oauth.callback', [
            'platform' => SocialAccountConnection::PLATFORM_LINKEDIN,
            'state' => 'social-state-123',
            'code' => 'linkedin-auth-code',
        ]))
        ->assertRedirect(route('social.accounts.index'));

    $fresh = $connection->fresh();

    expect($fresh->status)->toBe(SocialAccountConnection::STATUS_CONNECTED)
        ->and($fresh->is_active)->toBeTrue()
        ->and($fresh->oauth_state)->toBeNull()
        ->and($fresh->oauth_state_expires_at)->toBeNull()
        ->and($fresh->last_error)->toBeNull()
        ->and($fresh->credentials['access_token'] ?? null)->toBe('linkedin-access-token')
        ->and($fresh->credentials['refresh_token'] ?? null)->toBe('linkedin-refresh-token')
        ->and($fresh->permissions)->toBe(['rw_organization_admin', 'w_organization_social'])
        ->and($fresh->connected_at)->toBeInstanceOf(Carbon::class)
        ->and($fresh->last_synced_at)->toBeInstanceOf(Carbon::class)
        ->and($fresh->token_expires_at)->toBeInstanceOf(Carbon::class)
        ->and($fresh->metadata['oauth_ready'] ?? null)->toBeTrue()
        ->and($fresh->metadata['connection_flow'] ?? null)->toBe('oauth_connected');
});

it('blocks completing the oauth callback when the social module is disabled for the workspace', function () {
    $owner = pulseOauthOwner([
        'company_features' => [
            'social' => false,
        ],
    ]);

    $connection = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_LINKEDIN,
        'label' => 'LinkedIn HQ',
        'status' => SocialAccountConnection::STATUS_PENDING,
        'is_active' => false,
        'oauth_state' => 'social-state-disabled',
        'oauth_code_verifier' => 'disabled-pkce-verifier',
        'oauth_state_expires_at' => now()->addMinutes(10),
        'metadata' => [
            'connection_flow' => 'oauth',
            'oauth_ready' => false,
        ],
    ]);

    Http::fake();

    $this->get(route('social.accounts.oauth.callback', [
        'platform' => SocialAccountConnection::PLATFORM_LINKEDIN,
        'state' => 'social-state-disabled',
        'code' => 'linkedin-auth-code',
    ]))
        ->assertRedirect(route('dashboard'));

    Http::assertNothingSent();

    $fresh = $connection->fresh();

    expect($fresh->status)->toBe(SocialAccountConnection::STATUS_RECONNECT_REQUIRED)
        ->and($fresh->is_active)->toBeFalse()
        ->and($fresh->oauth_state)->toBeNull()
        ->and($fresh->oauth_code_verifier)->toBeNull()
        ->and($fresh->oauth_state_expires_at)->toBeNull()
        ->and($fresh->last_error)->toBe('Malikia Pulse is disabled for this workspace. Re-enable the social module before reconnecting this account.')
        ->and((array) ($fresh->credentials ?? []))->toBe([])
        ->and($fresh->metadata['oauth_ready'] ?? null)->toBeFalse()
        ->and($fresh->metadata['connection_flow'] ?? null)->toBe('oauth_blocked_feature_off');
});

it('clears the pkce verifier when the oauth callback has expired', function () {
    $owner = pulseOauthOwner();
    $connection = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_X,
        'label' => 'Expired X profile',
        'status' => SocialAccountConnection::STATUS_PENDING,
        'is_active' => false,
        'oauth_state' => 'x-expired-state',
        'oauth_code_verifier' => 'expired-pkce-verifier',
        'oauth_state_expires_at' => now()->subMinute(),
        'metadata' => ['connection_flow' => 'oauth'],
    ]);

    Http::fake();

    $this->actingAs($owner)
        ->get(route('social.accounts.oauth.callback', [
            'platform' => SocialAccountConnection::PLATFORM_X,
            'state' => 'x-expired-state',
            'code' => 'unused-code',
        ]))
        ->assertRedirect(route('social.accounts.index'));

    Http::assertNothingSent();

    $fresh = $connection->fresh();

    expect($fresh->status)->toBe(SocialAccountConnection::STATUS_RECONNECT_REQUIRED)
        ->and($fresh->oauth_code_verifier)->toBeNull()
        ->and($fresh->metadata)->not->toHaveKey('oauth_code_verifier');
});

it('clears the pkce verifier when the provider refuses oauth authorization', function () {
    $owner = pulseOauthOwner();
    $connection = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_X,
        'label' => 'Refused X profile',
        'status' => SocialAccountConnection::STATUS_PENDING,
        'is_active' => false,
        'oauth_state' => 'x-refused-state',
        'oauth_code_verifier' => 'refused-pkce-verifier',
        'oauth_state_expires_at' => now()->addMinutes(10),
        'metadata' => ['connection_flow' => 'oauth'],
    ]);

    Http::fake();

    $this->actingAs($owner)
        ->get(route('social.accounts.oauth.callback', [
            'platform' => SocialAccountConnection::PLATFORM_X,
            'state' => 'x-refused-state',
            'error' => 'access_denied',
        ]))
        ->assertRedirect(route('social.accounts.index'));

    Http::assertNothingSent();

    $fresh = $connection->fresh();

    expect($fresh->status)->toBe(SocialAccountConnection::STATUS_RECONNECT_REQUIRED)
        ->and($fresh->oauth_code_verifier)->toBeNull()
        ->and($fresh->metadata)->not->toHaveKey('oauth_code_verifier');
});

it('clears the pkce verifier when the provider rejects the token exchange', function () {
    $owner = pulseOauthOwner();
    $connection = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_X,
        'label' => 'Rejected X profile',
        'status' => SocialAccountConnection::STATUS_PENDING,
        'is_active' => false,
        'oauth_state' => 'x-rejected-state',
        'oauth_code_verifier' => 'rejected-pkce-verifier',
        'oauth_state_expires_at' => now()->addMinutes(10),
        'metadata' => ['connection_flow' => 'oauth'],
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'https://x.test/oauth2/token' => Http::response([
            'error' => 'invalid_grant',
            'error_description' => 'Authorization code expired.',
        ], 400),
    ]);

    $this->actingAs($owner)
        ->get(route('social.accounts.oauth.callback', [
            'platform' => SocialAccountConnection::PLATFORM_X,
            'state' => 'x-rejected-state',
            'code' => 'expired-code',
        ]))
        ->assertRedirect(route('social.accounts.index'));

    Http::assertSentCount(1);

    $fresh = $connection->fresh();

    expect($fresh->status)->toBe(SocialAccountConnection::STATUS_RECONNECT_REQUIRED)
        ->and($fresh->oauth_code_verifier)->toBeNull()
        ->and($fresh->metadata)->not->toHaveKey('oauth_code_verifier');
});

it('does not retry an ambiguous oauth token timeout and requires a fresh connection attempt', function () {
    $owner = pulseOauthOwner();
    $connection = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_X,
        'label' => 'Timed out X profile',
        'status' => SocialAccountConnection::STATUS_PENDING,
        'is_active' => false,
        'oauth_state' => 'x-timeout-state',
        'oauth_code_verifier' => 'x-timeout-verifier',
        'oauth_state_expires_at' => now()->addMinutes(10),
        'metadata' => ['connection_flow' => 'oauth'],
    ]);

    $requestCount = 0;

    Http::preventStrayRequests();
    Http::fake(function () use (&$requestCount) {
        $requestCount++;

        throw new ConnectionException('Ambiguous token timeout');
    });

    $this->actingAs($owner)
        ->get(route('social.accounts.oauth.callback', [
            'platform' => SocialAccountConnection::PLATFORM_X,
            'state' => 'x-timeout-state',
            'code' => 'possibly-consumed-code',
        ]))
        ->assertRedirect(route('social.accounts.index'));

    $fresh = $connection->fresh();

    expect($requestCount)->toBe(1)
        ->and($fresh->status)->toBe(SocialAccountConnection::STATUS_ERROR)
        ->and($fresh->oauth_state)->toBeNull()
        ->and($fresh->oauth_code_verifier)->toBeNull()
        ->and($fresh->oauth_state_expires_at)->toBeNull()
        ->and($fresh->last_error)->toContain('could not be reached');
});

it('refreshes a connected social account token through the provider strategy', function () {
    $owner = pulseOauthOwner();
    $connection = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_LINKEDIN,
        'label' => 'LinkedIn HQ',
        'auth_method' => SocialAccountConnection::AUTH_METHOD_OAUTH,
        'credentials' => [
            'access_token' => 'old-token',
            'refresh_token' => 'refresh-token',
            'token_type' => 'Bearer',
        ],
        'permissions' => ['rw_organization_admin'],
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
        'connected_at' => now()->subDay(),
        'oauth_state' => 'stale-refresh-state',
        'oauth_code_verifier' => 'stale-refresh-verifier',
        'oauth_state_expires_at' => now()->addMinutes(10),
        'metadata' => [
            'connection_flow' => 'oauth_connected',
            'oauth_ready' => true,
        ],
    ]);

    Http::fake([
        'https://linkedin.test/oauth/token' => Http::response([
            'access_token' => 'new-token',
            'refresh_token' => 'new-refresh-token',
            'expires_in' => 7200,
            'scope' => 'rw_organization_admin w_organization_social',
            'token_type' => 'Bearer',
        ], 200),
    ]);

    $this->actingAs($owner)
        ->postJson(route('social.accounts.refresh', $connection))
        ->assertOk()
        ->assertJsonPath('connection.status', SocialAccountConnection::STATUS_CONNECTED)
        ->assertJsonPath('connection.is_active', true);

    $fresh = $connection->fresh();

    expect($fresh->credentials['access_token'] ?? null)->toBe('new-token')
        ->and($fresh->credentials['refresh_token'] ?? null)->toBe('new-refresh-token')
        ->and($fresh->permissions)->toBe(['rw_organization_admin', 'w_organization_social'])
        ->and($fresh->last_synced_at)->toBeInstanceOf(Carbon::class)
        ->and($fresh->token_expires_at)->toBeInstanceOf(Carbon::class)
        ->and($fresh->status)->toBe(SocialAccountConnection::STATUS_CONNECTED)
        ->and($fresh->is_active)->toBeTrue()
        ->and($fresh->oauth_state)->toBeNull()
        ->and($fresh->oauth_code_verifier)->toBeNull()
        ->and($fresh->oauth_state_expires_at)->toBeNull();
});

it('clears pending oauth secrets when a token refresh cannot reach the provider', function () {
    $owner = pulseOauthOwner();
    $connection = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_LINKEDIN,
        'label' => 'LinkedIn timeout',
        'credentials' => [
            'access_token' => 'old-timeout-token',
            'refresh_token' => 'timeout-refresh-token',
        ],
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
        'oauth_state' => 'stale-timeout-state',
        'oauth_code_verifier' => 'stale-timeout-verifier',
        'oauth_state_expires_at' => now()->addMinutes(10),
    ]);

    Http::fake(fn () => throw new ConnectionException('Simulated provider timeout'));

    $this->actingAs($owner)
        ->postJson(route('social.accounts.refresh', $connection))
        ->assertOk()
        ->assertJsonPath('connection.status', SocialAccountConnection::STATUS_ERROR);

    $fresh = $connection->fresh();

    expect($fresh->oauth_state)->toBeNull()
        ->and($fresh->oauth_code_verifier)->toBeNull()
        ->and($fresh->oauth_state_expires_at)->toBeNull()
        ->and($fresh->last_error)->toContain('could not be reached');
});
