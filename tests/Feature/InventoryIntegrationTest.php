<?php

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductLot;
use App\Models\ProductStockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Laravel\Sanctum\Sanctum;

function inventoryIntegrationItem(
    User $owner,
    ProductCategory $category,
    string $name,
    string $itemType,
    int $stock = 0,
    int $minimumStock = 1,
): Product {
    return Product::query()->create([
        'user_id' => $owner->id,
        'category_id' => $category->id,
        'name' => $name,
        'price' => 50,
        'stock' => $stock,
        'minimum_stock' => $minimumStock,
        'item_type' => $itemType,
        'tracking_type' => 'none',
    ]);
}

test('integration inventory endpoints respect abilities', function () {
    $user = User::factory()->create(['company_type' => 'products']);
    $category = ProductCategory::factory()->create();
    $product = Product::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'name' => 'API product',
        'price' => 50,
        'stock' => 0,
        'minimum_stock' => 1,
        'item_type' => Product::ITEM_TYPE_PRODUCT,
        'tracking_type' => 'none',
    ]);

    Sanctum::actingAs($user, ['inventory:read']);
    $this->getJson('/api/v1/integrations/products')->assertOk();
    $this->postJson("/api/v1/integrations/products/{$product->id}/adjust", [
        'type' => 'in',
        'quantity' => 5,
    ])->assertForbidden();

    Sanctum::actingAs($user, ['inventory:read', 'inventory:write']);
    $this->postJson("/api/v1/integrations/products/{$product->id}/adjust", [
        'type' => 'in',
        'quantity' => 5,
    ])->assertOk();

    expect($product->fresh()->stock)->toBe(5);
});

test('integration inventory detail returns 404 for a service item', function () {
    $user = User::factory()->create(['company_type' => 'products']);
    $category = ProductCategory::factory()->create([
        'user_id' => $user->id,
        'created_by_user_id' => $user->id,
    ]);
    $service = inventoryIntegrationItem(
        $user,
        $category,
        'API service detail',
        Product::ITEM_TYPE_SERVICE,
    );

    Sanctum::actingAs($user, ['inventory:read']);

    $this->getJson("/api/v1/integrations/products/{$service->id}")
        ->assertNotFound();
});

test('integration inventory adjustment returns 404 and leaves a service item unchanged', function () {
    $user = User::factory()->create(['company_type' => 'products']);
    $category = ProductCategory::factory()->create([
        'user_id' => $user->id,
        'created_by_user_id' => $user->id,
    ]);
    $service = inventoryIntegrationItem(
        $user,
        $category,
        'API service adjustment',
        Product::ITEM_TYPE_SERVICE,
        stock: 4,
    );

    Sanctum::actingAs($user, ['inventory:read', 'inventory:write']);

    $this->postJson("/api/v1/integrations/products/{$service->id}/adjust", [
        'type' => 'in',
        'quantity' => 5,
    ])->assertNotFound();

    expect($service->fresh()->stock)->toBe(4)
        ->and(ProductStockMovement::query()->where('product_id', $service->id)->exists())->toBeFalse();
});

test('integration inventory movements exclude service item movements', function () {
    $user = User::factory()->create(['company_type' => 'products']);
    $category = ProductCategory::factory()->create([
        'user_id' => $user->id,
        'created_by_user_id' => $user->id,
    ]);
    $product = inventoryIntegrationItem(
        $user,
        $category,
        'API movement product',
        Product::ITEM_TYPE_PRODUCT,
    );
    $service = inventoryIntegrationItem(
        $user,
        $category,
        'API movement service',
        Product::ITEM_TYPE_SERVICE,
    );
    $productMovement = ProductStockMovement::query()->create([
        'product_id' => $product->id,
        'user_id' => $user->id,
        'type' => 'in',
        'quantity' => 3,
        'before_quantity' => 0,
        'after_quantity' => 3,
    ]);
    ProductStockMovement::query()->create([
        'product_id' => $service->id,
        'user_id' => $user->id,
        'type' => 'in',
        'quantity' => 2,
        'before_quantity' => 0,
        'after_quantity' => 2,
    ]);

    Sanctum::actingAs($user, ['inventory:read']);

    $this->getJson('/api/v1/integrations/movements')
        ->assertOk()
        ->assertJsonCount(1, 'movements.data')
        ->assertJsonPath('movements.data.0.id', $productMovement->id)
        ->assertJsonPath('movements.data.0.product_id', $product->id);
});

test('integration inventory alerts exclude service stock and lots', function () {
    $user = User::factory()->create(['company_type' => 'products']);
    $category = ProductCategory::factory()->create([
        'user_id' => $user->id,
        'created_by_user_id' => $user->id,
    ]);
    $lowStockProduct = inventoryIntegrationItem(
        $user,
        $category,
        'Low stock product',
        Product::ITEM_TYPE_PRODUCT,
        stock: 2,
        minimumStock: 5,
    );
    $outOfStockProduct = inventoryIntegrationItem(
        $user,
        $category,
        'Out of stock product',
        Product::ITEM_TYPE_PRODUCT,
        stock: 0,
        minimumStock: 5,
    );
    $lowStockService = inventoryIntegrationItem(
        $user,
        $category,
        'Low stock service',
        Product::ITEM_TYPE_SERVICE,
        stock: 2,
        minimumStock: 5,
    );
    inventoryIntegrationItem(
        $user,
        $category,
        'Out of stock service',
        Product::ITEM_TYPE_SERVICE,
        stock: 0,
        minimumStock: 5,
    );
    $warehouse = Warehouse::query()->create([
        'user_id' => $user->id,
        'name' => 'Integration warehouse',
        'code' => 'INT',
        'is_default' => true,
        'is_active' => true,
    ]);
    $productLot = ProductLot::query()->create([
        'product_id' => $lowStockProduct->id,
        'warehouse_id' => $warehouse->id,
        'lot_number' => 'PRODUCT-LOT',
        'expires_at' => now()->addDays(7),
        'quantity' => 2,
    ]);
    ProductLot::query()->create([
        'product_id' => $lowStockService->id,
        'warehouse_id' => $warehouse->id,
        'lot_number' => 'SERVICE-LOT',
        'expires_at' => now()->addDays(7),
        'quantity' => 2,
    ]);

    Sanctum::actingAs($user, ['inventory:read']);

    $this->getJson('/api/v1/integrations/alerts')
        ->assertOk()
        ->assertJsonCount(1, 'low_stock')
        ->assertJsonPath('low_stock.0.id', $lowStockProduct->id)
        ->assertJsonCount(1, 'out_of_stock')
        ->assertJsonPath('out_of_stock.0.id', $outOfStockProduct->id)
        ->assertJsonCount(1, 'expiring_lots')
        ->assertJsonPath('expiring_lots.0.id', $productLot->id)
        ->assertJsonPath('expiring_lots.0.product.id', $lowStockProduct->id);
});
