<?php

use App\Models\User;
use App\Notifications\ResetPasswordLinkNotification;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
});

test('reset password link screen can be rendered', function () {
    $response = $this->get('/forgot-password');

    $response->assertStatus(200);
});

test('reset password link screen inherits the selected public locale', function () {
    $this->withSession(['locale' => 'es'])
        ->get('/forgot-password')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Auth/ForgotPassword')
            ->where('locale', 'es')
        );
});

test('reset password link can be requested', function () {
    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email])
        ->assertSessionHas('status');
});

test('reset password link request keeps the selected public locale for the notification', function () {
    Notification::fake();

    $user = User::factory()->create([
        'locale' => null,
    ]);

    $this->withSession(['locale' => 'es'])
        ->post('/forgot-password', ['email' => $user->email])
        ->assertSessionHas('status', __('passwords.sent', [], 'es'));

    Notification::assertSentTo($user, ResetPasswordLinkNotification::class, function (ResetPasswordLinkNotification $notification) use ($user) {
        $mail = $notification->toMail($user);

        return str_contains((string) $mail->viewData['resetUrl'], 'locale=es');
    });
});

test('reset password screen can be rendered', function () {
    $user = User::factory()->create();
    $token = Password::broker()->createToken($user);

    $this->get('/reset-password/'.$token)
        ->assertStatus(200);
});

test('reset password screen can use the locale embedded in the reset link', function () {
    $user = User::factory()->create();
    $token = Password::broker()->createToken($user);

    $this->withSession(['locale' => 'fr'])
        ->get('/reset-password/'.$token.'?email='.urlencode($user->email).'&locale=es')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Auth/ResetPassword')
            ->where('locale', 'es')
        );
});

test('password can be reset with valid token', function () {
    $user = User::factory()->create();
    $token = Password::broker()->createToken($user);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'password',
        'password_confirmation' => 'password',
    ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('login'));
});
