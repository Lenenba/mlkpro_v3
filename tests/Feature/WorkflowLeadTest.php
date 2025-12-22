<?php

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('converts a lead request to a quote and accepts it into a job', function () {
    $user = User::factory()->create(['company_type' => 'services']);

    $customer = Customer::create([
        'user_id' => $user->id,
        'first_name' => 'Workflow',
        'last_name' => 'Customer',
        'company_name' => 'Workflow Customer',
        'email' => 'workflow.customer@example.com',
    ]);

    $category = ProductCategory::create(['name' => 'Services']);
    $product = Product::create([
        'user_id' => $user->id,
        'name' => 'Window cleaning',
        'category_id' => $category->id,
        'price' => 120,
        'stock' => 0,
        'minimum_stock' => 0,
        'item_type' => Product::ITEM_TYPE_SERVICE,
    ]);

    $lead = LeadRequest::create([
        'user_id' => $user->id,
        'status' => LeadRequest::STATUS_NEW,
        'title' => 'Lead request',
        'service_type' => 'Cleaning',
    ]);

    $this->actingAs($user)
        ->post(route('request.convert', ['lead' => $lead->id]), [
            'customer_id' => $customer->id,
            'job_title' => 'Lead quote',
        ])
        ->assertRedirect();

    $quote = Quote::where('request_id', $lead->id)->first();
    expect($quote)->not->toBeNull();

    $quote->update(['initial_deposit' => 50]);
    $quote->products()->sync([
        $product->id => [
            'quantity' => 1,
            'price' => 120,
            'total' => 120,
            'description' => 'Service line',
        ],
    ]);

    $this->actingAs($user)
        ->post(route('quote.accept', $quote), [
            'deposit_amount' => 50,
        ])
        ->assertRedirect();

    $work = Work::where('quote_id', $quote->id)->first();
    expect($work)->not->toBeNull();
    expect($quote->refresh()->status)->toBe('accepted');

    $this->assertDatabaseHas('work_checklist_items', [
        'work_id' => $work->id,
        'quote_id' => $quote->id,
    ]);

    $transaction = Transaction::where('quote_id', $quote->id)
        ->where('type', 'deposit')
        ->first();

    expect($transaction)->not->toBeNull();
});
