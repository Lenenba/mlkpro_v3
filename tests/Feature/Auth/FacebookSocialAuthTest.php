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
    config()->set('social_auth.providers.facebook.enabled', true);
    config()->set('social_auth.providers.facebook.implemented', true);
    config()->set('social_auth.providers.facebook.client_id', 'facebook-client-id');
    config()->set('social_auth.providers.facebook.client_secret', 'facebook-client-secret');
    config()->set('social_auth.providers.facebook.redirect_uri', 'https://app.test/auth/social/facebook/callback');
    config()->set('social_auth.providers.facebook.authorize_url', 'https://www.facebook.test/v23.0/dialog/oauth');
    config()->set('social_auth.providers.facebook.token_url', 'https://graph.facebook.test/v23.0/oauth/access_token');
    config()->set('social_auth.providers.facebook.userinfo_url', 'https://graph.facebook.test/me?fields=id,name,email,picture');
    config()->set('social_auth.providers.facebook.scopes', ['email', 'public_profile']);
});

test('facebook social auth redirect sends the guest to the provider with state and onboarding context', function () {
    $response = $this->get(route('auth.social.redirect', [
        'provider' => 'facebook',
        'source' => 'onboarding',
        'plan' => 'growth',
        'billing_period' => 'yearly',
    ]));

    $response->assertRedirect();

    $location = $response->headers->get('Location');

    expect($location)->toStartWith('https://www.facebook.test/v23.0/dialog/oauth?');

    parse_str(parse_url((string) $location, PHP_URL_QUERY) ?: '', $query);

    expect($query['client_id'] ?? null)->toBe('facebook-client-id')
        ->and($query['redirect_uri'] ?? null)->toBe('https://app.test/auth/social/facebook/callback')
        ->and($query['response_type'] ?? null)->toBe('code')
        ->and($query['scope'] ?? null)->toBe('email,public_profile')
        ->and($query['state'] ?? null)->toBeString()
        ->and($query['state'] ?? '')->not->toBe('');

    $this->assertSame('facebook', session('social_auth.pending.provider'));
    $this->assertSame('onboarding', session('social_auth.pending.source'));
    $this->assertSame('growth', session('social_auth.pending.plan'));
    $this->assertSame('yearly', session('social_auth.pending.billing_period'));
    $this->assertSame($query['state'] ?? null, session('social_auth.pending.state'));
});

test('facebook social auth callback creates a new owner and resumes onboarding with the selected context', function () {
    Http::fake([
        'https://graph.facebook.test/v23.0/oauth/access_token*' => Http::response([
            'access_token' => 'facebook-access-token',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
        ]),
        'https://graph.facebook.test/me?fields=id,name,email,picture' => Http::response([
            'id' => 'facebook-user-001',
            'email' => 'owner-facebook@example.com',
            'name' => 'Owner Facebook',
            'picture' => [
                'data' => [
                    'url' => 'https://cdn.example.com/facebook-owner.png',
                ],
            ],
        ]),
    ]);

    $response = $this
        ->withSession([
            'locale' => 'fr',
            'social_auth.pending' => [
                'provider' => 'facebook',
                'source' => 'onboarding',
                'plan' => 'growth',
                'billing_period' => 'yearly',
                'state' => 'facebook-state-001',
                'requested_at' => now()->toIso8601String(),
            ],
        ])
        ->get(route('auth.social.callback', [
            'provider' => 'facebook',
            'state' => 'facebook-state-001',
            'code' => 'facebook-auth-code',
        ]));

    $user = User::query()->where('email', 'owner-facebook@example.com')->firstOrFail();
    $socialAccount = UserSocialAccount::query()->where('user_id', $user->id)->firstOrFail();

    $this->assertAuthenticatedAs($user);

    $response->assertRedirect(route('onboarding.index', [
        'plan' => 'growth',
        'billing_period' => 'yearly',
    ]));

    expect($user->hasRole('owner'))->toBeTrue()
        ->and($user->name)->toBe('Owner Facebook')
        ->and($user->locale)->toBe('fr')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($socialAccount->provider)->toBe('facebook')
        ->and($socialAccount->provider_user_id)->toBe('facebook-user-001')
        ->and($socialAccount->provider_email)->toBe('owner-facebook@example.com')
        ->and($socialAccount->provider_name)->toBe('Owner Facebook')
        ->and($socialAccount->provider_avatar_url)->toBe('https://cdn.example.com/facebook-owner.png')
        ->and($socialAccount->provider_email_verified_at)->not->toBeNull()
        ->and($socialAccount->last_login_at)->not->toBeNull()
        ->and($socialAccount->access_token)->toBe('facebook-access-token');

    $storedAccount = DB::table('user_social_accounts')->where('id', $socialAccount->id)->first();

    expect($storedAccount)->not->toBeNull()
        ->and($storedAccount->access_token)->not->toBe('facebook-access-token');

    $this->assertNull(session('social_auth.pending'));
});

test('facebook social auth links an existing verified user by email and sends onboarded users to the dashboard', function () {
    $ownerRoleId = Role::query()->firstOrCreate(
        ['name' => 'owner'],
        ['description' => 'Account owner role']
    )->id;

    $user = User::factory()->create([
        'email' => 'existing-facebook@example.com',
        'role_id' => $ownerRoleId,
        'locale' => 'en',
        'onboarding_completed_at' => now(),
    ]);

    Http::fake([
        'https://graph.facebook.test/v23.0/oauth/access_token*' => Http::response([
            'access_token' => 'existing-facebook-access-token',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
        ]),
        'https://graph.facebook.test/me?fields=id,name,email,picture' => Http::response([
            'id' => 'facebook-user-002',
            'email' => 'existing-facebook@example.com',
            'name' => 'Existing Facebook Owner',
        ]),
    ]);

    $response = $this
        ->withSession([
            'social_auth.pending' => [
                'provider' => 'facebook',
                'source' => 'login',
                'plan' => null,
                'billing_period' => null,
                'state' => 'facebook-state-002',
                'requested_at' => now()->toIso8601String(),
            ],
        ])
        ->get(route('auth.social.callback', [
            'provider' => 'facebook',
            'state' => 'facebook-state-002',
            'code' => 'facebook-auth-code',
        ]));

    $this->assertAuthenticatedAs($user);

    $response->assertRedirect(route('dashboard', absolute: false));

    $socialAccount = UserSocialAccount::query()
        ->where('user_id', $user->id)
        ->where('provider', 'facebook')
        ->first();

    expect($socialAccount)->not->toBeNull()
        ->and($socialAccount?->provider_user_id)->toBe('facebook-user-002')
        ->and($socialAccount?->provider_email)->toBe('existing-facebook@example.com');
});

test('facebook social auth refuses provider profiles without a usable email address', function () {
    Http::fake([
        'https://graph.facebook.test/v23.0/oauth/access_token*' => Http::response([
            'access_token' => 'facebook-access-token',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
        ]),
        'https://graph.facebook.test/me?fields=id,name,email,picture' => Http::response([
            'id' => 'facebook-user-003',
            'name' => 'No Email Facebook User',
        ]),
    ]);

    $response = $this
        ->withSession([
            'social_auth.pending' => [
                'provider' => 'facebook',
                'source' => 'register',
                'plan' => null,
                'billing_period' => null,
                'state' => 'facebook-state-003',
                'requested_at' => now()->toIso8601String(),
            ],
        ])
        ->get(route('auth.social.callback', [
            'provider' => 'facebook',
            'state' => 'facebook-state-003',
            'code' => 'facebook-auth-code',
        ]));

    $this->assertGuest();

    $response->assertRedirect(route('register'))
        ->assertSessionHas('error', __('ui.auth.social.email_not_verified', ['provider' => 'Facebook']));

    expect(UserSocialAccount::query()->where('provider_user_id', 'facebook-user-003')->exists())->toBeFalse();
});
