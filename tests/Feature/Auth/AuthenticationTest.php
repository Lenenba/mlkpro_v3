<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('login screen can be rendered', function () {
    $response = $this->get('/login');

    $response->assertStatus(200);
});

test('login screen inherits the selected public locale', function () {
    $this->withSession(['locale' => 'es'])
        ->get('/login')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Auth/Login')
            ->where('locale', 'es')
        );
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('users can authenticate from onboarding and keep the selected plan context', function () {
    $user = User::factory()->create([
        'onboarding_completed_at' => null,
    ]);

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
        'source' => 'onboarding',
        'plan' => 'growth',
        'billing_period' => 'yearly',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('onboarding.index', [
        'plan' => 'growth',
        'billing_period' => 'yearly',
    ]));
});

test('users without a saved locale inherit the selected public locale on login', function () {
    $user = User::factory()->create([
        'locale' => null,
        'onboarding_completed_at' => null,
    ]);

    $response = $this->withSession(['locale' => 'es'])->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('onboarding.index', absolute: false))
        ->assertSessionHas('locale', 'es');

    expect($user->fresh()->locale)->toBe('es');
});

test('login keeps the saved user locale when it already exists', function () {
    $user = User::factory()->create([
        'locale' => 'en',
    ]);

    $response = $this->withSession(['locale' => 'es'])->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false))
        ->assertSessionHas('locale', 'en');

    expect($user->fresh()->locale)->toBe('en');
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('login errors are localized with the selected public locale', function () {
    $user = User::factory()->create();

    $this->withSession(['locale' => 'es'])
        ->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])
        ->assertSessionHasErrors([
            'email' => __('auth.failed', [], 'es'),
        ]);

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/logout');

    $this->assertGuest();
    $response->assertRedirect('/');
});
