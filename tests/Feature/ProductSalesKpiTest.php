<?php

use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Quote;
use App\Models\Role;
use App\Models\Sale;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Work;
use App\Notifications\LowStockNotification;
use App\Services\InventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

function productCapabilityMember(User $owner, array $permissions, string $teamRole = 'member'): User
{
    $employeeRole = Role::query()->firstOrCreate(
        ['name' => 'employee'],
        ['description' => 'Employee role'],
    );
    $member = User::factory()->create([
        'role_id' => $employeeRole->id,
        'company_type' => 'services',
    ]);

    TeamMember::query()->create([
        'account_id' => $owner->id,
        'user_id' => $member->id,
        'role' => $teamRole,
        'permissions' => $permissions,
        'is_active' => true,
    ]);

    return $member;
}

function productCapabilityFixture(User $owner, string $name = 'Salon retail product'): Product
{
    $category = ProductCategory::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'name' => $name.' category',
    ]);

    return Product::query()->create([
        'user_id' => $owner->id,
        'category_id' => $category->id,
        'name' => $name,
        'description' => 'Capability regression fixture',
        'item_type' => Product::ITEM_TYPE_PRODUCT,
        'tracking_type' => 'none',
        'price' => 20,
        'cost_price' => 10,
        'stock' => 10,
        'minimum_stock' => 5,
        'is_active' => true,
    ]);
}

it('adds paid sale items to product usage without multiplying quantities by payments', function () {
    $owner = User::factory()->create([
        'company_type' => 'products',
        'company_features' => [
            'products' => true,
            'sales' => true,
        ],
    ]);
    $otherOwner = User::factory()->create([
        'company_type' => 'products',
        'company_features' => [
            'products' => true,
            'sales' => true,
        ],
    ]);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $category = ProductCategory::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'name' => 'KPI products',
    ]);
    $product = Product::query()->create([
        'user_id' => $owner->id,
        'category_id' => $category->id,
        'name' => 'KPI retail product',
        'description' => 'Product usage regression fixture',
        'item_type' => Product::ITEM_TYPE_PRODUCT,
        'tracking_type' => 'none',
        'price' => 20,
        'cost_price' => 10,
        'stock' => 20,
        'minimum_stock' => 2,
        'is_active' => true,
    ]);

    $quote = Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'job_title' => 'KPI quote usage',
        'status' => 'accepted',
        'subtotal' => 80,
        'total' => 80,
    ]);
    DB::table('quote_products')->insert([
        'quote_id' => $quote->id,
        'product_id' => $product->id,
        'quantity' => 4,
        'price' => 20,
        'total' => 80,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $work = Work::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'job_title' => 'KPI work usage',
        'instructions' => 'Use six products.',
        'status' => Work::STATUS_COMPLETED,
    ]);
    DB::table('product_works')->insert([
        'work_id' => $work->id,
        'product_id' => $product->id,
        'quantity' => 6,
        'price' => 20,
        'total' => 120,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $paidSale = Sale::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => Sale::STATUS_PAID,
        'subtotal' => 100,
        'tax_total' => 0,
        'total' => 100,
        'paid_at' => now(),
    ]);
    $paidSale->items()->create([
        'product_id' => $product->id,
        'description' => $product->name,
        'quantity' => 5,
        'price' => 20,
        'total' => 100,
    ]);
    foreach ([40, 60] as $amount) {
        Payment::query()->create([
            'sale_id' => $paidSale->id,
            'customer_id' => $customer->id,
            'user_id' => $owner->id,
            'amount' => $amount,
            'method' => 'card',
            'status' => Payment::STATUS_PAID,
            'paid_at' => now(),
        ]);
    }

    $canceledSale = Sale::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => Sale::STATUS_CANCELED,
        'subtotal' => 140,
        'tax_total' => 0,
        'total' => 140,
    ]);
    $canceledSale->items()->create([
        'product_id' => $product->id,
        'description' => $product->name,
        'quantity' => 7,
        'price' => 20,
        'total' => 140,
    ]);

    $otherSale = Sale::query()->create([
        'user_id' => $otherOwner->id,
        'created_by_user_id' => $otherOwner->id,
        'status' => Sale::STATUS_PAID,
        'subtotal' => 1000,
        'tax_total' => 0,
        'total' => 1000,
        'paid_at' => now(),
    ]);
    $otherSale->items()->create([
        'product_id' => $product->id,
        'description' => $product->name,
        'quantity' => 50,
        'price' => 20,
        'total' => 1000,
    ]);

    $response = $this->actingAs($owner)->getJson(route('product.index'));

    $response
        ->assertOk()
        ->assertJsonPath('stats.rotation', 0.75)
        ->assertJsonPath('topProducts.0.id', $product->id)
        ->assertJsonPath('topProducts.0.quantity', 15);
});

it('shares the owner product catalog with authorized members of a service tenant', function (array $permissions) {
    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_sector' => 'salon',
        'company_features' => [
            'products' => true,
            'sales' => true,
        ],
    ]);
    $product = productCapabilityFixture($owner);
    $member = productCapabilityMember($owner, $permissions);

    $this->actingAs($member)
        ->getJson(route('product.index'))
        ->assertOk()
        ->assertJsonPath('products.data.0.id', $product->id);

    $this->actingAs($member)
        ->getJson(route('product.show', $product))
        ->assertOk()
        ->assertJsonPath('product.id', $product->id);
})->with([
    'product reader' => [['products.view']],
    'sales manager' => [['sales.manage']],
    'point of sale' => [['sales.pos']],
]);

it('keeps product edits permissioned and tenant scoped', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_features' => ['products' => true],
    ]);
    $product = productCapabilityFixture($owner);
    $reader = productCapabilityMember($owner, ['products.view']);
    $editor = productCapabilityMember($owner, ['products.edit']);
    $otherOwner = User::factory()->create([
        'company_type' => 'services',
        'company_features' => ['products' => true],
    ]);
    $otherEditor = productCapabilityMember($otherOwner, ['products.edit']);

    expect($reader->can('view', $product))->toBeTrue()
        ->and($reader->can('update', $product))->toBeFalse()
        ->and($editor->can('update', $product))->toBeTrue()
        ->and($otherEditor->can('view', $product))->toBeFalse()
        ->and($otherEditor->can('update', $product))->toBeFalse();
});

it('notifies a service tenant when its activated product stock crosses the low threshold', function () {
    Notification::fake();

    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_sector' => 'salon',
        'company_features' => ['products' => true],
    ]);
    $product = productCapabilityFixture($owner, 'Salon shampoo');

    app(InventoryService::class)->adjust($product, 6, 'out', [
        'account_id' => $owner->id,
        'actor_id' => $owner->id,
    ]);

    Notification::assertSentTo(
        $owner,
        LowStockNotification::class,
        fn (LowStockNotification $notification): bool => $notification->product->is($product)
            && $notification->currentStock === 4
            && $notification->minimumStock === 5,
    );
});

it('shows physical product inventory KPIs on a service tenant dashboard', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_sector' => 'salon',
        'company_features' => [
            'services' => true,
            'products' => true,
        ],
    ]);
    $product = productCapabilityFixture($owner, 'Hybrid dashboard shampoo');
    $product->forceFill([
        'stock' => 4,
        'minimum_stock' => 5,
        'cost_price' => 10,
    ])->save();

    Product::query()->create([
        'user_id' => $owner->id,
        'category_id' => $product->category_id,
        'name' => 'Hybrid dashboard service',
        'description' => 'A service must not inflate physical inventory KPIs.',
        'item_type' => Product::ITEM_TYPE_SERVICE,
        'tracking_type' => 'none',
        'price' => 100,
        'cost_price' => 80,
        'stock' => 25,
        'minimum_stock' => 30,
        'is_active' => true,
    ]);

    $this->actingAs($owner)
        ->getJson(route('dashboard', ['fresh' => 1]))
        ->assertOk()
        ->assertJsonPath('stats.catalog_total', 2)
        ->assertJsonPath('stats.products_total', 1)
        ->assertJsonPath('stats.products_low_stock', 1)
        ->assertJsonPath('stats.products_out', 0)
        ->assertJsonPath('stats.inventory_value', 40);
});

it('opens the public store by commerce capabilities instead of company type', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_sector' => 'salon',
        'company_slug' => 'hybrid-capability-store',
        'company_features' => [
            'products' => true,
            'sales' => true,
        ],
    ]);
    $product = productCapabilityFixture($owner, 'Public hybrid shampoo');

    $this->get(route('public.store.show', $owner->company_slug))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/Store')
            ->where('products.0.id', $product->id));

    $disabledOwner = User::factory()->create([
        'company_type' => 'services',
        'company_slug' => 'hybrid-disabled-store',
        'company_features' => [
            'products' => true,
            'sales' => false,
        ],
    ]);

    $this->get(route('public.store.show', $disabledOwner->company_slug))
        ->assertNotFound();
});

it('opens service catalog records by capability regardless of company type', function () {
    $owner = User::factory()->create([
        'company_type' => 'products',
        'selected_plan_key' => 'starter',
        'company_features' => [
            'services' => true,
            'products' => false,
        ],
    ]);
    $category = ProductCategory::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'name' => 'Hybrid services',
    ]);
    $service = Product::query()->create([
        'user_id' => $owner->id,
        'category_id' => $category->id,
        'name' => 'Hybrid consultation',
        'item_type' => Product::ITEM_TYPE_SERVICE,
        'tracking_type' => 'none',
        'price' => 75,
        'stock' => 0,
        'minimum_stock' => 0,
        'is_active' => true,
    ]);

    expect($owner->can('view', $service))->toBeTrue()
        ->and($owner->can('update', $service))->toBeTrue()
        ->and($owner->can('delete', $service))->toBeTrue();

    $this->actingAs($owner)
        ->getJson(route('service.show', $service))
        ->assertOk()
        ->assertJsonPath('service.id', $service->id);
});
