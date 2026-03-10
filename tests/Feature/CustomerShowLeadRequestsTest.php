<?php

use App\Models\Customer;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('customer show loads lead requests with their linked quote without querying a missing request quote_id column', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
    ]);

    $customer = Customer::create([
        'user_id' => $owner->id,
        'first_name' => 'Lead',
        'last_name' => 'Customer',
        'company_name' => 'Lead Customer Inc.',
        'email' => 'customer-show-lead@example.com',
    ]);

    $lead = LeadRequest::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_QUOTE_SENT,
        'title' => 'Kitchen renovation lead',
        'service_type' => 'Renovation',
    ]);

    $quote = Quote::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'request_id' => $lead->id,
        'job_title' => 'Kitchen renovation quote',
        'status' => 'sent',
        'subtotal' => 1200,
        'total' => 1200,
        'initial_deposit' => 0,
    ]);

    $this->actingAs($owner)
        ->get(route('customer.show', $customer))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Customer/Show')
            ->where('customer.id', $customer->id)
            ->where('customer.requests.0.id', $lead->id)
            ->where('customer.requests.0.title', 'Kitchen renovation lead')
            ->where('customer.requests.0.quote.id', $quote->id)
            ->where('customer.requests.0.quote.number', $quote->number)
        );
});
