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
    config()->set('social_auth.providers.google.enabled', true);
    config()->set('social_auth.providers.google.implemented', true);
    config()->set('social_auth.providers.google.client_id', 'google-client-id');
    config()->set('social_auth.providers.google.client_secret', 'google-client-secret');
    config()->set('social_auth.providers.google.redirect_uri', 'https://app.test/auth/social/google/callback');
    config()->set('social_auth.providers.google.authorize_url', 'https://accounts.google.test/o/oauth2/v2/auth');
    config()->set('social_auth.providers.google.token_url', 'https://oauth2.google.test/token');
    config()->set('social_auth.providers.google.userinfo_url', 'https://openidconnect.google.test/userinfo');
});

test('google social auth redirect sends the guest to the provider with state and onboarding context', function () {
    $response = $this->get(route('auth.social.redirect', [
        'provider' => 'google',
        'source' => 'onboarding',
        'plan' => 'growth',
        'billing_period' => 'yearly',
    ]));

    $response->assertRedirect();

    $location = $response->headers->get('Location');

    expect($location)->toStartWith('https://accounts.google.test/o/oauth2/v2/auth?');

    parse_str(parse_url((string) $location, PHP_URL_QUERY) ?: '', $query);

    expect($query['client_id'] ?? null)->toBe('google-client-id')
        ->and($query['redirect_uri'] ?? null)->toBe('https://app.test/auth/social/google/callback')
        ->and($query['response_type'] ?? null)->toBe('code')
        ->and($query['scope'] ?? null)->toBe('openid profile email')
        ->and($query['state'] ?? null)->toBeString()
        ->and($query['state'] ?? '')->not->toBe('');

    $this->assertSame('google', session('social_auth.pending.provider'));
    $this->assertSame('onboarding', session('social_auth.pending.source'));
    $this->assertSame('growth', session('social_auth.pending.plan'));
    $this->assertSame('yearly', session('social_auth.pending.billing_period'));
    $this->assertSame($query['state'] ?? null, session('social_auth.pending.state'));
});

test('google social auth callback creates a new owner and resumes onboarding with the selected context', function () {
    Http::fake([
        'https://oauth2.google.test/token' => Http::response([
            'access_token' => 'google-access-token',
            'expires_in' => 3600,
            'scope' => 'openid profile email',
            'token_type' => 'Bearer',
            'id_token' => 'google-id-token',
        ]),
        'https://openidconnect.google.test/userinfo' => Http::response([
            'sub' => 'google-user-001',
            'email' => 'owner-google@example.com',
            'email_verified' => true,
            'name' => 'Owner Google',
            'picture' => 'https://cdn.example.com/google-owner.png',
        ]),
    ]);

    $response = $this
        ->withSession([
            'locale' => 'es',
            'social_auth.pending' => [
                'provider' => 'google',
                'source' => 'onboarding',
                'plan' => 'growth',
                'billing_period' => 'yearly',
                'state' => 'google-state-001',
                'requested_at' => now()->toIso8601String(),
            ],
        ])
        ->get(route('auth.social.callback', [
            'provider' => 'google',
            'state' => 'google-state-001',
            'code' => 'google-auth-code',
        ]));

    $user = User::query()->where('email', 'owner-google@example.com')->firstOrFail();
    $socialAccount = UserSocialAccount::query()->where('user_id', $user->id)->firstOrFail();

    $this->assertAuthenticatedAs($user);

    $response->assertRedirect(route('onboarding.index', [
        'plan' => 'growth',
        'billing_period' => 'yearly',
    ]));

    expect($user->hasRole('owner'))->toBeTrue()
        ->and($user->name)->toBe('Owner Google')
        ->and($user->locale)->toBe('es')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->onboarding_completed_at)->toBeNull()
        ->and($socialAccount->provider)->toBe('google')
        ->and($socialAccount->provider_user_id)->toBe('google-user-001')
        ->and($socialAccount->provider_email)->toBe('owner-google@example.com')
        ->and($socialAccount->provider_name)->toBe('Owner Google')
        ->and($socialAccount->provider_avatar_url)->toBe('https://cdn.example.com/google-owner.png')
        ->and($socialAccount->provider_email_verified_at)->not->toBeNull()
        ->and($socialAccount->last_login_at)->not->toBeNull()
        ->and($socialAccount->access_token)->toBe('google-access-token');

    $storedAccount = DB::table('user_social_accounts')->where('id', $socialAccount->id)->first();

    expect($storedAccount)->not->toBeNull()
        ->and($storedAccount->access_token)->not->toBe('google-access-token');

    $this->assertNull(session('social_auth.pending'));
});

test('google social auth links an existing verified user by email and sends onboarded users to the dashboard', function () {
    $ownerRoleId = Role::query()->firstOrCreate(
        ['name' => 'owner'],
        ['description' => 'Account owner role']
    )->id;

    $user = User::factory()->create([
        'email' => 'existing-google@example.com',
        'role_id' => $ownerRoleId,
        'locale' => 'fr',
        'onboarding_completed_at' => now(),
    ]);

    Http::fake([
        'https://oauth2.google.test/token' => Http::response([
            'access_token' => 'existing-user-access-token',
            'expires_in' => 3600,
            'scope' => 'openid profile email',
            'token_type' => 'Bearer',
        ]),
        'https://openidconnect.google.test/userinfo' => Http::response([
            'sub' => 'google-user-002',
            'email' => 'existing-google@example.com',
            'email_verified' => true,
            'name' => 'Existing Google Owner',
            'picture' => 'https://cdn.example.com/google-existing.png',
        ]),
    ]);

    $response = $this
        ->withSession([
            'social_auth.pending' => [
                'provider' => 'google',
                'source' => 'login',
                'plan' => null,
                'billing_period' => null,
                'state' => 'google-state-002',
                'requested_at' => now()->toIso8601String(),
            ],
        ])
        ->get(route('auth.social.callback', [
            'provider' => 'google',
            'state' => 'google-state-002',
            'code' => 'google-auth-code',
        ]));

    $this->assertAuthenticatedAs($user);

    $response->assertRedirect(route('dashboard', absolute: false));

    $socialAccount = UserSocialAccount::query()
        ->where('user_id', $user->id)
        ->where('provider', 'google')
        ->first();

    expect($socialAccount)->not->toBeNull()
        ->and($socialAccount?->provider_user_id)->toBe('google-user-002')
        ->and($socialAccount?->provider_email)->toBe('existing-google@example.com');
});

test('google social auth refuses provider profiles without a verified email address', function () {
    Http::fake([
        'https://oauth2.google.test/token' => Http::response([
            'access_token' => 'google-access-token',
            'expires_in' => 3600,
            'scope' => 'openid profile email',
            'token_type' => 'Bearer',
        ]),
        'https://openidconnect.google.test/userinfo' => Http::response([
            'sub' => 'google-user-003',
            'email' => 'unverified-google@example.com',
            'email_verified' => false,
            'name' => 'Unverified Google User',
        ]),
    ]);

    $response = $this
        ->withSession([
            'social_auth.pending' => [
                'provider' => 'google',
                'source' => 'register',
                'plan' => null,
                'billing_period' => null,
                'state' => 'google-state-003',
                'requested_at' => now()->toIso8601String(),
            ],
        ])
        ->get(route('auth.social.callback', [
            'provider' => 'google',
            'state' => 'google-state-003',
            'code' => 'google-auth-code',
        ]));

    $this->assertGuest();

    $response->assertRedirect(route('register'))
        ->assertSessionHas('error', __('ui.auth.social.email_not_verified', ['provider' => 'Google']));

    expect(User::query()->where('email', 'unverified-google@example.com')->exists())->toBeFalse()
        ->and(UserSocialAccount::query()->where('provider_user_id', 'google-user-003')->exists())->toBeFalse();
});

test('legacy google callback path remains supported for older provider redirect uris', function () {
    config()->set('social_auth.providers.google.redirect_uri', 'https://app.test/auth/google/callback');

    Http::fake([
        'https://oauth2.google.test/token' => Http::response([
            'access_token' => 'legacy-google-access-token',
            'expires_in' => 3600,
            'scope' => 'openid profile email',
            'token_type' => 'Bearer',
            'id_token' => 'legacy-google-id-token',
        ]),
        'https://openidconnect.google.test/userinfo' => Http::response([
            'sub' => 'google-user-legacy-001',
            'email' => 'legacy-google-owner@example.com',
            'email_verified' => true,
            'name' => 'Legacy Google Owner',
            'picture' => 'https://cdn.example.com/google-legacy-owner.png',
        ]),
    ]);

    $response = $this
        ->withSession([
            'social_auth.pending' => [
                'provider' => 'google',
                'source' => 'onboarding',
                'plan' => 'growth',
                'billing_period' => 'yearly',
                'state' => 'google-state-legacy-001',
                'requested_at' => now()->toIso8601String(),
            ],
        ])
        ->get('/auth/google/callback?state=google-state-legacy-001&code=google-auth-code');

    $user = User::query()->where('email', 'legacy-google-owner@example.com')->firstOrFail();

    $this->assertAuthenticatedAs($user);

    $response->assertRedirect(route('onboarding.index', [
        'plan' => 'growth',
        'billing_period' => 'yearly',
    ]));

    expect(UserSocialAccount::query()
        ->where('user_id', $user->id)
        ->where('provider', 'google')
        ->exists())->toBeTrue();
});
