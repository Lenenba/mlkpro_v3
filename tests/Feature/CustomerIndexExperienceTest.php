<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\SavedSegment;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Segments\Resolvers\CustomerSegmentResolver;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CustomerIndexExperienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
        Carbon::setTestNow(Carbon::parse('2026-08-21 10:00:00', 'America/Toronto'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_quick_filters_are_deduplicated_and_combined_without_escaping_the_tenant_scope(): void
    {
        $owner = $this->owner('salon', $this->features());
        $foreignOwner = $this->owner('salon', $this->features());
        [$member, $service] = $this->appointmentResources($owner);
        [$foreignMember, $foreignService] = $this->appointmentResources($foreignOwner);

        $vipWithAppointment = $this->customer($owner, 'vip-with-appointment@example.com', ['is_vip' => true]);
        $vipWithoutAppointment = $this->customer($owner, 'vip-without-appointment@example.com', ['is_vip' => true]);
        $regularWithAppointment = $this->customer($owner, 'regular-with-appointment@example.com');
        $foreignVip = $this->customer($foreignOwner, 'foreign-vip@example.com', ['is_vip' => true]);
        $this->reservation($owner, $vipWithAppointment, $member, $service, Reservation::STATUS_CONFIRMED, now()->addDay());
        $this->reservation($owner, $regularWithAppointment, $member, $service, Reservation::STATUS_RESCHEDULED, now()->addDays(2));
        $this->reservation($foreignOwner, $foreignVip, $foreignMember, $foreignService, Reservation::STATUS_CONFIRMED, now()->addDay());

        $all = $this->index($owner, [
            'quick_filters' => ['vip', 'upcoming_appointment', 'unknown', 'vip'],
            'quick_filter_mode' => 'all',
            'operational_filter' => 'no_next_appointment',
            'per_page' => 100,
        ]);
        $this->assertSame([$vipWithAppointment->id], $this->ids($all));
        $all
            ->assertJsonPath('filters.quick_filters', ['vip', 'upcoming_appointment'])
            ->assertJsonPath('filters.quick_filter_mode', 'all')
            ->assertJsonMissingPath('filters.operational_filter')
            ->assertJsonPath('filterMeta.matching_count', 1)
            ->assertJsonPath('filterMeta.active_count', 2);

        $any = $this->index($owner, [
            'quick_filters' => ['vip', 'upcoming_appointment'],
            'quick_filter_mode' => 'any',
            'per_page' => 100,
        ]);
        $this->assertEqualsCanonicalizing([
            $vipWithAppointment->id,
            $vipWithoutAppointment->id,
            $regularWithAppointment->id,
        ], $this->ids($any));
        $this->assertNotContains($foreignVip->id, $this->ids($any));
        $this->assertSame(count($this->ids($any)), count(array_unique($this->ids($any))));

        $canonicalEmpty = $this->index($owner, [
            'quick_filters' => ['unknown'],
            'operational_filter' => 'no_next_appointment',
            'per_page' => 100,
        ]);
        $this->assertEqualsCanonicalizing([
            $vipWithAppointment->id,
            $vipWithoutAppointment->id,
            $regularWithAppointment->id,
        ], $this->ids($canonicalEmpty));
        $canonicalEmpty
            ->assertJsonMissingPath('filters.quick_filters')
            ->assertJsonMissingPath('filters.operational_filter');
    }

    public function test_reservation_filters_are_available_to_generic_reservation_profiles_while_legacy_alias_stays_profile_scoped(): void
    {
        $owner = $this->owner('restaurant', $this->features());
        [$member, $service] = $this->appointmentResources($owner);
        $withReservation = $this->customer($owner, 'restaurant-booked@example.com');
        $withoutReservation = $this->customer($owner, 'restaurant-unbooked@example.com');
        $this->reservation($owner, $withReservation, $member, $service, Reservation::STATUS_RESCHEDULED, now()->addDay());

        $canonical = $this->index($owner, [
            'quick_filters' => ['upcoming_appointment'],
            'per_page' => 100,
        ]);
        $this->assertSame([$withReservation->id], $this->ids($canonical));
        $canonical
            ->assertJsonPath('customerIndexContext.profile', 'generic')
            ->assertJsonFragment(['upcoming_appointment'])
            ->assertJsonPath('filters.quick_filters', ['upcoming_appointment']);

        $legacy = $this->index($owner, [
            'operational_filter' => 'no_next_appointment',
            'per_page' => 100,
        ]);
        $this->assertEqualsCanonicalizing([$withReservation->id, $withoutReservation->id], $this->ids($legacy));
        $legacy->assertJsonMissingPath('filters.operational_filter');
    }

    public function test_kpis_are_global_calendar_correct_and_stable_when_the_result_set_is_filtered(): void
    {
        $owner = $this->owner('salon', $this->features());
        [$member, $service] = $this->appointmentResources($owner);
        $returning = $this->customer($owner, 'returning@example.com', [
            'is_vip' => true,
            'created_at' => Carbon::parse('2026-08-01 00:30:00', 'America/Toronto')->utc(),
        ]);
        $oneVisit = $this->customer($owner, 'one-visit@example.com', [
            'created_at' => Carbon::parse('2026-07-31 23:30:00', 'America/Toronto')->utc(),
        ]);
        $inactive = $this->customer($owner, 'inactive@example.com', [
            'is_active' => false,
            'created_at' => now()->subDays(100),
        ]);

        $this->reservation($owner, $returning, $member, $service, Reservation::STATUS_COMPLETED, now()->subDays(40));
        $this->reservation($owner, $returning, $member, $service, Reservation::STATUS_COMPLETED, now()->subDays(10));
        $this->reservation($owner, $oneVisit, $member, $service, Reservation::STATUS_COMPLETED, now()->subDays(5));
        $this->reservation($owner, $returning, $member, $service, Reservation::STATUS_CANCELLED, now()->subDays(3));
        $this->reservation($owner, $returning, $member, $service, Reservation::STATUS_CANCELLED, now()->subDays(2));
        $this->reservation($owner, $oneVisit, $member, $service, Reservation::STATUS_NO_SHOW, now()->subDay());
        $this->reservation($owner, $oneVisit, $member, $service, Reservation::STATUS_NO_SHOW, now()->subHours(12));

        $response = $this->index($owner, ['status' => 'archived']);

        $response
            ->assertJsonPath('count', 1)
            ->assertJsonPath('stats.total', 1)
            ->assertJsonPath('kpis.total', 3)
            ->assertJsonPath('kpis.new_this_month', 1)
            ->assertJsonPath('kpis.active', 2)
            ->assertJsonPath('kpis.inactive', 1)
            ->assertJsonPath('kpis.vip', 1)
            ->assertJsonPath('kpis.no_next_appointment', 2)
            ->assertJsonPath('kpis.recent_cancellations', 1)
            ->assertJsonPath('kpis.recent_no_shows', 1)
            ->assertJsonPath('kpis.return_rate', 50)
            ->assertJsonPath('kpis.average_appointments_per_customer', 1);

        $this->assertSame([$inactive->id], $this->ids($response));
    }

    public function test_financial_filters_and_kpis_ignore_unsettled_refunded_foreign_currency_and_foreign_tenant_rows(): void
    {
        $owner = $this->owner('salon', $this->features());
        $foreignOwner = $this->owner('salon', $this->features());
        $outstandingCustomer = $this->customer($owner, 'outstanding@example.com');
        $paidCustomer = $this->customer($owner, 'paid@example.com');

        $first = $this->invoice($owner, $outstandingCustomer, 100, 'partial');
        $this->payment($owner, $outstandingCustomer, $first, 30, Payment::STATUS_COMPLETED);
        $this->payment($owner, $outstandingCustomer, $first, 40, Payment::STATUS_PENDING);
        $this->payment($owner, $outstandingCustomer, $first, 20, Payment::STATUS_REFUNDED);
        $this->invoice($owner, $outstandingCustomer, 50, 'sent');

        $paidInvoice = $this->invoice($owner, $paidCustomer, 40, 'paid');
        $this->payment($owner, $paidCustomer, $paidInvoice, 40, Payment::STATUS_PAID);

        $foreignInvoice = $this->invoice($foreignOwner, $outstandingCustomer, 900, 'sent');
        $this->payment($foreignOwner, $outstandingCustomer, $foreignInvoice, 900, Payment::STATUS_COMPLETED);
        $usdInvoice = $this->invoice($owner, $outstandingCustomer, 500, 'sent', 'USD');
        $this->payment($owner, $outstandingCustomer, $usdInvoice, 500, Payment::STATUS_COMPLETED, 'USD');
        $deletedInvoice = $this->invoice($owner, $outstandingCustomer, 700, 'sent');
        $deletedInvoice->forceFill(['deleted_at' => now()])->saveQuietly();

        $response = $this->index($owner, [
            'quick_filters' => ['outstanding_balance'],
            'payment_statuses' => [Payment::STATUS_REFUNDED],
            'per_page' => 100,
        ]);

        // The advanced payment-status condition is intentionally ANDed with
        // the outstanding quick filter; the same customer satisfies both.
        $this->assertSame([$outstandingCustomer->id], $this->ids($response));
        $response
            ->assertJsonPath('kpis.outstanding.customers', 1)
            ->assertJsonPath('kpis.outstanding.amount', 120)
            ->assertJsonPath('kpis.outstanding.currency_code', 'CAD')
            ->assertJsonPath('kpis.average_value_per_customer.amount', 35)
            ->assertJsonPath('kpis.average_value_per_customer.currency_code', 'CAD');
    }

    public function test_financial_filters_options_and_kpis_are_not_exposed_without_invoice_policy_access(): void
    {
        $owner = $this->owner('salon', $this->features());
        $customer = $this->customer($owner, 'private-finance@example.com');
        $this->invoice($owner, $customer, 100, 'sent');
        $employee = $this->employee($owner, ['customers.view']);

        $response = $this->index($employee, [
            'quick_filters' => ['outstanding_balance'],
            'has_outstanding_balance' => '1',
        ]);

        $response
            ->assertJsonPath('customerIndexContext.capabilities.invoices', false)
            ->assertJsonMissingPath('filters.quick_filters')
            ->assertJsonMissingPath('filters.has_outstanding_balance')
            ->assertJsonMissingPath('kpis.outstanding')
            ->assertJsonMissingPath('filterMeta.options.payment_statuses');
        $this->assertSame([$customer->id], $this->ids($response));
    }

    public function test_member_kpis_and_filter_options_follow_the_account_owner_customer_directory_scope(): void
    {
        $owner = $this->owner('service_general', $this->features([
            'reservations' => false,
            'sales' => false,
            'invoices' => false,
        ]));
        $employee = $this->employee($owner, ['customers.view']);
        $ownerCustomer = $this->customer($owner, 'account-visible@example.com', [
            'refer_by' => 'Account source',
            'tags' => ['account-visible'],
        ]);
        $memberCustomer = $this->customer($employee, 'member-private@example.com', [
            'refer_by' => 'Member only',
            'tags' => ['member-private'],
        ]);

        $response = $this->index($employee, ['per_page' => 100]);

        $this->assertSame([$ownerCustomer->id], $this->ids($response));
        $this->assertNotContains($memberCustomer->id, $this->ids($response));
        $response
            ->assertJsonPath('kpis.total', 1)
            ->assertJsonPath('filterOptions.acquisition_sources', ['Account source'])
            ->assertJsonPath('filterOptions.tags', ['account-visible'])
            ->assertJsonPath('filterMeta.options.acquisition_sources', ['Account source']);
    }

    public function test_partial_filter_visits_keep_expensive_global_props_lazy(): void
    {
        $owner = $this->owner('salon', $this->features());
        $this->customer($owner, 'partial-filter@example.com');
        $version = app(\App\Http\Middleware\HandleInertiaRequests::class)->version(
            \Illuminate\Http\Request::create(route('customer.index'))
        );
        $headers = [
            'Accept' => 'text/html, application/xhtml+xml',
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'Customer/Index',
            'X-Inertia-Partial-Data' => 'customers,filters,count,filterMeta,topCustomers',
        ];
        if ($version !== null) {
            $headers['X-Inertia-Version'] = $version;
        }

        $response = $this->actingAs($owner)->get(
            route('customer.index', ['name' => 'partial']),
            $headers
        );

        $response
            ->assertOk()
            ->assertHeader('X-Inertia', 'true')
            ->assertJsonPath('props.count', 1)
            ->assertJsonPath('props.filterMeta.matching_count', 1)
            ->assertJsonMissingPath('props.stats')
            ->assertJsonMissingPath('props.kpis')
            ->assertJsonMissingPath('props.filterOptions');
    }

    public function test_pagination_links_keep_only_normalized_authorized_filters(): void
    {
        $owner = $this->owner('service_general', $this->features([
            'reservations' => false,
            'invoices' => false,
        ]));
        $this->customer($owner, 'normalized-url@example.com');

        $response = $this->index($owner, [
            'name' => 'Index',
            'quick_filters' => ['unknown'],
            'has_outstanding_balance' => '1',
        ]);
        $urls = collect($response->json('customers.links'))
            ->pluck('url')
            ->filter()
            ->implode(' ');

        $this->assertStringContainsString('name=Index', $urls);
        $this->assertStringNotContainsString('unknown', $urls);
        $this->assertStringNotContainsString('has_outstanding_balance', $urls);
    }

    public function test_saved_customer_segments_resolve_canonical_arrays_modes_and_sanitize_unsafe_values(): void
    {
        $owner = $this->owner('service_general', $this->features(['reservations' => false]));
        $vip = $this->customer($owner, 'segment-vip@example.com', ['is_vip' => true]);
        $inactive = $this->customer($owner, 'segment-inactive@example.com', ['is_active' => false]);
        $this->customer($owner, 'segment-regular@example.com');

        $segment = SavedSegment::query()->create([
            'user_id' => $owner->id,
            'module' => SavedSegment::MODULE_CUSTOMER,
            'name' => 'VIP ou inactifs',
            'filters' => [
                'quick_filters' => ['vip', 'inactive', 'vip', ['malformed']],
                'quick_filter_mode' => 'any',
                'created_from' => 'not-a-date',
                'appointments_min' => 'not-a-number',
            ],
        ]);

        $resolved = app(CustomerSegmentResolver::class)->resolve($segment);

        $this->assertEqualsCanonicalizing([$vip->id, $inactive->id], $resolved['ids']);
        $this->assertSame(['vip', 'inactive'], $resolved['filters']['quick_filters']);
        $this->assertSame('any', $resolved['filters']['quick_filter_mode']);
        $this->assertArrayNotHasKey('created_from', $resolved['filters']);
        $this->assertArrayNotHasKey('appointments_min', $resolved['filters']);
    }

    /** @param array<string, bool> $overrides */
    private function features(array $overrides = []): array
    {
        return array_replace([
            'reservations' => true,
            'team_members' => true,
            'loyalty' => true,
            'invoices' => true,
            'sales' => false,
            'campaigns' => true,
            'services' => true,
            'products' => true,
        ], $overrides);
    }

    /** @param array<string, bool> $features */
    private function owner(string $sector, array $features): User
    {
        $role = Role::query()->firstOrCreate(
            ['name' => 'owner'],
            ['description' => 'Owner role']
        );

        return User::factory()->create([
            'role_id' => $role->id,
            'company_type' => 'services',
            'company_sector' => $sector,
            'company_timezone' => 'America/Toronto',
            'currency_code' => 'CAD',
            'onboarding_completed_at' => now(),
            'company_features' => $features,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function customer(User $owner, string $email, array $overrides = []): Customer
    {
        $createdAt = $overrides['created_at'] ?? null;
        unset($overrides['created_at']);
        $customer = Customer::query()->create(array_replace([
            'user_id' => $owner->id,
            'client_type' => 'individual',
            'first_name' => 'Index',
            'last_name' => 'Customer',
            'email' => $email,
            'portal_access' => false,
            'is_active' => true,
            'is_vip' => false,
        ], $overrides));

        if ($createdAt) {
            $customer->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();
        }

        return $customer->refresh();
    }

    /** @return array{TeamMember, Product} */
    private function appointmentResources(User $owner): array
    {
        $employee = User::factory()->create([
            'role_id' => Role::query()->firstOrCreate(['name' => 'employee'])->id,
            'company_type' => 'services',
            'onboarding_completed_at' => now(),
        ]);
        $member = TeamMember::query()->create([
            'account_id' => $owner->id,
            'user_id' => $employee->id,
            'role' => 'member',
            'title' => 'Member',
            'permissions' => [],
            'is_active' => true,
        ]);
        $category = ProductCategory::query()->create([
            'name' => 'Index services '.$owner->id,
            'user_id' => $owner->id,
            'created_by_user_id' => $owner->id,
        ]);
        $service = Product::query()->create([
            'name' => 'Index service',
            'description' => 'Index service',
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

        return [$member, $service];
    }

    private function reservation(
        User $owner,
        Customer $customer,
        TeamMember $member,
        Product $service,
        string $status,
        Carbon $startsAt
    ): Reservation {
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
            'ends_at' => $startsAt->copy()->addHour(),
            'duration_minutes' => 60,
            'buffer_minutes' => 0,
            'cancelled_at' => $status === Reservation::STATUS_CANCELLED ? $startsAt : null,
        ]);
    }

    private function invoice(
        User $owner,
        Customer $customer,
        float $total,
        string $status,
        string $currency = 'CAD'
    ): Invoice {
        return Invoice::query()->create([
            'user_id' => $owner->id,
            'created_by_user_id' => $owner->id,
            'customer_id' => $customer->id,
            'status' => $status,
            'subtotal' => $total,
            'tax_total' => 0,
            'total' => $total,
            'currency_code' => $currency,
        ]);
    }

    private function payment(
        User $owner,
        Customer $customer,
        Invoice $invoice,
        float $amount,
        string $status,
        string $currency = 'CAD'
    ): Payment {
        return Payment::query()->create([
            'user_id' => $owner->id,
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'currency_code' => $currency,
            'tip_amount' => 0,
            'charged_total' => $amount,
            'method' => 'card',
            'provider' => 'test',
            'status' => $status,
            'paid_at' => in_array($status, Payment::settledStatuses(), true) ? now() : null,
        ]);
    }

    /** @param array<int, string> $permissions */
    private function employee(User $owner, array $permissions): User
    {
        $role = Role::query()->firstOrCreate(
            ['name' => 'employee'],
            ['description' => 'Employee role']
        );
        $employee = User::factory()->create([
            'role_id' => $role->id,
            'company_type' => 'services',
            'onboarding_completed_at' => now(),
        ]);
        TeamMember::query()->create([
            'account_id' => $owner->id,
            'user_id' => $employee->id,
            'role' => 'member',
            'title' => 'Member',
            'permissions' => $permissions,
            'is_active' => true,
        ]);

        return $employee;
    }

    /** @param array<string, mixed> $query */
    private function index(User $actor, array $query = []): TestResponse
    {
        return $this->actingAs($actor)
            ->getJson(route('customer.index', $query))
            ->assertOk();
    }

    /** @return array<int, int> */
    private function ids(TestResponse $response): array
    {
        return collect($response->json('customers.data'))
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }
}
