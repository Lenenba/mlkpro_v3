<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
            'client_type' => 'company',
            'salutation' => 'Mr',
            'first_name' => 'Nellie',
            'last_name' => 'Kedagni',
            'email' => 'client-role-test@example.com',
            'company_name' => 'Kedagni Inc',
            'registration_number' => 'REG-42424',
            'industry' => 'Construction',
            'phone' => '+15145551234',
            'portal_access' => true,
        ];

        $response = $this->actingAs($owner)
            ->postJson(route('customer.store'), $payload);

        $response->assertStatus(201);
        $this->assertDatabaseHas('roles', ['name' => 'client']);
        $this->assertDatabaseHas('customers', [
            'email' => 'client-role-test@example.com',
            'client_type' => 'company',
            'registration_number' => 'REG-42424',
            'industry' => 'Construction',
        ]);
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
            'client_type' => 'company',
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

    public function test_customer_creation_can_redirect_to_create_another_form()
    {
        $this->withoutMiddleware(VerifyCsrfToken::class);

        $ownerRole = Role::firstOrCreate(
            ['name' => 'owner'],
            ['description' => 'Account owner access']
        );

        $owner = User::factory()->withRole($ownerRole->id)->create();

        $payload = [
            'client_type' => 'individual',
            'salutation' => 'Mrs',
            'first_name' => 'Amina',
            'last_name' => 'Diallo',
            'email' => 'customer-create-another@example.com',
            'phone' => '+15145550123',
            'portal_access' => false,
            'logo_icon' => Customer::DEFAULT_AVATAR_PATH,
            'create_another' => true,
        ];

        $this->actingAs($owner)
            ->post(route('customer.store'), $payload)
            ->assertRedirect(route('customer.create'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('customers', [
            'email' => 'customer-create-another@example.com',
            'user_id' => $owner->id,
            'logo' => Customer::DEFAULT_AVATAR_PATH,
        ]);
    }

    public function test_individual_customer_can_upload_a_profile_photo()
    {
        Storage::fake('public');
        $this->withoutMiddleware(VerifyCsrfToken::class);

        $ownerRole = Role::firstOrCreate(
            ['name' => 'owner'],
            ['description' => 'Account owner access']
        );

        $owner = User::factory()->withRole($ownerRole->id)->create();

        $this->actingAs($owner)
            ->post(route('customer.store'), [
                'client_type' => 'individual',
                'salutation' => 'Mrs',
                'first_name' => 'Amina',
                'last_name' => 'Diallo',
                'email' => 'amina-profile-photo@example.com',
                'phone' => '+15145550124',
                'portal_access' => false,
                'logo' => UploadedFile::fake()->image('amina-profile.jpg', 640, 640),
            ])
            ->assertRedirect(route('customer.index'));

        $customer = Customer::query()
            ->where('email', 'amina-profile-photo@example.com')
            ->firstOrFail();

        $this->assertSame('individual', $customer->client_type);
        $this->assertStringStartsWith('customers/', $customer->logo);
        Storage::disk('public')->assertExists($customer->logo);
        $this->assertSame(Storage::disk('public')->url($customer->logo), $customer->logo_url);
    }

    public function test_updating_an_individual_profile_photo_removes_the_previous_file()
    {
        Storage::fake('public');
        $this->withoutMiddleware(VerifyCsrfToken::class);

        $ownerRole = Role::firstOrCreate(
            ['name' => 'owner'],
            ['description' => 'Account owner access']
        );

        $owner = User::factory()->withRole($ownerRole->id)->create();
        $oldLogoPath = 'customers/old-profile-photo.jpg';
        Storage::disk('public')->put($oldLogoPath, 'old photo');

        $customer = Customer::factory()->create([
            'user_id' => $owner->id,
            'client_type' => 'individual',
            'company_name' => null,
            'logo' => $oldLogoPath,
        ]);

        $this->actingAs($owner)
            ->put(route('customer.update', $customer), [
                'client_type' => 'individual',
                'salutation' => $customer->salutation,
                'first_name' => $customer->first_name,
                'last_name' => $customer->last_name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'portal_access' => false,
                'logo' => UploadedFile::fake()->image('new-profile.jpg', 640, 640),
            ])
            ->assertRedirect(route('customer.index'));

        $customer->refresh();

        $this->assertNotSame($oldLogoPath, $customer->logo);
        Storage::disk('public')->assertMissing($oldLogoPath);
        Storage::disk('public')->assertExists($customer->logo);
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
            'client_type' => 'company',
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

    public function test_customer_update_clears_company_specific_fields_when_switching_to_individual()
    {
        $this->withoutMiddleware(VerifyCsrfToken::class);

        $ownerRole = Role::firstOrCreate(
            ['name' => 'owner'],
            ['description' => 'Account owner access']
        );

        $owner = User::factory()->withRole($ownerRole->id)->create();
        $customer = Customer::factory()->create([
            'user_id' => $owner->id,
            'client_type' => 'company',
            'company_name' => 'Switchable Inc',
            'registration_number' => 'REG-99881',
            'industry' => 'Retail',
        ]);

        $payload = [
            'client_type' => 'individual',
            'first_name' => 'Ari',
            'last_name' => 'Individual',
            'email' => $customer->email,
            'phone' => $customer->phone,
            'portal_access' => false,
            'billing_mode' => 'end_of_job',
            'billing_grouping' => 'single',
        ];

        $this->actingAs($owner)
            ->put(route('customer.update', $customer), $payload)
            ->assertRedirect(route('customer.index'));

        $customer->refresh();

        $this->assertSame('individual', $customer->client_type);
        $this->assertNull($customer->company_name);
        $this->assertNull($customer->registration_number);
        $this->assertNull($customer->industry);
    }
}
