<?php

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Sale;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('dashboard loads for service account owners', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('stats.customers_total', 0)
            ->has('tasks')
            ->has('billing.plans')
        );
});

test('dashboard loads for product account owners', function () {
    $owner = createProductAccountOwner([
        'company_slug' => 'dashboard-products-smoke',
    ]);

    createProductCategoryForAccount($owner, [
        'name' => 'Dashboard Smoke',
    ]);
    createProductForAccount($owner, null, [
        'name' => 'Dashboard product',
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('DashboardProductsOwner')
            ->where('stats.products_total', 1)
            ->has('recentSales')
            ->has('stockAlerts')
        );
});

test('public store page loads with minimal product payload', function () {
    $owner = createProductAccountOwner([
        'company_slug' => 'public-store-smoke',
    ]);
    $category = createProductCategoryForAccount($owner, [
        'name' => 'Public Smoke',
    ]);
    $product = createProductForAccount($owner, $category, [
        'name' => 'Public smoke product',
    ]);

    $this->get(route('public.store.show', $owner->company_slug))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/Store')
            ->where('company.slug', $owner->company_slug)
            ->where('products.0.id', $product->id)
            ->where('categories.0.id', $category->id)
            ->where('cart.item_count', 0)
        );
});

test('sales create page loads for product account owners', function () {
    $owner = createProductAccountOwner([
        'company_slug' => 'sales-create-smoke',
    ]);
    $customer = createCustomerForAccount($owner, [
        'company_name' => 'Sales Create Customer',
        'email' => 'sales-create@example.test',
    ]);
    $product = createProductForAccount($owner, null, [
        'name' => 'Sales create product',
    ]);

    $this->actingAs($owner)
        ->get(route('sales.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Sales/Create')
            ->where('customers.0.id', $customer->id)
            ->where('products.0.id', $product->id)
            ->has('paymentMethodSettings.enabled_methods')
        );
});

test('sales show page loads sale detail without runtime query errors', function () {
    $owner = createProductAccountOwner([
        'company_slug' => 'sales-show-smoke',
    ]);
    $customer = createCustomerForAccount($owner, [
        'company_name' => 'Sales Show Customer',
        'email' => 'sales-show@example.test',
    ]);
    $product = createProductForAccount($owner, null, [
        'name' => 'Sales show product',
    ]);

    $sale = Sale::create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => Sale::STATUS_PENDING,
        'subtotal' => 49.98,
        'tax_total' => 0,
        'discount_rate' => 0,
        'discount_total' => 0,
        'total' => 49.98,
    ]);

    $sale->items()->create([
        'product_id' => $product->id,
        'description' => $product->name,
        'quantity' => 2,
        'price' => 24.99,
        'total' => 49.98,
    ]);

    $this->actingAs($owner)
        ->get(route('sales.show', $sale))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Sales/Show')
            ->where('sale.id', $sale->id)
            ->where('sale.customer.id', $customer->id)
            ->where('sale.items.0.product.id', $product->id)
            ->has('paymentMethodSettings.enabled_methods')
        );
});

function createProductAccountOwner(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'company_type' => 'products',
        'company_slug' => 'products-'.fake()->unique()->slug(),
        'company_name' => 'Smoke Product Account',
        'is_suspended' => false,
    ], $overrides));
}

function createCustomerForAccount(User $owner, array $overrides = []): Customer
{
    return Customer::create(array_merge([
        'user_id' => $owner->id,
        'first_name' => 'Smoke',
        'last_name' => 'Customer',
        'company_name' => 'Smoke Customer',
        'email' => fake()->unique()->safeEmail(),
        'is_active' => true,
    ], $overrides));
}

function createProductCategoryForAccount(User $owner, array $overrides = []): ProductCategory
{
    return ProductCategory::create(array_merge([
        'name' => 'Category '.fake()->unique()->word(),
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
    ], $overrides));
}

function createProductForAccount(User $owner, ?ProductCategory $category = null, array $overrides = []): Product
{
    $category ??= createProductCategoryForAccount($owner);

    return Product::create(array_merge([
        'user_id' => $owner->id,
        'category_id' => $category->id,
        'name' => 'Product '.fake()->unique()->word(),
        'description' => 'Smoke test product',
        'price' => 24.99,
        'stock' => 10,
        'minimum_stock' => 2,
        'tax_rate' => 0,
        'item_type' => Product::ITEM_TYPE_PRODUCT,
        'is_active' => true,
        'sku' => 'SKU-'.strtoupper(fake()->bothify('??##??')),
    ], $overrides));
}
