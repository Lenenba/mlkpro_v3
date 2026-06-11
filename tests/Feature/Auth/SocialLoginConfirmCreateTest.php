<?php

use App\Models\User;
use App\Models\UserSocialAccount;
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

function fakeGoogleProfile(string $email, string $sub): void
{
    Http::fake([
        'https://oauth2.google.test/token' => Http::response([
            'access_token' => 'google-access-token',
            'expires_in' => 3600,
            'scope' => 'openid profile email',
            'token_type' => 'Bearer',
            'id_token' => 'google-id-token',
        ]),
        'https://openidconnect.google.test/userinfo' => Http::response([
            'sub' => $sub,
            'email' => $email,
            'email_verified' => true,
            'name' => 'New Google User',
            'picture' => 'https://cdn.example.com/google-new.png',
        ]),
    ]);
}

function callbackFromLogin(string $state = 'google-state-login'): \Illuminate\Testing\TestResponse
{
    return test()
        ->withSession([
            'social_auth.pending' => [
                'provider' => 'google',
                'source' => 'login',
                'plan' => null,
                'billing_period' => null,
                'state' => $state,
                'requested_at' => now()->toIso8601String(),
            ],
        ])
        ->get(route('auth.social.callback', [
            'provider' => 'google',
            'state' => $state,
            'code' => 'google-auth-code',
        ]));
}

test('login with an unknown email does not create an account and prompts for confirmation', function () {
    fakeGoogleProfile('unknown-login@example.com', 'google-login-001');

    $response = callbackFromLogin();

    $this->assertGuest();
    $response->assertRedirect(route('login'));

    expect(User::query()->where('email', 'unknown-login@example.com')->exists())->toBeFalse()
        ->and(UserSocialAccount::query()->where('provider_user_id', 'google-login-001')->exists())->toBeFalse();

    // A confirmation candidate is stashed in the session.
    expect(session('social_auth.create_candidate.provider'))->toBe('google')
        ->and(session('social_auth.create_candidate.token'))->toBeString()
        ->and(session('social_auth.create_candidate.token'))->not->toBe('');
});

test('the login page exposes the confirmation prompt with a masked email', function () {
    fakeGoogleProfile('prompt-login@example.com', 'google-login-prompt');

    callbackFromLogin();

    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Auth/Login')
            ->where('socialCreatePrompt.provider', 'google')
            ->where('socialCreatePrompt.email', 'p***********@example.com')
            ->has('socialCreatePrompt.token')
            ->has('socialCreatePrompt.confirm_url')
        );
});

test('confirming creates the account from the stashed profile and resumes onboarding', function () {
    fakeGoogleProfile('confirm-login@example.com', 'google-login-002');

    callbackFromLogin();

    $token = session('social_auth.create_candidate.token');

    $response = $this->post(route('auth.social.confirm', ['provider' => 'google']), [
        'token' => $token,
    ]);

    $user = User::query()->where('email', 'confirm-login@example.com')->firstOrFail();

    $this->assertAuthenticatedAs($user);
    $response->assertRedirect(route('onboarding.index'));

    expect($user->hasRole('owner'))->toBeTrue()
        ->and(UserSocialAccount::query()
            ->where('user_id', $user->id)
            ->where('provider', 'google')
            ->where('provider_user_id', 'google-login-002')
            ->exists())->toBeTrue();

    // The candidate is cleared after use.
    expect(session('social_auth.create_candidate'))->toBeNull();
});

test('confirming with an invalid token creates nothing', function () {
    fakeGoogleProfile('confirm-login@example.com', 'google-login-003');

    callbackFromLogin();

    $response = $this->post(route('auth.social.confirm', ['provider' => 'google']), [
        'token' => 'not-the-real-token',
    ]);

    $this->assertGuest();
    $response->assertRedirect(route('login'));

    expect(User::query()->where('email', 'confirm-login@example.com')->exists())->toBeFalse();
});

test('confirming an expired candidate creates nothing', function () {
    fakeGoogleProfile('expired-login@example.com', 'google-login-004');

    callbackFromLogin();

    $token = session('social_auth.create_candidate.token');

    // Force expiry.
    $candidate = session('social_auth.create_candidate');
    $candidate['expires_at'] = now()->subMinute()->toIso8601String();
    session(['social_auth.create_candidate' => $candidate]);

    $response = $this->post(route('auth.social.confirm', ['provider' => 'google']), [
        'token' => $token,
    ]);

    $this->assertGuest();
    $response->assertRedirect(route('login'));

    expect(User::query()->where('email', 'expired-login@example.com')->exists())->toBeFalse();
});

test('the local dev simulator triggers the confirmation prompt for an unknown email', function () {
    $response = $this->get(route('auth.social.dev-callback', [
        'provider' => 'google',
        'email' => 'dev-sim-unknown@example.com',
        'source' => 'login',
    ]));

    $this->assertGuest();
    $response->assertRedirect(route('login'));

    expect(User::query()->where('email', 'dev-sim-unknown@example.com')->exists())->toBeFalse()
        ->and(session('social_auth.create_candidate.provider'))->toBe('google');
});

test('the dev simulator is disabled outside local and testing environments', function () {
    $this->app->detectEnvironment(fn () => 'production');

    $this->get(route('auth.social.dev-callback', [
        'provider' => 'google',
        'email' => 'dev-sim-prod@example.com',
    ]))->assertNotFound();
});

test('register context still creates an account directly for an unknown email', function () {
    fakeGoogleProfile('register-direct@example.com', 'google-register-001');

    $response = test()
        ->withSession([
            'social_auth.pending' => [
                'provider' => 'google',
                'source' => 'register',
                'plan' => null,
                'billing_period' => null,
                'state' => 'google-state-register',
                'requested_at' => now()->toIso8601String(),
            ],
        ])
        ->get(route('auth.social.callback', [
            'provider' => 'google',
            'state' => 'google-state-register',
            'code' => 'google-auth-code',
        ]));

    $user = User::query()->where('email', 'register-direct@example.com')->firstOrFail();
    $this->assertAuthenticatedAs($user);
    $response->assertRedirect(route('onboarding.index'));
});
