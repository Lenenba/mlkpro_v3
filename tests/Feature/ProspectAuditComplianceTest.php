<?php

use App\Models\ActivityLog;
use App\Models\Request as LeadRequest;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function prospectAuditOwner(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'company_type' => 'services',
        'company_features' => [
            'requests' => true,
            'quotes' => true,
            'team_members' => true,
        ],
        'onboarding_completed_at' => now(),
    ], $attributes));
}

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
});

it('records prospect creation in the audit trail', function () {
    $owner = prospectAuditOwner();

    $this->actingAs($owner)
        ->postJson(route('prospects.store'), [
            'channel' => 'email',
            'title' => 'Audit trail creation',
            'service_type' => 'Inspection',
            'contact_name' => 'Audit Prospect',
            'contact_email' => 'audit.creation@example.com',
        ])
        ->assertCreated()
        ->assertJsonPath('message', 'Prospect created successfully.');

    $lead = LeadRequest::query()->latest('id')->firstOrFail();
    $activity = ActivityLog::query()
        ->where('subject_type', $lead->getMorphClass())
        ->where('subject_id', $lead->id)
        ->where('action', 'created')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity?->description)->toBe('Prospect created')
        ->and(data_get($activity?->properties, 'title'))->toBe('Audit trail creation')
        ->and(data_get($activity?->properties, 'service_type'))->toBe('Inspection');
});

it('records explicit status and assignment audit entries on prospect updates', function () {
    $owner = prospectAuditOwner();
    $firstAssigneeUser = User::factory()->create(['name' => 'First Assignee']);
    $secondAssigneeUser = User::factory()->create(['name' => 'Second Assignee']);
    $firstAssignee = TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $firstAssigneeUser->id,
        'is_active' => true,
    ]);
    $secondAssignee = TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $secondAssigneeUser->id,
        'is_active' => true,
    ]);

    $lead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'assigned_team_member_id' => $firstAssignee->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'status_updated_at' => now(),
        'last_activity_at' => now(),
        'title' => 'Audit update prospect',
        'contact_name' => 'Update Prospect',
        'contact_email' => 'audit.update@example.com',
    ]);

    $this->actingAs($owner)
        ->putJson(route('prospects.update', $lead), [
            'status' => LeadRequest::STATUS_QUALIFIED,
            'assigned_team_member_id' => $secondAssignee->id,
        ])
        ->assertOk()
        ->assertJsonPath('request.status', LeadRequest::STATUS_QUALIFIED)
        ->assertJsonPath('request.assigned_team_member_id', $secondAssignee->id);

    $statusActivity = ActivityLog::query()
        ->where('subject_type', $lead->getMorphClass())
        ->where('subject_id', $lead->id)
        ->where('action', 'status_changed')
        ->latest('id')
        ->first();
    $assignmentActivity = ActivityLog::query()
        ->where('subject_type', $lead->getMorphClass())
        ->where('subject_id', $lead->id)
        ->where('action', 'assignment_changed')
        ->latest('id')
        ->first();
    $updatedActivity = ActivityLog::query()
        ->where('subject_type', $lead->getMorphClass())
        ->where('subject_id', $lead->id)
        ->where('action', 'updated')
        ->latest('id')
        ->first();

    expect($statusActivity)->not->toBeNull()
        ->and(data_get($statusActivity?->properties, 'from_status'))->toBe(LeadRequest::STATUS_CONTACTED)
        ->and(data_get($statusActivity?->properties, 'to_status'))->toBe(LeadRequest::STATUS_QUALIFIED)
        ->and(data_get($statusActivity?->properties, 'source'))->toBe('manual_update')
        ->and($assignmentActivity)->not->toBeNull()
        ->and((int) data_get($assignmentActivity?->properties, 'from_assigned_team_member_id'))->toBe($firstAssignee->id)
        ->and((int) data_get($assignmentActivity?->properties, 'to_assigned_team_member_id'))->toBe($secondAssignee->id)
        ->and(data_get($assignmentActivity?->properties, 'source'))->toBe('manual_update')
        ->and($updatedActivity)->not->toBeNull()
        ->and((int) data_get($updatedActivity?->properties, 'assigned_team_member_id'))->toBe($secondAssignee->id);
});

it('records merge audit entries for both retained and duplicate prospects', function () {
    $owner = prospectAuditOwner();

    $primary = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'status_updated_at' => now(),
        'last_activity_at' => now(),
        'title' => 'Primary audit prospect',
        'contact_name' => 'Primary Prospect',
        'contact_email' => 'primary.audit@example.com',
    ]);

    $secondary = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_NEW,
        'status_updated_at' => now(),
        'last_activity_at' => now(),
        'title' => 'Secondary audit prospect',
        'contact_name' => 'Secondary Prospect',
        'contact_email' => 'secondary.audit@example.com',
    ]);

    $this->actingAs($owner)
        ->postJson(route('prospects.merge', $primary), [
            'source_id' => $secondary->id,
        ])
        ->assertOk()
        ->assertJsonPath('message', 'Prospect merged.');

    expect(ActivityLog::query()
        ->where('subject_type', $primary->getMorphClass())
        ->where('subject_id', $primary->id)
        ->where('action', 'merged')
        ->exists())->toBeTrue()
        ->and(ActivityLog::query()
            ->where('subject_type', $secondary->getMorphClass())
            ->where('subject_id', $secondary->id)
            ->where('action', 'merged_into')
            ->exists())->toBeTrue();
});

it('records conversion and status audit entries for quote and customer conversions', function () {
    $owner = prospectAuditOwner();

    $quoteLead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'status_updated_at' => now(),
        'last_activity_at' => now(),
        'title' => 'Quote conversion audit',
        'contact_name' => 'Quote Prospect',
        'contact_email' => 'quote.audit@example.com',
    ]);

    $this->actingAs($owner)
        ->postJson(route('prospects.convert', $quoteLead), [
            'create_customer' => true,
            'customer_name' => 'Quote Audit Customer',
            'contact_name' => 'Quote Prospect',
            'contact_email' => 'quote.audit@example.com',
            'job_title' => 'Audit Quote',
        ])
        ->assertOk()
        ->assertJsonPath('message', 'Prospect converted to quote.');

    $quoteLead->refresh();

    $quoteStatusActivity = ActivityLog::query()
        ->where('subject_type', $quoteLead->getMorphClass())
        ->where('subject_id', $quoteLead->id)
        ->where('action', 'status_changed')
        ->latest('id')
        ->first();

    expect(ActivityLog::query()
        ->where('subject_type', $quoteLead->getMorphClass())
        ->where('subject_id', $quoteLead->id)
        ->where('action', 'converted')
        ->exists())->toBeTrue()
        ->and($quoteStatusActivity)->not->toBeNull()
        ->and(data_get($quoteStatusActivity?->properties, 'source'))->toBe('quote_conversion')
        ->and(data_get($quoteStatusActivity?->properties, 'from_status'))->toBe(LeadRequest::STATUS_CONTACTED)
        ->and(data_get($quoteStatusActivity?->properties, 'to_status'))->toBe(LeadRequest::STATUS_QUALIFIED);

    $customerLead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_QUALIFIED,
        'status_updated_at' => now(),
        'last_activity_at' => now(),
        'title' => 'Customer conversion audit',
        'contact_name' => 'Customer Prospect',
        'contact_email' => 'customer.audit@example.com',
        'contact_phone' => '+1 438 555 0130',
        'meta' => [
            'company_name' => 'Audit Conversion Studio',
        ],
    ]);

    $this->actingAs($owner)
        ->postJson(route('prospects.convert-customer', $customerLead), [
            'mode' => 'create_new',
            'contact_name' => 'Customer Prospect',
            'contact_email' => 'customer.audit@example.com',
            'contact_phone' => '+1 438 555 0130',
            'company_name' => 'Audit Conversion Studio',
        ])
        ->assertOk()
        ->assertJsonPath('message', 'Prospect converted to customer.')
        ->assertJsonPath('request.status', LeadRequest::STATUS_CONVERTED);

    $customerLead->refresh();

    $customerStatusActivity = ActivityLog::query()
        ->where('subject_type', $customerLead->getMorphClass())
        ->where('subject_id', $customerLead->id)
        ->where('action', 'status_changed')
        ->latest('id')
        ->first();

    expect(ActivityLog::query()
        ->where('subject_type', $customerLead->getMorphClass())
        ->where('subject_id', $customerLead->id)
        ->where('action', 'converted_to_customer')
        ->exists())->toBeTrue()
        ->and($customerStatusActivity)->not->toBeNull()
        ->and(data_get($customerStatusActivity?->properties, 'source'))->toBe('customer_conversion')
        ->and(data_get($customerStatusActivity?->properties, 'from_status'))->toBe(LeadRequest::STATUS_QUALIFIED)
        ->and(data_get($customerStatusActivity?->properties, 'to_status'))->toBe(LeadRequest::STATUS_CONVERTED);
});

it('allows exporting prospects only to authorized users and logs the export', function () {
    $owner = prospectAuditOwner();
    $lead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_NEW,
        'status_updated_at' => now(),
        'last_activity_at' => now(),
        'title' => 'Exportable prospect',
        'contact_name' => 'Export Prospect',
        'contact_email' => 'export.audit@example.com',
        'channel' => 'phone',
    ]);

    $memberUser = User::factory()->create([
        'company_type' => 'services',
        'company_features' => [
            'requests' => true,
        ],
        'onboarding_completed_at' => now(),
    ]);
    TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $memberUser->id,
        'role' => 'member',
        'permissions' => ['prospects.view'],
        'is_active' => true,
    ]);

    $this->actingAs($memberUser)
        ->getJson(route('prospects.export'))
        ->assertForbidden();

    $exportUser = User::factory()->create([
        'company_type' => 'services',
        'company_features' => [
            'requests' => true,
        ],
        'onboarding_completed_at' => now(),
    ]);
    TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $exportUser->id,
        'role' => 'member',
        'permissions' => ['requests.export'],
        'is_active' => true,
    ]);

    $response = $this->actingAs($exportUser)
        ->get(route('prospects.export', [
            'status' => LeadRequest::STATUS_NEW,
        ]));

    $response->assertOk();

    $csv = $response->streamedContent();
    $activity = ActivityLog::query()
        ->where('subject_type', $exportUser->getMorphClass())
        ->where('subject_id', $exportUser->id)
        ->where('action', 'prospect_export')
        ->latest('id')
        ->first();

    expect((string) $response->headers->get('content-type'))->toContain('text/csv')
        ->and($csv)->toContain('contact_email')
        ->and($csv)->toContain('export.audit@example.com')
        ->and($activity)->not->toBeNull()
        ->and((int) data_get($activity?->properties, 'row_count'))->toBe(1)
        ->and(data_get($activity?->properties, 'filters.status'))->toBe(LeadRequest::STATUS_NEW)
        ->and(data_get($activity?->properties, 'exported_ids'))->toBe([$lead->id]);
});
