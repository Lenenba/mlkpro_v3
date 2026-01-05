<?php

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

    $customerResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
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

    $this->withHeader('Authorization', 'Bearer ' . $token)
        ->patchJson("/api/v1/customer/{$customerId}/notes", [
            'description' => 'RN note',
        ])
        ->assertOk()
        ->assertJsonPath('customer.description', 'RN note');
});
