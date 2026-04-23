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
    config()->set('social_auth.providers.microsoft.enabled', true);
    config()->set('social_auth.providers.microsoft.implemented', true);
    config()->set('social_auth.providers.microsoft.client_id', 'microsoft-client-id');
    config()->set('social_auth.providers.microsoft.client_secret', 'microsoft-client-secret');
    config()->set('social_auth.providers.microsoft.redirect_uri', 'https://app.test/auth/social/microsoft/callback');
    config()->set('social_auth.providers.microsoft.authorize_url', 'https://login.microsoftonline.test/common/oauth2/v2.0/authorize');
    config()->set('social_auth.providers.microsoft.token_url', 'https://login.microsoftonline.test/common/oauth2/v2.0/token');
    config()->set('social_auth.providers.microsoft.userinfo_url', 'https://graph.microsoft.test/oidc/userinfo');
    config()->set('social_auth.providers.microsoft.scopes', ['openid', 'profile', 'email']);
});

test('microsoft social auth redirect sends the guest to the provider with state and onboarding context', function () {
    $response = $this->get(route('auth.social.redirect', [
        'provider' => 'microsoft',
        'source' => 'onboarding',
        'plan' => 'growth',
        'billing_period' => 'yearly',
    ]));

    $response->assertRedirect();

    $location = $response->headers->get('Location');

    expect($location)->toStartWith('https://login.microsoftonline.test/common/oauth2/v2.0/authorize?');

    parse_str(parse_url((string) $location, PHP_URL_QUERY) ?: '', $query);

    expect($query['client_id'] ?? null)->toBe('microsoft-client-id')
        ->and($query['redirect_uri'] ?? null)->toBe('https://app.test/auth/social/microsoft/callback')
        ->and($query['response_type'] ?? null)->toBe('code')
        ->and($query['scope'] ?? null)->toBe('openid profile email')
        ->and($query['state'] ?? null)->toBeString()
        ->and($query['state'] ?? '')->not->toBe('');

    $this->assertSame('microsoft', session('social_auth.pending.provider'));
    $this->assertSame('onboarding', session('social_auth.pending.source'));
    $this->assertSame('growth', session('social_auth.pending.plan'));
    $this->assertSame('yearly', session('social_auth.pending.billing_period'));
    $this->assertSame($query['state'] ?? null, session('social_auth.pending.state'));
});

test('microsoft social auth callback creates a new owner and resumes onboarding with the selected context', function () {
    Http::fake([
        'https://login.microsoftonline.test/common/oauth2/v2.0/token' => Http::response([
            'access_token' => 'microsoft-access-token',
            'expires_in' => 3600,
            'scope' => 'openid profile email',
            'token_type' => 'Bearer',
            'refresh_token' => 'microsoft-refresh-token',
            'id_token' => fakeJwt([
                'sub' => 'microsoft-user-001',
                'email' => 'owner-microsoft@example.com',
                'preferred_username' => 'owner-microsoft@example.com',
                'name' => 'Owner Microsoft',
            ]),
        ]),
        'https://graph.microsoft.test/oidc/userinfo' => Http::response([
            'sub' => 'microsoft-user-001',
            'email' => 'owner-microsoft@example.com',
            'name' => 'Owner Microsoft',
        ]),
    ]);

    $response = $this
        ->withSession([
            'locale' => 'fr',
            'social_auth.pending' => [
                'provider' => 'microsoft',
                'source' => 'onboarding',
                'plan' => 'growth',
                'billing_period' => 'yearly',
                'state' => 'microsoft-state-001',
                'requested_at' => now()->toIso8601String(),
            ],
        ])
        ->get(route('auth.social.callback', [
            'provider' => 'microsoft',
            'state' => 'microsoft-state-001',
            'code' => 'microsoft-auth-code',
        ]));

    $user = User::query()->where('email', 'owner-microsoft@example.com')->firstOrFail();
    $socialAccount = UserSocialAccount::query()->where('user_id', $user->id)->firstOrFail();

    $this->assertAuthenticatedAs($user);

    $response->assertRedirect(route('onboarding.index', [
        'plan' => 'growth',
        'billing_period' => 'yearly',
    ]));

    expect($user->hasRole('owner'))->toBeTrue()
        ->and($user->name)->toBe('Owner Microsoft')
        ->and($user->locale)->toBe('fr')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($socialAccount->provider)->toBe('microsoft')
        ->and($socialAccount->provider_user_id)->toBe('microsoft-user-001')
        ->and($socialAccount->provider_email)->toBe('owner-microsoft@example.com')
        ->and($socialAccount->provider_name)->toBe('Owner Microsoft')
        ->and($socialAccount->provider_email_verified_at)->not->toBeNull()
        ->and($socialAccount->last_login_at)->not->toBeNull()
        ->and($socialAccount->access_token)->toBe('microsoft-access-token');

    $storedAccount = DB::table('user_social_accounts')->where('id', $socialAccount->id)->first();

    expect($storedAccount)->not->toBeNull()
        ->and($storedAccount->access_token)->not->toBe('microsoft-access-token');

    $this->assertNull(session('social_auth.pending'));
});

test('microsoft social auth links an existing verified user by preferred username when email is absent', function () {
    $ownerRoleId = Role::query()->firstOrCreate(
        ['name' => 'owner'],
        ['description' => 'Account owner role']
    )->id;

    $user = User::factory()->create([
        'email' => 'existing-microsoft@example.com',
        'role_id' => $ownerRoleId,
        'locale' => 'en',
        'onboarding_completed_at' => now(),
    ]);

    Http::fake([
        'https://login.microsoftonline.test/common/oauth2/v2.0/token' => Http::response([
            'access_token' => 'existing-microsoft-access-token',
            'expires_in' => 3600,
            'scope' => 'openid profile email',
            'token_type' => 'Bearer',
            'id_token' => fakeJwt([
                'sub' => 'microsoft-user-002',
                'preferred_username' => 'existing-microsoft@example.com',
                'name' => 'Existing Microsoft Owner',
            ]),
        ]),
        'https://graph.microsoft.test/oidc/userinfo' => Http::response([
            'sub' => 'microsoft-user-002',
            'name' => 'Existing Microsoft Owner',
        ]),
    ]);

    $response = $this
        ->withSession([
            'social_auth.pending' => [
                'provider' => 'microsoft',
                'source' => 'login',
                'plan' => null,
                'billing_period' => null,
                'state' => 'microsoft-state-002',
                'requested_at' => now()->toIso8601String(),
            ],
        ])
        ->get(route('auth.social.callback', [
            'provider' => 'microsoft',
            'state' => 'microsoft-state-002',
            'code' => 'microsoft-auth-code',
        ]));

    $this->assertAuthenticatedAs($user);

    $response->assertRedirect(route('dashboard', absolute: false));

    $socialAccount = UserSocialAccount::query()
        ->where('user_id', $user->id)
        ->where('provider', 'microsoft')
        ->first();

    expect($socialAccount)->not->toBeNull()
        ->and($socialAccount?->provider_user_id)->toBe('microsoft-user-002')
        ->and($socialAccount?->provider_email)->toBe('existing-microsoft@example.com');
});

test('microsoft social auth refuses provider profiles without a usable email address', function () {
    Http::fake([
        'https://login.microsoftonline.test/common/oauth2/v2.0/token' => Http::response([
            'access_token' => 'microsoft-access-token',
            'expires_in' => 3600,
            'scope' => 'openid profile email',
            'token_type' => 'Bearer',
            'id_token' => fakeJwt([
                'sub' => 'microsoft-user-003',
                'preferred_username' => 'not-an-email',
                'name' => 'No Email Microsoft User',
            ]),
        ]),
        'https://graph.microsoft.test/oidc/userinfo' => Http::response([
            'sub' => 'microsoft-user-003',
            'name' => 'No Email Microsoft User',
        ]),
    ]);

    $response = $this
        ->withSession([
            'social_auth.pending' => [
                'provider' => 'microsoft',
                'source' => 'register',
                'plan' => null,
                'billing_period' => null,
                'state' => 'microsoft-state-003',
                'requested_at' => now()->toIso8601String(),
            ],
        ])
        ->get(route('auth.social.callback', [
            'provider' => 'microsoft',
            'state' => 'microsoft-state-003',
            'code' => 'microsoft-auth-code',
        ]));

    $this->assertGuest();

    $response->assertRedirect(route('register'))
        ->assertSessionHas('error', __('ui.auth.social.email_not_verified', ['provider' => 'Microsoft']));

    expect(UserSocialAccount::query()->where('provider_user_id', 'microsoft-user-003')->exists())->toBeFalse();
});

function fakeJwt(array $claims): string
{
    $encode = static function (array $payload): string {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    };

    return $encode(['alg' => 'none', 'typ' => 'JWT']).'.'.$encode($claims).'.signature';
}
