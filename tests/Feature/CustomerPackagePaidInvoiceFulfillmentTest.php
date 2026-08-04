<?php

use App\Actions\Invoices\CreateInvoicePaymentAction;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerPackage;
use App\Models\Invoice;
use App\Models\OfferPackage;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Models\Work;
use App\Services\OfferPackages\CustomerPackageService;
use App\Services\OfferPackages\OfferPackageSalesLineBuilder;
use Illuminate\Support\Carbon;

function paidInvoiceFulfillmentOwner(): User
{
    return User::factory()->create([
        'company_type' => 'services',
        'company_timezone' => 'UTC',
        'company_features' => [
            'customers' => true,
            'invoices' => true,
            'products' => true,
            'services' => true,
        ],
    ]);
}

function paidInvoiceFulfillmentProduct(User $owner): Product
{
    $category = ProductCategory::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'name' => 'Paid forfait catalog',
    ]);

    return Product::query()->create([
        'user_id' => $owner->id,
        'category_id' => $category->id,
        'name' => 'Forfait session',
        'description' => 'Session included with a forfait',
        'price' => 70,
        'currency_code' => 'CAD',
        'stock' => 0,
        'minimum_stock' => 0,
        'item_type' => Product::ITEM_TYPE_SERVICE,
        'is_active' => true,
    ]);
}

function paidInvoiceFulfillmentOffer(User $owner, Product $product, array $overrides = []): OfferPackage
{
    $offer = OfferPackage::query()->create(array_merge([
        'user_id' => $owner->id,
        'name' => 'Forfait five sessions',
        'type' => OfferPackage::TYPE_FORFAIT,
        'status' => OfferPackage::STATUS_ACTIVE,
        'description' => 'Five prepaid sessions',
        'price' => 350,
        'currency_code' => 'CAD',
        'validity_days' => 30,
        'included_quantity' => 5,
        'unit_type' => OfferPackage::UNIT_SESSION,
        'is_public' => true,
        'is_recurring' => false,
    ], $overrides));

    $offer->items()->create([
        'product_id' => $product->id,
        'item_type_snapshot' => $product->item_type,
        'name_snapshot' => $product->name,
        'description_snapshot' => $product->description,
        'quantity' => 5,
        'unit_price' => 70,
        'included' => true,
        'is_optional' => false,
        'sort_order' => 0,
    ]);

    return $offer->fresh('items');
}

function paidInvoiceFulfillmentInvoice(User $owner, Customer $customer, OfferPackage $offer, int $quantity = 1): Invoice
{
    $attributes = app(OfferPackageSalesLineBuilder::class)->invoiceItemAttributes($offer, $quantity);
    $work = Work::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'job_title' => 'Forfait payment job',
        'instructions' => 'Invoice for a paid forfait',
        'subtotal' => $attributes['total'],
        'total' => $attributes['total'],
    ]);
    $invoice = Invoice::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'created_by_user_id' => $owner->id,
        'work_id' => $work->id,
        'status' => 'sent',
        'total' => $attributes['total'],
        'currency_code' => 'CAD',
    ]);

    $invoice->items()->create($attributes);

    return $invoice->fresh(['items', 'customer']);
}

it('provisions forfait rights only after the whole invoice is paid and keeps the paid snapshot', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-04 10:30:00', 'UTC'));

    $owner = paidInvoiceFulfillmentOwner();
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $product = paidInvoiceFulfillmentProduct($owner);
    $offer = paidInvoiceFulfillmentOffer($owner, $product);
    $invoice = paidInvoiceFulfillmentInvoice($owner, $customer, $offer, 2);

    app(CreateInvoicePaymentAction::class)->execute($invoice, ['amount' => 350], 'card', $owner);

    expect($invoice->fresh()->status)->toBe('partial')
        ->and(CustomerPackage::query()->count())->toBe(0);

    $offer->update([
        'status' => OfferPackage::STATUS_ARCHIVED,
        'included_quantity' => 99,
        'validity_days' => 1,
    ]);

    app(CreateInvoicePaymentAction::class)->execute($invoice, ['amount' => 350], 'card', $owner);

    $package = CustomerPackage::query()->firstOrFail();

    expect($invoice->fresh()->status)->toBe('paid')
        ->and($package->initial_quantity)->toBe(10)
        ->and($package->remaining_quantity)->toBe(10)
        ->and($package->price_paid)->toEqual('700.00')
        ->and($package->currency_code)->toBe('CAD')
        ->and($package->starts_at->toDateString())->toBe('2026-08-04')
        ->and($package->expires_at->toDateString())->toBe('2026-09-03')
        ->and(data_get($package->source_details, 'offer_package.included_quantity'))->toBe(5)
        ->and(data_get($package->metadata, 'provisioning.line_quantity'))->toBe(2)
        ->and(data_get($package->metadata, 'provisioning.rights_per_unit'))->toBe(5)
        ->and(data_get($package->metadata, 'provisioning.allocated_quantity'))->toBe(10)
        ->and(ActivityLog::query()->where('action', 'customer_package_provisioned_from_paid_invoice')->count())->toBe(1);

    app(CustomerPackageService::class)->fulfillPaidInvoice($invoice->fresh(), $owner);

    expect(CustomerPackage::query()->count())->toBe(1)
        ->and(ActivityLog::query()->where('action', 'customer_package_provisioned_from_paid_invoice')->count())->toBe(1);

    Carbon::setTestNow();
});

it('never provisions customer rights for a paid pack', function () {
    $owner = paidInvoiceFulfillmentOwner();
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $product = paidInvoiceFulfillmentProduct($owner);
    $offer = paidInvoiceFulfillmentOffer($owner, $product, [
        'name' => 'Commercial pack',
        'type' => OfferPackage::TYPE_PACK,
        'included_quantity' => null,
        'unit_type' => null,
        'validity_days' => null,
    ]);
    $invoice = paidInvoiceFulfillmentInvoice($owner, $customer, $offer);

    app(CreateInvoicePaymentAction::class)->execute($invoice, ['amount' => 350], 'card', $owner);

    expect($invoice->fresh()->status)->toBe('paid')
        ->and(CustomerPackage::query()->count())->toBe(0);
});

it('keeps a recurring forfait purchase quantity through its renewal invoice and renewal rights', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-04 10:30:00', 'UTC'));

    $owner = paidInvoiceFulfillmentOwner();
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $product = paidInvoiceFulfillmentProduct($owner);
    $offer = paidInvoiceFulfillmentOffer($owner, $product, [
        'name' => 'Recurring five sessions',
        'price' => 350,
        'validity_days' => null,
        'is_recurring' => true,
        'recurrence_frequency' => OfferPackage::RECURRENCE_MONTHLY,
    ]);
    $invoice = paidInvoiceFulfillmentInvoice($owner, $customer, $offer, 3);

    app(CreateInvoicePaymentAction::class)->execute($invoice, ['amount' => 1050], 'card', $owner);

    $package = CustomerPackage::query()->firstOrFail();
    expect($package->initial_quantity)->toBe(15)
        ->and(data_get($package->metadata, 'recurrence.subscription_quantity'))->toBe(3)
        ->and(data_get($package->metadata, 'recurrence.period_allocation_quantity'))->toBe(15);

    $renewalInvoice = app(CustomerPackageService::class)->createRenewalInvoice($owner, $customer, $package, [
        'price_paid' => 350,
    ]);
    $renewalItem = $renewalInvoice->items()->firstOrFail();

    expect((float) $renewalInvoice->total)->toBe(350.0)
        ->and((float) $renewalItem->quantity)->toBe(1.0)
        ->and((float) $renewalItem->unit_price)->toBe(350.0)
        ->and((float) $renewalItem->total)->toBe(350.0)
        ->and($renewalItem->description)->toContain('Subscription quantity: 3')
        ->and(data_get($renewalItem->meta, 'subscription_quantity'))->toBe(3);

    app(CreateInvoicePaymentAction::class)->execute($renewalInvoice, ['amount' => 350], 'card', $owner);

    $renewed = CustomerPackage::query()
        ->where('renewed_from_customer_package_id', $package->id)
        ->firstOrFail();

    expect($renewed->initial_quantity)->toBe(15)
        ->and($renewed->remaining_quantity)->toBe(15)
        ->and((float) $renewed->price_paid)->toBe(350.0);

    Carbon::setTestNow();
});
