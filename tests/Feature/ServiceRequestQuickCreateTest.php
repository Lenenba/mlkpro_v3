<?php

use App\Models\Customer;
use App\Models\Request as LeadRequest;
use App\Models\ServiceRequest;
use App\Models\User;

it('creates a service request linked to an existing customer without creating a prospect', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_features' => ['requests' => true],
        'onboarding_completed_at' => now(),
    ]);

    $customer = Customer::query()->create([
        'user_id' => $owner->id,
        'company_name' => 'Quick Create Customer',
        'first_name' => 'Quick',
        'last_name' => 'Customer',
        'email' => 'quick.customer@example.com',
        'phone' => '+1 555 1000',
    ]);

    $response = $this->actingAs($owner)->postJson(route('service-requests.store'), [
        'relation_mode' => 'existing_customer',
        'customer_id' => $customer->id,
        'source' => 'phone',
        'title' => 'Urgent sink issue',
        'service_type' => 'Plumbing',
        'contact_name' => 'Quick Customer',
        'contact_email' => 'quick.customer@example.com',
        'contact_phone' => '+1 555 1000',
        'meta' => [
            'budget' => 250,
        ],
    ])->assertCreated();

    $serviceRequest = ServiceRequest::query()->findOrFail($response->json('service_request.id'));

    expect($serviceRequest->customer_id)->toBe($customer->id)
        ->and($serviceRequest->prospect_id)->toBeNull()
        ->and($serviceRequest->source)->toBe('manual_admin')
        ->and($serviceRequest->channel)->toBe('phone')
        ->and($serviceRequest->status)->toBe(ServiceRequest::STATUS_NEW)
        ->and((float) data_get($serviceRequest->meta, 'budget'))->toBe(250.0);

    expect(LeadRequest::query()->where('user_id', $owner->id)->count())->toBe(0);
});

it('creates a service request and a new prospect when the quick-create flow targets a new prospect', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_features' => ['requests' => true],
        'onboarding_completed_at' => now(),
    ]);

    $response = $this->actingAs($owner)->postJson(route('service-requests.store'), [
        'relation_mode' => 'new_prospect',
        'source' => 'manual',
        'title' => 'Garden cleanup request',
        'service_type' => 'Landscaping',
        'description' => 'Need cleanup before the weekend.',
        'contact_name' => 'Jordan Prospect',
        'contact_email' => 'jordan.prospect@example.com',
        'contact_phone' => '+1 555 2000',
    ])->assertCreated();

    $serviceRequest = ServiceRequest::query()->findOrFail($response->json('service_request.id'));
    $prospect = LeadRequest::query()->findOrFail($response->json('prospect.id'));

    expect($serviceRequest->prospect_id)->toBe($prospect->id)
        ->and($serviceRequest->customer_id)->toBeNull()
        ->and($prospect->title)->toBe('Garden cleanup request')
        ->and($prospect->service_type)->toBe('Landscaping')
        ->and($prospect->contact_email)->toBe('jordan.prospect@example.com');

    $this->actingAs($owner)
        ->getJson(route('prospects.options', ['search' => 'Jordan']))
        ->assertOk()
        ->assertJsonPath('prospects.0.id', $prospect->id);
});

it('creates a service request linked to an existing prospect and keeps the customer relation when available', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_features' => ['requests' => true],
        'onboarding_completed_at' => now(),
    ]);

    $customer = Customer::query()->create([
        'user_id' => $owner->id,
        'company_name' => 'Prospect Customer',
        'first_name' => 'Linked',
        'last_name' => 'Customer',
        'email' => 'linked.customer@example.com',
        'phone' => '+1 555 3000',
    ]);

    $prospect = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Existing prospect record',
        'service_type' => 'Electrical',
        'contact_name' => 'Existing Prospect',
        'contact_email' => 'existing.prospect@example.com',
        'contact_phone' => '+1 555 3001',
    ]);

    $response = $this->actingAs($owner)->postJson(route('service-requests.store'), [
        'relation_mode' => 'existing_prospect',
        'prospect_id' => $prospect->id,
        'source' => 'manual',
        'title' => 'Lighting upgrade request',
        'service_type' => 'Electrical',
        'description' => 'Upgrade lobby lighting fixtures.',
    ])->assertCreated();

    $serviceRequest = ServiceRequest::query()->findOrFail($response->json('service_request.id'));

    expect($serviceRequest->prospect_id)->toBe($prospect->id)
        ->and($serviceRequest->customer_id)->toBe($customer->id)
        ->and($serviceRequest->requester_email)->toBe('existing.prospect@example.com')
        ->and($serviceRequest->requester_phone)->toBe('+1 555 3001');
});

it('creates an unlinked service request when the quick-create flow continues without relation', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_features' => ['requests' => true],
        'onboarding_completed_at' => now(),
    ]);

    $response = $this->actingAs($owner)->postJson(route('service-requests.store'), [
        'relation_mode' => 'none',
        'source' => 'email',
        'title' => 'General inquiry',
        'service_type' => 'Consultation',
        'contact_name' => 'No Relation',
        'contact_email' => 'no.relation@example.com',
        'description' => 'Need advice before deciding next steps.',
    ])->assertCreated();

    $serviceRequest = ServiceRequest::query()->findOrFail($response->json('service_request.id'));

    expect($serviceRequest->customer_id)->toBeNull()
        ->and($serviceRequest->prospect_id)->toBeNull()
        ->and($serviceRequest->source)->toBe('manual_admin')
        ->and($serviceRequest->channel)->toBe('email')
        ->and($serviceRequest->requester_email)->toBe('no.relation@example.com');
});
