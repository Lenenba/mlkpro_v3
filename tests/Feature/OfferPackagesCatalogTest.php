<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\Customer;
use App\Models\CustomerPackage;
use App\Models\CustomerPackageUsage;
use App\Models\OfferPackage;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function offerPackageRoleId(string $name): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $name.' role']
    )->id;
}

function offerPackageOwner(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role_id' => offerPackageRoleId('owner'),
        'email' => 'offer-package-owner-'.Str::lower(Str::random(10)).'@example.com',
        'currency_code' => 'CAD',
        'company_type' => 'services',
        'company_sector' => 'service_general',
        'onboarding_completed_at' => now(),
    ], $overrides));
}

function offerPackageCatalogItem(User $owner, array $overrides = []): Product
{
    $category = ProductCategory::query()->firstOrCreate([
        'user_id' => $owner->id,
        'name' => 'Catalogue',
    ], [
        'created_by_user_id' => $owner->id,
    ]);

    return Product::query()->create(array_merge([
        'user_id' => $owner->id,
        'category_id' => $category->id,
        'name' => 'Consultation strategie',
        'description' => 'Session de conseil',
        'price' => 125,
        'currency_code' => 'CAD',
        'stock' => 0,
        'minimum_stock' => 0,
        'item_type' => Product::ITEM_TYPE_SERVICE,
        'is_active' => true,
    ], $overrides));
}

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
});

it('renders the offer packages catalog with catalog items', function () {
    $owner = offerPackageOwner();
    $product = offerPackageCatalogItem($owner);

    $offer = OfferPackage::query()->create([
        'user_id' => $owner->id,
        'name' => 'Pack lancement',
        'type' => OfferPackage::TYPE_PACK,
        'status' => OfferPackage::STATUS_ACTIVE,
        'price' => 299,
        'currency_code' => 'CAD',
        'is_public' => true,
    ]);

    $offer->items()->create([
        'product_id' => $product->id,
        'item_type_snapshot' => $product->item_type,
        'name_snapshot' => $product->name,
        'quantity' => 1,
        'unit_price' => 125,
        'included' => true,
        'is_optional' => false,
    ]);

    $this->actingAs($owner)
        ->getJson(route('offer-packages.index'))
        ->assertOk()
        ->assertJsonPath('offers.data.0.name', 'Pack lancement')
        ->assertJsonPath('catalogItems.0.name', 'Consultation strategie')
        ->assertJsonPath('stats.total', 1);
});

it('creates an active public pack with product snapshots', function () {
    $owner = offerPackageOwner();
    $product = offerPackageCatalogItem($owner, [
        'name' => 'Service photo',
        'price' => 85,
        'item_type' => Product::ITEM_TYPE_SERVICE,
    ]);

    $this->actingAs($owner)
        ->postJson(route('offer-packages.store'), [
            'name' => 'Pack evenement',
            'type' => OfferPackage::TYPE_PACK,
            'status' => OfferPackage::STATUS_ACTIVE,
            'description' => 'Photo + accompagnement',
            'price' => 249,
            'currency_code' => 'CAD',
            'is_public' => true,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'unit_price' => 80,
                ],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('offer.name', 'Pack evenement')
        ->assertJsonPath('offer.items.0.name_snapshot', 'Service photo');

    $offer = OfferPackage::query()->with('items')->firstOrFail();

    expect($offer->status)->toBe(OfferPackage::STATUS_ACTIVE)
        ->and($offer->is_public)->toBeTrue()
        ->and($offer->slug)->toBe('pack-evenement')
        ->and($offer->items)->toHaveCount(1)
        ->and($offer->items->first()->name_snapshot)->toBe('Service photo')
        ->and((float) $offer->items->first()->unit_price)->toBe(80.0);
});

it('creates a forfait with included quantity and unit type', function () {
    $owner = offerPackageOwner();
    $service = offerPackageCatalogItem($owner);

    $this->actingAs($owner)
        ->postJson(route('offer-packages.store'), [
            'name' => 'Forfait 10 seances',
            'type' => OfferPackage::TYPE_FORFAIT,
            'status' => OfferPackage::STATUS_ACTIVE,
            'price' => 900,
            'currency_code' => 'CAD',
            'included_quantity' => 10,
            'unit_type' => OfferPackage::UNIT_SESSION,
            'validity_days' => 180,
            'items' => [
                ['product_id' => $service->id, 'quantity' => 1],
            ],
        ])
        ->assertCreated();

    $offer = OfferPackage::query()->firstOrFail();

    expect($offer->type)->toBe(OfferPackage::TYPE_FORFAIT)
        ->and($offer->included_quantity)->toBe(10)
        ->and($offer->unit_type)->toBe(OfferPackage::UNIT_SESSION)
        ->and($offer->validity_days)->toBe(180);
});

it('renders an offer package detail sheet with kpis and linked customers', function () {
    $owner = offerPackageOwner();
    $service = offerPackageCatalogItem($owner, [
        'name' => 'Coaching session',
        'price' => 100,
    ]);
    $offer = OfferPackage::query()->create([
        'user_id' => $owner->id,
        'name' => 'Forfait coaching',
        'type' => OfferPackage::TYPE_FORFAIT,
        'status' => OfferPackage::STATUS_ACTIVE,
        'price' => 500,
        'currency_code' => 'CAD',
        'included_quantity' => 5,
        'unit_type' => OfferPackage::UNIT_SESSION,
        'is_recurring' => true,
        'recurrence_frequency' => OfferPackage::RECURRENCE_MONTHLY,
        'renewal_notice_days' => 7,
    ]);
    $offer->items()->create([
        'product_id' => $service->id,
        'item_type_snapshot' => $service->item_type,
        'name_snapshot' => $service->name,
        'quantity' => 5,
        'unit_price' => 100,
        'included' => true,
        'is_optional' => false,
    ]);

    $activeCustomer = Customer::factory()->create([
        'user_id' => $owner->id,
        'company_name' => null,
        'first_name' => 'Amina',
        'last_name' => 'Diallo',
        'email' => 'amina@example.com',
    ]);
    $consumedCustomer = Customer::factory()->create([
        'user_id' => $owner->id,
        'company_name' => 'Studio Nord',
        'email' => 'studio@example.com',
    ]);

    $activePackage = CustomerPackage::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $activeCustomer->id,
        'offer_package_id' => $offer->id,
        'status' => CustomerPackage::STATUS_ACTIVE,
        'starts_at' => '2026-05-01',
        'expires_at' => '2026-05-31',
        'initial_quantity' => 5,
        'consumed_quantity' => 2,
        'remaining_quantity' => 3,
        'unit_type' => OfferPackage::UNIT_SESSION,
        'price_paid' => 500,
        'currency_code' => 'CAD',
        'is_recurring' => true,
        'recurrence_frequency' => OfferPackage::RECURRENCE_MONTHLY,
        'recurrence_status' => CustomerPackage::RECURRENCE_ACTIVE,
        'next_renewal_at' => '2026-06-01',
        'source_details' => ['offer_package' => ['name' => $offer->name]],
    ]);

    CustomerPackage::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $consumedCustomer->id,
        'offer_package_id' => $offer->id,
        'status' => CustomerPackage::STATUS_CONSUMED,
        'starts_at' => '2026-04-01',
        'expires_at' => '2026-04-30',
        'consumed_at' => '2026-04-20',
        'initial_quantity' => 5,
        'consumed_quantity' => 5,
        'remaining_quantity' => 0,
        'unit_type' => OfferPackage::UNIT_SESSION,
        'price_paid' => 450,
        'currency_code' => 'CAD',
        'is_recurring' => false,
        'source_details' => ['offer_package' => ['name' => $offer->name]],
    ]);

    CustomerPackageUsage::query()->create([
        'customer_package_id' => $activePackage->id,
        'user_id' => $owner->id,
        'customer_id' => $activeCustomer->id,
        'created_by_user_id' => $owner->id,
        'quantity' => 2,
        'used_at' => '2026-05-10 10:00:00',
        'note' => 'Session completee',
        'metadata' => ['source' => 'manual'],
    ]);

    $this->actingAs($owner)
        ->get(route('offer-packages.show', $offer))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('OfferPackages/Show')
            ->where('offer.name', 'Forfait coaching')
            ->where('kpis.sold_count', 2)
            ->where('kpis.assigned_customers', 2)
            ->where('kpis.total_revenue', 950)
            ->where('kpis.consumed_quantity', 7)
            ->where('kpis.remaining_quantity', 3)
            ->where('customers.0.customer.name', 'Amina Diallo')
            ->where('customers.0.remaining_quantity', 3)
            ->where('recentUsages.0.quantity', 2)
            ->where('recentUsages.0.customer.name', 'Amina Diallo'));
});

it('updates an offer and replaces included items', function () {
    $owner = offerPackageOwner();
    $first = offerPackageCatalogItem($owner, ['name' => 'Premier service', 'price' => 100]);
    $second = offerPackageCatalogItem($owner, ['name' => 'Deuxieme service', 'price' => 150]);

    $offer = OfferPackage::query()->create([
        'user_id' => $owner->id,
        'name' => 'Pack initial',
        'type' => OfferPackage::TYPE_PACK,
        'status' => OfferPackage::STATUS_DRAFT,
        'price' => 100,
        'currency_code' => 'CAD',
    ]);

    $offer->items()->create([
        'product_id' => $first->id,
        'item_type_snapshot' => $first->item_type,
        'name_snapshot' => $first->name,
        'quantity' => 1,
        'unit_price' => 100,
        'included' => true,
        'is_optional' => false,
    ]);

    $this->actingAs($owner)
        ->putJson(route('offer-packages.update', $offer), [
            'name' => 'Pack modifie',
            'type' => OfferPackage::TYPE_PACK,
            'status' => OfferPackage::STATUS_ACTIVE,
            'price' => 175,
            'currency_code' => 'CAD',
            'items' => [
                ['product_id' => $second->id, 'quantity' => 1, 'unit_price' => 150],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('offer.name', 'Pack modifie')
        ->assertJsonPath('offer.items.0.name_snapshot', 'Deuxieme service');

    expect($offer->fresh()->items()->count())->toBe(1)
        ->and($offer->fresh('items')->items->first()->product_id)->toBe($second->id);
});

it('duplicates offers as draft non public copies', function () {
    $owner = offerPackageOwner();
    $product = offerPackageCatalogItem($owner);
    $offer = OfferPackage::query()->create([
        'user_id' => $owner->id,
        'name' => 'Pack public',
        'type' => OfferPackage::TYPE_PACK,
        'status' => OfferPackage::STATUS_ACTIVE,
        'price' => 500,
        'currency_code' => 'CAD',
        'is_public' => true,
    ]);
    $offer->items()->create([
        'product_id' => $product->id,
        'item_type_snapshot' => $product->item_type,
        'name_snapshot' => $product->name,
        'quantity' => 1,
        'unit_price' => 125,
        'included' => true,
        'is_optional' => false,
    ]);

    $this->actingAs($owner)
        ->postJson(route('offer-packages.duplicate', $offer))
        ->assertCreated()
        ->assertJsonPath('offer.status', OfferPackage::STATUS_DRAFT)
        ->assertJsonPath('offer.is_public', false);

    expect(OfferPackage::query()->count())->toBe(2)
        ->and(OfferPackage::query()->latest('id')->first()?->items()->count())->toBe(1);
});

it('archives and restores offers instead of deleting them', function () {
    $owner = offerPackageOwner();
    $offer = OfferPackage::query()->create([
        'user_id' => $owner->id,
        'name' => 'Pack archive',
        'type' => OfferPackage::TYPE_PACK,
        'status' => OfferPackage::STATUS_ACTIVE,
        'price' => 120,
        'currency_code' => 'CAD',
        'is_public' => true,
    ]);

    $this->actingAs($owner)
        ->deleteJson(route('offer-packages.destroy', $offer))
        ->assertOk()
        ->assertJsonPath('offer.status', OfferPackage::STATUS_ARCHIVED)
        ->assertJsonPath('offer.is_public', false);

    $this->actingAs($owner)
        ->postJson(route('offer-packages.restore', $offer))
        ->assertOk()
        ->assertJsonPath('offer.status', OfferPackage::STATUS_ACTIVE);

    expect($offer->fresh()->status)->toBe(OfferPackage::STATUS_ACTIVE);
});

it('returns redirects instead of plain json for inertia duplicate and restore actions', function () {
    $owner = offerPackageOwner();
    $product = offerPackageCatalogItem($owner);
    $offer = OfferPackage::query()->create([
        'user_id' => $owner->id,
        'name' => 'Pack inertia',
        'type' => OfferPackage::TYPE_PACK,
        'status' => OfferPackage::STATUS_ACTIVE,
        'price' => 120,
        'currency_code' => 'CAD',
        'is_public' => true,
    ]);
    $offer->items()->create([
        'product_id' => $product->id,
        'item_type_snapshot' => $product->item_type,
        'name_snapshot' => $product->name,
        'quantity' => 1,
        'unit_price' => 125,
        'included' => true,
        'is_optional' => false,
    ]);

    $this->actingAs($owner)
        ->withHeader('X-Inertia', 'true')
        ->post(route('offer-packages.duplicate', $offer))
        ->assertRedirect(route('offer-packages.index'));

    $offer->forceFill([
        'status' => OfferPackage::STATUS_ARCHIVED,
        'is_public' => false,
    ])->save();

    $this->actingAs($owner)
        ->withHeader('X-Inertia', 'true')
        ->post(route('offer-packages.restore', $offer))
        ->assertRedirect(route('offer-packages.index'));
});

it('rejects optional items and catalog items from another account', function () {
    $owner = offerPackageOwner();
    $other = offerPackageOwner();
    $foreignProduct = offerPackageCatalogItem($other);

    $this->actingAs($owner)
        ->postJson(route('offer-packages.store'), [
            'name' => 'Pack invalid',
            'type' => OfferPackage::TYPE_PACK,
            'status' => OfferPackage::STATUS_ACTIVE,
            'price' => 200,
            'currency_code' => 'CAD',
            'items' => [
                [
                    'product_id' => $foreignProduct->id,
                    'quantity' => 1,
                    'is_optional' => true,
                ],
            ],
        ])
        ->assertUnprocessable();

    expect(OfferPackage::query()->exists())->toBeFalse();
});
