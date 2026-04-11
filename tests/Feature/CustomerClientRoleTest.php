<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\Customer;
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

    public function test_customer_creation_normalizes_empty_optional_billing_fields()
    {
        $this->withoutMiddleware(VerifyCsrfToken::class);

        $ownerRole = Role::firstOrCreate(
            ['name' => 'owner'],
            ['description' => 'Account owner access']
        );

        $owner = User::factory()->withRole($ownerRole->id)->create();

        $payload = [
            'salutation' => 'Mr',
            'first_name' => 'Billing',
            'last_name' => 'Defaults',
            'email' => 'customer-billing-defaults@example.com',
            'company_name' => 'Defaults Inc',
            'phone' => '+15145550000',
            'portal_access' => true,
            'billing_same_as_physical' => '',
            'billing_mode' => 'end_of_job',
            'billing_cycle' => '',
            'billing_grouping' => 'single',
            'billing_delay_days' => '',
            'billing_date_rule' => '',
            'discount_rate' => '',
            'auto_accept_quotes' => '',
            'auto_validate_jobs' => '',
            'auto_validate_tasks' => '',
            'auto_validate_invoices' => '',
        ];

        $this->actingAs($owner)
            ->post(route('customer.store'), $payload)
            ->assertRedirect(route('customer.index'));

        $customer = Customer::query()
            ->where('email', 'customer-billing-defaults@example.com')
            ->firstOrFail();

        $this->assertSame('0.00', $customer->discount_rate);
        $this->assertFalse($customer->billing_same_as_physical);
        $this->assertFalse($customer->auto_accept_quotes);
        $this->assertFalse($customer->auto_validate_jobs);
        $this->assertFalse($customer->auto_validate_tasks);
        $this->assertFalse($customer->auto_validate_invoices);
    }

    public function test_customer_update_normalizes_empty_optional_billing_fields()
    {
        $this->withoutMiddleware(VerifyCsrfToken::class);

        $ownerRole = Role::firstOrCreate(
            ['name' => 'owner'],
            ['description' => 'Account owner access']
        );

        $owner = User::factory()->withRole($ownerRole->id)->create();
        $customer = Customer::factory()->create([
            'user_id' => $owner->id,
            'discount_rate' => 7.5,
            'billing_same_as_physical' => true,
            'auto_accept_quotes' => true,
            'auto_validate_jobs' => true,
            'auto_validate_tasks' => true,
            'auto_validate_invoices' => true,
        ]);

        $payload = [
            'salutation' => $customer->salutation,
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'email' => $customer->email,
            'company_name' => $customer->company_name,
            'phone' => $customer->phone,
            'portal_access' => false,
            'billing_same_as_physical' => '',
            'billing_mode' => 'end_of_job',
            'billing_cycle' => '',
            'billing_grouping' => 'single',
            'billing_delay_days' => '',
            'billing_date_rule' => '',
            'discount_rate' => '',
            'auto_accept_quotes' => '',
            'auto_validate_jobs' => '',
            'auto_validate_tasks' => '',
            'auto_validate_invoices' => '',
        ];

        $this->actingAs($owner)
            ->put(route('customer.update', $customer), $payload)
            ->assertRedirect(route('customer.index'));

        $customer->refresh();

        $this->assertSame('0.00', $customer->discount_rate);
        $this->assertFalse($customer->billing_same_as_physical);
        $this->assertFalse($customer->auto_accept_quotes);
        $this->assertFalse($customer->auto_validate_jobs);
        $this->assertFalse($customer->auto_validate_tasks);
        $this->assertFalse($customer->auto_validate_invoices);
    }
}
