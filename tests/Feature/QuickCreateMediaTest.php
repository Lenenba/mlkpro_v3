<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\StripeCatalogService;
use App\Services\UsageLimitService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);

    $this->mock(UsageLimitService::class, function ($mock): void {
        $mock->shouldReceive('enforceLimit')->andReturnNull();
    });

    $this->mock(StripeCatalogService::class, function ($mock): void {
        $mock->shouldReceive('syncProductPrice')->andReturnNull();
    });
});

function quickCreateMediaOwner(string $companyType = 'services'): User
{
    $ownerRole = Role::query()->firstOrCreate(
        ['name' => 'owner'],
        ['description' => 'Account owner access']
    );

    return User::factory()->withRole($ownerRole->id)->create([
        'company_type' => $companyType,
        'onboarding_completed_at' => now(),
    ]);
}

function quickCreateMediaCategory(User $owner): ProductCategory
{
    return ProductCategory::factory()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
    ]);
}

it('creates an individual customer with a profile photo and birth date from quick create', function () {
    Storage::fake('public');
    $owner = quickCreateMediaOwner();

    $response = $this->actingAs($owner)
        ->post(route('customer.quick.store'), [
            'client_type' => 'individual',
            'first_name' => 'Amina',
            'last_name' => 'Diallo',
            'birth_date' => '1992-04-18',
            'email' => 'amina.quick@example.com',
            'portal_access' => false,
            'description' => 'Cliente régulière du salon.',
            'billing_same_as_physical' => true,
            'billing_mode' => 'deferred',
            'billing_cycle' => 'monthly',
            'billing_grouping' => 'periodic',
            'billing_delay_days' => 15,
            'billing_date_rule' => 'Premier lundi du mois',
            'logo' => UploadedFile::fake()->image('amina-profile.jpg', 640, 640),
        ], [
            'Accept' => 'application/json',
        ])
        ->assertCreated()
        ->assertJsonPath('customer.client_type', 'individual')
        ->assertJsonPath('customer.email', 'amina.quick@example.com');

    $customer = Customer::query()->where('email', 'amina.quick@example.com')->firstOrFail();

    expect($customer->birth_date?->toDateString())->toBe('1992-04-18')
        ->and($customer->billing_same_as_physical)->toBeTrue()
        ->and($customer->billing_mode)->toBe('deferred')
        ->and($customer->billing_cycle)->toBe('monthly')
        ->and($customer->billing_grouping)->toBe('periodic')
        ->and($customer->billing_delay_days)->toBe(15)
        ->and($customer->billing_date_rule)->toBe('Premier lundi du mois')
        ->and($customer->logo)->toStartWith('customers/');
    Storage::disk('public')->assertExists($customer->logo);
    $response
        ->assertJsonPath('customer.logo', $customer->logo)
        ->assertJsonPath('customer.logo_url', $customer->logo_url);
});

it('creates a company customer with a matching preset icon from quick create', function () {
    $owner = quickCreateMediaOwner();

    $this->actingAs($owner)
        ->postJson(route('customer.quick.store'), [
            'client_type' => 'company',
            'company_name' => 'Studio Éclat',
            'first_name' => 'Sophie',
            'last_name' => 'Tremblay',
            'email' => 'studio.quick@example.com',
            'portal_access' => false,
            'logo_icon' => '/images/presets/company-2.svg',
        ])
        ->assertCreated()
        ->assertJsonPath('customer.logo', '/images/presets/company-2.svg');

    $this->assertDatabaseHas('customers', [
        'email' => 'studio.quick@example.com',
        'client_type' => 'company',
        'logo' => '/images/presets/company-2.svg',
    ]);
});

it('rejects an unsupported customer profile file from quick create', function () {
    Storage::fake('public');
    $owner = quickCreateMediaOwner();

    $this->actingAs($owner)
        ->post(route('customer.quick.store'), [
            'client_type' => 'individual',
            'first_name' => 'Invalid',
            'last_name' => 'Avatar',
            'email' => 'invalid-avatar@example.com',
            'portal_access' => false,
            'logo' => UploadedFile::fake()->create('avatar.svg', 12, 'image/svg+xml'),
        ], [
            'Accept' => 'application/json',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('logo');

    $this->assertDatabaseMissing('customers', ['email' => 'invalid-avatar@example.com']);
});

it('stores a service image from quick create', function () {
    Storage::fake('public');
    $owner = quickCreateMediaOwner();
    $category = quickCreateMediaCategory($owner);

    $response = $this->actingAs($owner)
        ->post(route('service.quick.store'), [
            'name' => 'Balayage lumière',
            'category_id' => $category->id,
            'price' => 210,
            'unit' => 'piece',
            'description' => 'Balayage personnalisé avec finition.',
            'is_active' => true,
            'image' => UploadedFile::fake()->image('balayage.jpg', 1200, 800),
        ], [
            'Accept' => 'application/json',
        ])
        ->assertCreated();

    $service = Product::query()->services()->where('name', 'Balayage lumière')->firstOrFail();

    expect($service->image)->toStartWith('services/');
    Storage::disk('public')->assertExists($service->image);
    $response
        ->assertJsonPath('service.image', $service->image)
        ->assertJsonPath('service.image_url', $service->image_url);
});

it('stores product media and current catalog fields from quick create', function () {
    Storage::fake('public');
    $owner = quickCreateMediaOwner('products');
    $category = quickCreateMediaCategory($owner);

    $response = $this->actingAs($owner)
        ->post(route('product.quick.store'), [
            'name' => 'Shampoing réparateur',
            'category_id' => $category->id,
            'sku' => 'SH-REP-01',
            'barcode' => '0123456789012',
            'tracking_type' => 'lot',
            'price' => 28,
            'cost_price' => 12,
            'stock' => 24,
            'minimum_stock' => 5,
            'promo_discount_percent' => 15,
            'promo_start_at' => '2026-08-12',
            'promo_end_at' => '2026-08-31',
            'is_active' => true,
            'image' => UploadedFile::fake()->image('shampoing-main.jpg', 1000, 1000),
            'images' => [
                UploadedFile::fake()->image('shampoing-detail.jpg', 1000, 1000),
            ],
        ], [
            'Accept' => 'application/json',
        ])
        ->assertCreated()
        ->assertJsonPath('product.images.0.is_primary', true);

    $product = Product::query()->products()->where('sku', 'SH-REP-01')->firstOrFail();
    $product->load('images');

    expect($product->barcode)->toBe('0123456789012')
        ->and($product->tracking_type)->toBe('lot')
        ->and((float) $product->promo_discount_percent)->toBe(15.0)
        ->and($product->images)->toHaveCount(2)
        ->and($product->image)->toStartWith('products/');

    Storage::disk('public')->assertExists($product->image);
    foreach ($product->images as $image) {
        Storage::disk('public')->assertExists($image->path);
    }

    $response
        ->assertJsonPath('product.image', $product->image)
        ->assertJsonCount(2, 'product.images');
});

it('rejects unsupported additional product media from quick create', function () {
    Storage::fake('public');
    $owner = quickCreateMediaOwner('products');
    $category = quickCreateMediaCategory($owner);

    $this->actingAs($owner)
        ->post(route('product.quick.store'), [
            'name' => 'Produit avec galerie invalide',
            'category_id' => $category->id,
            'price' => 10,
            'stock' => 1,
            'minimum_stock' => 0,
            'images' => [
                UploadedFile::fake()->create('detail.gif', 12, 'image/gif'),
            ],
        ], [
            'Accept' => 'application/json',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('images.0');

    $this->assertDatabaseMissing('products', ['name' => 'Produit avec galerie invalide']);
});

it('forbids team members from owner-only service and product quick create', function () {
    $employeeRole = Role::query()->firstOrCreate(
        ['name' => 'employee'],
        ['description' => 'Employee access']
    );
    $owner = quickCreateMediaOwner('services');
    $category = quickCreateMediaCategory($owner);
    $member = User::factory()->withRole($employeeRole->id)->create();
    TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $member->id,
        'role' => 'member',
        'permissions' => [],
    ]);

    $this->actingAs($member)
        ->postJson(route('service.quick.store'), [
            'name' => 'Service interdit',
            'category_id' => $category->id,
            'price' => 50,
        ])
        ->assertForbidden();

    $productOwner = quickCreateMediaOwner('products');
    $productCategory = quickCreateMediaCategory($productOwner);
    $seller = User::factory()->withRole($employeeRole->id)->create();
    TeamMember::factory()->create([
        'account_id' => $productOwner->id,
        'user_id' => $seller->id,
        'role' => 'seller',
        'permissions' => ['sales.pos'],
    ]);

    $this->actingAs($seller)
        ->postJson(route('product.quick.store'), [
            'name' => 'Produit interdit',
            'category_id' => $productCategory->id,
            'price' => 20,
            'stock' => 1,
            'minimum_stock' => 0,
        ])
        ->assertForbidden();
});
