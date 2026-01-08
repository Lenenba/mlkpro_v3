<?php

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductInventory;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;

test('inventory service adjusts stock and tracks damage', function () {
    $user = User::factory()->create(['company_type' => 'products']);
    $category = ProductCategory::factory()->create();
    $product = Product::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'name' => 'Test product',
        'price' => 120,
        'stock' => 0,
        'minimum_stock' => 2,
        'item_type' => Product::ITEM_TYPE_PRODUCT,
        'tracking_type' => 'none',
    ]);

    $service = app(InventoryService::class);
    $warehouse = $service->resolveDefaultWarehouse($user->id);
    $service->ensureInventory($product, $warehouse);

    $service->adjust($product, 10, 'in', [
        'actor_id' => $user->id,
        'warehouse' => $warehouse,
        'reason' => 'test',
    ]);

    $product->refresh();
    $inventory = ProductInventory::where('product_id', $product->id)->first();

    expect($inventory)->not->toBeNull();
    expect($inventory->on_hand)->toBe(10);
    expect($inventory->damaged)->toBe(0);
    expect($product->stock)->toBe(10);

    $service->adjust($product, 3, 'damage', [
        'actor_id' => $user->id,
        'warehouse' => $warehouse,
        'reason' => 'damage',
    ]);

    $inventory->refresh();
    $product->refresh();

    expect($inventory->on_hand)->toBe(7);
    expect($inventory->damaged)->toBe(3);
    expect($product->stock)->toBe(7);
});

test('inventory service transfers stock between warehouses', function () {
    $user = User::factory()->create(['company_type' => 'products']);
    $category = ProductCategory::factory()->create();
    $product = Product::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'name' => 'Transfer product',
        'price' => 80,
        'stock' => 0,
        'minimum_stock' => 1,
        'item_type' => Product::ITEM_TYPE_PRODUCT,
        'tracking_type' => 'none',
    ]);

    $service = app(InventoryService::class);
    $warehouseA = $service->resolveDefaultWarehouse($user->id);
    $warehouseB = Warehouse::create([
        'user_id' => $user->id,
        'name' => 'Secondary',
        'code' => 'SEC',
        'is_default' => false,
        'is_active' => true,
    ]);

    $service->ensureInventory($product, $warehouseA);
    $service->ensureInventory($product, $warehouseB);

    $service->adjust($product, 8, 'in', [
        'actor_id' => $user->id,
        'warehouse' => $warehouseA,
        'reason' => 'stock_in',
    ]);

    $service->transfer($product, 5, $warehouseA, $warehouseB, [
        'actor_id' => $user->id,
        'reason' => 'transfer',
    ]);

    $inventoryA = ProductInventory::where('product_id', $product->id)
        ->where('warehouse_id', $warehouseA->id)
        ->first();
    $inventoryB = ProductInventory::where('product_id', $product->id)
        ->where('warehouse_id', $warehouseB->id)
        ->first();

    expect($inventoryA)->not->toBeNull();
    expect($inventoryB)->not->toBeNull();
    expect($inventoryA->on_hand)->toBe(3);
    expect($inventoryB->on_hand)->toBe(5);
    expect($product->fresh()->stock)->toBe(8);
});
