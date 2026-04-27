<?php

use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config()->set('social_auth.enabled', true);
    config()->set('social_auth.providers.google.enabled', true);
    config()->set('social_auth.providers.google.client_id', 'google-client-id');
    config()->set('social_auth.providers.google.client_secret', 'google-client-secret');
    config()->set('social_auth.providers.google.redirect_uri', 'https://app.test/auth/social/google/callback');
    config()->set('social_auth.providers.google.implemented', true);
    config()->set('social_auth.providers.microsoft.enabled', false);
    config()->set('social_auth.providers.microsoft.client_id', null);
    config()->set('social_auth.providers.microsoft.client_secret', null);
    config()->set('social_auth.providers.microsoft.redirect_uri', null);
    config()->set('social_auth.providers.facebook.enabled', false);
    config()->set('social_auth.providers.facebook.client_id', null);
    config()->set('social_auth.providers.facebook.client_secret', null);
    config()->set('social_auth.providers.facebook.redirect_uri', null);
    config()->set('social_auth.providers.linkedin.enabled', false);
    config()->set('social_auth.providers.linkedin.client_id', null);
    config()->set('social_auth.providers.linkedin.client_secret', null);
    config()->set('social_auth.providers.linkedin.redirect_uri', null);
});

test('login screen shares enabled social auth providers for frontend wiring', function () {
    config()->set('social_auth.providers.microsoft.enabled', true);
    config()->set('social_auth.providers.microsoft.client_id', 'microsoft-client-id');
    config()->set('social_auth.providers.microsoft.client_secret', 'microsoft-client-secret');
    config()->set('social_auth.providers.microsoft.redirect_uri', 'https://app.test/auth/social/microsoft/callback');
    config()->set('social_auth.providers.microsoft.implemented', true);
    config()->set('social_auth.providers.linkedin.enabled', true);
    config()->set('social_auth.providers.linkedin.client_id', 'linkedin-client-id');
    config()->set('social_auth.providers.linkedin.client_secret', 'linkedin-client-secret');
    config()->set('social_auth.providers.linkedin.redirect_uri', 'https://app.test/auth/social/linkedin/callback');
    config()->set('social_auth.providers.linkedin.implemented', true);

    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Auth/Login')
            ->where('socialAuth.enabled', true)
            ->has('socialAuth.providers', 3)
            ->where('socialAuth.providers.0.key', 'google')
            ->where('socialAuth.providers.0.configured', true)
            ->where('socialAuth.providers.0.implemented', true)
            ->where('socialAuth.providers.0.ready', true)
            ->where('socialAuth.providers.0.redirect_url', route('auth.social.redirect', ['provider' => 'google'], false))
            ->where('socialAuth.providers.0.callback_url', route('auth.social.callback', ['provider' => 'google'], false))
            ->where('socialAuth.providers.1.key', 'microsoft')
            ->where('socialAuth.providers.1.configured', true)
            ->where('socialAuth.providers.1.implemented', true)
            ->where('socialAuth.providers.1.ready', true)
            ->where('socialAuth.providers.2.key', 'linkedin')
            ->where('socialAuth.providers.2.configured', true)
            ->where('socialAuth.providers.2.implemented', true)
            ->where('socialAuth.providers.2.ready', true)
        );
});

test('social auth redirect returns not found when the provider is disabled', function () {
    config()->set('social_auth.providers.google.enabled', false);

    $this->get(route('auth.social.redirect', ['provider' => 'google']))
        ->assertNotFound();
});

test('social auth redirect stores onboarding context before provider implementation is ready', function () {
    config()->set('social_auth.providers.facebook.enabled', true);
    config()->set('social_auth.providers.facebook.client_id', 'facebook-client-id');
    config()->set('social_auth.providers.facebook.client_secret', 'facebook-client-secret');
    config()->set('social_auth.providers.facebook.redirect_uri', 'https://app.test/auth/social/facebook/callback');
    config()->set('social_auth.providers.facebook.implemented', false);

    $this->get(route('auth.social.redirect', [
        'provider' => 'facebook',
        'source' => 'onboarding',
        'plan' => 'growth',
        'billing_period' => 'yearly',
    ]))
        ->assertRedirect(route('onboarding.index', [
            'plan' => 'growth',
            'billing_period' => 'yearly',
        ]))
        ->assertSessionHas('warning', __('ui.auth.social.provider_not_ready', ['provider' => 'Facebook']))
        ->assertSessionHas('social_auth.pending', function (array $pending): bool {
            return $pending['provider'] === 'facebook'
                && $pending['source'] === 'onboarding'
                && $pending['plan'] === 'growth'
                && $pending['billing_period'] === 'yearly'
                && is_string($pending['requested_at'])
                && $pending['requested_at'] !== '';
        });
});

test('social auth callback returns to the stored onboarding context until oauth is implemented', function () {
    config()->set('social_auth.providers.facebook.enabled', true);
    config()->set('social_auth.providers.facebook.client_id', 'facebook-client-id');
    config()->set('social_auth.providers.facebook.client_secret', 'facebook-client-secret');
    config()->set('social_auth.providers.facebook.redirect_uri', 'https://app.test/auth/social/facebook/callback');
    config()->set('social_auth.providers.facebook.implemented', false);

    $this->withSession([
        'social_auth.pending' => [
            'provider' => 'facebook',
            'source' => 'onboarding',
            'plan' => 'starter',
            'billing_period' => 'monthly',
            'requested_at' => now()->toIso8601String(),
        ],
    ])->get(route('auth.social.callback', ['provider' => 'facebook']))
        ->assertRedirect(route('onboarding.index', [
            'plan' => 'starter',
            'billing_period' => 'monthly',
        ]))
        ->assertSessionHas('warning', __('ui.auth.social.callback_not_ready', ['provider' => 'Facebook']));
});
