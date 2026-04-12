<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('registration screen can be rendered', function () {
    $response = $this->get('/register');

    $response->assertStatus(200);
});

test('registration screen inherits the selected public locale', function () {
    $this->withSession(['locale' => 'es'])
        ->get('/register')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Auth/Register')
            ->where('locale', 'es')
        );
});

test('new users can register', function () {
    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('onboarding.index', absolute: false));
});

test('new users keep the selected public locale when they register from onboarding', function () {
    $response = $this->withSession(['locale' => 'es'])->post(route('onboarding.register'), [
        'name' => 'Usuario Demo',
        'email' => 'demo-es@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::query()->where('email', 'demo-es@example.com')->firstOrFail();

    $this->assertAuthenticatedAs($user);
    $response->assertRedirect(route('onboarding.index', absolute: false))
        ->assertSessionHas('locale', 'es');

    expect($user->locale)->toBe('es');
});
