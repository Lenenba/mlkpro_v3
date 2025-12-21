<?php

use App\Models\User;
use App\Models\Quote;
use App\Models\Work;
use App\Models\Invoice;
use App\Models\Customer;
use App\Models\Property;
use App\Models\Product;
use App\Models\ProductCategory;

test('quotes can convert to jobs, then invoices and payments update status', function () {
    $user = User::factory()->create();

    $customer = Customer::create([
        'user_id' => $user->id,
        'first_name' => 'Test',
        'last_name' => 'Customer',
        'company_name' => 'Test Co',
        'email' => 'customer@example.com',
        'salutation' => 'Mr',
    ]);

    $property = Property::create([
        'customer_id' => $customer->id,
        'type' => 'physical',
        'street1' => '123 Test St',
        'city' => 'Montreal',
        'country' => 'Canada',
    ]);

    $category = ProductCategory::create([
        'name' => 'Materials',
    ]);

    $product = Product::create([
        'user_id' => $user->id,
        'name' => 'Test Product',
        'category_id' => $category->id,
        'price' => 100,
        'stock' => 10,
        'minimum_stock' => 1,
    ]);

    $quote = Quote::create([
        'user_id' => $user->id,
        'customer_id' => $customer->id,
        'property_id' => $property->id,
        'job_title' => 'Test Quote',
        'status' => 'draft',
        'subtotal' => 200,
        'total' => 200,
        'initial_deposit' => 0,
        'is_fixed' => false,
    ]);

    $quote->products()->sync([
        $product->id => [
            'quantity' => 2,
            'price' => 100,
            'total' => 200,
        ],
    ]);

    $this->actingAs($user)
        ->post(route('quote.convert', $quote))
        ->assertRedirect();

    $work = Work::where('quote_id', $quote->id)->first();
    expect($work)->not->toBeNull();

    $this->actingAs($user)
        ->post(route('invoice.store-from-work', $work))
        ->assertRedirect();

    $invoice = Invoice::where('work_id', $work->id)->first();
    expect($invoice)->not->toBeNull();

    $this->actingAs($user)
        ->post(route('payment.store', $invoice), [
            'amount' => $invoice->total,
            'method' => 'card',
        ])
        ->assertRedirect();

    expect($invoice->refresh()->status)->toBe('paid');
});
