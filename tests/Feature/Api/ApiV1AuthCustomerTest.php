<?php

use App\Models\Customer;
use App\Models\Role;
use App\Models\User;

test('api v1 login and customer notes update', function () {
    $user = User::factory()->create([
        'password' => 'password',
    ]);

    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'api-test',
    ])->assertOk();

    $token = $loginResponse->json('token');
    expect($token)->not->toBeEmpty();

    $customerResponse = $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/v1/customer', [
            'portal_access' => false,
            'first_name' => 'Api',
            'last_name' => 'Customer',
            'email' => 'api.customer@example.com',
            'salutation' => 'Mr',
        ])
        ->assertStatus(201);

    $customerId = $customerResponse->json('customer.id');
    expect($customerId)->not->toBeNull();
    expect($customerResponse->json('customer.client_type'))->toBe('individual');

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->patchJson("/api/v1/customer/{$customerId}/notes", [
            'description' => 'RN note',
        ])
        ->assertOk()
        ->assertJsonPath('customer.description', 'RN note');
});

test('api v1 login returns 403 for a client with disabled portal access', function () {
    $clientRole = Role::query()->firstOrCreate(
        ['name' => 'client'],
        ['description' => 'Client role'],
    );
    $owner = User::factory()->create();
    $client = User::factory()->create([
        'role_id' => $clientRole->id,
        'password' => 'password',
    ]);
    Customer::factory()->create([
        'user_id' => $owner->id,
        'portal_user_id' => $client->id,
        'portal_access' => false,
        'email' => $client->email,
    ]);

    $this->postJson('/api/v1/auth/login', [
        'email' => $client->email,
        'password' => 'password',
        'device_name' => 'revoked-client',
    ])
        ->assertForbidden()
        ->assertExactJson([
            'message' => __('ui.auth.portal_access_disabled'),
        ]);

    expect($client->tokens()->exists())->toBeFalse();
});
