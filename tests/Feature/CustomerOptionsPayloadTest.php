<?php

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
});

test('customer options audience scope returns a lean audience payload', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
    ]);

    Customer::query()->create([
        'user_id' => $owner->id,
        'first_name' => 'Alice',
        'last_name' => 'Audience',
        'company_name' => 'Audience Co',
        'email' => 'alice.audience@example.com',
        'phone' => '+15145550101',
        'logo' => '/logos/audience.png',
    ]);

    $response = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('customer.options', ['scope' => 'audience']))
        ->assertOk();

    $customer = $response->json('customers.0');

    expect($customer)->toMatchArray([
        'company_name' => 'Audience Co',
        'first_name' => 'Alice',
        'last_name' => 'Audience',
        'email' => 'alice.audience@example.com',
        'phone' => '+15145550101',
    ])
        ->and(array_key_exists('properties', $customer))->toBeFalse()
        ->and(array_key_exists('logo', $customer))->toBeFalse()
        ->and(array_key_exists('logo_url', $customer))->toBeFalse()
        ->and(array_key_exists('number', $customer))->toBeFalse();
});

test('customer options quote scope returns compact property payloads', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
    ]);

    $customer = Customer::query()->create([
        'user_id' => $owner->id,
        'first_name' => 'Quentin',
        'last_name' => 'Quote',
        'company_name' => 'Quote Co',
        'email' => 'quentin.quote@example.com',
        'phone' => '+15145550102',
        'logo' => '/logos/quote.png',
    ]);

    $customer->properties()->create([
        'type' => 'physical',
        'is_default' => true,
        'street1' => '123 Main Street',
        'street2' => 'Suite 200',
        'city' => 'Montreal',
        'state' => 'QC',
        'zip' => 'H1H 1H1',
        'country' => 'Canada',
    ]);

    $response = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('customer.options', ['scope' => 'quote']))
        ->assertOk();

    $payload = $response->json('customers.0');
    $property = $response->json('customers.0.properties.0');

    expect($payload)->toMatchArray([
        'company_name' => 'Quote Co',
        'email' => 'quentin.quote@example.com',
    ])
        ->and($property)->toMatchArray([
            'street1' => '123 Main Street',
            'city' => 'Montreal',
            'is_default' => true,
        ])
        ->and(array_key_exists('type', $property))->toBeFalse()
        ->and(array_key_exists('street2', $property))->toBeFalse()
        ->and(array_key_exists('state', $property))->toBeFalse()
        ->and(array_key_exists('zip', $property))->toBeFalse()
        ->and(array_key_exists('country', $property))->toBeFalse();
});
