<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\StripeCatalogService;
use App\Services\UsageLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);

    $this->mock(UsageLimitService::class, function ($mock) {
        $mock->shouldReceive('enforceLimit')->andReturnNull();
    });

    $this->mock(StripeCatalogService::class, function ($mock) {
        $mock->shouldReceive('syncProductPrice')->andReturnNull();
    });
});

function serviceImageOwner(): User
{
    return User::factory()->create([
        'company_type' => 'services',
        'onboarding_completed_at' => now(),
    ]);
}

function serviceImageCategory(User $owner): ProductCategory
{
    return ProductCategory::factory()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
    ]);
}

it('stores a service without an image when none is uploaded', function () {
    Storage::fake('public');

    $owner = serviceImageOwner();
    $category = serviceImageCategory($owner);

    $this->actingAs($owner)
        ->post(route('service.store'), [
            'name' => 'Consultation initiale',
            'category_id' => $category->id,
            'price' => 120,
            'unit' => 'hour',
            'description' => 'Session de cadrage',
            'is_active' => true,
        ])
        ->assertRedirect(route('service.index'));

    $service = Product::query()->services()->latest('id')->firstOrFail();

    expect($service->image)->toBeNull()
        ->and($service->image_url)->toEndWith('/images/placeholders/service-default.jpg');
});

it('can remove an existing uploaded image from a service', function () {
    Storage::fake('public');

    $owner = serviceImageOwner();
    $category = serviceImageCategory($owner);

    $this->actingAs($owner)
        ->post(route('service.store'), [
            'name' => 'Coloration premium',
            'category_id' => $category->id,
            'price' => 180,
            'unit' => 'piece',
            'description' => 'Service avec image',
            'is_active' => true,
            'image' => UploadedFile::fake()->image('service-photo.jpg'),
        ])
        ->assertRedirect(route('service.index'));

    $service = Product::query()->services()->latest('id')->firstOrFail();
    $storedPath = $service->image;

    expect($storedPath)->not->toBeNull();
    expect(Storage::disk('public')->exists($storedPath))->toBeTrue();

    $this->actingAs($owner)
        ->put(route('service.update', $service), [
            'name' => 'Coloration premium',
            'category_id' => $category->id,
            'price' => 180,
            'unit' => 'piece',
            'description' => 'Service avec image',
            'is_active' => true,
            'remove_image' => true,
        ])
        ->assertRedirect(route('service.index'));

    $service->refresh();

    expect($service->image)->toBeNull()
        ->and($service->image_url)->toEndWith('/images/placeholders/service-default.jpg')
        ->and(Storage::disk('public')->exists($storedPath))->toBeFalse();
});

it('treats the legacy default placeholder as no image for services', function () {
    $owner = serviceImageOwner();
    $category = serviceImageCategory($owner);

    $service = $owner->products()->create([
        'name' => 'Service legacy',
        'category_id' => $category->id,
        'description' => 'Legacy placeholder path',
        'price' => 75,
        'stock' => 0,
        'minimum_stock' => 0,
        'item_type' => Product::ITEM_TYPE_SERVICE,
        'image' => 'products/product.jpg',
    ]);

    expect($service->fresh()->image_url)->toEndWith('/images/placeholders/service-default.jpg');
});
