<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerClientRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_creation_autocreates_client_role_if_deleted()
    {
        $this->withoutMiddleware(VerifyCsrfToken::class);

        $ownerRole = Role::firstOrCreate(
            ['name' => 'owner'],
            ['description' => 'Account owner access']
        );

        $owner = User::factory()->withRole($ownerRole->id)->create();

        Role::where('name', 'client')->delete();

        $payload = [
            'salutation' => 'Mr',
            'first_name' => 'Nellie',
            'last_name' => 'Kedagni',
            'email' => 'client-role-test@example.com',
            'company_name' => 'Kedagni Inc',
            'phone' => '+15145551234',
            'portal_access' => true,
        ];

        $response = $this->actingAs($owner)
            ->postJson(route('customer.store'), $payload);

        $response->assertStatus(201);
        $this->assertDatabaseHas('roles', ['name' => 'client']);
        $this->assertDatabaseHas('customers', ['email' => 'client-role-test@example.com']);
        $this->assertDatabaseHas('users', ['email' => 'client-role-test@example.com']);
    }
}
