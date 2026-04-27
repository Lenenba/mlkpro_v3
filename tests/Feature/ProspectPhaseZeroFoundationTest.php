<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\Request as LeadRequest;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
});

test('prospects routes resolve to the legacy request workspace during phase zero', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_sector' => 'service_general',
        'onboarding_completed_at' => now(),
        'company_features' => [
            'requests' => true,
        ],
    ]);

    $lead = LeadRequest::create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_NEW,
        'status_updated_at' => now(),
        'title' => 'Phase zero prospect',
        'contact_name' => 'Prospect Alpha',
        'contact_email' => 'prospect-alpha@example.com',
    ]);

    $this->actingAs($owner)
        ->get(route('prospects.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Request/Index'));

    $this->actingAs($owner)
        ->getJson(route('prospects.show', $lead))
        ->assertOk()
        ->assertJsonPath('lead.id', $lead->id)
        ->assertJsonFragment([
            'id' => LeadRequest::STATUS_NEW,
            'name' => 'New',
        ]);
});

test('team member permission aliases bridge requests and prospects during phase zero', function () {
    $legacyMember = TeamMember::factory()->make([
        'permissions' => ['requests.view', 'requests.edit'],
    ]);

    expect($legacyMember->hasPermission('prospects.view'))->toBeTrue();
    expect($legacyMember->hasPermission('prospects.edit'))->toBeTrue();

    $prospectMember = TeamMember::factory()->make([
        'permissions' => ['prospects.assign', 'prospects.convert'],
    ]);

    expect($prospectMember->hasPermission('requests.assign'))->toBeTrue();
    expect($prospectMember->hasPermission('requests.convert'))->toBeTrue();
});
