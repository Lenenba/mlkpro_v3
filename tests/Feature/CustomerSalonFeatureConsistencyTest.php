<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Property;
use App\Models\Quote;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerSalonFeatureConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_salon_index_ignores_disabled_quote_and_job_filters_sorts_and_activity(): void
    {
        $owner = $this->salonOwner();
        $legacyCustomer = $this->customer($owner, 'legacy-salon-customer@example.com');
        $recentCustomer = $this->customer($owner, 'recent-salon-customer@example.com');

        Customer::query()->whereKey($legacyCustomer->id)->update(['created_at' => now()->subDays(2)]);
        Customer::query()->whereKey($recentCustomer->id)->update(['created_at' => now()]);

        $property = Property::query()->create([
            'customer_id' => $legacyCustomer->id,
            'type' => 'physical',
            'street1' => '123 Rue du Salon',
            'city' => 'Montréal',
            'state' => 'Québec',
            'zip' => 'H2X 1Y4',
            'country' => 'Canada',
        ]);
        Quote::factory()->create([
            'user_id' => $owner->id,
            'customer_id' => $legacyCustomer->id,
            'property_id' => $property->id,
            'created_at' => now(),
        ]);
        Work::factory()->create([
            'user_id' => $owner->id,
            'customer_id' => $legacyCustomer->id,
            'created_at' => now(),
        ]);
        $teamMember = TeamMember::query()->create([
            'account_id' => $owner->id,
            'user_id' => $owner->id,
            'role' => 'owner',
            'permissions' => [],
            'is_active' => true,
        ]);
        Reservation::query()->create([
            'account_id' => $owner->id,
            'team_member_id' => $teamMember->id,
            'client_id' => $recentCustomer->id,
            'created_by_user_id' => $owner->id,
            'status' => Reservation::STATUS_CONFIRMED,
            'source' => Reservation::SOURCE_STAFF,
            'timezone' => 'America/Toronto',
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHours(2),
            'duration_minutes' => 60,
            'buffer_minutes' => 0,
        ]);

        $response = $this->actingAs($owner)->getJson(route('customer.index', [
            'has_quotes' => '1',
            'has_works' => '1',
            'sort' => 'quotes_count',
            'direction' => 'desc',
        ]));

        $response
            ->assertOk()
            ->assertJsonCount(2, 'customers.data')
            ->assertJsonPath('customers.data.0.id', $recentCustomer->id)
            ->assertJsonPath('stats.with_quotes', 0)
            ->assertJsonPath('stats.with_works', 0)
            ->assertJsonPath('stats.active', 1)
            ->assertJsonCount(0, 'topCustomers')
            ->assertJsonMissingPath('filters.has_quotes')
            ->assertJsonMissingPath('filters.has_works')
            ->assertJsonMissingPath('filters.sort')
            ->assertJsonMissingPath('filters.direction')
            ->assertJsonMissingPath('customers.data.0.quotes_count')
            ->assertJsonMissingPath('customers.data.0.works_count');
    }

    public function test_salon_store_forces_disabled_auto_validations_off(): void
    {
        $owner = $this->salonOwner();

        $this->actingAs($owner)
            ->post(route('customer.store'), [
                'client_type' => 'individual',
                'first_name' => 'Amina',
                'last_name' => 'Diallo',
                'email' => 'amina-salon-features@example.com',
                'portal_access' => false,
                'auto_accept_quotes' => true,
                'auto_validate_jobs' => true,
                'auto_validate_tasks' => true,
                'auto_validate_invoices' => true,
            ])
            ->assertRedirect(route('customer.index'));

        $customer = Customer::query()->where('email', 'amina-salon-features@example.com')->firstOrFail();

        $this->assertFalse($customer->auto_accept_quotes);
        $this->assertFalse($customer->auto_validate_jobs);
        $this->assertFalse($customer->auto_validate_tasks);
        $this->assertTrue($customer->auto_validate_invoices);
    }

    public function test_salon_auto_validation_endpoint_cannot_reenable_disabled_modules(): void
    {
        $owner = $this->salonOwner();
        $customer = $this->customer($owner, 'salon-auto-validation@example.com', [
            'auto_accept_quotes' => true,
            'auto_validate_jobs' => true,
            'auto_validate_tasks' => true,
            'auto_validate_invoices' => true,
        ]);

        $this->actingAs($owner)
            ->patchJson(route('customer.auto-validation.update', $customer), [
                'auto_accept_quotes' => true,
                'auto_validate_jobs' => true,
                'auto_validate_tasks' => true,
                'auto_validate_invoices' => true,
            ])
            ->assertOk();

        $customer->refresh();

        $this->assertFalse($customer->auto_accept_quotes);
        $this->assertFalse($customer->auto_validate_jobs);
        $this->assertFalse($customer->auto_validate_tasks);
        $this->assertTrue($customer->auto_validate_invoices);
    }

    public function test_salon_team_member_does_not_inherit_the_owner_customer_directory(): void
    {
        $owner = $this->salonOwner();
        $employeeRole = Role::query()->firstOrCreate(
            ['name' => 'employee'],
            ['description' => 'Employee role']
        );
        $member = User::factory()->create([
            'role_id' => $employeeRole->id,
            'company_type' => 'services',
            'company_sector' => null,
            'company_features' => [
                'quotes' => true,
                'jobs' => true,
                'tasks' => true,
            ],
            'onboarding_completed_at' => now(),
        ]);
        TeamMember::query()->create([
            'account_id' => $owner->id,
            'user_id' => $member->id,
            'role' => 'member',
            'permissions' => [],
            'is_active' => true,
        ]);
        $owner->forceFill([
            'company_timezone' => 'America/Vancouver',
            'currency_code' => 'USD',
        ])->saveQuietly();
        $this->customer($owner, 'salon-team-member-customer@example.com');

        $response = $this->actingAs($member)->getJson(route('customer.index', [
            'has_quotes' => '1',
            'has_works' => '1',
            'sort' => 'quotes_count',
            'direction' => 'desc',
        ]));

        $response->assertForbidden();

        $this->actingAs($member)
            ->postJson(route('customer.store'), [
                'client_type' => 'individual',
                'first_name' => 'Unauthorized',
                'last_name' => 'Creation',
                'email' => 'unauthorized-salon-creation@example.com',
                'portal_access' => false,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('customers', [
            'email' => 'unauthorized-salon-creation@example.com',
        ]);
    }

    private function salonOwner(): User
    {
        $ownerRole = Role::query()->firstOrCreate(
            ['name' => 'owner'],
            ['description' => 'Account owner role']
        );

        return User::factory()->create([
            'role_id' => $ownerRole->id,
            'company_type' => 'services',
            'company_sector' => 'salon',
            'onboarding_completed_at' => now(),
            'company_features' => [
                'invoices' => true,
            ],
        ]);
    }

    private function customer(User $owner, string $email, array $overrides = []): Customer
    {
        return Customer::query()->create(array_replace([
            'user_id' => $owner->id,
            'client_type' => 'individual',
            'first_name' => 'Salon',
            'last_name' => 'Customer',
            'email' => $email,
            'portal_access' => false,
            'is_active' => true,
        ], $overrides));
    }
}
