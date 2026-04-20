<?php

use App\Models\Customer;
use App\Models\SavedSegment;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lets the account owner create list update and delete crm saved segments', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_features' => [
            'requests' => true,
            'quotes' => true,
        ],
    ]);

    $createResponse = $this->actingAs($owner)->postJson(route('crm.saved-segments.store'), [
        'module' => SavedSegment::MODULE_REQUEST,
        'name' => 'Due Montreal leads',
        'filters' => [
            'queue' => 'due_soon',
            'status' => 'REQ_CONTACTED',
        ],
        'search_term' => 'Montreal',
    ]);

    $createResponse->assertCreated()
        ->assertJsonPath('segment.module', SavedSegment::MODULE_REQUEST)
        ->assertJsonPath('segment.name', 'Due Montreal leads')
        ->assertJsonPath('segment.filters.queue', 'due_soon')
        ->assertJsonPath('segment.search_term', 'Montreal');

    $segmentId = $createResponse->json('segment.id');

    $this->actingAs($owner)
        ->getJson(route('crm.saved-segments.index', ['module' => SavedSegment::MODULE_REQUEST]))
        ->assertOk()
        ->assertJsonCount(1, 'segments')
        ->assertJsonPath('segments.0.id', $segmentId)
        ->assertJsonPath('segments.0.name', 'Due Montreal leads');

    $this->actingAs($owner)
        ->putJson(route('crm.saved-segments.update', $segmentId), [
            'name' => 'Breached Montreal leads',
            'filters' => [
                'queue' => 'breached',
            ],
            'search_term' => 'Montreal urgent',
        ])
        ->assertOk()
        ->assertJsonPath('segment.name', 'Breached Montreal leads')
        ->assertJsonPath('segment.filters.queue', 'breached')
        ->assertJsonPath('segment.search_term', 'Montreal urgent');

    $this->actingAs($owner)
        ->deleteJson(route('crm.saved-segments.destroy', $segmentId))
        ->assertOk();

    expect(SavedSegment::query()->count())->toBe(0);
});

it('blocks team members from managing owner crm saved segments in phase three ui', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_features' => [
            'requests' => true,
            'quotes' => true,
        ],
    ]);
    $member = User::factory()->create(['company_type' => 'services']);

    TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $member->id,
        'permissions' => ['customers.view'],
        'is_active' => true,
    ]);

    $segment = SavedSegment::create([
        'user_id' => $owner->id,
        'module' => SavedSegment::MODULE_CUSTOMER,
        'name' => 'VIP customers',
    ]);

    $this->actingAs($member)
        ->getJson(route('crm.saved-segments.index', ['module' => SavedSegment::MODULE_CUSTOMER]))
        ->assertForbidden();

    $this->actingAs($member)
        ->postJson(route('crm.saved-segments.store'), [
            'module' => SavedSegment::MODULE_CUSTOMER,
            'name' => 'Member should not save',
        ])
        ->assertForbidden();

    $this->actingAs($member)
        ->deleteJson(route('crm.saved-segments.destroy', $segment))
        ->assertForbidden();
});

it('exposes saved segment props on request customer and quote indexes for the owner', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_features' => [
            'requests' => true,
            'quotes' => true,
        ],
    ]);

    Customer::create([
        'user_id' => $owner->id,
        'first_name' => 'Index',
        'last_name' => 'Customer',
        'company_name' => 'Index Co',
        'email' => 'index@example.com',
        'salutation' => 'Mr',
    ]);

    SavedSegment::create([
        'user_id' => $owner->id,
        'module' => SavedSegment::MODULE_REQUEST,
        'name' => 'Request inbox',
    ]);
    SavedSegment::create([
        'user_id' => $owner->id,
        'module' => SavedSegment::MODULE_CUSTOMER,
        'name' => 'Customer list',
    ]);
    SavedSegment::create([
        'user_id' => $owner->id,
        'module' => SavedSegment::MODULE_QUOTE,
        'name' => 'Quote recovery',
    ]);

    $this->actingAs($owner)
        ->getJson(route('request.index'))
        ->assertOk()
        ->assertJsonPath('canManageSavedSegments', true)
        ->assertJsonCount(1, 'savedSegments')
        ->assertJsonPath('savedSegments.0.module', SavedSegment::MODULE_REQUEST)
        ->assertJsonPath('savedSegments.0.name', 'Request inbox');

    $this->actingAs($owner)
        ->getJson(route('customer.index'))
        ->assertOk()
        ->assertJsonPath('canManageSavedSegments', true)
        ->assertJsonCount(1, 'savedSegments')
        ->assertJsonPath('savedSegments.0.module', SavedSegment::MODULE_CUSTOMER)
        ->assertJsonPath('savedSegments.0.name', 'Customer list');

    $this->actingAs($owner)
        ->getJson(route('quote.index'))
        ->assertOk()
        ->assertJsonPath('canManageSavedSegments', true)
        ->assertJsonCount(1, 'savedSegments')
        ->assertJsonPath('savedSegments.0.module', SavedSegment::MODULE_QUOTE)
        ->assertJsonPath('savedSegments.0.name', 'Quote recovery');
});

it('keeps customer saved segments hidden on the index for team members', function () {
    $owner = User::factory()->create(['company_type' => 'services']);
    $member = User::factory()->create(['company_type' => 'services']);

    TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $member->id,
        'permissions' => ['customers.view'],
        'is_active' => true,
    ]);

    SavedSegment::create([
        'user_id' => $owner->id,
        'module' => SavedSegment::MODULE_CUSTOMER,
        'name' => 'Archived customers',
    ]);

    $this->actingAs($member)
        ->getJson(route('customer.index'))
        ->assertOk()
        ->assertJsonPath('canManageSavedSegments', false)
        ->assertJsonCount(0, 'savedSegments');
});
