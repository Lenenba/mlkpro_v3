<?php

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerPackage;
use App\Models\CustomerPackageUsage;
use App\Models\OfferPackage;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

function customerPackagesPhaseThreeOwner(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'company_type' => 'services',
        'company_features' => [
            'customers' => true,
            'quotes' => true,
            'invoices' => true,
            'products' => true,
            'services' => true,
        ],
    ], $overrides));
}

function customerPackagesPhaseThreeProduct(User $owner, array $overrides = []): Product
{
    $category = ProductCategory::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'name' => 'Phase 3 catalog',
    ]);

    return Product::query()->create(array_merge([
        'user_id' => $owner->id,
        'category_id' => $category->id,
        'name' => 'Wellness session',
        'description' => 'Session included in a forfait',
        'price' => 90,
        'currency_code' => 'CAD',
        'stock' => 0,
        'minimum_stock' => 0,
        'item_type' => Product::ITEM_TYPE_SERVICE,
        'is_active' => true,
    ], $overrides));
}

function customerPackagesPhaseThreeOffer(User $owner, Product $product, array $overrides = []): OfferPackage
{
    $offer = OfferPackage::query()->create(array_merge([
        'user_id' => $owner->id,
        'name' => 'Forfait 5 sessions',
        'type' => OfferPackage::TYPE_FORFAIT,
        'status' => OfferPackage::STATUS_ACTIVE,
        'description' => 'A five session customer balance',
        'price' => 400,
        'currency_code' => 'CAD',
        'validity_days' => 30,
        'included_quantity' => 5,
        'unit_type' => OfferPackage::UNIT_SESSION,
        'is_public' => true,
    ], $overrides));

    $offer->items()->create([
        'product_id' => $product->id,
        'item_type_snapshot' => $product->item_type,
        'name_snapshot' => $product->name,
        'description_snapshot' => $product->description,
        'quantity' => 5,
        'unit_price' => 90,
        'included' => true,
        'is_optional' => false,
        'sort_order' => 0,
    ]);

    return $offer->fresh('items');
}

it('assigns an active forfait to a customer with balance and a stable snapshot', function () {
    Carbon::setTestNow(Carbon::parse('2026-05-11 10:00:00'));

    $owner = customerPackagesPhaseThreeOwner();
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $product = customerPackagesPhaseThreeProduct($owner);
    $offer = customerPackagesPhaseThreeOffer($owner, $product);

    $this->actingAs($owner)
        ->postJson(route('customer.packages.store', $customer), [
            'offer_package_id' => $offer->id,
            'starts_at' => '2026-05-11',
            'note' => 'Sold in store',
        ])
        ->assertCreated()
        ->assertJsonPath('customerPackage.remaining_quantity', 5);

    $package = CustomerPackage::query()->firstOrFail();

    expect($package->customer_id)->toBe($customer->id)
        ->and($package->offer_package_id)->toBe($offer->id)
        ->and($package->status)->toBe(CustomerPackage::STATUS_ACTIVE)
        ->and($package->starts_at->toDateString())->toBe('2026-05-11')
        ->and($package->expires_at->toDateString())->toBe('2026-06-10')
        ->and($package->initial_quantity)->toBe(5)
        ->and($package->remaining_quantity)->toBe(5)
        ->and(data_get($package->source_details, 'offer_package.name'))->toBe('Forfait 5 sessions')
        ->and(data_get($package->source_details, 'offer_package_items.0.name_snapshot'))->toBe('Wellness session')
        ->and(data_get($package->metadata, 'note'))->toBe('Sold in store');

    expect(ActivityLog::query()->where('action', 'customer_package_assigned')->exists())->toBeTrue();

    $this->actingAs($owner)
        ->get(route('customer.show', $customer))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Customer/Show')
            ->has('customerPackages', 1)
            ->where('customerPackages.0.name', 'Forfait 5 sessions')
            ->where('customerPackages.0.remaining_quantity', 5)
            ->where('customerPackageSummary.active', 1)
            ->has('customerPackageOptions', 1));

    Carbon::setTestNow();
});

it('records manual consumption and updates the forfait status when balance reaches zero', function () {
    $owner = customerPackagesPhaseThreeOwner();
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $product = customerPackagesPhaseThreeProduct($owner);
    $offer = customerPackagesPhaseThreeOffer($owner, $product);

    $package = app(\App\Services\OfferPackages\CustomerPackageService::class)
        ->assign($owner, $customer, $offer, [
            'starts_at' => '2026-05-11',
            'initial_quantity' => 5,
        ]);

    $this->actingAs($owner)
        ->postJson(route('customer.packages.usages.store', [$customer, $package]), [
            'quantity' => 2,
            'used_at' => '2026-05-12',
            'note' => 'First visit',
        ])
        ->assertOk()
        ->assertJsonPath('customerPackage.remaining_quantity', 3);

    $package->refresh();
    expect($package->remaining_quantity)->toBe(3)
        ->and($package->consumed_quantity)->toBe(2)
        ->and($package->status)->toBe(CustomerPackage::STATUS_ACTIVE)
        ->and(CustomerPackageUsage::query()->where('note', 'First visit')->exists())->toBeTrue();

    $this->actingAs($owner)
        ->postJson(route('customer.packages.usages.store', [$customer, $package]), [
            'quantity' => 3,
            'used_at' => '2026-05-13',
        ])
        ->assertOk()
        ->assertJsonPath('customerPackage.status', CustomerPackage::STATUS_CONSUMED)
        ->assertJsonPath('customerPackage.remaining_quantity', 0);

    expect(ActivityLog::query()->where('action', 'customer_package_consumed')->count())->toBe(2);
});

it('rejects packs, foreign forfaits, and overconsumption without owner override', function () {
    $owner = customerPackagesPhaseThreeOwner();
    $otherOwner = customerPackagesPhaseThreeOwner();
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $product = customerPackagesPhaseThreeProduct($owner);
    $otherProduct = customerPackagesPhaseThreeProduct($otherOwner);
    $pack = customerPackagesPhaseThreeOffer($owner, $product, [
        'name' => 'Pack not consumable',
        'type' => OfferPackage::TYPE_PACK,
    ]);
    $foreignForfait = customerPackagesPhaseThreeOffer($otherOwner, $otherProduct);
    $forfait = customerPackagesPhaseThreeOffer($owner, $product, [
        'included_quantity' => 2,
    ]);

    $this->actingAs($owner)
        ->postJson(route('customer.packages.store', $customer), [
            'offer_package_id' => $pack->id,
        ])
        ->assertUnprocessable();

    $this->actingAs($owner)
        ->postJson(route('customer.packages.store', $customer), [
            'offer_package_id' => $foreignForfait->id,
        ])
        ->assertUnprocessable();

    $package = app(\App\Services\OfferPackages\CustomerPackageService::class)
        ->assign($owner, $customer, $forfait, ['initial_quantity' => 2]);

    $this->actingAs($owner)
        ->postJson(route('customer.packages.usages.store', [$customer, $package]), [
            'quantity' => 3,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['quantity']);

    expect($package->fresh()->remaining_quantity)->toBe(2);
});
