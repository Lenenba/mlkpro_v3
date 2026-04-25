<?php

use App\Models\Customer;
use App\Models\Request as LeadRequest;
use App\Models\ServiceRequest;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('customer and prospect expose service request relations and customer detail loads them without replacing legacy requests', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
    ]);

    $customer = Customer::create([
        'user_id' => $owner->id,
        'first_name' => 'Service',
        'last_name' => 'Customer',
        'company_name' => 'Service Customer Inc.',
        'email' => 'service-request-customer@example.com',
    ]);

    $prospect = LeadRequest::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Legacy linked prospect',
        'service_type' => 'Inspection',
        'contact_name' => 'Legacy Prospect',
        'contact_email' => 'legacy-prospect@example.com',
        'contact_phone' => '+1 514 555 0001',
    ]);

    $serviceRequest = ServiceRequest::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'prospect_id' => $prospect->id,
        'source' => 'manual_admin',
        'channel' => 'phone',
        'status' => ServiceRequest::STATUS_NEW,
        'request_type' => 'service_inquiry',
        'service_type' => 'Inspection',
        'title' => 'New service request record',
        'description' => 'Customer asked for a service inspection.',
        'requester_name' => 'Jordan Service',
        'requester_email' => 'jordan@example.com',
        'requester_phone' => '+1 514 555 0002',
        'submitted_at' => now(),
    ]);

    expect($customer->serviceRequests()->pluck('id')->all())->toBe([$serviceRequest->id]);
    expect($prospect->serviceRequests()->pluck('id')->all())->toBe([$serviceRequest->id]);

    $this->actingAs($owner)
        ->get(route('customer.show', $customer))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Customer/Show')
            ->where('customer.id', $customer->id)
            ->where('stats.requests', 1)
            ->where('customer.requests.0.id', $prospect->id)
            ->where('customer.service_requests.0.id', $serviceRequest->id)
            ->where('customer.service_requests.0.title', 'New service request record')
            ->where('customer.service_requests.0.source', 'manual_admin')
            ->where('customer.service_requests.0.prospect.id', $prospect->id)
            ->where('customer.service_requests.0.prospect.title', 'Legacy linked prospect')
        );
});
