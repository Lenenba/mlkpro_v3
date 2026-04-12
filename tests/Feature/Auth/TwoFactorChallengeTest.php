<?php

use App\Models\User;
use App\Notifications\TwoFactorCodeNotification;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

test('two factor challenge screen inherits the selected locale when the user locale is missing', function () {
    $user = User::factory()->create([
        'locale' => null,
        'two_factor_exempt' => false,
        'two_factor_method' => 'app',
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
    ]);

    $this->withSession(['locale' => 'es'])
        ->actingAs($user)
        ->get('/two-factor-challenge')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Auth/TwoFactorChallenge')
            ->where('locale', 'es')
            ->where('method', 'app')
        );

    expect($user->fresh()->locale)->toBe('es');
});

test('two factor resend keeps the selected locale for users missing one', function () {
    Notification::fake();

    $user = User::factory()->create([
        'locale' => null,
        'two_factor_exempt' => false,
        'two_factor_method' => 'email',
    ]);

    $this->withSession(['locale' => 'es'])
        ->actingAs($user)
        ->post('/two-factor-challenge/resend')
        ->assertRedirect()
        ->assertSessionHas('status', __('ui.auth.two_factor.resent', [], 'es'))
        ->assertSessionHas('locale', 'es');

    expect($user->fresh()->locale)->toBe('es');

    Notification::assertSentTo($user, TwoFactorCodeNotification::class);
});
