<?php

use App\Models\Customer;
use App\Models\Quote;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Support\Facades\Http;

it('persists products and replaces taxes through the quote create and update routes', function (): void {
    Http::preventStrayRequests();
    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_features' => ['quotes' => true, 'services' => true],
        'onboarding_completed_at' => now(),
    ]);
    $customer = Customer::factory()->create(['user_id' => $owner->id, 'auto_accept_quotes' => false]);
    $originalTax = Tax::factory()->create(['name' => 'Initial tax', 'rate' => 5]);
    $replacementTax = Tax::factory()->create(['name' => 'Replacement tax', 'rate' => 10]);
    $payload = [
        'customer_id' => $customer->id,
        'job_title' => 'Consultation quote',
        'status' => 'draft',
        'product' => [['name' => 'Consultation', 'quantity' => 2, 'price' => 80]],
        'taxes' => [$originalTax->id],
    ];

    $response = $this->actingAs($owner)
        ->postJson(route('customer.quote.store'), $payload)
        ->assertCreated()
        ->assertJsonPath('quote.subtotal', '160.00')
        ->assertJsonPath('quote.total', '168.00')
        ->assertJsonCount(1, 'quote.products')
        ->assertJsonCount(1, 'quote.taxes');
    $quote = Quote::query()->findOrFail($response->json('quote.id'));
    $productId = (int) $response->json('quote.products.0.id');
    $this->assertDatabaseHas('quote_products', [
        'quote_id' => $quote->id, 'product_id' => $productId, 'quantity' => 2, 'price' => 80, 'total' => 160,
    ]);
    $this->assertDatabaseHas('quote_taxes', [
        'quote_id' => $quote->id, 'tax_id' => $originalTax->id, 'rate' => 5, 'amount' => 8,
    ]);

    $payload['product'] = [['id' => $productId, 'quantity' => 3, 'price' => 100]];
    $payload['taxes'] = [$replacementTax->id];
    $this->putJson(route('customer.quote.update', $quote), $payload)
        ->assertOk()
        ->assertJsonPath('quote.subtotal', '300.00')
        ->assertJsonPath('quote.total', '330.00')
        ->assertJsonCount(1, 'quote.products')
        ->assertJsonCount(1, 'quote.taxes');
    $this->assertDatabaseHas('quote_products', [
        'quote_id' => $quote->id, 'product_id' => $productId, 'quantity' => 3, 'price' => 100, 'total' => 300,
    ]);
    $this->assertDatabaseMissing('quote_taxes', ['quote_id' => $quote->id, 'tax_id' => $originalTax->id]);
    $this->assertDatabaseHas('quote_taxes', [
        'quote_id' => $quote->id, 'tax_id' => $replacementTax->id, 'rate' => 10, 'amount' => 30,
    ]);

    $payload['taxes'] = [];
    $this->putJson(route('customer.quote.update', $quote), $payload)
        ->assertOk()
        ->assertJsonPath('quote.total', '300.00')
        ->assertJsonCount(1, 'quote.products')
        ->assertJsonCount(0, 'quote.taxes');
    expect($quote->products()->count())->toBe(1)
        ->and($quote->taxes()->count())->toBe(0);
    Http::assertNothingSent();
});
