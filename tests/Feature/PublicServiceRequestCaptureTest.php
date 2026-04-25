<?php

use App\Models\Request as LeadRequest;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Support\Facades\URL;

it('captures a service request alongside the legacy lead when the public form submits a call request', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_features' => [
            'requests' => true,
        ],
    ]);

    $this->post(URL::signedRoute('public.requests.store', ['user' => $owner->id]), [
        'contact_name' => 'Public Caller',
        'contact_email' => 'public.caller@example.com',
        'service_type' => 'HVAC diagnosis',
        'description' => 'Need a callback tomorrow morning.',
        'final_action' => 'request_call',
    ])->assertRedirect();

    $lead = LeadRequest::query()
        ->where('user_id', $owner->id)
        ->latest('id')
        ->firstOrFail();

    $serviceRequest = ServiceRequest::query()
        ->where('user_id', $owner->id)
        ->latest('id')
        ->firstOrFail();

    expect($serviceRequest->prospect_id)->toBe($lead->id)
        ->and($serviceRequest->customer_id)->toBeNull()
        ->and($serviceRequest->source)->toBe('public_form')
        ->and($serviceRequest->channel)->toBe('web')
        ->and($serviceRequest->requester_email)->toBe('public.caller@example.com');
});
