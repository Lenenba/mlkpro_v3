<?php

use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config()->set('social_auth.enabled', true);
    config()->set('social_auth.providers.google.enabled', true);
    config()->set('social_auth.providers.google.client_id', 'google-client-id');
    config()->set('social_auth.providers.google.client_secret', 'google-client-secret');
    config()->set('social_auth.providers.google.redirect_uri', 'https://app.test/auth/social/google/callback');
    config()->set('social_auth.providers.google.implemented', false);
});

test('login screen shares enabled social auth providers for frontend wiring', function () {
    config()->set('social_auth.providers.microsoft.enabled', true);
    config()->set('social_auth.providers.microsoft.client_id', 'microsoft-client-id');
    config()->set('social_auth.providers.microsoft.client_secret', 'microsoft-client-secret');
    config()->set('social_auth.providers.microsoft.redirect_uri', 'https://app.test/auth/social/microsoft/callback');
    config()->set('social_auth.providers.microsoft.implemented', false);

    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Auth/Login')
            ->where('socialAuth.enabled', true)
            ->has('socialAuth.providers', 2)
            ->where('socialAuth.providers.0.key', 'google')
            ->where('socialAuth.providers.0.configured', true)
            ->where('socialAuth.providers.0.implemented', false)
            ->where('socialAuth.providers.0.ready', false)
            ->where('socialAuth.providers.0.redirect_url', route('auth.social.redirect', ['provider' => 'google'], false))
            ->where('socialAuth.providers.0.callback_url', route('auth.social.callback', ['provider' => 'google'], false))
            ->where('socialAuth.providers.1.key', 'microsoft')
            ->where('socialAuth.providers.1.configured', true)
            ->where('socialAuth.providers.1.ready', false)
        );
});

test('social auth redirect returns not found when the provider is disabled', function () {
    config()->set('social_auth.providers.google.enabled', false);

    $this->get(route('auth.social.redirect', ['provider' => 'google']))
        ->assertNotFound();
});

test('social auth redirect stores onboarding context before provider implementation is ready', function () {
    $this->get(route('auth.social.redirect', [
        'provider' => 'google',
        'source' => 'onboarding',
        'plan' => 'growth',
        'billing_period' => 'yearly',
    ]))
        ->assertRedirect(route('onboarding.index', [
            'plan' => 'growth',
            'billing_period' => 'yearly',
        ]))
        ->assertSessionHas('warning', __('ui.auth.social.provider_not_ready', ['provider' => 'Google']))
        ->assertSessionHas('social_auth.pending', function (array $pending): bool {
            return $pending['provider'] === 'google'
                && $pending['source'] === 'onboarding'
                && $pending['plan'] === 'growth'
                && $pending['billing_period'] === 'yearly'
                && is_string($pending['requested_at'])
                && $pending['requested_at'] !== '';
        });
});

test('social auth callback returns to the stored onboarding context until oauth is implemented', function () {
    $this->withSession([
        'social_auth.pending' => [
            'provider' => 'google',
            'source' => 'onboarding',
            'plan' => 'starter',
            'billing_period' => 'monthly',
            'requested_at' => now()->toIso8601String(),
        ],
    ])->get(route('auth.social.callback', ['provider' => 'google']))
        ->assertRedirect(route('onboarding.index', [
            'plan' => 'starter',
            'billing_period' => 'monthly',
        ]))
        ->assertSessionHas('warning', __('ui.auth.social.callback_not_ready', ['provider' => 'Google']));
});
