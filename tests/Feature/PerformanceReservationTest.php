<?php

use App\Models\Customer;
use App\Models\DemoWorkspace;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Task;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Work;
use App\Services\ReservationQueueInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Carbon::setTestNow();
});

function reservationPerformanceRoleId(string $name): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $name.' role'],
    )->id;
}

function reservationPerformanceOwner(array $overrides = []): User
{
    $features = array_replace([
        'performance' => true,
        'team_members' => true,
        'reservations' => true,
        'jobs' => false,
        'tasks' => false,
        'sales' => false,
    ], (array) ($overrides['company_features'] ?? []));

    return User::query()->create(array_replace([
        'name' => 'Performance Owner',
        'email' => 'performance-data-owner-'.Str::lower(Str::random(8)).'@example.com',
        'password' => 'password',
        'role_id' => reservationPerformanceRoleId('owner'),
        'company_name' => 'Performance Company',
        'company_type' => 'services',
        'company_sector' => 'healthcare',
        'company_timezone' => 'America/Vancouver',
        'currency_code' => 'CAD',
        'onboarding_completed_at' => now(),
        'company_features' => $features,
    ], array_diff_key($overrides, ['company_features' => true])));
}

function reservationPerformanceMember(User $owner, string $name): TeamMember
{
    $user = User::query()->create([
        'name' => $name,
        'email' => Str::slug($name).'-'.Str::lower(Str::random(6)).'@example.com',
        'password' => 'password',
        'role_id' => reservationPerformanceRoleId('employee'),
        'onboarding_completed_at' => now(),
    ]);

    return TeamMember::query()->create([
        'account_id' => $owner->id,
        'user_id' => $user->id,
        'role' => 'member',
        'title' => 'Practitioner',
        'permissions' => ['reservations.view'],
        'is_active' => true,
    ]);
}

function reservationPerformanceCustomer(User $owner, string $firstName): Customer
{
    return Customer::factory()->create([
        'user_id' => $owner->id,
        'first_name' => $firstName,
        'last_name' => 'Customer',
        'company_name' => null,
        'email' => Str::slug($firstName).'-'.Str::lower(Str::random(6)).'@example.com',
    ]);
}

function reservationPerformanceProduct(User $owner, string $name, string $type = Product::ITEM_TYPE_SERVICE): Product
{
    $category = ProductCategory::query()->create([
        'name' => $name.' category '.Str::lower(Str::random(5)),
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
    ]);

    return Product::query()->create([
        'name' => $name,
        'description' => $name,
        'category_id' => $category->id,
        'user_id' => $owner->id,
        'item_type' => $type,
        'price' => 50,
        'currency_code' => 'CAD',
        'stock' => $type === Product::ITEM_TYPE_PRODUCT ? 10 : 0,
        'minimum_stock' => 0,
        'is_active' => true,
    ]);
}

function reservationPerformanceReservation(
    User $owner,
    TeamMember $member,
    Customer $customer,
    Product $service,
    string $status,
    Carbon $startsAt,
): Reservation {
    return Reservation::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $member->id,
        'client_id' => $customer->id,
        'service_id' => $service->id,
        'created_by_user_id' => $owner->id,
        'status' => $status,
        'source' => Reservation::SOURCE_STAFF,
        'timezone' => $startsAt->timezoneName,
        'starts_at' => $startsAt->copy()->utc(),
        'ends_at' => $startsAt->copy()->addHour()->utc(),
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
        'metadata' => [],
    ]);
}

test('reservation capability powers performance for a non salon with demo time and no duplicated revenue', function () {
    Carbon::setTestNow('2031-01-10 12:00:00 UTC');
    $owner = reservationPerformanceOwner();
    DemoWorkspace::query()->create([
        'owner_user_id' => $owner->id,
        'prospect_name' => 'Reference customer',
        'company_name' => $owner->company_name,
        'company_type' => 'services',
        'company_sector' => 'healthcare',
        'seed_profile' => 'small',
        'team_size' => 2,
        'locale' => 'en',
        'timezone' => 'America/Vancouver',
        'selected_modules' => ['reservations', 'performance'],
        'reference_date' => '2026-04-15',
    ]);

    $memberA = reservationPerformanceMember($owner, 'Alex Practitioner');
    $memberB = reservationPerformanceMember($owner, 'Blair Practitioner');
    $priorMember = reservationPerformanceMember($owner, 'Casey Practitioner');
    $customer = reservationPerformanceCustomer($owner, 'Completed');
    $priorCustomer = reservationPerformanceCustomer($owner, 'Paid This Month');
    $serviceA = reservationPerformanceProduct($owner, 'Assessment');
    $serviceB = reservationPerformanceProduct($owner, 'Follow-up');
    $priorService = reservationPerformanceProduct($owner, 'March Service');
    $firstStart = Carbon::parse('2026-04-10 10:00:00', 'America/Vancouver');
    $boundaryStart = Carbon::parse('2026-04-30 23:30:00', 'America/Vancouver');
    $reservationA = reservationPerformanceReservation(
        $owner,
        $memberA,
        $customer,
        $serviceA,
        Reservation::STATUS_COMPLETED,
        $firstStart,
    );
    $reservationB = reservationPerformanceReservation(
        $owner,
        $memberB,
        $customer,
        $serviceB,
        Reservation::STATUS_COMPLETED,
        $boundaryStart,
    );
    $priorReservation = reservationPerformanceReservation(
        $owner,
        $priorMember,
        $priorCustomer,
        $priorService,
        Reservation::STATUS_COMPLETED,
        Carbon::parse('2026-03-20 14:00:00', 'America/Vancouver'),
    );

    foreach ([
        Reservation::STATUS_PENDING,
        Reservation::STATUS_CONFIRMED,
        Reservation::STATUS_CANCELLED,
        Reservation::STATUS_NO_SHOW,
    ] as $index => $status) {
        reservationPerformanceReservation(
            $owner,
            $memberA,
            reservationPerformanceCustomer($owner, 'Ignored '.$index),
            $serviceA,
            $status,
            $firstStart->copy()->addDays($index + 1),
        );
    }

    $invoice = Invoice::query()->create([
        'work_id' => null,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'status' => 'paid',
        'subtotal' => 100,
        'tax_total' => 0,
        'total' => 100,
        'currency_code' => 'CAD',
        'source' => 'reservation',
    ]);
    InvoiceItem::query()->create([
        'invoice_id' => $invoice->id,
        'assigned_team_member_id' => $memberA->id,
        'title' => $serviceA->name,
        'scheduled_date' => $firstStart->toDateString(),
        'task_status' => 'completed',
        'quantity' => 1,
        'unit_price' => 60,
        'currency_code' => 'CAD',
        'total' => 60,
        'meta' => ['reservation_id' => $reservationA->id, 'service_id' => $serviceA->id],
    ]);
    InvoiceItem::query()->create([
        'invoice_id' => $invoice->id,
        'assigned_team_member_id' => $memberB->id,
        'title' => $serviceB->name,
        'scheduled_date' => $boundaryStart->toDateString(),
        'task_status' => 'completed',
        'quantity' => 1,
        'unit_price' => 40,
        'currency_code' => 'CAD',
        'total' => 40,
        'meta' => ['reservation_id' => $reservationB->id, 'service_id' => $serviceB->id],
    ]);

    $paidAt = Carbon::parse('2026-04-30 23:45:00', 'America/Vancouver')->utc();
    foreach ([[70, Payment::STATUS_COMPLETED], [30, Payment::STATUS_PAID], [900, Payment::STATUS_FAILED]] as [$amount, $status]) {
        Payment::query()->create([
            'invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'user_id' => $owner->id,
            'amount' => $amount,
            'currency_code' => 'CAD',
            'method' => 'card',
            'status' => $status,
            'paid_at' => $paidAt,
        ]);
    }
    $queueInvoice = Invoice::query()->create([
        'work_id' => null,
        'customer_id' => $priorCustomer->id,
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'status' => 'paid',
        'subtotal' => 25,
        'tax_total' => 0,
        'total' => 25,
        'currency_code' => 'CAD',
        'source' => ReservationQueueInvoiceService::SOURCE_RESERVATION_QUEUE,
    ]);
    InvoiceItem::query()->create([
        'invoice_id' => $queueInvoice->id,
        'assigned_team_member_id' => $priorMember->id,
        'title' => $priorService->name,
        'scheduled_date' => '2026-03-20',
        'task_status' => 'completed',
        'quantity' => 1,
        'unit_price' => 25,
        'currency_code' => 'CAD',
        'total' => 25,
        'meta' => [
            'reservation_id' => $priorReservation->id,
            'service' => ['id' => $priorService->id],
        ],
    ]);
    Payment::query()->create([
        'invoice_id' => $queueInvoice->id,
        'customer_id' => $priorCustomer->id,
        'user_id' => $owner->id,
        'amount' => 25,
        'currency_code' => 'CAD',
        'method' => 'card',
        'status' => Payment::STATUS_COMPLETED,
        'paid_at' => Carbon::parse('2026-04-05 09:00:00', 'America/Vancouver')->utc(),
    ]);

    $voidInvoice = Invoice::query()->create([
        'work_id' => null,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'status' => 'void',
        'subtotal' => 500,
        'tax_total' => 0,
        'total' => 500,
        'currency_code' => 'CAD',
        'source' => 'reservation',
    ]);
    Payment::query()->create([
        'invoice_id' => $voidInvoice->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'amount' => 500,
        'currency_code' => 'CAD',
        'method' => 'card',
        'status' => Payment::STATUS_COMPLETED,
        'paid_at' => $paidAt,
    ]);

    $response = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('performance.index'))
        ->assertOk()
        ->assertJsonPath('performanceMode', 'reservations')
        ->assertJsonPath('clientPerformance.periods.month.range.start', '2026-04-01')
        ->assertJsonPath('clientPerformance.periods.month.range.end', '2026-04-30')
        ->assertJsonPath('clientPerformance.periods.month.orders', 2)
        ->assertJsonPath('clientPerformance.periods.month.items_sold', 2)
        ->assertJsonPath('clientPerformance.periods.month.customers', 1)
        ->assertJsonPath('clientPerformance.periods.year.revenue', 125)
        ->assertJsonPath('employeePerformance.periods.month.active_sellers', 2);

    $payload = $response->json();
    $clientMonth = data_get($payload, 'clientPerformance.periods.month');
    $employeeMonth = data_get($payload, 'employeePerformance.periods.month');
    $cashOnlyCustomer = collect($clientMonth['top_customers'])->firstWhere('id', $priorCustomer->id);
    $cashOnlyMember = collect($employeeMonth['top_sellers'])->firstWhere('team_member_id', $priorMember->id);
    $cashOnlyService = collect($employeeMonth['top_products'])->firstWhere('id', $priorService->id);
    expect((float) $clientMonth['revenue'])->toBe(125.0)
        ->and((float) $clientMonth['avg_order'])->toBe(62.5)
        ->and((float) $clientMonth['top_customers'][0]['revenue'])->toBe(100.0)
        ->and(round((float) collect($clientMonth['top_customers'])->sum('revenue'), 2))->toBe(125.0)
        ->and(round((float) collect($employeeMonth['top_sellers'])->sum('revenue'), 2))->toBe(125.0)
        ->and(round((float) collect($employeeMonth['top_products'])->sum('revenue'), 2))->toBe(125.0)
        ->and($cashOnlyCustomer['orders'])->toBe(0)
        ->and((float) $cashOnlyCustomer['revenue'])->toBe(25.0)
        ->and($cashOnlyMember['orders'])->toBe(0)
        ->and((float) $cashOnlyMember['revenue'])->toBe(25.0)
        ->and($cashOnlyService['quantity'])->toBe(0)
        ->and((float) $cashOnlyService['revenue'])->toBe(25.0);

    $this->actingAs($memberA->user()->firstOrFail())
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('performance.employee.show', $memberA->user_id))
        ->assertOk()
        ->assertJsonPath('performanceMode', 'reservations')
        ->assertJsonPath('performance.periods.month.orders', 1)
        ->assertJsonPath('performance.periods.month.customers', 1)
        ->assertJsonPath('performance.periods.month.revenue', 60);

    $this->actingAs($priorMember->user()->firstOrFail())
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('performance.employee.show', $priorMember->user_id))
        ->assertOk()
        ->assertJsonPath('performance.periods.month.orders', 0)
        ->assertJsonPath('performance.periods.month.customers', 0)
        ->assertJsonPath('performance.periods.month.revenue', 25)
        ->assertJsonPath('performance.periods.month.top_products.0.quantity', 0)
        ->assertJsonPath('performance.periods.month.top_customers.0.orders', 0);
});

test('field service performance keeps using works and tasks when reservations are disabled', function () {
    Carbon::setTestNow('2026-07-15 12:00:00 America/Toronto');
    $owner = reservationPerformanceOwner([
        'company_sector' => 'field_services',
        'company_timezone' => 'America/Toronto',
        'company_features' => [
            'reservations' => false,
            'jobs' => true,
            'tasks' => true,
        ],
    ]);
    $member = reservationPerformanceMember($owner, 'Field Technician');
    $customer = reservationPerformanceCustomer($owner, 'Field');
    $work = Work::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'job_title' => 'Inspection',
        'instructions' => 'Inspect equipment.',
        'start_date' => '2026-07-10',
        'end_date' => '2026-07-10',
        'start_time' => '09:00:00',
        'end_time' => '10:00:00',
        'status' => Work::STATUS_COMPLETED,
        'subtotal' => 175,
        'total' => 175,
    ]);
    $work->teamMembers()->attach($member->id, ['role' => 'technician']);
    Task::query()->create([
        'account_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'assigned_team_member_id' => $member->id,
        'customer_id' => $customer->id,
        'work_id' => $work->id,
        'title' => 'Send report',
        'status' => Task::STATUS_DONE,
        'due_date' => '2026-07-11',
    ]);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('performance.index'))
        ->assertOk()
        ->assertJsonPath('performanceMode', 'services')
        ->assertJsonPath('clientPerformance.periods.month.orders', 1)
        ->assertJsonPath('clientPerformance.periods.month.revenue', 175)
        ->assertJsonPath('clientPerformance.periods.month.items_sold', 1)
        ->assertJsonPath('employeePerformance.periods.month.active_sellers', 1)
        ->assertJsonPath('employeePerformance.periods.month.top_sellers.0.team_member_id', $member->id);
});

test('product performance keeps using paid sales when reservations are disabled', function () {
    Carbon::setTestNow('2026-07-15 12:00:00 America/Toronto');
    $owner = reservationPerformanceOwner([
        'company_type' => 'products',
        'company_sector' => 'retail',
        'company_timezone' => 'America/Toronto',
        'company_features' => [
            'reservations' => false,
            'sales' => true,
        ],
    ]);
    $seller = reservationPerformanceMember($owner, 'Retail Seller');
    $customer = reservationPerformanceCustomer($owner, 'Retail');
    $product = reservationPerformanceProduct($owner, 'Retail Product', Product::ITEM_TYPE_PRODUCT);
    $sale = Sale::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $seller->user_id,
        'customer_id' => $customer->id,
        'status' => Sale::STATUS_PAID,
        'subtotal' => 80,
        'tax_total' => 0,
        'total' => 80,
        'currency_code' => 'CAD',
        'paid_at' => now(),
    ]);
    SaleItem::query()->create([
        'sale_id' => $sale->id,
        'product_id' => $product->id,
        'description' => $product->name,
        'quantity' => 2,
        'price' => 40,
        'currency_code' => 'CAD',
        'total' => 80,
    ]);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('performance.index'))
        ->assertOk()
        ->assertJsonPath('performanceMode', 'products')
        ->assertJsonPath('clientPerformance.periods.month.orders', 1)
        ->assertJsonPath('clientPerformance.periods.month.revenue', 80)
        ->assertJsonPath('clientPerformance.periods.month.items_sold', 2)
        ->assertJsonPath('employeePerformance.periods.month.active_sellers', 1)
        ->assertJsonPath('employeePerformance.periods.month.top_sellers.0.id', $seller->user_id);
});

test('sales capability powers product performance for a service classified company without operational services', function () {
    Carbon::setTestNow('2026-07-15 12:00:00 America/Toronto');
    $owner = reservationPerformanceOwner([
        'company_type' => 'services',
        'company_sector' => 'professional',
        'company_timezone' => 'America/Toronto',
        'company_features' => [
            'reservations' => false,
            'jobs' => false,
            'tasks' => false,
            'sales' => true,
        ],
    ]);
    $seller = reservationPerformanceMember($owner, 'Consulting Seller');
    $customer = reservationPerformanceCustomer($owner, 'Consulting');
    $product = reservationPerformanceProduct($owner, 'Consulting Product', Product::ITEM_TYPE_PRODUCT);
    $sale = Sale::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $seller->user_id,
        'customer_id' => $customer->id,
        'status' => Sale::STATUS_PAID,
        'subtotal' => 55,
        'tax_total' => 0,
        'total' => 55,
        'currency_code' => 'CAD',
        'paid_at' => now(),
    ]);
    SaleItem::query()->create([
        'sale_id' => $sale->id,
        'product_id' => $product->id,
        'description' => $product->name,
        'quantity' => 1,
        'price' => 55,
        'currency_code' => 'CAD',
        'total' => 55,
    ]);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('performance.index'))
        ->assertOk()
        ->assertJsonPath('performanceMode', 'products')
        ->assertJsonPath('clientPerformance.periods.month.orders', 1)
        ->assertJsonPath('clientPerformance.periods.month.revenue', 55)
        ->assertJsonPath('employeePerformance.periods.month.top_sellers.0.id', $seller->user_id);
});

test('jobs capability powers service performance for a product classified company', function () {
    Carbon::setTestNow('2026-07-15 12:00:00 America/Toronto');
    $owner = reservationPerformanceOwner([
        'company_type' => 'products',
        'company_sector' => 'retail',
        'company_timezone' => 'America/Toronto',
        'company_features' => [
            'reservations' => false,
            'jobs' => true,
            'tasks' => false,
            'sales' => true,
        ],
    ]);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('performance.index'))
        ->assertOk()
        ->assertJsonPath('performanceMode', 'services')
        ->assertJsonPath('clientPerformance.periods.month.orders', 0);
});

test('reservation capability takes priority over the product company classification', function () {
    Carbon::setTestNow('2026-07-15 12:00:00 America/Toronto');
    $owner = reservationPerformanceOwner([
        'company_type' => 'products',
        'company_sector' => 'retail',
        'company_timezone' => 'America/Toronto',
        'company_features' => [
            'reservations' => true,
            'sales' => true,
        ],
    ]);
    $member = reservationPerformanceMember($owner, 'Retail Practitioner');
    $customer = reservationPerformanceCustomer($owner, 'Booked');
    $service = reservationPerformanceProduct($owner, 'Retail Consultation');
    reservationPerformanceReservation(
        $owner,
        $member,
        $customer,
        $service,
        Reservation::STATUS_COMPLETED,
        Carbon::parse('2026-07-10 10:00:00', 'America/Toronto'),
    );

    $product = reservationPerformanceProduct($owner, 'Retail Product', Product::ITEM_TYPE_PRODUCT);
    $sale = Sale::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $member->user_id,
        'customer_id' => $customer->id,
        'status' => Sale::STATUS_PAID,
        'subtotal' => 80,
        'tax_total' => 0,
        'total' => 80,
        'currency_code' => 'CAD',
        'paid_at' => now(),
    ]);
    SaleItem::query()->create([
        'sale_id' => $sale->id,
        'product_id' => $product->id,
        'description' => $product->name,
        'quantity' => 2,
        'price' => 40,
        'currency_code' => 'CAD',
        'total' => 80,
    ]);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('performance.index'))
        ->assertOk()
        ->assertJsonPath('performanceMode', 'reservations')
        ->assertJsonPath('clientPerformance.periods.month.orders', 1)
        ->assertJsonPath('clientPerformance.periods.month.revenue', 0)
        ->assertJsonPath('clientPerformance.periods.month.items_sold', 1)
        ->assertJsonPath('employeePerformance.periods.month.active_sellers', 1)
        ->assertJsonPath('employeePerformance.periods.month.top_sellers.0.team_member_id', $member->id);
});
