<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\Customer;
use App\Models\Request as LeadRequest;
use App\Models\ServiceRequest;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function serviceRequestWorkspaceOwner(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'company_type' => 'services',
        'company_features' => [
            'requests' => true,
            'team_members' => true,
        ],
        'onboarding_completed_at' => now(),
    ], $attributes));
}

beforeEach(function () {
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
});

it('renders the service requests workspace index and detail pages', function () {
    $owner = serviceRequestWorkspaceOwner();

    $customer = Customer::query()->create([
        'user_id' => $owner->id,
        'company_name' => 'Northwind Services',
        'first_name' => 'Nina',
        'last_name' => 'Northwind',
        'email' => 'northwind@example.test',
        'phone' => '+1 555 0100',
    ]);

    $prospect = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Existing heating prospect',
        'service_type' => 'Heating',
        'contact_name' => 'Jordan Prospect',
        'contact_email' => 'jordan@example.test',
    ]);

    $serviceRequest = ServiceRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'prospect_id' => $prospect->id,
        'source' => 'manual_admin',
        'channel' => 'phone',
        'status' => ServiceRequest::STATUS_NEW,
        'request_type' => 'inspection',
        'service_type' => 'Heating',
        'title' => 'Emergency furnace inspection',
        'description' => 'Heating stopped this morning.',
        'requester_name' => 'Jordan Prospect',
        'requester_email' => 'jordan@example.test',
        'requester_phone' => '+1 555 0101',
        'city' => 'Montreal',
        'submitted_at' => now(),
        'meta' => [
            'urgency' => 'high',
            'budget' => 450,
        ],
    ]);

    $this->actingAs($owner)
        ->get(route('service-requests.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ServiceRequests/Index')
            ->where('stats.total', 1)
            ->where('stats.new', 1)
            ->where('serviceRequests.data.0.id', $serviceRequest->id)
            ->where('serviceRequests.data.0.customer.id', $customer->id)
            ->where('serviceRequests.data.0.prospect.id', $prospect->id)
        );

    $this->actingAs($owner)
        ->get(route('service-requests.show', $serviceRequest))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ServiceRequests/Show')
            ->where('serviceRequest.id', $serviceRequest->id)
            ->where('serviceRequest.customer.id', $customer->id)
            ->where('serviceRequest.prospect.id', $prospect->id)
            ->where('serviceRequest.meta.urgency', 'high')
        );
});

it('allows sales managers to access the service requests module but blocks members without sales permissions', function () {
    $owner = serviceRequestWorkspaceOwner();

    $serviceRequest = ServiceRequest::query()->create([
        'user_id' => $owner->id,
        'source' => 'manual_admin',
        'status' => ServiceRequest::STATUS_PENDING,
        'service_type' => 'Inspection',
        'title' => 'Pending service request',
        'requester_name' => 'Alex Requester',
        'submitted_at' => now(),
    ]);

    $manager = User::factory()->create([
        'company_type' => 'services',
        'onboarding_completed_at' => now(),
    ]);

    TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $manager->id,
        'role' => 'sales_manager',
        'permissions' => ['sales.manage'],
        'is_active' => true,
    ]);

    $member = User::factory()->create([
        'company_type' => 'services',
        'onboarding_completed_at' => now(),
    ]);

    TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $member->id,
        'role' => 'member',
        'permissions' => ['quotes.view'],
        'is_active' => true,
    ]);

    $this->actingAs($manager)
        ->getJson(route('service-requests.index'))
        ->assertOk()
        ->assertJsonPath('stats.total', 1);

    $this->actingAs($manager)
        ->getJson(route('service-requests.show', $serviceRequest))
        ->assertOk()
        ->assertJsonPath('serviceRequest.id', $serviceRequest->id);

    $this->actingAs($member)
        ->getJson(route('service-requests.index'))
        ->assertForbidden();

    $this->actingAs($member)
        ->getJson(route('service-requests.show', $serviceRequest))
        ->assertForbidden();
});

it('returns service requests in global search for request-enabled workspaces', function () {
    $owner = serviceRequestWorkspaceOwner();

    $serviceRequest = ServiceRequest::query()->create([
        'user_id' => $owner->id,
        'source' => 'manual_admin',
        'status' => ServiceRequest::STATUS_IN_PROGRESS,
        'service_type' => 'Electrical',
        'title' => 'Electrical upgrade request',
        'requester_name' => 'Taylor Search',
        'submitted_at' => now(),
    ]);

    $response = $this->actingAs($owner)
        ->getJson(route('global.search', ['q' => 'Electrical']));

    $response->assertOk();

    $group = collect($response->json('groups'))->firstWhere('type', 'service_requests');

    expect($group)->not->toBeNull()
        ->and(data_get($group, 'items.0.id'))->toBe($serviceRequest->id)
        ->and(data_get($group, 'items.0.url'))->toBe(route('service-requests.show', $serviceRequest->id));
});
