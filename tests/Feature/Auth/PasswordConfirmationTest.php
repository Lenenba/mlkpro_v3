<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('confirm password screen can be rendered', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/confirm-password');

    $response->assertStatus(200);
});

test('confirm password screen follows the saved user locale', function () {
    $user = User::factory()->create([
        'locale' => 'es',
    ]);

    $this->actingAs($user)
        ->get('/confirm-password')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Auth/ConfirmPassword')
            ->where('locale', 'es')
        );
});

test('password can be confirmed', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/confirm-password', [
        'password' => 'password',
    ]);

    $response->assertRedirect();
    $response->assertSessionHasNoErrors();
});

test('password is not confirmed with invalid password', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/confirm-password', [
        'password' => 'wrong-password',
    ]);

    $response->assertSessionHasErrors();
});
