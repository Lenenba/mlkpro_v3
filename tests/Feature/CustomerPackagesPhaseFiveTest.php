<?php

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerPackage;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\OfferPackage;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Notifications\CampaignInAppNotification;
use App\Services\OfferPackages\CustomerPackageService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

function customerPackagesPhaseFiveOwner(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'company_type' => 'services',
        'company_timezone' => 'UTC',
        'company_features' => [
            'customers' => true,
            'quotes' => true,
            'invoices' => true,
            'products' => true,
            'services' => true,
        ],
    ], $overrides));
}

function customerPackagesPhaseFiveProduct(User $owner, array $overrides = []): Product
{
    $category = ProductCategory::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'name' => 'Phase 5 catalog',
    ]);

    return Product::query()->create(array_merge([
        'user_id' => $owner->id,
        'category_id' => $category->id,
        'name' => 'Recurring service',
        'description' => 'Service included in a recurring forfait',
        'price' => 90,
        'currency_code' => 'CAD',
        'stock' => 0,
        'minimum_stock' => 0,
        'item_type' => Product::ITEM_TYPE_SERVICE,
        'is_active' => true,
    ], $overrides));
}

function customerPackagesPhaseFiveOffer(User $owner, Product $product, array $overrides = []): OfferPackage
{
    $offer = OfferPackage::query()->create(array_merge([
        'user_id' => $owner->id,
        'name' => 'Recurring monthly forfait',
        'type' => OfferPackage::TYPE_FORFAIT,
        'status' => OfferPackage::STATUS_ACTIVE,
        'description' => 'Monthly recurring balance',
        'price' => 360,
        'currency_code' => 'CAD',
        'validity_days' => null,
        'included_quantity' => 4,
        'unit_type' => OfferPackage::UNIT_SESSION,
        'is_public' => true,
        'is_recurring' => true,
        'recurrence_frequency' => OfferPackage::RECURRENCE_MONTHLY,
        'renewal_notice_days' => 7,
    ], $overrides));

    $offer->items()->create([
        'product_id' => $product->id,
        'item_type_snapshot' => $product->item_type,
        'name_snapshot' => $product->name,
        'description_snapshot' => $product->description,
        'quantity' => 4,
        'unit_price' => 90,
        'included' => true,
        'is_optional' => false,
        'sort_order' => 0,
    ]);

    return $offer->fresh('items');
}

it('creates and assigns a recurring forfait with a first renewal cycle', function () {
    Carbon::setTestNow(Carbon::parse('2026-05-11 09:00:00', 'UTC'));

    $owner = customerPackagesPhaseFiveOwner();
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $product = customerPackagesPhaseFiveProduct($owner);

    $this->actingAs($owner)
        ->postJson(route('offer-packages.store'), [
            'name' => 'Monthly beauty plan',
            'type' => OfferPackage::TYPE_FORFAIT,
            'status' => OfferPackage::STATUS_ACTIVE,
            'description' => 'A recurring forfait',
            'price' => 360,
            'currency_code' => 'CAD',
            'included_quantity' => 4,
            'unit_type' => OfferPackage::UNIT_SESSION,
            'is_public' => true,
            'is_recurring' => true,
            'recurrence_frequency' => OfferPackage::RECURRENCE_MONTHLY,
            'renewal_notice_days' => 5,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 4,
                'unit_price' => 90,
                'is_optional' => false,
            ]],
        ])
        ->assertCreated()
        ->assertJsonPath('offer.is_recurring', true)
        ->assertJsonPath('offer.recurrence_frequency', OfferPackage::RECURRENCE_MONTHLY);

    $offer = OfferPackage::query()->where('name', 'Monthly beauty plan')->firstOrFail();

    $this->actingAs($owner)
        ->postJson(route('customer.packages.store', $customer), [
            'offer_package_id' => $offer->id,
            'starts_at' => '2026-05-11',
        ])
        ->assertCreated()
        ->assertJsonPath('customerPackage.is_recurring', true)
        ->assertJsonPath('customerPackage.next_renewal_at', '2026-06-11T00:00:00.000000Z');

    $package = CustomerPackage::query()->firstOrFail();

    expect($package->is_recurring)->toBeTrue()
        ->and($package->recurrence_frequency)->toBe(OfferPackage::RECURRENCE_MONTHLY)
        ->and($package->current_period_starts_at->toDateString())->toBe('2026-05-11')
        ->and($package->current_period_ends_at->toDateString())->toBe('2026-06-10')
        ->and($package->next_renewal_at->toDateString())->toBe('2026-06-11')
        ->and($package->expires_at->toDateString())->toBe('2026-06-10')
        ->and(data_get($package->source_details, 'recurrence.frequency'))->toBe(OfferPackage::RECURRENCE_MONTHLY);

    Carbon::setTestNow();
});

it('renews a recurring customer forfait and closes the previous period', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-01 09:00:00', 'UTC'));

    $owner = customerPackagesPhaseFiveOwner();
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $product = customerPackagesPhaseFiveProduct($owner);
    $offer = customerPackagesPhaseFiveOffer($owner, $product);

    $package = app(CustomerPackageService::class)->assign($owner, $customer, $offer, [
        'starts_at' => '2026-05-01',
    ]);

    $this->actingAs($owner)
        ->postJson(route('customer.packages.renew', [$customer, $package]), [
            'starts_at' => '2026-06-01',
            'initial_quantity' => 4,
            'price_paid' => 360,
            'note' => 'June renewal',
        ])
        ->assertCreated()
        ->assertJsonPath('customerPackage.renewed_from_customer_package_id', $package->id)
        ->assertJsonPath('customerPackage.current_period_ends_at', '2026-06-30T00:00:00.000000Z')
        ->assertJsonPath('customerPackage.next_renewal_at', '2026-07-01T00:00:00.000000Z');

    $renewed = CustomerPackage::query()
        ->where('renewed_from_customer_package_id', $package->id)
        ->firstOrFail();

    expect($package->fresh()->status)->toBe(CustomerPackage::STATUS_EXPIRED)
        ->and($renewed->status)->toBe(CustomerPackage::STATUS_ACTIVE)
        ->and($renewed->renewal_count)->toBe(1)
        ->and($renewed->remaining_quantity)->toBe(4)
        ->and(data_get($renewed->metadata, 'note'))->toBe('June renewal')
        ->and(ActivityLog::query()->where('action', 'customer_package_renewed')->exists())->toBeTrue();

    $this->actingAs($owner)
        ->get(route('customer.show', $customer))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Customer/Show')
            ->where('customerPackages.0.id', $renewed->id)
            ->where('customerPackages.0.is_recurring', true)
            ->where('customerPackages.0.next_renewal_at', '2026-07-01T00:00:00.000000Z'));

    Carbon::setTestNow();
});

it('prepares recurring renewal reminders from automation', function () {
    Notification::fake();
    Carbon::setTestNow(Carbon::parse('2026-05-11 08:00:00', 'UTC'));

    $owner = customerPackagesPhaseFiveOwner();
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $product = customerPackagesPhaseFiveProduct($owner);
    $offer = customerPackagesPhaseFiveOffer($owner, $product);

    $package = app(CustomerPackageService::class)->assign($owner, $customer, $offer, [
        'starts_at' => '2026-04-15',
        'expires_at' => '2026-06-30',
    ]);

    $this->artisan('offer-packages:automation --date=2026-05-11')
        ->expectsOutput('Offer package automation: expired 0, low balance alerts 0, marketing reminders 0, renewal reminders 1, renewal invoices 0.')
        ->assertExitCode(0);

    expect(data_get($package->fresh()->metadata, 'automation.notifications.renewal_due_sent_at.sent_at'))->not->toBeNull()
        ->and(ActivityLog::query()->where('action', 'customer_package_renewal_due')->exists())->toBeTrue();

    Notification::assertSentTo($owner, CampaignInAppNotification::class);

    Carbon::setTestNow();
});

it('creates an idempotent renewal invoice for a recurring forfait', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-01 08:00:00', 'UTC'));

    $owner = customerPackagesPhaseFiveOwner();
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $product = customerPackagesPhaseFiveProduct($owner);
    $offer = customerPackagesPhaseFiveOffer($owner, $product);

    $package = app(CustomerPackageService::class)->assign($owner, $customer, $offer, [
        'starts_at' => '2026-05-01',
    ]);

    $this->actingAs($owner)
        ->postJson(route('customer.packages.renewal-invoice', [$customer, $package]))
        ->assertCreated()
        ->assertJsonPath('invoice.customer_id', $customer->id)
        ->assertJsonPath('invoice.status', 'sent')
        ->assertJsonPath('invoice.total', '360.00');

    $package->refresh();
    $invoice = Invoice::query()->firstOrFail();
    $item = InvoiceItem::query()->firstOrFail();

    expect($invoice->total)->toEqual('360.00')
        ->and($item->invoice_id)->toBe($invoice->id)
        ->and(data_get($item->meta, 'source'))->toBe('customer_package_renewal')
        ->and(data_get($item->meta, 'renewal_for_customer_package_id'))->toBe($package->id)
        ->and($package->recurrence_status)->toBe(CustomerPackage::RECURRENCE_PAYMENT_DUE)
        ->and(data_get($package->metadata, 'recurrence.pending_invoice_id'))->toBe($invoice->id)
        ->and(ActivityLog::query()->where('action', 'customer_package_renewal_invoice_created')->exists())->toBeTrue();

    $this->actingAs($owner)
        ->postJson(route('customer.packages.renewal-invoice', [$customer, $package]))
        ->assertCreated()
        ->assertJsonPath('invoice.id', $invoice->id);

    expect(Invoice::query()->count())->toBe(1)
        ->and(InvoiceItem::query()->count())->toBe(1);

    $this->actingAs($owner)
        ->get(route('customer.show', $customer))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Customer/Show')
            ->where('customerPackages.0.renewal_invoice.id', $invoice->id)
            ->where('customerPackages.0.renewal_invoice.status', 'sent'));

    Carbon::setTestNow();
});

it('creates due renewal invoices from automation', function () {
    Notification::fake();
    Carbon::setTestNow(Carbon::parse('2026-06-01 08:00:00', 'UTC'));

    $owner = customerPackagesPhaseFiveOwner();
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $product = customerPackagesPhaseFiveProduct($owner);
    $offer = customerPackagesPhaseFiveOffer($owner, $product);

    $package = app(CustomerPackageService::class)->assign($owner, $customer, $offer, [
        'starts_at' => '2026-05-01',
    ]);

    $this->artisan('offer-packages:automation --date=2026-06-01')
        ->expectsOutput('Offer package automation: expired 0, low balance alerts 0, marketing reminders 0, renewal reminders 1, renewal invoices 1.')
        ->assertExitCode(0);

    expect(Invoice::query()->count())->toBe(1)
        ->and(data_get($package->fresh()->metadata, 'recurrence.pending_invoice_id'))->toBe(Invoice::query()->value('id'));

    Carbon::setTestNow();
});
