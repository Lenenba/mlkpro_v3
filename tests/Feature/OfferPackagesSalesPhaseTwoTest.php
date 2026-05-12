<?php

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\OfferPackage;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Quote;
use App\Models\QuoteProduct;
use App\Models\User;
use App\Models\Work;
use App\Services\OfferPackages\OfferPackageSalesLineBuilder;
use Illuminate\Support\Facades\Notification;

function offerPackagesPhaseTwoOwner(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'company_type' => 'services',
        'company_features' => [
            'quotes' => true,
            'invoices' => true,
            'products' => true,
            'services' => true,
        ],
    ], $overrides));
}

function offerPackagesPhaseTwoProduct(User $owner, array $overrides = []): Product
{
    $category = ProductCategory::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'name' => 'Phase 2 catalog',
    ]);

    return Product::query()->create(array_merge([
        'user_id' => $owner->id,
        'category_id' => $category->id,
        'name' => 'Consultation strategy',
        'description' => 'Advisory session',
        'price' => 150,
        'currency_code' => 'CAD',
        'stock' => 0,
        'minimum_stock' => 0,
        'item_type' => Product::ITEM_TYPE_SERVICE,
        'is_active' => true,
    ], $overrides));
}

function offerPackagesPhaseTwoOffer(User $owner, Product $product, array $overrides = []): OfferPackage
{
    $offer = OfferPackage::query()->create(array_merge([
        'user_id' => $owner->id,
        'name' => 'Pack launch',
        'type' => OfferPackage::TYPE_PACK,
        'status' => OfferPackage::STATUS_ACTIVE,
        'description' => 'Launch support bundle',
        'price' => 299,
        'currency_code' => 'CAD',
        'is_public' => true,
    ], $overrides));

    $offer->items()->create([
        'product_id' => $product->id,
        'item_type_snapshot' => $product->item_type,
        'name_snapshot' => $product->name,
        'description_snapshot' => $product->description,
        'quantity' => 2,
        'unit_price' => 150,
        'included' => true,
        'is_optional' => false,
        'sort_order' => 0,
    ]);

    return $offer->fresh('items');
}

it('adds a pack to a quote with a stable offer snapshot and carries it to invoice items', function () {
    Notification::fake();

    $owner = offerPackagesPhaseTwoOwner();
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $product = offerPackagesPhaseTwoProduct($owner);
    $offer = offerPackagesPhaseTwoOffer($owner, $product);
    $line = app(OfferPackageSalesLineBuilder::class)->quoteLinePayload($offer);

    $this->actingAs($owner)
        ->postJson(route('customer.quote.store'), [
            'customer_id' => $customer->id,
            'job_title' => 'Launch quote',
            'status' => 'draft',
            'product' => [$line],
            'taxes' => [],
            'initial_deposit' => 0,
        ])
        ->assertCreated()
        ->assertJsonPath('quote.total', '299.00');

    $quote = Quote::query()->with('products')->firstOrFail();
    $quoteLine = QuoteProduct::query()->where('quote_id', $quote->id)->firstOrFail();

    expect(data_get($quoteLine->source_details, 'source'))->toBe('offer_package')
        ->and(data_get($quoteLine->source_details, 'offer_package.name'))->toBe('Pack launch')
        ->and(data_get($quoteLine->source_details, 'offer_package_items.0.name_snapshot'))->toBe('Consultation strategy');

    $offer->update(['name' => 'Pack launch updated']);
    expect(data_get($quoteLine->fresh()->source_details, 'offer_package.name'))->toBe('Pack launch');

    $this->actingAs($owner)
        ->post(route('quote.convert', $quote))
        ->assertRedirect();

    $work = Work::query()->where('quote_id', $quote->id)->firstOrFail();

    $this->actingAs($owner)
        ->post(route('invoice.store-from-work', $work))
        ->assertRedirect();

    $invoice = Invoice::query()->where('work_id', $work->id)->with('items')->firstOrFail();
    $item = $invoice->items->first();

    expect($item)->not->toBeNull()
        ->and($item->title)->toBe('Pack launch')
        ->and(data_get($item->meta, 'source'))->toBe('offer_package')
        ->and(data_get($item->meta, 'offer_package_id'))->toBe($offer->id)
        ->and(data_get($item->meta, 'offer_package_snapshot.name'))->toBe('Pack launch')
        ->and(data_get($item->meta, 'offer_package_items.0.name_snapshot'))->toBe('Consultation strategy');
});

it('adds a pack or forfait directly to an editable invoice with metadata snapshot', function () {
    $owner = offerPackagesPhaseTwoOwner();
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $product = offerPackagesPhaseTwoProduct($owner, [
        'name' => 'Massage session',
        'price' => 80,
    ]);
    $offer = offerPackagesPhaseTwoOffer($owner, $product, [
        'name' => 'Forfait 5 sessions',
        'type' => OfferPackage::TYPE_FORFAIT,
        'price' => 350,
        'included_quantity' => 5,
        'unit_type' => OfferPackage::UNIT_SESSION,
    ]);

    $work = Work::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'job_title' => 'Open invoice job',
        'instructions' => 'Invoice context',
        'subtotal' => 100,
        'total' => 100,
    ]);

    $invoice = Invoice::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'work_id' => $work->id,
        'status' => 'sent',
        'total' => 100,
    ]);

    $this->actingAs($owner)
        ->postJson(route('invoice.offer-packages.store', $invoice), [
            'offer_package_id' => $offer->id,
            'quantity' => 2,
        ])
        ->assertCreated()
        ->assertJsonPath('invoice.total', '800.00');

    $invoice->refresh()->load('items');
    $item = $invoice->items->firstOrFail();

    expect((float) $invoice->total)->toBe(800.0)
        ->and($item->title)->toBe('Forfait 5 sessions')
        ->and((float) $item->quantity)->toBe(2.0)
        ->and((float) $item->total)->toBe(700.0)
        ->and($item->description)->toContain('Droits inclus: 5 session')
        ->and(data_get($item->meta, 'offer_package_id'))->toBe($offer->id)
        ->and(data_get($item->meta, 'offer_package_type'))->toBe(OfferPackage::TYPE_FORFAIT)
        ->and(data_get($item->meta, 'offer_package_items.0.name_snapshot'))->toBe('Massage session');
});
