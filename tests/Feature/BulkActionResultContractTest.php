<?php

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Request as LeadRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function bulkContractOwner(array $features = []): User
{
    $roleId = (int) Role::query()->firstOrCreate(
        ['name' => 'owner'],
        ['description' => 'Account owner role']
    )->id;

    return User::factory()->create([
        'role_id' => $roleId,
        'company_features' => $features,
        'onboarding_completed_at' => now(),
        'company_type' => 'products',
    ]);
}

function bulkContractCustomer(User $owner, array $overrides = []): Customer
{
    return Customer::query()->create(array_merge([
        'user_id' => $owner->id,
        'first_name' => 'Casey',
        'last_name' => 'Customer',
        'company_name' => 'Bulk Contract Co',
        'email' => 'customer-'.fake()->unique()->safeEmail(),
        'phone' => '+15145551234',
        'is_active' => true,
    ], $overrides));
}

function bulkContractProduct(User $owner, array $overrides = []): Product
{
    $category = ProductCategory::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'name' => 'Bulk Category '.fake()->unique()->word(),
    ]);

    return Product::query()->create(array_merge([
        'user_id' => $owner->id,
        'category_id' => $category->id,
        'item_type' => Product::ITEM_TYPE_PRODUCT,
        'name' => 'Bulk Product '.fake()->unique()->word(),
        'description' => 'Bulk contract test product',
        'price' => 19.99,
        'stock' => 10,
        'minimum_stock' => 1,
        'is_active' => true,
    ], $overrides));
}

function bulkContractLead(User $owner, Customer $customer, array $overrides = []): LeadRequest
{
    return LeadRequest::query()->create(array_merge([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_NEW,
        'title' => 'Bulk lead',
        'service_type' => 'Consulting',
    ], $overrides));
}

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
});

test('customer bulk json response uses the standard bulk result contract', function () {
    $owner = bulkContractOwner();
    $customer = bulkContractCustomer($owner);
    $missingId = $customer->id + 999;

    $response = $this->actingAs($owner)->postJson(route('customer.bulk'), [
        'action' => 'archive',
        'ids' => [$customer->id, $missingId],
    ]);

    $response->assertOk()
        ->assertJsonPath('message', 'Customers archived.')
        ->assertJsonPath('ids', [$customer->id, $missingId])
        ->assertJsonPath('processed_ids', [$customer->id])
        ->assertJsonPath('selected_count', 2)
        ->assertJsonPath('processed_count', 1)
        ->assertJsonPath('success_count', 1)
        ->assertJsonPath('failed_count', 0)
        ->assertJsonPath('skipped_count', 1)
        ->assertJsonPath('errors', []);

    expect($customer->fresh()->is_active)->toBeFalse();
});

test('product bulk json response uses the standard bulk result contract', function () {
    $owner = bulkContractOwner(['products' => true]);
    $product = bulkContractProduct($owner);
    $missingId = $product->id + 999;

    $response = $this->actingAs($owner)->postJson(route('product.bulk'), [
        'action' => 'archive',
        'ids' => [$product->id, $missingId],
    ]);

    $response->assertOk()
        ->assertJsonPath('message', 'Products archived.')
        ->assertJsonPath('ids', [$product->id, $missingId])
        ->assertJsonPath('processed_ids', [$product->id])
        ->assertJsonPath('selected_count', 2)
        ->assertJsonPath('processed_count', 1)
        ->assertJsonPath('success_count', 1)
        ->assertJsonPath('failed_count', 0)
        ->assertJsonPath('skipped_count', 1)
        ->assertJsonPath('errors', []);

    expect($product->fresh()->is_active)->toBeFalse();
});

test('request bulk json response uses the standard bulk result contract and keeps legacy updated count', function () {
    $owner = bulkContractOwner(['requests' => true]);
    $customer = bulkContractCustomer($owner);
    $lead = bulkContractLead($owner, $customer);

    $response = $this->actingAs($owner)->patchJson(route('request.bulk'), [
        'ids' => [$lead->id],
        'status' => LeadRequest::STATUS_CONTACTED,
    ]);

    $response->assertOk()
        ->assertJsonPath('message', 'Prospects updated.')
        ->assertJsonPath('ids', [$lead->id])
        ->assertJsonPath('processed_ids', [$lead->id])
        ->assertJsonPath('selected_count', 1)
        ->assertJsonPath('processed_count', 1)
        ->assertJsonPath('success_count', 1)
        ->assertJsonPath('failed_count', 0)
        ->assertJsonPath('skipped_count', 0)
        ->assertJsonPath('errors', [])
        ->assertJsonPath('updated', 1);

    expect($lead->fresh()->status)->toBe(LeadRequest::STATUS_CONTACTED);
});
