<?php

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductInventory;
use App\Models\Reservation;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\Work;
use App\Notifications\DemoActionNotification;
use App\Services\Demo\DemoScenarioInvariantValidator;
use App\Services\Demo\DemoScenarioInvariantViolationException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

function createInvariantCustomer(User $owner, CarbonImmutable $createdAt, string $suffix): Customer
{
    $customer = Customer::query()->create([
        'user_id' => $owner->id,
        'first_name' => 'Demo',
        'last_name' => "Customer {$suffix}",
        'email' => "demo-customer-{$suffix}@example.test",
    ]);
    setInvariantTimestamps($customer, $createdAt);

    return $customer;
}

function setInvariantTimestamps(Model $model, CarbonImmutable $createdAt): void
{
    DB::table($model->getTable())
        ->where($model->getKeyName(), $model->getKey())
        ->update([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    $model->refresh();
}

function createInvariantWork(User $owner, Customer $customer, string $title): Work
{
    return Work::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'job_title' => $title,
        'instructions' => 'Scenario invariant test work.',
    ]);
}

function createInvariantInvoice(
    User $owner,
    Customer $customer,
    string $status,
    float $total,
    CarbonImmutable $createdAt
): Invoice {
    $invoice = Invoice::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'work_id' => createInvariantWork($owner, $customer, "{$status} invoice work")->id,
        'status' => $status,
        'total' => $total,
    ]);
    setInvariantTimestamps($invoice, $createdAt);

    return $invoice;
}

test('it returns a serializable valid report for a coherent twelve month scenario', function () {
    $reference = CarbonImmutable::parse('2026-08-20 12:00:00', 'UTC');
    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_timezone' => 'UTC',
    ]);
    $customers = collect(range(0, 11))->map(function (int $offset) use ($owner, $reference): Customer {
        $createdAt = $reference->startOfMonth()->subMonths(11)->addMonths($offset)->addDays(4)->setTime(9, 0);

        return createInvariantCustomer($owner, $createdAt, (string) $offset);
    });
    $customer = $customers->first();
    $teamMember = TeamMember::query()->create([
        'account_id' => $owner->id,
        'user_id' => $owner->id,
        'role' => 'member',
        'title' => 'Stylist',
        'permissions' => [],
        'is_active' => true,
    ]);

    $completed = Reservation::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $teamMember->id,
        'client_id' => $customer->id,
        'status' => Reservation::STATUS_COMPLETED,
        'source' => Reservation::SOURCE_STAFF,
        'timezone' => 'UTC',
        'starts_at' => '2026-06-10 10:00:00',
        'ends_at' => '2026-06-10 11:00:00',
        'duration_minutes' => 60,
        'buffer_minutes' => 10,
        'created_by_user_id' => $owner->id,
    ]);
    setInvariantTimestamps($completed, CarbonImmutable::parse('2026-06-01 09:00:00', 'UTC'));

    $future = Reservation::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $teamMember->id,
        'client_id' => $customer->id,
        'status' => Reservation::STATUS_CONFIRMED,
        'source' => Reservation::SOURCE_STAFF,
        'timezone' => 'UTC',
        'starts_at' => '2026-08-22 10:00:00',
        'ends_at' => '2026-08-22 11:00:00',
        'duration_minutes' => 60,
        'buffer_minutes' => 10,
        'created_by_user_id' => $owner->id,
    ]);
    setInvariantTimestamps($future, CarbonImmutable::parse('2026-08-01 09:00:00', 'UTC'));

    $paidInvoice = createInvariantInvoice(
        $owner,
        $customer,
        'paid',
        100,
        CarbonImmutable::parse('2026-06-11 09:00:00', 'UTC')
    );
    $paidPayment = Payment::query()->create([
        'invoice_id' => $paidInvoice->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'amount' => 100,
        'status' => Payment::STATUS_COMPLETED,
        'method' => 'cash',
        'paid_at' => '2026-06-11 10:00:00',
    ]);
    setInvariantTimestamps($paidPayment, CarbonImmutable::parse('2026-06-11 09:30:00', 'UTC'));

    $partialInvoice = createInvariantInvoice(
        $owner,
        $customer,
        'partial',
        100,
        CarbonImmutable::parse('2026-07-05 09:00:00', 'UTC')
    );
    $partialPayment = Payment::query()->create([
        'invoice_id' => $partialInvoice->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'amount' => 40,
        'status' => Payment::STATUS_PAID,
        'method' => 'card',
        'paid_at' => '2026-07-06 10:00:00',
    ]);
    setInvariantTimestamps($partialPayment, CarbonImmutable::parse('2026-07-06 09:30:00', 'UTC'));

    $category = ProductCategory::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'name' => 'Retail',
    ]);
    $product = Product::query()->create([
        'user_id' => $owner->id,
        'category_id' => $category->id,
        'name' => 'Shampoo',
        'item_type' => Product::ITEM_TYPE_PRODUCT,
        'tracking_type' => 'stock',
        'price' => 20,
        'stock' => 5,
        'minimum_stock' => 2,
    ]);
    setInvariantTimestamps($product, $reference->subDays(2));
    $warehouse = Warehouse::query()->create([
        'user_id' => $owner->id,
        'name' => 'Salon stock',
        'code' => 'SALON',
        'is_default' => true,
        'is_active' => true,
    ]);
    ProductInventory::query()->create([
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'on_hand' => 5,
        'reserved' => 0,
        'damaged' => 0,
        'minimum_stock' => 2,
        'reorder_point' => 2,
    ]);

    $validator = app(DemoScenarioInvariantValidator::class);
    $report = $validator->validateOrFail($owner, $reference);

    expect($report['valid'])->toBeTrue()
        ->and($report['summary']['violation_count'])->toBe(0)
        ->and($report['checks']['twelve_month_coverage']['covered_month_count'])->toBe(12)
        ->and(json_decode(json_encode($report, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR))
        ->toBeArray();
});

test('it reports domain violations and exposes the same report from validate or fail', function () {
    $reference = CarbonImmutable::parse('2026-08-20 12:00:00', 'UTC');
    $owner = User::factory()->create(['company_timezone' => 'UTC']);
    $foreignOwner = User::factory()->create(['company_timezone' => 'UTC']);
    $customer = createInvariantCustomer($owner, CarbonImmutable::parse('2026-08-01', 'UTC'), 'owner');
    $foreignCustomer = createInvariantCustomer($foreignOwner, CarbonImmutable::parse('2026-08-01', 'UTC'), 'foreign');
    $teamMember = TeamMember::query()->create([
        'account_id' => $owner->id,
        'user_id' => $owner->id,
        'role' => 'member',
        'title' => 'Stylist',
        'permissions' => [],
        'is_active' => true,
    ]);

    $futureCompleted = Reservation::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $teamMember->id,
        'client_id' => $foreignCustomer->id,
        'status' => Reservation::STATUS_COMPLETED,
        'source' => Reservation::SOURCE_STAFF,
        'timezone' => 'UTC',
        'starts_at' => '2026-08-22 10:00:00',
        'ends_at' => '2026-08-22 11:00:00',
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
    ]);
    setInvariantTimestamps($futureCompleted, CarbonImmutable::parse('2026-08-01', 'UTC'));

    foreach ([['10:00:00', '11:00:00'], ['10:30:00', '11:30:00']] as [$start, $end]) {
        $overlap = Reservation::query()->create([
            'account_id' => $owner->id,
            'team_member_id' => $teamMember->id,
            'client_id' => $customer->id,
            'status' => Reservation::STATUS_CONFIRMED,
            'source' => Reservation::SOURCE_STAFF,
            'timezone' => 'UTC',
            'starts_at' => "2026-08-23 {$start}",
            'ends_at' => "2026-08-23 {$end}",
            'duration_minutes' => 60,
            'buffer_minutes' => 0,
        ]);
        setInvariantTimestamps($overlap, CarbonImmutable::parse('2026-08-01', 'UTC'));
    }

    $invalidRange = Reservation::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $teamMember->id,
        'client_id' => $customer->id,
        'status' => Reservation::STATUS_CANCELLED,
        'source' => Reservation::SOURCE_STAFF,
        'timezone' => 'UTC',
        'starts_at' => '2026-08-10 11:00:00',
        'ends_at' => '2026-08-10 10:00:00',
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
    ]);
    setInvariantTimestamps($invalidRange, CarbonImmutable::parse('2026-08-01', 'UTC'));

    createInvariantInvoice(
        $owner,
        $customer,
        'paid',
        100,
        CarbonImmutable::parse('2026-08-02', 'UTC')
    );
    $partialInvoice = createInvariantInvoice(
        $owner,
        $customer,
        'partial',
        100,
        CarbonImmutable::parse('2026-08-03', 'UTC')
    );
    $foreignPayment = Payment::query()->create([
        'invoice_id' => $partialInvoice->id,
        'customer_id' => $customer->id,
        'user_id' => $foreignOwner->id,
        'amount' => 40,
        'status' => Payment::STATUS_PENDING,
        'method' => 'cash',
    ]);
    setInvariantTimestamps($foreignPayment, CarbonImmutable::parse('2026-08-04', 'UTC'));

    $category = ProductCategory::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'name' => 'Invalid stock',
    ]);
    $product = Product::query()->create([
        'user_id' => $owner->id,
        'category_id' => $category->id,
        'name' => 'Negative product',
        'item_type' => Product::ITEM_TYPE_PRODUCT,
        'tracking_type' => 'stock',
        'price' => 20,
        'stock' => -1,
        'minimum_stock' => 2,
    ]);
    $service = Product::query()->create([
        'user_id' => $owner->id,
        'category_id' => $category->id,
        'name' => 'Timed service',
        'item_type' => Product::ITEM_TYPE_SERVICE,
        'tracking_type' => 'none',
        'price' => 75,
        'stock' => 0,
        'minimum_stock' => 0,
        'tags' => ['key:timed_service', 'duration:90', 'buffer-after:15'],
    ]);
    $timingMismatch = Reservation::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $teamMember->id,
        'client_id' => $customer->id,
        'service_id' => $service->id,
        'status' => Reservation::STATUS_CANCELLED,
        'source' => Reservation::SOURCE_STAFF,
        'timezone' => 'UTC',
        'starts_at' => '2026-08-09 10:00:00',
        'ends_at' => '2026-08-09 11:00:00',
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
        'metadata' => ['service_key' => 'wrong_service'],
    ]);
    setInvariantTimestamps($timingMismatch, CarbonImmutable::parse('2026-08-01', 'UTC'));
    $warehouse = Warehouse::query()->create([
        'user_id' => $owner->id,
        'name' => 'Invalid warehouse',
        'code' => 'INVALID',
        'is_default' => true,
        'is_active' => true,
    ]);
    ProductInventory::query()->create([
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'on_hand' => -2,
        'reserved' => 1,
        'damaged' => 0,
        'minimum_stock' => 2,
        'reorder_point' => 2,
    ]);

    $validator = app(DemoScenarioInvariantValidator::class);
    $report = $validator->validate($owner, $reference);
    $codes = collect($report['violations'])->pluck('code');

    expect($report['valid'])->toBeFalse()
        ->and($codes)->toContain(
            'reservation.client_tenant_mismatch',
            'reservation.completed_not_past',
            'reservation.future_completed',
            'reservation.active_overlap',
            'invoice.paid_balance_non_zero',
            'invoice.partial_payment_or_balance_invalid',
            'payment.account_mismatch',
            'product.stock_negative',
            'inventory.on_hand_negative',
            'inventory.available_negative',
            'reservation.invalid_date_range',
            'reservation.service_timing_mismatch',
            'scenario.missing_monthly_coverage'
        );

    try {
        $validator->validateOrFail($owner, $reference);
        $this->fail('Expected scenario validation to fail.');
    } catch (DemoScenarioInvariantViolationException $exception) {
        expect($exception->report())->toBe($report)
            ->and($exception->jsonSerialize())->toBe($report);
    }
});

test('it rejects duplicate customer names in a demo scenario', function () {
    $reference = CarbonImmutable::parse('2026-08-20 12:00:00', 'UTC');
    $owner = User::factory()->create(['company_timezone' => 'UTC']);
    $firstCustomer = createInvariantCustomer($owner, $reference->subMonth(), 'duplicate-a');
    $secondCustomer = createInvariantCustomer($owner, $reference->subDays(10), 'duplicate-b');

    foreach ([$firstCustomer, $secondCustomer] as $customer) {
        $customer->forceFill([
            'first_name' => 'Laurence',
            'last_name' => 'Bélanger',
        ])->save();
    }

    $report = app(DemoScenarioInvariantValidator::class)->validate($owner, $reference);
    $nameCheck = $report['checks']['customer_names_are_unique'];

    expect($report['valid'])->toBeFalse()
        ->and($report['summary']['failed_checks'])->toContain('customer_names_are_unique')
        ->and($nameCheck['valid'])->toBeFalse()
        ->and($nameCheck['duplicate_group_count'])->toBe(1)
        ->and($nameCheck['violations'])->toHaveCount(1)
        ->and($nameCheck['violations'][0]['code'])->toBe('customer.name_duplicate')
        ->and($nameCheck['violations'][0]['context']['name'])->toBe('Laurence Bélanger')
        ->and($nameCheck['violations'][0]['context']['customer_ids'])->toBe([
            $firstCustomer->id,
            $secondCustomer->id,
        ]);
});

test('demo action notification is stored with actionable center payload only', function () {
    $owner = User::factory()->create();

    $owner->notify(new DemoActionNotification([
        'title' => 'Invoice overdue',
        'message' => 'Invoice INV-100 is overdue.',
        'action_url' => '/invoices/100',
        'type' => 'billing',
        'severity' => 'warning',
        'reference' => ['type' => 'invoice', 'id' => 100],
    ]));

    $notification = $owner->notifications()->firstOrFail();

    expect($notification->data)->toMatchArray([
        'title' => 'Invoice overdue',
        'message' => 'Invoice INV-100 is overdue.',
        'action_url' => '/invoices/100',
        'type' => 'billing',
        'category' => 'billing',
        'severity' => 'warning',
        'reference' => ['type' => 'invoice', 'id' => 100],
    ]);
});
