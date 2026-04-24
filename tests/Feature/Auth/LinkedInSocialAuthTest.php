<?php

use App\Models\Role;
use App\Models\User;
use App\Models\UserSocialAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();

    config()->set('social_auth.enabled', true);
    config()->set('social_auth.providers.linkedin.enabled', true);
    config()->set('social_auth.providers.linkedin.implemented', true);
    config()->set('social_auth.providers.linkedin.client_id', 'linkedin-client-id');
    config()->set('social_auth.providers.linkedin.client_secret', 'linkedin-client-secret');
    config()->set('social_auth.providers.linkedin.redirect_uri', 'https://app.test/auth/social/linkedin/callback');
    config()->set('social_auth.providers.linkedin.authorize_url', 'https://www.linkedin.test/oauth/v2/authorization');
    config()->set('social_auth.providers.linkedin.token_url', 'https://www.linkedin.test/oauth/v2/accessToken');
    config()->set('social_auth.providers.linkedin.userinfo_url', 'https://api.linkedin.test/v2/userinfo');
    config()->set('social_auth.providers.linkedin.scopes', ['openid', 'profile', 'email']);
});

test('linkedin social auth redirect sends the guest to the provider with state and onboarding context', function () {
    $response = $this->get(route('auth.social.redirect', [
        'provider' => 'linkedin',
        'source' => 'onboarding',
        'plan' => 'growth',
        'billing_period' => 'yearly',
    ]));

    $response->assertRedirect();

    $location = $response->headers->get('Location');

    expect($location)->toStartWith('https://www.linkedin.test/oauth/v2/authorization?');

    parse_str(parse_url((string) $location, PHP_URL_QUERY) ?: '', $query);

    expect($query['client_id'] ?? null)->toBe('linkedin-client-id')
        ->and($query['redirect_uri'] ?? null)->toBe('https://app.test/auth/social/linkedin/callback')
        ->and($query['response_type'] ?? null)->toBe('code')
        ->and($query['scope'] ?? null)->toBe('openid profile email')
        ->and($query['state'] ?? null)->toBeString()
        ->and($query['state'] ?? '')->not->toBe('');

    $this->assertSame('linkedin', session('social_auth.pending.provider'));
    $this->assertSame('onboarding', session('social_auth.pending.source'));
    $this->assertSame('growth', session('social_auth.pending.plan'));
    $this->assertSame('yearly', session('social_auth.pending.billing_period'));
    $this->assertSame($query['state'] ?? null, session('social_auth.pending.state'));
});

test('linkedin social auth callback creates a new owner and resumes onboarding with the selected context', function () {
    Http::fake([
        'https://www.linkedin.test/oauth/v2/accessToken' => Http::response([
            'access_token' => 'linkedin-access-token',
            'expires_in' => 3600,
            'scope' => 'openid profile email',
            'token_type' => 'Bearer',
            'id_token' => fakeLinkedinJwt([
                'sub' => 'linkedin-user-001',
                'email' => 'owner-linkedin@example.com',
                'email_verified' => true,
                'name' => 'Owner LinkedIn',
                'picture' => 'https://cdn.example.com/linkedin-owner.png',
            ]),
        ]),
        'https://api.linkedin.test/v2/userinfo' => Http::response([
            'sub' => 'linkedin-user-001',
            'email' => 'owner-linkedin@example.com',
            'email_verified' => true,
            'name' => 'Owner LinkedIn',
            'picture' => 'https://cdn.example.com/linkedin-owner.png',
        ]),
    ]);

    $response = $this
        ->withSession([
            'locale' => 'fr',
            'social_auth.pending' => [
                'provider' => 'linkedin',
                'source' => 'onboarding',
                'plan' => 'growth',
                'billing_period' => 'yearly',
                'state' => 'linkedin-state-001',
                'requested_at' => now()->toIso8601String(),
            ],
        ])
        ->get(route('auth.social.callback', [
            'provider' => 'linkedin',
            'state' => 'linkedin-state-001',
            'code' => 'linkedin-auth-code',
        ]));

    $user = User::query()->where('email', 'owner-linkedin@example.com')->firstOrFail();
    $socialAccount = UserSocialAccount::query()->where('user_id', $user->id)->firstOrFail();

    $this->assertAuthenticatedAs($user);

    $response->assertRedirect(route('onboarding.index', [
        'plan' => 'growth',
        'billing_period' => 'yearly',
    ]));

    expect($user->hasRole('owner'))->toBeTrue()
        ->and($user->name)->toBe('Owner LinkedIn')
        ->and($user->locale)->toBe('fr')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($socialAccount->provider)->toBe('linkedin')
        ->and($socialAccount->provider_user_id)->toBe('linkedin-user-001')
        ->and($socialAccount->provider_email)->toBe('owner-linkedin@example.com')
        ->and($socialAccount->provider_name)->toBe('Owner LinkedIn')
        ->and($socialAccount->provider_avatar_url)->toBe('https://cdn.example.com/linkedin-owner.png')
        ->and($socialAccount->provider_email_verified_at)->not->toBeNull()
        ->and($socialAccount->last_login_at)->not->toBeNull()
        ->and($socialAccount->access_token)->toBe('linkedin-access-token');

    $storedAccount = DB::table('user_social_accounts')->where('id', $socialAccount->id)->first();

    expect($storedAccount)->not->toBeNull()
        ->and($storedAccount->access_token)->not->toBe('linkedin-access-token');

    $this->assertNull(session('social_auth.pending'));
});

test('linkedin social auth links an existing verified user when email is only present in the id token', function () {
    $ownerRoleId = Role::query()->firstOrCreate(
        ['name' => 'owner'],
        ['description' => 'Account owner role']
    )->id;

    $user = User::factory()->create([
        'email' => 'existing-linkedin@example.com',
        'role_id' => $ownerRoleId,
        'locale' => 'en',
        'onboarding_completed_at' => now(),
    ]);

    Http::fake([
        'https://www.linkedin.test/oauth/v2/accessToken' => Http::response([
            'access_token' => 'existing-linkedin-access-token',
            'expires_in' => 3600,
            'scope' => 'openid profile email',
            'token_type' => 'Bearer',
            'id_token' => fakeLinkedinJwt([
                'sub' => 'linkedin-user-002',
                'email' => 'existing-linkedin@example.com',
                'name' => 'Existing LinkedIn Owner',
            ]),
        ]),
        'https://api.linkedin.test/v2/userinfo' => Http::response([
            'sub' => 'linkedin-user-002',
            'name' => 'Existing LinkedIn Owner',
        ]),
    ]);

    $response = $this
        ->withSession([
            'social_auth.pending' => [
                'provider' => 'linkedin',
                'source' => 'login',
                'plan' => null,
                'billing_period' => null,
                'state' => 'linkedin-state-002',
                'requested_at' => now()->toIso8601String(),
            ],
        ])
        ->get(route('auth.social.callback', [
            'provider' => 'linkedin',
            'state' => 'linkedin-state-002',
            'code' => 'linkedin-auth-code',
        ]));

    $this->assertAuthenticatedAs($user);

    $response->assertRedirect(route('dashboard', absolute: false));

    $socialAccount = UserSocialAccount::query()
        ->where('user_id', $user->id)
        ->where('provider', 'linkedin')
        ->first();

    expect($socialAccount)->not->toBeNull()
        ->and($socialAccount?->provider_user_id)->toBe('linkedin-user-002')
        ->and($socialAccount?->provider_email)->toBe('existing-linkedin@example.com');
});

test('linkedin social auth refuses provider profiles without a usable email address', function () {
    Http::fake([
        'https://www.linkedin.test/oauth/v2/accessToken' => Http::response([
            'access_token' => 'linkedin-access-token',
            'expires_in' => 3600,
            'scope' => 'openid profile email',
            'token_type' => 'Bearer',
            'id_token' => fakeLinkedinJwt([
                'sub' => 'linkedin-user-003',
                'name' => 'No Email LinkedIn User',
            ]),
        ]),
        'https://api.linkedin.test/v2/userinfo' => Http::response([
            'sub' => 'linkedin-user-003',
            'name' => 'No Email LinkedIn User',
        ]),
    ]);

    $response = $this
        ->withSession([
            'social_auth.pending' => [
                'provider' => 'linkedin',
                'source' => 'register',
                'plan' => null,
                'billing_period' => null,
                'state' => 'linkedin-state-003',
                'requested_at' => now()->toIso8601String(),
            ],
        ])
        ->get(route('auth.social.callback', [
            'provider' => 'linkedin',
            'state' => 'linkedin-state-003',
            'code' => 'linkedin-auth-code',
        ]));

    $this->assertGuest();

    $response->assertRedirect(route('register'))
        ->assertSessionHas('error', __('ui.auth.social.email_not_verified', ['provider' => 'LinkedIn']));

    expect(UserSocialAccount::query()->where('provider_user_id', 'linkedin-user-003')->exists())->toBeFalse();
});

test('legacy linkedin callback path remains supported for older provider redirect uris', function () {
    config()->set('social_auth.providers.linkedin.redirect_uri', 'https://app.test/auth/linkedin/callback');

    Http::fake([
        'https://www.linkedin.test/oauth/v2/accessToken' => Http::response([
            'access_token' => 'legacy-linkedin-access-token',
            'expires_in' => 3600,
            'scope' => 'openid profile email',
            'token_type' => 'Bearer',
            'id_token' => fakeLinkedinJwt([
                'sub' => 'linkedin-user-legacy-001',
                'email' => 'legacy-linkedin-owner@example.com',
                'name' => 'Legacy LinkedIn Owner',
            ]),
        ]),
        'https://api.linkedin.test/v2/userinfo' => Http::response([
            'sub' => 'linkedin-user-legacy-001',
            'name' => 'Legacy LinkedIn Owner',
        ]),
    ]);

    $response = $this
        ->withSession([
            'social_auth.pending' => [
                'provider' => 'linkedin',
                'source' => 'onboarding',
                'plan' => 'growth',
                'billing_period' => 'yearly',
                'state' => 'linkedin-state-legacy-001',
                'requested_at' => now()->toIso8601String(),
            ],
        ])
        ->get('/auth/linkedin/callback?state=linkedin-state-legacy-001&code=linkedin-auth-code');

    $user = User::query()->where('email', 'legacy-linkedin-owner@example.com')->firstOrFail();

    $this->assertAuthenticatedAs($user);

    $response->assertRedirect(route('onboarding.index', [
        'plan' => 'growth',
        'billing_period' => 'yearly',
    ]));

    expect(UserSocialAccount::query()
        ->where('user_id', $user->id)
        ->where('provider', 'linkedin')
        ->exists())->toBeTrue();
});

function fakeLinkedinJwt(array $claims): string
{
    $encode = static function (array $payload): string {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    };

    return $encode(['alg' => 'none', 'typ' => 'JWT']).'.'.$encode($claims).'.signature';
}
