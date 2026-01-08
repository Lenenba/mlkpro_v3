<?php

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Models\Work;
use Illuminate\Support\Facades\Notification;

test('invoice pdf download is available to the owner', function () {
    Notification::fake();

    $user = User::factory()->create();

    $customer = Customer::create([
        'user_id' => $user->id,
        'first_name' => 'Test',
        'last_name' => 'Customer',
        'company_name' => 'Test Co',
        'email' => 'billing@example.com',
        'salutation' => 'Mr',
    ]);

    $category = ProductCategory::factory()->create();
    $product = Product::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'name' => 'Service A',
        'price' => 150,
        'stock' => 0,
        'minimum_stock' => 0,
        'item_type' => Product::ITEM_TYPE_SERVICE,
    ]);

    $work = Work::create([
        'user_id' => $user->id,
        'customer_id' => $customer->id,
        'job_title' => 'Test job',
        'instructions' => 'Handle service',
        'start_date' => now()->toDateString(),
        'subtotal' => 300,
        'total' => 300,
    ]);

    $work->products()->sync([
        $product->id => [
            'quantity' => 2,
            'price' => 150,
            'total' => 300,
            'description' => 'Service work',
        ],
    ]);

    $this->actingAs($user)
        ->post(route('invoice.store-from-work', $work))
        ->assertRedirect();

    $invoice = Invoice::where('work_id', $work->id)->first();
    expect($invoice)->not->toBeNull();

    $response = $this->actingAs($user)->get(route('invoice.pdf', $invoice));
    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
});

test('invoice pdf download is forbidden for non-owners', function () {
    Notification::fake();

    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $customer = Customer::create([
        'user_id' => $user->id,
        'first_name' => 'Test',
        'last_name' => 'Customer',
        'company_name' => 'Test Co',
        'email' => 'billing@example.com',
        'salutation' => 'Mr',
    ]);

    $work = Work::create([
        'user_id' => $user->id,
        'customer_id' => $customer->id,
        'job_title' => 'Test job',
        'instructions' => 'Handle service',
        'start_date' => now()->toDateString(),
        'subtotal' => 100,
        'total' => 100,
    ]);

    $this->actingAs($user)
        ->post(route('invoice.store-from-work', $work))
        ->assertRedirect();

    $invoice = Invoice::where('work_id', $work->id)->first();
    expect($invoice)->not->toBeNull();

    $this->actingAs($otherUser)
        ->getJson(route('invoice.pdf', $invoice))
        ->assertForbidden();
});
