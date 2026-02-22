<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\LoyaltyProgram;
use App\Models\User;
use App\Support\TenantPaymentMethodsResolver;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
});

test('tenant payment methods resolver maps internal methods to business methods', function () {
    $owner = User::factory()->create([
        'payment_methods' => ['cash', 'bank_transfer', 'card', 'check', 'cash'],
        'default_payment_method' => 'card',
        'cash_allowed_contexts' => ['invoice', 'walk_in', 'invoice', 'invalid'],
    ]);

    $resolved = TenantPaymentMethodsResolver::forAccountId($owner->id);

    expect($resolved['enabled_methods_internal'])->toBe(['cash', 'bank_transfer', 'card', 'check'])
        ->and($resolved['enabled_methods'])->toBe(['cash', 'other', 'stripe'])
        ->and($resolved['default_method_internal'])->toBe('card')
        ->and($resolved['default_method'])->toBe('stripe')
        ->and($resolved['cash_allowed_contexts'])->toBe(['invoice', 'walk_in']);
});

test('tenant payment methods resolver falls back to defaults when account is missing', function () {
    $resolved = TenantPaymentMethodsResolver::forAccountId(0);

    expect($resolved['enabled_methods_internal'])->toBe(['cash', 'card'])
        ->and($resolved['enabled_methods'])->toBe(['cash', 'stripe'])
        ->and($resolved['default_method_internal'])->toBe('cash')
        ->and($resolved['default_method'])->toBe('cash')
        ->and($resolved['cash_allowed_contexts'])->toBe(['reservation', 'invoice', 'store_order', 'tip', 'walk_in']);
});

test('tenant payment methods resolver normalizes stripe alias and invalid defaults', function () {
    $owner = User::factory()->create([
        'payment_methods' => ['stripe', 'unknown'],
        'default_payment_method' => 'check',
        'cash_allowed_contexts' => null,
    ]);

    $resolved = TenantPaymentMethodsResolver::forUser($owner);

    expect($resolved['enabled_methods_internal'])->toBe(['card'])
        ->and($resolved['enabled_methods'])->toBe(['stripe'])
        ->and($resolved['default_method_internal'])->toBe('card')
        ->and($resolved['default_method'])->toBe('stripe')
        ->and($resolved['cash_allowed_contexts'])->toBe(['reservation', 'invoice', 'store_order', 'tip', 'walk_in']);
});

test('billing settings update persists phase1 payment fields', function () {
    $owner = User::factory()->create([
        'payment_methods' => ['cash', 'card'],
        'default_payment_method' => 'cash',
        'cash_allowed_contexts' => ['invoice'],
    ]);

    $response = $this
        ->actingAs($owner)
        ->from('/settings/billing')
        ->put(route('settings.billing.update'), [
            'payment_methods' => ['cash', 'card', 'bank_transfer'],
            'default_payment_method' => 'card',
            'cash_allowed_contexts' => ['invoice', 'walk_in'],
            'loyalty' => [
                'is_enabled' => true,
                'points_per_currency_unit' => 2.5,
                'minimum_spend' => 20,
                'rounding_mode' => 'round',
                'points_label' => 'pts',
            ],
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/settings/billing');

    $owner->refresh();

    expect($owner->payment_methods)->toBe(['cash', 'card', 'bank_transfer'])
        ->and($owner->default_payment_method)->toBe('card')
        ->and($owner->cash_allowed_contexts)->toBe(['invoice', 'walk_in']);

    $program = LoyaltyProgram::query()->where('user_id', $owner->id)->first();

    expect($program)->not->toBeNull()
        ->and((bool) $program->is_enabled)->toBeTrue()
        ->and((float) $program->points_per_currency_unit)->toBe(2.5)
        ->and((float) $program->minimum_spend)->toBe(20.0)
        ->and((string) $program->rounding_mode)->toBe('round')
        ->and((string) $program->points_label)->toBe('pts');
});
