<?php

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

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
