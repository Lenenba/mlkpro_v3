<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ForbiddenRedirectTest extends TestCase
{
    use RefreshDatabase;

    private function createClientUser(): User
    {
        $role = Role::factory()->withName('client')->create();

        $user = User::factory()->withRole($role->id)->create([
            'email' => 'client@example.com',
        ]);

        Customer::factory()->create([
            'portal_user_id' => $user->id,
            'portal_access' => true,
        ]);

        return $user;
    }

    public function test_forbidden_routes_redirect_back_with_flash_message()
    {
        $user = $this->createClientUser();

        $message = "Acces refuse. Vous n'avez pas les permissions necessaires.";
        $response = $this->actingAs($user)
            ->from('/pricing')
            ->get('/settings/company');

        $response->assertRedirect(url('/pricing'));
        $response->assertSessionHas('warning', $message);
    }

    public function test_forbidden_routes_without_referer_redirect_home()
    {
        $user = $this->createClientUser();

        $message = "Acces refuse. Vous n'avez pas les permissions necessaires.";
        $response = $this->actingAs($user)->get('/settings/company');

        $response->assertRedirect(url('/'));
        $response->assertSessionHas('warning', $message);
    }
}
