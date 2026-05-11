<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\OfferPackage;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

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
