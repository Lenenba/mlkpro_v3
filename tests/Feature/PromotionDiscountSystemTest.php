<?php

use App\Enums\PromotionDiscountType;
use App\Enums\PromotionStatus;
use App\Enums\PromotionTargetType;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Promotion;
use App\Models\PromotionUsage;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function promotionOwner(array $overrides = []): User
{
    $roleId = (int) Role::query()->firstOrCreate(
        ['name' => 'owner'],
        ['description' => 'Owner role']
    )->id;

    return User::factory()->create(array_merge([
        'role_id' => $roleId,
        'email' => 'promo-owner-'.Str::lower(Str::random(8)).'@example.com',
        'company_type' => 'products',
        'company_features' => [
            'sales' => true,
            'products' => true,
            'promotions' => true,
        ],
        'onboarding_completed_at' => now(),
    ], $overrides));
}

function promotionCategory(User $owner): ProductCategory
{
    return ProductCategory::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'name' => 'Promo Category '.Str::upper(Str::random(4)),
    ]);
}

function promotionCatalogItem(User $owner, string $type = Product::ITEM_TYPE_PRODUCT, array $overrides = []): Product
{
    $category = $overrides['category_id'] ?? promotionCategory($owner)->id;

    return Product::query()->create(array_merge([
        'user_id' => $owner->id,
        'category_id' => $category,
        'item_type' => $type,
        'name' => ucfirst($type).' '.Str::upper(Str::random(4)),
        'description' => 'Promotion test item',
        'price' => 100,
        'stock' => 20,
        'minimum_stock' => 0,
        'tax_rate' => 10,
        'is_active' => true,
    ], $overrides));
}

function promotionCustomer(User $owner, array $overrides = []): Customer
{
    return Customer::query()->create(array_merge([
        'user_id' => $owner->id,
        'first_name' => 'Alex',
        'last_name' => 'Buyer',
        'company_name' => 'Client '.Str::upper(Str::random(3)),
        'email' => 'promo-customer-'.Str::lower(Str::random(8)).'@example.com',
        'phone' => '+1514555'.random_int(1000, 9999),
        'is_active' => true,
    ], $overrides));
}

function promotionPayload(Product $product, Customer $customer, array $overrides = []): array
{
    return array_merge([
        'customer_id' => $customer->id,
        'status' => Sale::STATUS_PENDING,
        'items' => [
            [
                'product_id' => $product->id,
                'quantity' => 1,
                'price' => 100,
                'description' => $product->name,
            ],
        ],
        'notes' => 'Promotion order',
        'promotion_code' => null,
    ], $overrides);
}

test('sales store applies the best eligible automatic promotion and records usage', function () {
    $owner = promotionOwner();
    $customer = promotionCustomer($owner);
    $product = promotionCatalogItem($owner, Product::ITEM_TYPE_PRODUCT, [
        'price' => 100,
        'tax_rate' => 10,
    ]);

    Promotion::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'name' => 'Spring 10',
        'target_type' => PromotionTargetType::GLOBAL->value,
        'discount_type' => PromotionDiscountType::PERCENTAGE->value,
        'discount_value' => 10,
        'start_date' => now()->subDay()->toDateString(),
        'end_date' => now()->addDay()->toDateString(),
        'status' => PromotionStatus::ACTIVE->value,
    ]);

    $response = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->post(route('sales.store'), promotionPayload($product, $customer));

    $response->assertRedirect(route('sales.create'));

    $sale = Sale::query()->latest()->firstOrFail();
    $usage = PromotionUsage::query()->where('sale_id', $sale->id)->first();

    expect($sale->promotion_id)->not->toBeNull()
        ->and($sale->discount_source)->toBe('promotion')
        ->and((float) $sale->pricing_discount_total)->toBe(10.0)
        ->and((float) $sale->discount_total)->toBe(10.0)
        ->and((float) $sale->tax_total)->toBe(9.0)
        ->and((float) $sale->total)->toBe(99.0)
        ->and($usage)->not->toBeNull()
        ->and((float) $usage->discount_total)->toBe(10.0);
});

test('client targeted promo codes reject the wrong customer and apply for the matching one', function () {
    $owner = promotionOwner();
    $rightCustomer = promotionCustomer($owner, ['company_name' => 'Right Client']);
    $wrongCustomer = promotionCustomer($owner, ['company_name' => 'Wrong Client']);
    $product = promotionCatalogItem($owner);

    Promotion::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'name' => 'VIP 20',
        'code' => 'VIP20',
        'target_type' => PromotionTargetType::CLIENT->value,
        'target_id' => $rightCustomer->id,
        'discount_type' => PromotionDiscountType::PERCENTAGE->value,
        'discount_value' => 20,
        'start_date' => now()->subDay()->toDateString(),
        'end_date' => now()->addDay()->toDateString(),
        'status' => PromotionStatus::ACTIVE->value,
    ]);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->from(route('sales.create'))
        ->post(route('sales.store'), promotionPayload($product, $wrongCustomer, [
            'promotion_code' => 'VIP20',
        ]))
        ->assertSessionHasErrors('promotion_code');

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->post(route('sales.store'), promotionPayload($product, $rightCustomer, [
            'promotion_code' => 'VIP20',
        ]))
        ->assertRedirect(route('sales.create'));

    $sale = Sale::query()->latest()->firstOrFail();

    expect($sale->customer_id)->toBe($rightCustomer->id)
        ->and($sale->discount_code)->toBe('VIP20')
        ->and((float) $sale->pricing_discount_total)->toBe(20.0)
        ->and((float) $sale->tax_total)->toBe(8.0)
        ->and((float) $sale->total)->toBe(88.0);
});

test('promotion usage limits prevent reusing the same promo code and service targets can be created', function () {
    $owner = promotionOwner();
    $customer = promotionCustomer($owner);
    $product = promotionCatalogItem($owner);
    $service = promotionCatalogItem($owner, Product::ITEM_TYPE_SERVICE, [
        'name' => 'Install Service',
        'price' => 150,
    ]);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->post(route('promotions.store'), [
            'name' => 'Service kickoff',
            'code' => 'svc15',
            'target_type' => PromotionTargetType::SERVICE->value,
            'target_id' => $service->id,
            'discount_type' => PromotionDiscountType::FIXED->value,
            'discount_value' => 15,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addWeek()->toDateString(),
            'status' => PromotionStatus::ACTIVE->value,
            'usage_limit' => null,
            'minimum_order_amount' => 50,
        ])
        ->assertRedirect(route('promotions.index'));

    $limitedPromotion = Promotion::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'name' => 'One shot',
        'code' => 'ONCE10',
        'target_type' => PromotionTargetType::GLOBAL->value,
        'discount_type' => PromotionDiscountType::FIXED->value,
        'discount_value' => 10,
        'start_date' => now()->subDay()->toDateString(),
        'end_date' => now()->addDay()->toDateString(),
        'status' => PromotionStatus::ACTIVE->value,
        'usage_limit' => 1,
    ]);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->post(route('sales.store'), promotionPayload($product, $customer, [
            'promotion_code' => 'ONCE10',
        ]))
        ->assertRedirect(route('sales.create'));

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->from(route('sales.create'))
        ->post(route('sales.store'), promotionPayload($product, $customer, [
            'promotion_code' => 'ONCE10',
        ]))
        ->assertSessionHasErrors('promotion_code');

    $servicePromotion = Promotion::query()->where('target_type', PromotionTargetType::SERVICE->value)->first();

    expect($servicePromotion)->not->toBeNull()
        ->and($servicePromotion->code)->toBe('SVC15')
        ->and($limitedPromotion->fresh()->usages()->count())->toBe(1);
});

test('disabled promotions module blocks the admin screen and bypasses promotion pricing in sales', function () {
    $owner = promotionOwner([
        'company_features' => [
            'sales' => true,
            'products' => true,
            'promotions' => false,
        ],
    ]);
    $customer = promotionCustomer($owner);
    $product = promotionCatalogItem($owner, Product::ITEM_TYPE_PRODUCT, [
        'price' => 100,
        'tax_rate' => 10,
    ]);

    Promotion::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'name' => 'Hidden promo',
        'target_type' => PromotionTargetType::GLOBAL->value,
        'discount_type' => PromotionDiscountType::PERCENTAGE->value,
        'discount_value' => 15,
        'start_date' => now()->subDay()->toDateString(),
        'end_date' => now()->addDay()->toDateString(),
        'status' => PromotionStatus::ACTIVE->value,
    ]);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->get(route('promotions.index'))
        ->assertRedirect(url('/'))
        ->assertSessionHas('warning', 'Module indisponible pour votre plan.');

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->post(route('sales.store'), promotionPayload($product, $customer))
        ->assertRedirect(route('sales.create'));

    $sale = Sale::query()->latest()->firstOrFail();

    expect($sale->promotion_id)->toBeNull()
        ->and($sale->discount_source)->toBeNull()
        ->and((float) $sale->pricing_discount_total)->toBe(0.0)
        ->and((float) $sale->tax_total)->toBe(10.0)
        ->and((float) $sale->total)->toBe(110.0);
});

test('service companies can access the promotions module without the sales module', function () {
    $roleId = (int) Role::query()->firstOrCreate(
        ['name' => 'owner'],
        ['description' => 'Owner role']
    )->id;

    $owner = User::query()->create([
        'name' => 'Service Promotions Owner',
        'email' => 'service-promotions-owner@example.com',
        'password' => 'password',
        'role_id' => $roleId,
        'company_type' => 'services',
        'company_features' => [
            'services' => true,
            'sales' => false,
            'promotions' => true,
        ],
        'onboarding_completed_at' => now(),
    ]);

    $service = promotionCatalogItem($owner, Product::ITEM_TYPE_SERVICE, [
        'name' => 'Spring Window Cleaning',
    ]);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->get(route('promotions.index'))
        ->assertOk();

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->post(route('promotions.store'), [
            'name' => 'Spring service promo',
            'code' => 'SPRING15',
            'target_type' => PromotionTargetType::SERVICE->value,
            'target_id' => $service->id,
            'discount_type' => PromotionDiscountType::PERCENTAGE->value,
            'discount_value' => 15,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addWeek()->toDateString(),
            'status' => PromotionStatus::ACTIVE->value,
        ])
        ->assertRedirect(route('promotions.index'));

    expect(Promotion::query()
        ->where('user_id', $owner->id)
        ->where('target_type', PromotionTargetType::SERVICE->value)
        ->exists())->toBeTrue();
});
