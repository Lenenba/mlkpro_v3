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

test('invoice pdf download supports the clean professional owner template', function () {
    Notification::fake();

    $user = User::factory()->create([
        'company_store_settings' => [
            'invoice_template_key' => 'clean_professional',
        ],
    ]);

    $customer = Customer::create([
        'user_id' => $user->id,
        'first_name' => 'Template',
        'last_name' => 'Customer',
        'company_name' => 'Template Co',
        'email' => 'template-billing@example.com',
        'salutation' => 'Mr',
    ]);

    $category = ProductCategory::factory()->create();
    $product = Product::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'name' => 'Service B',
        'price' => 175,
        'stock' => 0,
        'minimum_stock' => 0,
        'item_type' => Product::ITEM_TYPE_SERVICE,
    ]);

    $work = Work::create([
        'user_id' => $user->id,
        'customer_id' => $customer->id,
        'job_title' => 'Template job',
        'instructions' => 'Handle template service',
        'start_date' => now()->toDateString(),
        'subtotal' => 350,
        'total' => 350,
    ]);

    $work->products()->sync([
        $product->id => [
            'quantity' => 2,
            'price' => 175,
            'total' => 350,
            'description' => 'Template service work',
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

test('invoice pdf download supports the minimal corporate owner template', function () {
    Notification::fake();

    $user = User::factory()->create([
        'company_store_settings' => [
            'invoice_template_key' => 'minimal_corporate',
        ],
    ]);

    $customer = Customer::create([
        'user_id' => $user->id,
        'first_name' => 'Minimal',
        'last_name' => 'Client',
        'company_name' => 'Minimal Co',
        'email' => 'minimal-billing@example.com',
        'salutation' => 'Mr',
    ]);

    $category = ProductCategory::factory()->create();
    $product = Product::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'name' => 'Service C',
        'price' => 210,
        'stock' => 0,
        'minimum_stock' => 0,
        'item_type' => Product::ITEM_TYPE_SERVICE,
    ]);

    $work = Work::create([
        'user_id' => $user->id,
        'customer_id' => $customer->id,
        'job_title' => 'Minimal job',
        'instructions' => 'Handle minimal template service',
        'start_date' => now()->toDateString(),
        'subtotal' => 420,
        'total' => 420,
    ]);

    $work->products()->sync([
        $product->id => [
            'quantity' => 2,
            'price' => 210,
            'total' => 420,
            'description' => 'Minimal template service work',
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
