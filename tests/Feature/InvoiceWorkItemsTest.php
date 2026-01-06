<?php

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Quote;
use App\Models\Task;
use App\Models\User;
use App\Models\Work;
use Illuminate\Support\Facades\Notification;

test('creating an invoice from a work includes product lines', function () {
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

    $invoice = Invoice::where('work_id', $work->id)->with('items')->first();
    expect($invoice)->not->toBeNull();
    expect($invoice->items)->toHaveCount(1);

    $item = $invoice->items->first();
    expect($item->title)->toBe('Service A');
    expect((float) $item->quantity)->toBe(2.0);
    expect((float) $item->unit_price)->toBe(150.0);
    expect((float) $item->total)->toBe(300.0);
});

test('creating an invoice from a work uses work product lines over accepted quote lines', function () {
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
    $quoteProduct = Product::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'name' => 'Quote Service',
        'price' => 100,
        'stock' => 0,
        'minimum_stock' => 0,
        'item_type' => Product::ITEM_TYPE_SERVICE,
    ]);
    $workProduct = Product::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'name' => 'Work Service',
        'price' => 200,
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
        'subtotal' => 200,
        'total' => 200,
    ]);

    $work->products()->sync([
        $workProduct->id => [
            'quantity' => 1,
            'price' => 200,
            'total' => 200,
            'description' => 'Work service',
        ],
    ]);

    $quote = Quote::create([
        'user_id' => $user->id,
        'customer_id' => $customer->id,
        'job_title' => 'Quote',
        'status' => 'accepted',
        'subtotal' => 100,
        'total' => 100,
        'initial_deposit' => 0,
        'is_fixed' => false,
        'work_id' => $work->id,
        'accepted_at' => now(),
    ]);

    $quote->products()->sync([
        $quoteProduct->id => [
            'quantity' => 1,
            'price' => 100,
            'total' => 100,
            'description' => 'Quote service',
        ],
    ]);

    $work->quote_id = $quote->id;
    $work->save();

    $this->actingAs($user)
        ->post(route('invoice.store-from-work', $work))
        ->assertRedirect();

    $invoice = Invoice::where('work_id', $work->id)->with('items')->first();
    expect($invoice)->not->toBeNull();
    expect($invoice->items)->toHaveCount(1);
    expect($invoice->items->first()->title)->toBe('Work Service');
    expect($invoice->items->first()->meta['source'] ?? null)->toBe('work');
});

test('creating an invoice from a work includes billable tasks when no products exist', function () {
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

    $work = Work::create([
        'user_id' => $user->id,
        'customer_id' => $customer->id,
        'job_title' => 'Task job',
        'instructions' => 'Handle tasks',
        'start_date' => now()->toDateString(),
        'subtotal' => 200,
        'total' => 200,
        'totalVisits' => 0,
    ]);

    Task::create([
        'account_id' => $user->id,
        'created_by_user_id' => $user->id,
        'customer_id' => $customer->id,
        'work_id' => $work->id,
        'title' => 'Task A',
        'description' => 'Task A desc',
        'status' => 'done',
        'billable' => true,
        'due_date' => now()->toDateString(),
        'start_time' => '09:00:00',
        'end_time' => '10:00:00',
        'completed_at' => now(),
    ]);

    Task::create([
        'account_id' => $user->id,
        'created_by_user_id' => $user->id,
        'customer_id' => $customer->id,
        'work_id' => $work->id,
        'title' => 'Task B',
        'description' => 'Task B desc',
        'status' => 'done',
        'billable' => true,
        'due_date' => now()->toDateString(),
        'start_time' => '10:00:00',
        'end_time' => '11:00:00',
        'completed_at' => now(),
    ]);

    $this->actingAs($user)
        ->post(route('invoice.store-from-work', $work))
        ->assertRedirect();

    $invoice = Invoice::where('work_id', $work->id)->with('items')->first();
    expect($invoice)->not->toBeNull();
    expect($invoice->items)->toHaveCount(2);
    expect($invoice->items->pluck('title')->all())->toContain('Task A', 'Task B');
});
