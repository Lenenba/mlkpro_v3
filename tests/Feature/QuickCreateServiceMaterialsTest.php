<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\User;
use App\Services\StripeCatalogService;
use App\Services\UsageLimitService;

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);

    $this->mock(UsageLimitService::class, function ($mock): void {
        $mock->shouldReceive('enforceLimit')->andReturnNull();
    });

    $this->mock(StripeCatalogService::class, function ($mock): void {
        $mock->shouldReceive('syncProductPrice')->andReturnNull();
    });
});

function quickServiceMaterialsOwner(): User
{
    $ownerRole = Role::query()->firstOrCreate(
        ['name' => 'owner'],
        ['description' => 'Account owner access']
    );

    return User::factory()->withRole($ownerRole->id)->create([
        'company_type' => 'services',
        'onboarding_completed_at' => now(),
    ]);
}

function quickServiceMaterialsCategory(User $owner, string $name): ProductCategory
{
    return ProductCategory::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'name' => $name,
    ]);
}

function quickServiceMaterialProduct(
    User $owner,
    ProductCategory $category,
    string $name,
    float $price,
    string $unit = 'piece'
): Product {
    return $owner->products()->create([
        'name' => $name,
        'category_id' => $category->id,
        'price' => $price,
        'unit' => $unit,
        'stock' => 10,
        'minimum_stock' => 0,
        'item_type' => Product::ITEM_TYPE_PRODUCT,
        'is_active' => true,
    ]);
}

it('returns only material products from the authenticated tenant in service options', function () {
    $owner = quickServiceMaterialsOwner();
    $otherOwner = quickServiceMaterialsOwner();
    $category = quickServiceMaterialsCategory($owner, 'Produits salon');
    $otherCategory = quickServiceMaterialsCategory($otherOwner, 'Produits externes');

    $ownProduct = quickServiceMaterialProduct($owner, $category, 'Coloration cuivre', 34.50, 'tube');
    $foreignProduct = quickServiceMaterialProduct($otherOwner, $otherCategory, 'Produit concurrent', 99, 'bottle');

    $owner->products()->create([
        'name' => 'Coupe express',
        'category_id' => $category->id,
        'price' => 45,
        'stock' => 0,
        'minimum_stock' => 0,
        'item_type' => Product::ITEM_TYPE_SERVICE,
        'is_active' => true,
    ]);

    $this->actingAs($owner)
        ->getJson(route('service.options'))
        ->assertOk()
        ->assertJsonCount(1, 'material_products')
        ->assertJsonPath('material_products.0.id', $ownProduct->id)
        ->assertJsonPath('material_products.0.name', 'Coloration cuivre')
        ->assertJsonMissing([
            'id' => $foreignProduct->id,
            'name' => 'Produit concurrent',
        ]);
});

it('persists indexed quick-create materials and rejects products from another tenant', function () {
    $owner = quickServiceMaterialsOwner();
    $otherOwner = quickServiceMaterialsOwner();
    $category = quickServiceMaterialsCategory($owner, 'Services coiffure');
    $otherCategory = quickServiceMaterialsCategory($otherOwner, 'Catalogue externe');

    $ownProduct = quickServiceMaterialProduct($owner, $category, 'Crème protectrice', 18.75, 'dose');
    $foreignProduct = quickServiceMaterialProduct($otherOwner, $otherCategory, 'Secret concurrent', 250, 'kit');

    $this->actingAs($owner)
        ->post(route('service.quick.store'), [
            'name' => 'Coloration avec produit étranger',
            'category_id' => $category->id,
            'price' => 165,
            'unit' => 'piece',
            'is_active' => true,
            'materials' => [
                [
                    'product_id' => $foreignProduct->id,
                    'label' => 'Apport externe déclaré',
                    'quantity' => 1,
                    'sort_order' => 0,
                ],
            ],
        ], [
            'Accept' => 'application/json',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('materials.0.product_id');

    $this->assertDatabaseMissing('products', ['name' => 'Coloration avec produit étranger']);

    $response = $this->actingAs($owner)
        ->post(route('service.quick.store'), [
            'name' => 'Coloration personnalisée',
            'category_id' => $category->id,
            'price' => 165,
            'unit' => 'piece',
            'is_active' => true,
            'materials' => [
                [
                    'product_id' => $ownProduct->id,
                    'quantity' => 2.5,
                    'billable' => false,
                    'sort_order' => 0,
                ],
                [
                    'label' => 'Serviettes jetables',
                    'unit' => 'lot',
                    'quantity' => 3,
                    'unit_price' => 2.25,
                    'billable' => true,
                    'sort_order' => 1,
                ],
            ],
        ], [
            'Accept' => 'application/json',
        ])
        ->assertCreated();

    $service = Product::query()->services()->findOrFail($response->json('service.id'));
    $materials = $service->serviceMaterials()->orderBy('sort_order')->get();

    expect($service->user_id)->toBe($owner->id)
        ->and($materials)->toHaveCount(2)
        ->and($materials[0]->product_id)->toBe($ownProduct->id)
        ->and($materials[0]->label)->toBe('Crème protectrice')
        ->and($materials[0]->unit)->toBe('dose')
        ->and((float) $materials[0]->unit_price)->toBe(18.75)
        ->and((float) $materials[0]->quantity)->toBe(2.5)
        ->and($materials[0]->billable)->toBeFalse()
        ->and($materials[1]->product_id)->toBeNull()
        ->and($materials[1]->label)->toBe('Serviettes jetables')
        ->and((float) $materials[1]->unit_price)->toBe(2.25);

    $this->assertDatabaseMissing('service_materials', [
        'service_id' => $service->id,
        'product_id' => $foreignProduct->id,
    ]);
});
