<?php

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductInventory;
use App\Models\ProductStockMovement;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config(['services.stripe.enabled' => false]);
});

dataset('initial product stock', [
    'positive stock' => [10, [[
        'type' => 'in',
        'quantity' => 10,
        'before_quantity' => 0,
        'after_quantity' => 10,
    ]]],
    'zero stock' => [0, []],
]);

/**
 * @param  list<array{type: string, quantity: int, before_quantity: int, after_quantity: int}>  $expectedMovements
 */
function assertInitialProductInventory(Product $product, int $initialStock, array $expectedMovements): void
{
    expect($product->stock)->toBe($initialStock);

    $inventory = ProductInventory::query()->where('product_id', $product->id)->sole();
    expect($inventory->on_hand)->toBe($initialStock)
        ->and($inventory->reserved)->toBe(0)
        ->and($inventory->damaged)->toBe(0);

    $movements = ProductStockMovement::query()
        ->where('product_id', $product->id)
        ->get(['type', 'quantity', 'before_quantity', 'after_quantity'])
        ->toArray();
    expect($movements)->toBe($expectedMovements);
}

it('records the requested initial stock once when creating a product', function (
    string $routeName,
    int $initialStock,
    array $expectedMovements,
): void {
    Http::preventStrayRequests();
    $owner = User::factory()->create(['company_type' => 'products']);
    $category = ProductCategory::factory()->create(['user_id' => $owner->id]);

    $response = $this->actingAs($owner)->postJson(route($routeName), [
        'name' => 'Initial stock product',
        'category_id' => $category->id,
        'price' => 20,
        'stock' => $initialStock,
        'minimum_stock' => 0,
    ])->assertCreated();

    $product = Product::query()->products()->where('user_id', $owner->id)->sole();
    $response->assertJsonPath('product.stock', $initialStock);
    assertInitialProductInventory($product, $initialStock, $expectedMovements);
    Http::assertNothingSent();
})->with([
    'standard creation' => ['product.store'],
    'quick creation' => ['product.quick.store'],
])->with('initial product stock');

it('records the requested initial stock once when importing a new product', function (
    int $initialStock,
    array $expectedMovements,
): void {
    Http::preventStrayRequests();
    $owner = User::factory()->create(['company_type' => 'products']);
    $category = ProductCategory::factory()->create(['user_id' => $owner->id]);
    $file = UploadedFile::fake()->createWithContent('products.csv', implode("\n", [
        'name,sku,price,stock_available,minimum_stock,category',
        "Imported stock product,INITIAL-STOCK,20,{$initialStock},0,{$category->name}",
    ]));

    $this->actingAs($owner)->postJson(route('product.import'), ['file' => $file])
        ->assertOk()
        ->assertJsonPath('imported', 1);

    $product = Product::query()->products()->where('user_id', $owner->id)->sole();
    assertInitialProductInventory($product, $initialStock, $expectedMovements);
    Http::assertNothingSent();
})->with('initial product stock');
