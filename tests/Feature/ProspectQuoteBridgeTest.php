<?php

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function prospectQuoteBridgeOwner(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'company_type' => 'services',
        'company_features' => [
            'requests' => true,
            'quotes' => true,
        ],
        'onboarding_completed_at' => now(),
    ], $attributes));
}

it('allows creating a quote for a prospect without a customer', function () {
    $owner = prospectQuoteBridgeOwner();

    $prospect = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_QUALIFIED,
        'status_updated_at' => now(),
        'last_activity_at' => now(),
        'title' => 'Prospect-first estimate',
        'contact_name' => 'Avery Prospect',
    ]);

    $quote = Quote::query()->create([
        'user_id' => $owner->id,
        'prospect_id' => $prospect->id,
        'job_title' => 'Prospect draft quote',
        'status' => 'draft',
        'subtotal' => 950,
        'total' => 950,
        'currency_code' => 'CAD',
    ])->fresh();

    expect($quote->customer_id)->toBeNull()
        ->and($quote->prospect_id)->toBe($prospect->id)
        ->and($quote->request_id)->toBe($prospect->id)
        ->and($quote->prospect?->is($prospect))->toBeTrue()
        ->and($quote->request?->is($prospect))->toBeTrue()
        ->and($prospect->quote()->first()?->id)->toBe($quote->id)
        ->and($quote->number)->toBe('Q001');
});

it('keeps legacy request backed quotes readable through the new prospect alias', function () {
    $owner = prospectQuoteBridgeOwner();
    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
    ]);

    $prospect = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_QUOTE_SENT,
        'status_updated_at' => now(),
        'last_activity_at' => now(),
        'title' => 'Legacy request linked quote',
    ]);

    $quote = Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'request_id' => $prospect->id,
        'job_title' => 'Legacy linked quote',
        'status' => 'sent',
        'subtotal' => 1200,
        'total' => 1200,
        'currency_code' => 'CAD',
    ])->fresh();

    expect($quote->request_id)->toBe($prospect->id)
        ->and($quote->prospect_id)->toBe($prospect->id)
        ->and($quote->prospect?->is($prospect))->toBeTrue()
        ->and($quote->request?->is($prospect))->toBeTrue();
});

it('generates quote numbers per tenant across customer and prospect quotes', function () {
    $owner = prospectQuoteBridgeOwner();

    $customerA = Customer::factory()->create([
        'user_id' => $owner->id,
    ]);
    $customerB = Customer::factory()->create([
        'user_id' => $owner->id,
    ]);
    $prospect = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'status_updated_at' => now(),
        'last_activity_at' => now(),
        'title' => 'Standalone prospect quote',
    ]);

    $quoteA = Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customerA->id,
        'job_title' => 'Customer A quote',
        'status' => 'draft',
        'subtotal' => 500,
        'total' => 500,
        'currency_code' => 'CAD',
    ]);

    $quoteB = Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customerB->id,
        'job_title' => 'Customer B quote',
        'status' => 'draft',
        'subtotal' => 600,
        'total' => 600,
        'currency_code' => 'CAD',
    ]);

    $prospectQuote = Quote::query()->create([
        'user_id' => $owner->id,
        'prospect_id' => $prospect->id,
        'job_title' => 'Prospect quote',
        'status' => 'draft',
        'subtotal' => 700,
        'total' => 700,
        'currency_code' => 'CAD',
    ]);

    expect([$quoteA->number, $quoteB->number, $prospectQuote->number])
        ->toBe(['Q001', 'Q002', 'Q003']);
});

it('converts a prospect to a quote without forcing customer creation', function () {
    $owner = prospectQuoteBridgeOwner();

    $prospect = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'status_updated_at' => now(),
        'last_activity_at' => now(),
        'title' => 'Prospect-only conversion',
        'contact_name' => 'Taylor Prospect',
        'contact_email' => 'taylor.prospect@example.com',
    ]);

    $response = $this->actingAs($owner)->postJson(route('prospects.convert', $prospect), [
        'job_title' => 'Prospect bridge quote',
        'create_customer' => false,
    ]);

    $response->assertOk()
        ->assertJsonPath('message', 'Prospect converted to quote.')
        ->assertJsonPath('quote.customer_id', null)
        ->assertJsonPath('quote.prospect_id', $prospect->id)
        ->assertJsonPath('quote.request_id', $prospect->id)
        ->assertJsonPath('request.customer_id', null)
        ->assertJsonPath('request.status', LeadRequest::STATUS_QUALIFIED);

    $quote = Quote::query()->findOrFail((int) $response->json('quote.id'));

    expect($quote->customer_id)->toBeNull()
        ->and($quote->prospect_id)->toBe($prospect->id)
        ->and($quote->request_id)->toBe($prospect->id)
        ->and($quote->prospect?->is($prospect))->toBeTrue()
        ->and($quote->number)->toBe('Q001');
});

it('renders the quote editor for a prospect backed quote without a customer', function () {
    $owner = prospectQuoteBridgeOwner();

    $prospect = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_QUALIFIED,
        'status_updated_at' => now(),
        'last_activity_at' => now(),
        'title' => 'Editor fallback prospect',
        'contact_name' => 'Morgan Prospect',
        'contact_email' => 'morgan.prospect@example.com',
    ]);

    $quote = Quote::query()->create([
        'user_id' => $owner->id,
        'prospect_id' => $prospect->id,
        'job_title' => 'Prospect editor quote',
        'status' => 'draft',
        'subtotal' => 800,
        'total' => 800,
        'currency_code' => 'CAD',
    ]);

    $this->actingAs($owner)
        ->get(route('customer.quote.edit', $quote))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Quote/Create')
            ->where('quote.id', $quote->id)
            ->where('quote.prospect.id', $prospect->id)
        );
});

it('allows updating a prospect backed quote without attaching a customer', function () {
    $owner = prospectQuoteBridgeOwner();

    $prospect = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_QUALIFIED,
        'status_updated_at' => now(),
        'last_activity_at' => now(),
        'title' => 'Update-only prospect quote',
        'contact_name' => 'Jordan Prospect',
        'contact_email' => 'jordan.prospect@example.com',
    ]);

    $category = ProductCategory::factory()->create();
    $service = Product::query()->create([
        'name' => 'Prospect Service',
        'description' => 'Prospect quote line item',
        'category_id' => $category->id,
        'stock' => 0,
        'minimum_stock' => 0,
        'price' => 125,
        'currency_code' => 'CAD',
        'user_id' => $owner->id,
        'item_type' => Product::ITEM_TYPE_SERVICE,
        'is_active' => true,
    ]);

    $quote = Quote::query()->create([
        'user_id' => $owner->id,
        'prospect_id' => $prospect->id,
        'job_title' => 'Initial prospect quote',
        'status' => 'draft',
        'subtotal' => 125,
        'total' => 125,
        'currency_code' => 'CAD',
    ]);

    $this->actingAs($owner)
        ->putJson(route('customer.quote.update', $quote), [
            'customer_id' => null,
            'property_id' => null,
            'job_title' => 'Updated prospect quote',
            'status' => 'draft',
            'product' => [[
                'id' => $service->id,
                'quantity' => 2,
                'price' => 125,
            ]],
            'notes' => 'Updated notes',
            'messages' => 'Updated message',
            'initial_deposit' => 0,
            'taxes' => [],
        ])
        ->assertOk()
        ->assertJsonPath('message', 'Quote updated successfully!')
        ->assertJsonPath('quote.customer_id', null)
        ->assertJsonPath('quote.job_title', 'Updated prospect quote')
        ->assertJsonPath('quote.prospect.id', $prospect->id);

    $quote->refresh();

    expect($quote->customer_id)->toBeNull()
        ->and($quote->job_title)->toBe('Updated prospect quote')
        ->and($quote->products()->count())->toBe(1);
});

it('keeps a prospect aligned with quote sent then won statuses', function () {
    $owner = prospectQuoteBridgeOwner();

    $prospect = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_QUALIFIED,
        'status_updated_at' => now(),
        'last_activity_at' => now(),
        'title' => 'Commercial progression prospect',
        'contact_name' => 'Casey Prospect',
    ]);

    $quote = Quote::query()->create([
        'user_id' => $owner->id,
        'prospect_id' => $prospect->id,
        'job_title' => 'Commercial progression quote',
        'status' => 'draft',
        'subtotal' => 1400,
        'total' => 1400,
        'currency_code' => 'CAD',
    ]);

    $quote->update(['status' => 'sent']);
    $quote->syncRequestStatusFromQuote();
    $prospect->refresh();

    expect($prospect->status)->toBe(LeadRequest::STATUS_QUOTE_SENT);

    $quote->update(['status' => 'accepted']);
    $quote->syncRequestStatusFromQuote();
    $prospect->refresh();

    expect($prospect->status)->toBe(LeadRequest::STATUS_WON)
        ->and($prospect->statusHistories()->where('to_status', LeadRequest::STATUS_QUOTE_SENT)->exists())->toBeTrue()
        ->and($prospect->statusHistories()->where('to_status', LeadRequest::STATUS_WON)->exists())->toBeTrue();
});

it('keeps a prospect aligned with a declined quote status', function () {
    $owner = prospectQuoteBridgeOwner();

    $prospect = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_QUALIFIED,
        'status_updated_at' => now(),
        'last_activity_at' => now(),
        'title' => 'Lost commercial prospect',
        'contact_name' => 'Jamie Prospect',
    ]);

    $quote = Quote::query()->create([
        'user_id' => $owner->id,
        'prospect_id' => $prospect->id,
        'job_title' => 'Lost commercial quote',
        'status' => 'draft',
        'subtotal' => 500,
        'total' => 500,
        'currency_code' => 'CAD',
    ]);

    $quote->update(['status' => 'declined']);
    $quote->syncRequestStatusFromQuote();
    $prospect->refresh();

    expect($prospect->status)->toBe(LeadRequest::STATUS_LOST)
        ->and($prospect->statusHistories()->where('to_status', LeadRequest::STATUS_LOST)->exists())->toBeTrue();
});
