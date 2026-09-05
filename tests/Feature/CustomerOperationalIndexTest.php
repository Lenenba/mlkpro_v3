<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerPackage;
use App\Models\Invoice;
use App\Models\OfferPackage;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CustomerOperationalIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
        Carbon::setTestNow(Carbon::parse('2026-08-08 10:00:00', 'America/Toronto'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_salon_index_builds_an_appointment_summary_from_tenant_scoped_operational_data(): void
    {
        $owner = $this->owner('salon', $this->operationalFeatures());
        $otherOwner = $this->owner('salon', $this->operationalFeatures());
        $customer = $this->customer($owner, 'salon-summary@example.com', [
            'is_vip' => true,
            'loyalty_points_balance' => 240,
            'birth_date' => '1990-08-20',
        ], now()->subDays(60));

        $haircut = $this->service($owner, 'Coupe signature');
        $colour = $this->service($owner, 'Coloration éclat');
        $karim = $this->teamMember($owner, 'Karim Benali');
        $nadia = $this->teamMember($owner, 'Nadia Haddad');

        // Two completed visits each. Karim wins the tie because his latest
        // completed visit is the most recent one.
        $this->reservation($owner, $customer, $karim, $haircut, Reservation::STATUS_COMPLETED, now()->subDays(45));
        $this->reservation($owner, $customer, $nadia, $colour, Reservation::STATUS_COMPLETED, now()->subDays(35));
        $this->reservation($owner, $customer, $nadia, $colour, Reservation::STATUS_COMPLETED, now()->subDays(20));
        $latestCompleted = $this->reservation(
            $owner,
            $customer,
            $karim,
            $haircut,
            Reservation::STATUS_COMPLETED,
            now()->subDays(10)
        );

        // More recent non-completed reservations must not replace the last visit.
        $this->reservation($owner, $customer, $nadia, $colour, Reservation::STATUS_NO_SHOW, now()->subDays(2));
        $this->reservation($owner, $customer, $nadia, $colour, Reservation::STATUS_CANCELLED, now()->subDay());

        // A cancelled future reservation is ignored; the confirmed one is next.
        $this->reservation($owner, $customer, $karim, $haircut, Reservation::STATUS_CANCELLED, now()->addDay());
        $nextReservation = $this->reservation(
            $owner,
            $customer,
            $nadia,
            $colour,
            Reservation::STATUS_CONFIRMED,
            now()->addDays(5)
        );

        $paidInvoice = $this->invoice($owner, $customer, 40.25, 'paid');
        $paidInvoice->forceFill([
            'subtotal' => 35,
            'tax_total' => 5.25,
        ])->saveQuietly();
        $tipPayment = $this->payment(
            $owner,
            $customer,
            $paidInvoice,
            40.25,
            Payment::STATUS_COMPLETED,
            5.25,
            45.50
        );
        $tipPayment->forceFill([
            'charged_total' => null,
            'tip_reversed_amount' => 2,
        ])->saveQuietly();

        $partialInvoice = $this->invoice($owner, $customer, 100, 'partial');
        $this->payment($owner, $customer, $partialInvoice, 30, Payment::STATUS_PAID, 0, 30);
        $this->payment($owner, $customer, $partialInvoice, 70, Payment::STATUS_PENDING, 7, 77);
        $this->payment($owner, $customer, $partialInvoice, 10, Payment::STATUS_REFUNDED, 3, 13);
        $this->payment($otherOwner, $customer, $partialInvoice, 70, Payment::STATUS_COMPLETED, 0, 70);

        $voidInvoice = $this->invoice($owner, $customer, 500, 'void');
        $this->payment($owner, $customer, $voidInvoice, 500, Payment::STATUS_PENDING, 0, 500);

        $this->customerPackage($owner, $customer, 'Forfait couleur 5 séances', 2);

        // Deliberately invalid tenant links ensure every operational aggregate
        // also scopes the source row to the account owner.
        $foreignService = $this->service($otherOwner, 'Foreign service');
        $foreignMember = $this->teamMember($otherOwner, 'Foreign employee');
        $this->reservation(
            $otherOwner,
            $customer,
            $foreignMember,
            $foreignService,
            Reservation::STATUS_COMPLETED,
            now()->subHour()
        );
        $this->reservation(
            $otherOwner,
            $customer,
            $foreignMember,
            $foreignService,
            Reservation::STATUS_CONFIRMED,
            now()->addHour()
        );
        $foreignInvoice = $this->invoice($otherOwner, $customer, 999, 'partial');
        $this->payment($otherOwner, $customer, $foreignInvoice, 999, Payment::STATUS_COMPLETED, 99, 1098);
        $this->customerPackage($otherOwner, $customer, 'Foreign package', 1);

        $response = $this->index($owner);

        $response
            ->assertJsonPath('customerIndexContext.profile', 'appointment')
            ->assertJsonPath('customerIndexContext.sector', 'salon')
            ->assertJsonPath('customerIndexContext.capabilities.reservations', true)
            ->assertJsonPath('customerIndexContext.capabilities.loyalty', true)
            ->assertJsonPath('customerIndexContext.capabilities.packages', true)
            ->assertJsonPath('customerIndexContext.capabilities.invoices', true)
            ->assertJsonPath('customerIndexContext.actions.can_create_customer', true);

        $row = collect($response->json('customers.data'))->firstWhere('id', $customer->id);

        $this->assertNotNull($row);
        $this->assertTrue((bool) data_get($row, 'is_vip'));
        $this->assertSame('active', data_get($row, 'operational_summary.lifecycle_status'));
        $this->assertSame('Coupe signature', data_get($row, 'operational_summary.last_visit.service_name'));
        $this->assertSame(
            $latestCompleted->starts_at->timestamp,
            Carbon::parse((string) data_get($row, 'operational_summary.last_visit.starts_at'))->timestamp
        );
        $this->assertSame('Nadia Haddad', data_get($row, 'operational_summary.next_appointment.team_member_name'));
        $this->assertSame(
            $nextReservation->starts_at->timestamp,
            Carbon::parse((string) data_get($row, 'operational_summary.next_appointment.starts_at'))->timestamp
        );
        $this->assertSame($karim->id, data_get($row, 'operational_summary.usual_team_member.id'));
        $this->assertSame('Karim Benali', data_get($row, 'operational_summary.usual_team_member.name'));
        $this->assertSame(
            (int) $customer->refresh()->loyalty_points_balance,
            data_get($row, 'operational_summary.loyalty_points')
        );
        $this->assertSame('Forfait couleur 5 séances', data_get($row, 'operational_summary.active_package.name'));
        $this->assertSame(2, data_get($row, 'operational_summary.active_package.remaining_quantity'));
        // total_spent tracks settled charged totals, including net gratuities.
        // Reversed tips and cross-tenant payments are excluded.
        $this->assertSame(73.50, (float) data_get($row, 'operational_summary.total_spent'));
        $this->assertSame(3.25, (float) data_get($row, 'operational_summary.tip_total'));
        $this->assertSame(70.0, (float) data_get($row, 'operational_summary.unpaid_balance'));
        $this->assertSame($partialInvoice->id, data_get($row, 'operational_summary.unpaid_invoice_id'));
        $this->assertSame('CAD', data_get($row, 'operational_summary.currency_code'));
    }

    public function test_appointment_profile_is_limited_to_supported_sectors_with_reservations(): void
    {
        $salon = $this->owner('salon', ['reservations' => true]);
        $wellness = $this->owner('wellness', ['reservations' => true]);
        $wellnessWithoutReservations = $this->owner('wellness', ['reservations' => false]);
        $restaurant = $this->owner('restaurant', ['reservations' => true]);
        $genericService = $this->owner('service_general', ['reservations' => true]);
        $productSalon = $this->owner('salon', ['reservations' => true, 'sales' => true]);
        $productSalon->forceFill(['company_type' => 'products'])->saveQuietly();

        $this->index($salon)->assertJsonPath('customerIndexContext.profile', 'appointment');
        $this->index($wellness)->assertJsonPath('customerIndexContext.profile', 'appointment');
        $this->index($wellnessWithoutReservations)->assertJsonPath('customerIndexContext.profile', 'generic');
        $this->index($restaurant)->assertJsonPath('customerIndexContext.profile', 'generic');
        $this->index($genericService)->assertJsonPath('customerIndexContext.profile', 'generic');
        $this->index($productSalon)->assertJsonPath('customerIndexContext.profile', 'generic');
    }

    public function test_booking_action_is_hidden_when_the_active_plan_blocks_manual_reservations(): void
    {
        $soloSalon = $this->owner('salon', $this->operationalFeatures());
        $soloSalon->forceFill(['selected_plan_key' => 'solo_growth'])->saveQuietly();

        $teamSalon = $this->owner('salon', $this->operationalFeatures());
        $teamSalon->forceFill(['selected_plan_key' => 'starter'])->saveQuietly();

        $this->index($soloSalon)
            ->assertJsonPath('customerIndexContext.profile', 'appointment')
            ->assertJsonPath('customerIndexContext.actions.can_book', false);

        $this->index($teamSalon)
            ->assertJsonPath('customerIndexContext.profile', 'appointment')
            ->assertJsonPath('customerIndexContext.actions.can_book', true);
    }

    public function test_operational_quick_filters_use_the_same_tenant_scoped_semantics_as_the_summary(): void
    {
        $owner = $this->owner('salon', $this->operationalFeatures());
        $service = $this->service($owner, 'Soin filtre');
        $member = $this->teamMember($owner, 'Filtre Styliste');

        $vip = $this->customer($owner, 'filter-vip@example.com', ['is_vip' => true], now()->subDays(200));
        $nonVip = $this->customer($owner, 'filter-non-vip@example.com', [], now()->subDays(200));

        $new = $this->customer($owner, 'filter-new@example.com', [], now()->subDays(5));
        $old = $this->customer($owner, 'filter-old@example.com', [], now()->subDays(60));

        $withoutNext = $this->customer($owner, 'filter-without-next@example.com', [], now()->subDays(200));
        $withNext = $this->customer($owner, 'filter-with-next@example.com', [], now()->subDays(200));
        $this->reservation($owner, $withoutNext, $member, $service, Reservation::STATUS_CANCELLED, now()->addDay());
        $this->reservation($owner, $withNext, $member, $service, Reservation::STATUS_CONFIRMED, now()->addDay());

        $followUp = $this->customer($owner, 'filter-follow-up@example.com', [], now()->subDays(200));
        $recent = $this->customer($owner, 'filter-recent@example.com', [], now()->subDays(200));
        $this->reservation($owner, $followUp, $member, $service, Reservation::STATUS_COMPLETED, now()->subDays(100));
        $this->reservation($owner, $recent, $member, $service, Reservation::STATUS_COMPLETED, now()->subDays(20));

        $packageLow = $this->customer($owner, 'filter-package-low@example.com', [], now()->subDays(200));
        $packageHealthy = $this->customer($owner, 'filter-package-healthy@example.com', [], now()->subDays(200));
        $this->customerPackage($owner, $packageLow, 'Forfait presque épuisé', 2);
        $this->customerPackage($owner, $packageHealthy, 'Forfait disponible', 5);

        $unpaid = $this->customer($owner, 'filter-unpaid@example.com', [], now()->subDays(200));
        $paid = $this->customer($owner, 'filter-paid@example.com', [], now()->subDays(200));
        $this->invoice($owner, $unpaid, 80, 'sent');
        $paidInvoice = $this->invoice($owner, $paid, 80, 'paid');
        $this->payment($owner, $paid, $paidInvoice, 80, Payment::STATUS_COMPLETED, 0, 80);

        $birthdayUpcoming = $this->customer($owner, 'filter-birthday-upcoming@example.com', [
            'birth_date' => '1992-08-20',
        ], now()->subDays(200));
        $birthdayLater = $this->customer($owner, 'filter-birthday-later@example.com', [
            'birth_date' => '1992-12-20',
        ], now()->subDays(200));

        $this->assertOperationalFilter($owner, 'vip', $vip, $nonVip);
        $this->assertOperationalFilter($owner, 'new', $new, $old);
        $this->assertOperationalFilter($owner, 'no_next_appointment', $withoutNext, $withNext);
        $this->assertOperationalFilter($owner, 'follow_up_90', $followUp, $recent);
        $this->assertOperationalFilter($owner, 'package_low', $packageLow, $packageHealthy);
        $this->assertOperationalFilter($owner, 'unpaid', $unpaid, $paid);
        $this->assertOperationalFilter($owner, 'birthday_upcoming', $birthdayUpcoming, $birthdayLater);
    }

    public function test_operational_filters_are_ignored_outside_the_appointment_profile(): void
    {
        $owner = $this->owner('service_general', [
            'reservations' => false,
            'loyalty' => false,
            'invoices' => false,
            'sales' => false,
            'campaigns' => false,
            'services' => false,
            'products' => false,
        ]);
        $first = $this->customer($owner, 'ignored-operational-first@example.com', [], now()->subDays(5));
        $second = $this->customer($owner, 'ignored-operational-second@example.com', [], now()->subDays(200));

        foreach ([
            'vip',
            'new',
            'no_next_appointment',
            'follow_up_90',
            'package_low',
            'unpaid',
            'birthday_upcoming',
        ] as $filter) {
            $response = $this->index($owner, ['operational_filter' => $filter]);
            $ids = collect($response->json('customers.data'))->pluck('id')->all();

            $response
                ->assertJsonPath('customerIndexContext.profile', 'generic')
                ->assertJsonMissingPath('filters.operational_filter');
            $this->assertContains($first->id, $ids, "{$filter} unexpectedly filtered the generic customer index.");
            $this->assertContains($second->id, $ids, "{$filter} unexpectedly filtered the generic customer index.");
        }
    }

    public function test_appointment_profile_ignores_filters_for_disabled_optional_capabilities(): void
    {
        $owner = $this->owner('salon', [
            'reservations' => true,
            'team_members' => true,
            'loyalty' => false,
            'invoices' => false,
            'sales' => false,
            'campaigns' => false,
            'services' => false,
            'products' => false,
        ]);
        $first = $this->customer($owner, 'disabled-capabilities-first@example.com', [
            'is_vip' => true,
        ], now()->subDays(200));
        $second = $this->customer($owner, 'disabled-capabilities-second@example.com', [], now()->subDays(200));
        $this->invoice($owner, $first, 90, 'sent');
        $this->customerPackage($owner, $first, 'Disabled capability package', 1);

        $contextResponse = $this->index($owner)
            ->assertJsonPath('customerIndexContext.profile', 'appointment')
            ->assertJsonPath('customerIndexContext.capabilities.campaigns', false)
            ->assertJsonPath('customerIndexContext.capabilities.loyalty', false)
            ->assertJsonPath('customerIndexContext.capabilities.invoices', false)
            ->assertJsonPath('customerIndexContext.capabilities.packages', false);
        $this->assertNull(data_get(
            collect($contextResponse->json('customers.data'))->firstWhere('id', $first->id),
            'operational_summary.loyalty_points'
        ));

        foreach (['vip', 'unpaid', 'package_low'] as $filter) {
            $response = $this->index($owner, ['operational_filter' => $filter]);
            $ids = collect($response->json('customers.data'))->pluck('id')->all();

            $response->assertJsonMissingPath('filters.operational_filter');
            $this->assertContains($first->id, $ids, "{$filter} unexpectedly applied while its capability is disabled.");
            $this->assertContains($second->id, $ids, "{$filter} unexpectedly applied while its capability is disabled.");
        }
    }

    public function test_appointment_profile_hides_legacy_quote_and_job_overrides(): void
    {
        $owner = $this->owner('salon', array_replace($this->operationalFeatures(), [
            'quotes' => true,
            'jobs' => true,
        ]));
        $customer = $this->customer(
            $owner,
            'appointment-with-legacy-pipeline@example.com',
            [],
            now()->subDays(60)
        );

        $response = $this->index($owner, [
            'has_quotes' => true,
            'has_works' => true,
            'sort' => 'quotes_count',
            'direction' => 'desc',
        ])
            ->assertJsonPath('customerIndexContext.profile', 'appointment')
            ->assertJsonMissingPath('filters.has_quotes')
            ->assertJsonMissingPath('filters.has_works')
            ->assertJsonMissingPath('filters.sort')
            ->assertJsonPath('stats.with_quotes', 0)
            ->assertJsonPath('stats.with_works', 0)
            ->assertJsonPath('topCustomers', []);

        $row = collect($response->json('customers.data'))->firstWhere('id', $customer->id);

        $this->assertNotNull($row);
        $this->assertArrayNotHasKey('quotes_count', $row);
        $this->assertArrayNotHasKey('works_count', $row);
    }

    public function test_customer_creation_rejects_a_future_birth_date(): void
    {
        $owner = $this->owner('salon', $this->operationalFeatures());

        $this->actingAs($owner)
            ->postJson(route('customer.store'), [
                'client_type' => 'individual',
                'first_name' => 'Future',
                'last_name' => 'Birthday',
                'email' => 'future-birthday@example.com',
                'portal_access' => false,
                'birth_date' => now()->addDay()->toDateString(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('birth_date');

        $this->assertDatabaseMissing('customers', [
            'email' => 'future-birthday@example.com',
        ]);
    }

    /**
     * @param  array<string, bool>  $features
     */
    private function owner(string $sector, array $features): User
    {
        $role = Role::query()->firstOrCreate(
            ['name' => 'owner'],
            ['description' => 'Account owner role']
        );

        return User::factory()->create([
            'role_id' => $role->id,
            'company_type' => 'services',
            'company_sector' => $sector,
            'company_timezone' => 'America/Toronto',
            'currency_code' => 'CAD',
            'onboarding_completed_at' => now(),
            'company_features' => array_replace([
                'reservations' => false,
                'team_members' => false,
                'loyalty' => false,
                'invoices' => false,
                'sales' => false,
                'campaigns' => false,
                'services' => false,
                'products' => false,
            ], $features),
        ]);
    }

    /**
     * @return array<string, bool>
     */
    private function operationalFeatures(): array
    {
        return [
            'reservations' => true,
            'team_members' => true,
            'loyalty' => true,
            'invoices' => true,
            'sales' => true,
            'campaigns' => true,
            'services' => true,
            'products' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function customer(
        User $owner,
        string $email,
        array $overrides = [],
        ?Carbon $createdAt = null
    ): Customer {
        $customer = Customer::query()->create(array_replace([
            'user_id' => $owner->id,
            'client_type' => 'individual',
            'first_name' => 'Salon',
            'last_name' => 'Client',
            'email' => $email,
            'portal_access' => false,
            'is_active' => true,
            'is_vip' => false,
            'loyalty_points_balance' => 0,
        ], $overrides));

        if ($createdAt) {
            $customer->forceFill([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ])->saveQuietly();
        }

        return $customer->refresh();
    }

    private function service(User $owner, string $name): Product
    {
        $category = ProductCategory::query()->create([
            'name' => $name.' category',
            'user_id' => $owner->id,
            'created_by_user_id' => $owner->id,
        ]);

        return Product::query()->create([
            'name' => $name,
            'description' => $name,
            'category_id' => $category->id,
            'user_id' => $owner->id,
            'item_type' => Product::ITEM_TYPE_SERVICE,
            'tracking_type' => 'none',
            'price' => 50,
            'currency_code' => 'CAD',
            'stock' => 0,
            'minimum_stock' => 0,
            'is_active' => true,
        ]);
    }

    private function teamMember(User $owner, string $name): TeamMember
    {
        $employeeRole = Role::query()->firstOrCreate(
            ['name' => 'employee'],
            ['description' => 'Employee role']
        );
        $employee = User::factory()->create([
            'role_id' => $employeeRole->id,
            'name' => $name,
            'company_type' => 'services',
            'onboarding_completed_at' => now(),
        ]);

        return TeamMember::query()->create([
            'account_id' => $owner->id,
            'user_id' => $employee->id,
            'role' => 'member',
            'title' => 'Styliste',
            'permissions' => [],
            'is_active' => true,
        ]);
    }

    private function reservation(
        User $owner,
        Customer $customer,
        TeamMember $member,
        Product $service,
        string $status,
        Carbon $startsAt
    ): Reservation {
        $duration = 60;

        return Reservation::query()->create([
            'account_id' => $owner->id,
            'team_member_id' => $member->id,
            'client_id' => $customer->id,
            'service_id' => $service->id,
            'created_by_user_id' => $owner->id,
            'status' => $status,
            'source' => Reservation::SOURCE_STAFF,
            'timezone' => 'America/Toronto',
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes($duration),
            'duration_minutes' => $duration,
            'buffer_minutes' => 0,
            'cancelled_at' => $status === Reservation::STATUS_CANCELLED ? $startsAt : null,
        ]);
    }

    private function invoice(User $owner, Customer $customer, float $total, string $status): Invoice
    {
        return Invoice::query()->create([
            'user_id' => $owner->id,
            'created_by_user_id' => $owner->id,
            'customer_id' => $customer->id,
            'status' => $status,
            'subtotal' => $total,
            'tax_total' => 0,
            'total' => $total,
            'currency_code' => 'CAD',
        ]);
    }

    private function payment(
        User $owner,
        Customer $customer,
        Invoice $invoice,
        float $amount,
        string $status,
        float $tipAmount,
        float $chargedTotal
    ): Payment {
        return Payment::query()->create([
            'user_id' => $owner->id,
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'currency_code' => 'CAD',
            'tip_amount' => $tipAmount,
            'charged_total' => $chargedTotal,
            'method' => 'card',
            'provider' => 'test',
            'status' => $status,
            'paid_at' => in_array($status, Payment::settledStatuses(), true) ? now() : null,
        ]);
    }

    private function customerPackage(
        User $owner,
        Customer $customer,
        string $name,
        int $remainingQuantity
    ): CustomerPackage {
        $offer = OfferPackage::query()->create([
            'user_id' => $owner->id,
            'name' => $name,
            'type' => OfferPackage::TYPE_FORFAIT,
            'status' => OfferPackage::STATUS_ACTIVE,
            'pricing_mode' => OfferPackage::PRICING_FIXED,
            'price' => 200,
            'currency_code' => 'CAD',
            'included_quantity' => 5,
            'unit_type' => OfferPackage::UNIT_SESSION,
        ]);

        return CustomerPackage::query()->create([
            'user_id' => $owner->id,
            'customer_id' => $customer->id,
            'offer_package_id' => $offer->id,
            'status' => CustomerPackage::STATUS_ACTIVE,
            'starts_at' => today()->subMonth(),
            'expires_at' => today()->addMonth(),
            'initial_quantity' => 5,
            'consumed_quantity' => 5 - $remainingQuantity,
            'remaining_quantity' => $remainingQuantity,
            'unit_type' => OfferPackage::UNIT_SESSION,
            'price_paid' => 200,
            'currency_code' => 'CAD',
            'source_details' => [
                'offer_package' => [
                    'name' => $name,
                ],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function index(User $owner, array $query = []): TestResponse
    {
        return $this->actingAs($owner)
            ->getJson(route('customer.index', $query))
            ->assertOk();
    }

    private function assertOperationalFilter(
        User $owner,
        string $filter,
        Customer $included,
        Customer $excluded
    ): void {
        $response = $this->index($owner, [
            'operational_filter' => $filter,
            'per_page' => 100,
        ])
            ->assertJsonPath('filters.operational_filter', $filter);
        $ids = collect($response->json('customers.data'))->pluck('id')->all();

        $this->assertContains($included->id, $ids, "{$filter} did not include the expected customer.");
        $this->assertNotContains($excluded->id, $ids, "{$filter} included a customer that should be excluded.");
    }
}
