<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\Role;
use App\Models\SocialAccountConnection;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

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

    $response->assertOk();

    $fresh = $connection->fresh();

    expect($fresh->metadata['oauth_code_verifier'] ?? null)->not->toBeNull()
        ->and($response->json('redirect_url'))->toContain('code_challenge=')
        ->and($response->json('redirect_url'))->toContain('code_challenge_method=S256');
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
            'scope' => 'r_organization_admin w_organization_social',
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
        ->and($fresh->permissions)->toBe(['r_organization_admin', 'w_organization_social'])
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
        ->and($fresh->oauth_state_expires_at)->toBeNull()
        ->and($fresh->last_error)->toBe('Malikia Pulse is disabled for this workspace. Re-enable the social module before reconnecting this account.')
        ->and((array) ($fresh->credentials ?? []))->toBe([])
        ->and($fresh->metadata['oauth_ready'] ?? null)->toBeFalse()
        ->and($fresh->metadata['connection_flow'] ?? null)->toBe('oauth_blocked_feature_off');
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
        'permissions' => ['r_organization_admin'],
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
        'connected_at' => now()->subDay(),
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
            'scope' => 'r_organization_admin w_organization_social',
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
        ->and($fresh->permissions)->toBe(['r_organization_admin', 'w_organization_social'])
        ->and($fresh->last_synced_at)->toBeInstanceOf(Carbon::class)
        ->and($fresh->token_expires_at)->toBeInstanceOf(Carbon::class)
        ->and($fresh->status)->toBe(SocialAccountConnection::STATUS_CONNECTED)
        ->and($fresh->is_active)->toBeTrue();
});
