<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\Customer;
use App\Models\LoyaltyProgram;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
});

test('customer profile omits loyalty data when the loyalty module is disabled', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_features' => [
            'loyalty' => false,
        ],
    ]);

    $customer = Customer::query()->create([
        'user_id' => $owner->id,
        'first_name' => 'Loyalty',
        'last_name' => 'Hidden',
        'company_name' => 'Hidden Loyalty Co.',
        'email' => 'hidden-loyalty@example.test',
        'loyalty_points_balance' => 240,
    ]);

    LoyaltyProgram::query()->create([
        'user_id' => $owner->id,
        'is_enabled' => true,
        'points_per_currency_unit' => 2,
        'minimum_spend' => 20,
        'rounding_mode' => LoyaltyProgram::ROUND_ROUND,
        'points_label' => 'pts',
    ]);

    $this->actingAs($owner)
        ->get(route('customer.show', $customer))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Customer/Show')
            ->where('loyalty', null)
            ->where('auth.account.features', fn ($features) => ! collect($features)->has('loyalty'))
        );
});

test('sales create omits loyalty payloads and customer loyalty balances when the module is disabled', function () {
    $owner = User::factory()->create([
        'company_type' => 'products',
        'company_features' => [
            'sales' => true,
            'loyalty' => false,
        ],
    ]);

    Customer::query()->create([
        'user_id' => $owner->id,
        'first_name' => 'Retail',
        'last_name' => 'Customer',
        'company_name' => 'Retail Customer Co.',
        'email' => 'retail-customer@example.test',
        'loyalty_points_balance' => 120,
    ]);

    LoyaltyProgram::query()->create([
        'user_id' => $owner->id,
        'is_enabled' => true,
        'points_per_currency_unit' => 1.5,
        'minimum_spend' => 15,
        'rounding_mode' => LoyaltyProgram::ROUND_FLOOR,
        'points_label' => 'points',
    ]);

    $this->actingAs($owner)
        ->get(route('sales.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Sales/Create')
            ->where('loyaltyProgram', null)
            ->where('customers', fn ($customers) => count($customers) === 1
                && ! array_key_exists('loyalty_points_balance', $customers[0] ?? []))
        );
});

test('billing settings ignore loyalty updates and keep loyalty payload hidden when the module is disabled', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_features' => [
            'loyalty' => false,
        ],
    ]);

    $this->actingAs($owner)
        ->get(route('settings.billing.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Billing')
            ->where('loyaltyProgram', null)
        );

    $this->actingAs($owner)
        ->from(route('settings.billing.edit'))
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
        ])
        ->assertRedirect(route('settings.billing.edit'))
        ->assertSessionHasNoErrors();

    $owner->refresh();

    expect($owner->payment_methods)->toBe(['cash', 'card', 'bank_transfer'])
        ->and($owner->default_payment_method)->toBe('card')
        ->and($owner->cash_allowed_contexts)->toBe(['invoice', 'walk_in'])
        ->and(LoyaltyProgram::query()->where('user_id', $owner->id)->exists())->toBeFalse();
});

test('shared dashboard props omit planning and assistant payloads when the modules are disabled', function () {
    config()->set('services.openai.key', 'test-key');

    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_features' => [
            'planning' => false,
            'assistant' => false,
        ],
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('planning', null)
            ->where('assistant', null)
            ->where('auth.account.features', fn ($features) => ! collect($features)->has('planning')
                && ! collect($features)->has('assistant'))
        );
});

test('disabled module settings routes redirect gracefully instead of rendering residual pages', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_features' => [
            'campaigns' => false,
        ],
    ]);

    $this->actingAs($owner)
        ->get(route('settings.marketing.edit'))
        ->assertRedirect(url('/'))
        ->assertSessionHas('warning', 'Module indisponible pour votre plan.');
});
