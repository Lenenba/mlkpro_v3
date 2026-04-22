<?php

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns the product default placeholder when a product uses the legacy default path', function () {
    $owner = User::factory()->create([
        'company_type' => 'products',
        'onboarding_completed_at' => now(),
    ]);

    $category = ProductCategory::factory()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
    ]);

    $product = $owner->products()->create([
        'name' => 'Produit catalogue',
        'category_id' => $category->id,
        'description' => 'Produit sans image personnalisee',
        'price' => 45,
        'stock' => 8,
        'minimum_stock' => 1,
        'item_type' => Product::ITEM_TYPE_PRODUCT,
        'image' => Product::LEGACY_DEFAULT_IMAGE_PATH,
    ]);

    expect($product->fresh()->image_url)->toEndWith('/images/placeholders/product-default.jpg');
});

it('resolves legacy primary product images to the new product placeholder url', function () {
    $owner = User::factory()->create([
        'company_type' => 'products',
        'onboarding_completed_at' => now(),
    ]);

    $category = ProductCategory::factory()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
    ]);

    $product = $owner->products()->create([
        'name' => 'Produit avec image legacy',
        'category_id' => $category->id,
        'description' => 'Produit avec image primaire par defaut legacy',
        'price' => 65,
        'stock' => 5,
        'minimum_stock' => 1,
        'item_type' => Product::ITEM_TYPE_PRODUCT,
        'image' => Product::LEGACY_DEFAULT_IMAGE_PATH,
    ]);

    $image = ProductImage::query()->create([
        'product_id' => $product->id,
        'path' => Product::LEGACY_DEFAULT_IMAGE_PATH,
        'is_primary' => true,
        'sort_order' => 0,
    ]);

    expect($image->fresh()->url)->toEndWith('/images/placeholders/product-default.jpg');
});
