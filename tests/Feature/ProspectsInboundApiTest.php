<?php

use App\Models\Customer;
use App\Models\Request as LeadRequest;
use App\Models\ServiceRequest;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function inboundApiProspectOwner(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'company_type' => 'services',
        'company_features' => [
            'requests' => true,
        ],
        'onboarding_completed_at' => now(),
    ], $attributes));
}

it('creates api prospects without auto-linking existing customers and keeps intake metadata', function () {
    $owner = inboundApiProspectOwner();

    Customer::query()->create([
        'user_id' => $owner->id,
        'company_name' => 'Inbound API Existing Customer',
        'first_name' => 'API',
        'last_name' => 'Customer',
        'email' => 'api.existing@example.com',
        'phone' => '+1 555 0110',
    ]);

    Sanctum::actingAs($owner, ['requests:write']);

    $response = $this->postJson(route('api.integrations.requests.store'), [
        'channel' => 'webhook',
        'title' => 'Connector prospect',
        'service_type' => 'Webhook lead',
        'contact_name' => 'API Existing Customer',
        'contact_email' => 'api.existing@example.com',
        'contact_phone' => '+1 555 0110',
        'meta' => [
            'request_type' => 'connector_event',
            'contact_consent' => true,
            'marketing_consent' => false,
            'budget' => 4200,
        ],
    ])->assertCreated();

    $lead = LeadRequest::query()->findOrFail($response->json('request.id'));
    $serviceRequest = ServiceRequest::query()->findOrFail($response->json('service_request.id'));

    expect($lead->customer_id)->toBeNull()
        ->and($lead->channel)->toBe('api')
        ->and($lead->status)->toBe(LeadRequest::STATUS_NEW)
        ->and($lead->last_activity_at)->not->toBeNull()
        ->and(data_get($lead->meta, 'intake_source'))->toBe('api')
        ->and(data_get($lead->meta, 'request_type'))->toBe('connector_event')
        ->and(data_get($lead->meta, 'contact_consent'))->toBeTrue()
        ->and(data_get($lead->meta, 'marketing_consent'))->toBeFalse()
        ->and((float) data_get($lead->meta, 'budget'))->toBe(4200.0);

    expect($serviceRequest->prospect_id)->toBe($lead->id)
        ->and($serviceRequest->source)->toBe('api')
        ->and($serviceRequest->channel)->toBe('api')
        ->and($serviceRequest->requester_email)->toBe('api.existing@example.com');

    expect(Customer::query()->where('user_id', $owner->id)->count())->toBe(1);
});
