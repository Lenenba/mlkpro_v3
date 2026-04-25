<?php

use App\Models\Customer;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\User;

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
