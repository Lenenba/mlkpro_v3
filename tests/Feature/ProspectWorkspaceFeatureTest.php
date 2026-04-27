<?php

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\LeadNote;
use App\Models\Request as LeadRequest;
use App\Models\Task;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

function prospectWorkspaceOwner(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'company_type' => 'services',
        'company_features' => [
            'requests' => true,
        ],
        'onboarding_completed_at' => now(),
    ], $attributes));
}

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
});

it('creates a manual prospect without requiring a linked customer', function () {
    $owner = prospectWorkspaceOwner();

    $this->actingAs($owner)->post(route('prospects.store'), [
        'channel' => 'phone',
        'title' => 'Phone inquiry',
        'service_type' => 'Consultation',
        'contact_name' => 'Phone Prospect',
        'contact_email' => 'phone.prospect@example.com',
        'contact_phone' => '+1 555 0120',
        'meta' => [
            'request_type' => 'phone_inquiry',
            'contact_consent' => true,
            'marketing_consent' => false,
            'budget' => 1800,
        ],
    ])->assertRedirect();

    $lead = LeadRequest::query()
        ->where('user_id', $owner->id)
        ->latest('id')
        ->firstOrFail();
    $activity = ActivityLog::query()
        ->where('subject_type', $lead->getMorphClass())
        ->where('subject_id', $lead->id)
        ->where('action', 'created')
        ->latest('id')
        ->first();

    expect($lead->customer_id)->toBeNull()
        ->and($lead->channel)->toBe('phone')
        ->and($lead->status)->toBe(LeadRequest::STATUS_NEW)
        ->and($lead->last_activity_at)->not->toBeNull()
        ->and(data_get($lead->meta, 'intake_source'))->toBe('phone')
        ->and(data_get($lead->meta, 'request_type'))->toBe('phone_inquiry')
        ->and(data_get($lead->meta, 'contact_consent'))->toBeTrue()
        ->and(data_get($lead->meta, 'marketing_consent'))->toBeFalse()
        ->and((float) data_get($lead->meta, 'budget'))->toBe(1800.0)
        ->and($activity?->description)->toBe('Prospect created');
});

it('imports prospects from csv without auto-linking an existing customer', function () {
    $owner = prospectWorkspaceOwner();

    Customer::query()->create([
        'user_id' => $owner->id,
        'company_name' => 'CSV Existing Customer',
        'first_name' => 'CSV',
        'last_name' => 'Customer',
        'email' => 'csv.existing@example.com',
        'phone' => '+1 555 0130',
    ]);

    $file = UploadedFile::fake()->createWithContent(
        'prospects.csv',
        implode("\n", [
            'name,email,phone,source,request_type,contact_consent,marketing_consent,budget',
            'CSV Existing Customer,csv.existing@example.com,+1 555 0130,phone,estimate_request,yes,no,2500',
        ])
    );

    $this->actingAs($owner)->post(route('prospects.import'), [
        'file' => $file,
    ])->assertRedirect();

    $lead = LeadRequest::query()
        ->where('user_id', $owner->id)
        ->latest('id')
        ->firstOrFail();
    $activity = ActivityLog::query()
        ->where('subject_type', $lead->getMorphClass())
        ->where('subject_id', $lead->id)
        ->where('action', 'created')
        ->latest('id')
        ->first();

    expect($lead->customer_id)->toBeNull()
        ->and($lead->channel)->toBe('phone')
        ->and($lead->status)->toBe(LeadRequest::STATUS_NEW)
        ->and($lead->last_activity_at)->not->toBeNull()
        ->and(data_get($lead->meta, 'intake_source'))->toBe('phone')
        ->and(data_get($lead->meta, 'request_type'))->toBe('estimate_request')
        ->and(data_get($lead->meta, 'contact_consent'))->toBeTrue()
        ->and(data_get($lead->meta, 'marketing_consent'))->toBeFalse()
        ->and((float) data_get($lead->meta, 'budget'))->toBe(2500.0)
        ->and($activity?->description)->toBe('Prospect imported');

    expect(Customer::query()->where('user_id', $owner->id)->count())->toBe(1);
});

it('updates quick-action prospect fields and refreshes the activity trail', function () {
    $owner = prospectWorkspaceOwner();
    $assigneeUser = User::factory()->create();
    $assignee = TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $assigneeUser->id,
        'is_active' => true,
    ]);

    $lead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Needs follow-up',
        'contact_name' => 'Quick Action Prospect',
        'contact_email' => 'quick.action@example.com',
    ]);

    $updatedAt = Carbon::parse('2026-04-21 11:45:00');
    $followUpAt = Carbon::parse('2026-04-23 09:30:00');

    try {
        Carbon::setTestNow($updatedAt);

        $this->actingAs($owner)
            ->putJson(route('prospects.update', $lead), [
                'status' => LeadRequest::STATUS_QUALIFIED,
                'assigned_team_member_id' => $assignee->id,
                'next_follow_up_at' => $followUpAt->toDateTimeString(),
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Prospect updated successfully.')
            ->assertJsonPath('request.status', LeadRequest::STATUS_QUALIFIED)
            ->assertJsonPath('request.assigned_team_member_id', $assignee->id);
    } finally {
        Carbon::setTestNow();
    }

    $lead->refresh();

    $activity = ActivityLog::query()
        ->where('subject_type', $lead->getMorphClass())
        ->where('subject_id', $lead->id)
        ->where('action', 'updated')
        ->latest('id')
        ->first();

    expect($lead->status)->toBe(LeadRequest::STATUS_QUALIFIED)
        ->and($lead->assigned_team_member_id)->toBe($assignee->id)
        ->and($lead->status_updated_at?->equalTo($updatedAt))->toBeTrue()
        ->and($lead->last_activity_at?->equalTo($updatedAt))->toBeTrue()
        ->and($lead->next_follow_up_at?->equalTo($followUpAt))->toBeTrue()
        ->and($activity)->not->toBeNull()
        ->and($activity?->description)->toBe('Prospect updated')
        ->and(data_get($activity?->properties, 'from'))->toBe(LeadRequest::STATUS_CONTACTED)
        ->and(data_get($activity?->properties, 'to'))->toBe(LeadRequest::STATUS_QUALIFIED)
        ->and((int) data_get($activity?->properties, 'assigned_team_member_id'))->toBe($assignee->id);
});

it('returns a prospect cockpit payload with timeline, duplicates, notes, files, and tasks', function () {
    $owner = prospectWorkspaceOwner();
    $assigneeUser = User::factory()->create(['name' => 'Prospect Owner']);
    $assignee = TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $assigneeUser->id,
        'is_active' => true,
    ]);

    $lead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'assigned_team_member_id' => $assignee->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Cockpit prospect',
        'service_type' => 'Discovery call',
        'contact_name' => 'Cockpit Prospect',
        'contact_email' => 'cockpit@example.com',
        'contact_phone' => '+1 555 0188',
        'channel' => 'email',
        'last_activity_at' => Carbon::parse('2026-04-22 10:00:00'),
        'next_follow_up_at' => Carbon::parse('2026-04-24 15:00:00'),
        'meta' => [
            'request_type' => 'demo_request',
            'company_name' => 'Cockpit Inc',
        ],
    ]);

    $duplicate = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_NEW,
        'title' => 'Duplicate prospect',
        'contact_name' => 'Cockpit Duplicate',
        'contact_email' => 'cockpit@example.com',
    ]);

    LeadNote::query()->create([
        'request_id' => $lead->id,
        'user_id' => $owner->id,
        'body' => 'Qualified after intro call.',
    ]);

    \App\Models\LeadMedia::query()->create([
        'request_id' => $lead->id,
        'user_id' => $owner->id,
        'path' => 'lead-media/cockpit-brief.pdf',
        'original_name' => 'cockpit-brief.pdf',
        'mime' => 'application/pdf',
        'size' => 4096,
    ]);

    Task::query()->create([
        'account_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'assigned_team_member_id' => $assignee->id,
        'request_id' => $lead->id,
        'title' => 'Send tailored follow-up',
        'status' => Task::STATUS_TODO,
        'due_date' => '2026-04-24',
    ]);

    ActivityLog::record($owner, $lead, 'updated', [
        'from' => LeadRequest::STATUS_NEW,
        'to' => LeadRequest::STATUS_CONTACTED,
    ], 'Prospect updated');

    $this->actingAs($owner)
        ->getJson(route('prospects.show', $lead))
        ->assertOk()
        ->assertJsonPath('lead.id', $lead->id)
        ->assertJsonPath('lead.assignee.id', $assignee->id)
        ->assertJsonPath('lead.meta.request_type', 'demo_request')
        ->assertJsonPath('lead.notes.0.body', 'Qualified after intro call.')
        ->assertJsonPath('lead.media.0.original_name', 'cockpit-brief.pdf')
        ->assertJsonPath('lead.tasks.0.title', 'Send tailored follow-up')
        ->assertJsonPath('duplicates.0.id', $duplicate->id)
        ->assertJsonPath('activity.0.action', 'updated')
        ->assertJsonPath('activity.0.description', 'Prospect updated');
});

it('adds a prospect note and refreshes last activity with an audit trail', function () {
    $owner = prospectWorkspaceOwner();
    $initialActivityAt = Carbon::parse('2026-04-20 09:00:00');
    $noteCreatedAt = Carbon::parse('2026-04-20 14:15:00');

    $lead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Prospect with note',
        'contact_name' => 'Prospect Note',
        'contact_email' => 'prospect.note@example.com',
        'last_activity_at' => $initialActivityAt,
    ]);

    try {
        Carbon::setTestNow($noteCreatedAt);

        $this->actingAs($owner)->post(route('prospects.notes.store', $lead), [
            'body' => 'Called back and booked a follow-up.',
        ])->assertRedirect();
    } finally {
        Carbon::setTestNow();
    }

    $lead->refresh();

    $note = LeadNote::query()->where('request_id', $lead->id)->latest('id')->first();
    $activity = ActivityLog::query()
        ->where('subject_type', $lead->getMorphClass())
        ->where('subject_id', $lead->id)
        ->where('action', 'note_added')
        ->latest('id')
        ->first();

    expect($note)->not->toBeNull()
        ->and($note?->body)->toBe('Called back and booked a follow-up.')
        ->and($lead->last_activity_at?->equalTo($noteCreatedAt))->toBeTrue()
        ->and($activity)->not->toBeNull()
        ->and($activity?->description)->toBe('Prospect note added')
        ->and(data_get($activity?->properties, 'note_id'))->toBe($note?->id);
});

it('archives and restores a prospect without deleting it', function () {
    $owner = prospectWorkspaceOwner();

    $lead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Archive me',
        'contact_name' => 'Archived Prospect',
        'contact_email' => 'archived.prospect@example.com',
    ]);

    $this->actingAs($owner)->patch(route('prospects.archive', $lead), [
        'archive_reason' => 'Qualified later backlog',
    ])->assertRedirect();

    $lead->refresh();

    expect($lead->archived_at)->not->toBeNull()
        ->and($lead->archived_by_user_id)->toBe($owner->id)
        ->and($lead->archive_reason)->toBe('Qualified later backlog');

    $this->actingAs($owner)
        ->getJson(route('prospects.index'))
        ->assertOk()
        ->assertJsonPath('requests.total', 0);

    $this->actingAs($owner)
        ->getJson(route('prospects.index', ['archived' => 1]))
        ->assertOk()
        ->assertJsonPath('filters.archived', true)
        ->assertJsonPath('requests.total', 1)
        ->assertJsonPath('requests.data.0.id', $lead->id);

    expect(ActivityLog::query()
        ->where('subject_type', $lead->getMorphClass())
        ->where('subject_id', $lead->id)
        ->where('action', 'archived')
        ->exists())->toBeTrue();

    $this->actingAs($owner)->post(route('prospects.restore', $lead))
        ->assertRedirect();

    $lead->refresh();

    expect($lead->archived_at)->toBeNull()
        ->and($lead->archived_by_user_id)->toBeNull()
        ->and($lead->archive_reason)->toBeNull();

    $this->actingAs($owner)
        ->getJson(route('prospects.index'))
        ->assertOk()
        ->assertJsonPath('requests.total', 1)
        ->assertJsonPath('requests.data.0.id', $lead->id);

    expect(ActivityLog::query()
        ->where('subject_type', $lead->getMorphClass())
        ->where('subject_id', $lead->id)
        ->where('action', 'restored')
        ->exists())->toBeTrue();
});

it('blocks prospect mutations while archived', function () {
    $owner = prospectWorkspaceOwner();

    $lead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Archived read only prospect',
        'contact_name' => 'Archive Guard',
        'contact_email' => 'archive.guard@example.com',
        'archived_at' => now(),
        'archived_by_user_id' => $owner->id,
        'archive_reason' => 'Retention hold',
    ]);

    $json = fn () => ['Accept' => 'application/json'];

    $this->actingAs($owner)
        ->put(route('prospects.update', $lead), [
            'status' => LeadRequest::STATUS_QUALIFIED,
        ], $json())
        ->assertStatus(422)
        ->assertJsonValidationErrors('lead');

    $this->actingAs($owner)
        ->post(route('prospects.notes.store', $lead), [
            'body' => 'This note should be blocked.',
        ], $json())
        ->assertStatus(422)
        ->assertJsonValidationErrors('lead');

    $this->actingAs($owner)
        ->post(route('prospects.media.store', $lead), [
            'file' => UploadedFile::fake()->create('prospect-evidence.pdf', 32, 'application/pdf'),
        ], $json())
        ->assertStatus(422)
        ->assertJsonValidationErrors('lead');

    $this->actingAs($owner)
        ->post(route('crm.sales-activities.requests.store', $lead), [
            'action' => 'sales_note_added',
            'note' => 'Blocked activity',
        ], $json())
        ->assertStatus(422)
        ->assertJsonValidationErrors('lead');

    $this->actingAs($owner)
        ->post(route('task.store'), [
            'title' => 'Blocked archived follow-up',
            'standalone' => true,
            'request_id' => $lead->id,
        ], $json())
        ->assertStatus(422)
        ->assertJsonValidationErrors('request_id');

    $this->actingAs($owner)
        ->delete(route('prospects.destroy', $lead), [], $json())
        ->assertStatus(422)
        ->assertJsonValidationErrors('lead');

    $lead->refresh();

    expect($lead->status)->toBe(LeadRequest::STATUS_CONTACTED)
        ->and(LeadNote::query()->count())->toBe(0)
        ->and(\App\Models\LeadMedia::query()->count())->toBe(0)
        ->and(\App\Models\Task::query()->count())->toBe(0)
        ->and(ActivityLog::query()->where('action', 'sales_note_added')->exists())->toBeFalse();
});

it('blocks converting or merging archived prospects', function () {
    $owner = prospectWorkspaceOwner();

    $archivedLead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Archived target',
        'contact_name' => 'Archived Target',
        'contact_email' => 'archived.target@example.com',
        'archived_at' => now(),
        'archived_by_user_id' => $owner->id,
    ]);

    $activeLead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Active source',
        'contact_name' => 'Active Source',
        'contact_email' => 'active.source@example.com',
    ]);

    $archivedSource = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Archived source',
        'contact_name' => 'Archived Source',
        'contact_email' => 'archived.source@example.com',
        'archived_at' => now(),
        'archived_by_user_id' => $owner->id,
    ]);

    $json = fn () => ['Accept' => 'application/json'];

    $this->actingAs($owner)
        ->post(route('prospects.convert', $archivedLead), [
            'create_customer' => true,
        ], $json())
        ->assertStatus(422)
        ->assertJsonValidationErrors('lead');

    $this->actingAs($owner)
        ->post(route('prospects.merge', $archivedLead), [
            'source_id' => $activeLead->id,
        ], $json())
        ->assertStatus(422)
        ->assertJsonValidationErrors('lead');

    $this->actingAs($owner)
        ->post(route('prospects.merge', $activeLead), [
            'source_id' => $archivedSource->id,
        ], $json())
        ->assertStatus(422)
        ->assertJsonValidationErrors('source_id');

    expect(\App\Models\Quote::query()->count())->toBe(0)
        ->and(LeadRequest::query()->count())->toBe(3);
});

it('anonymizes an archived prospect and scrubs related personal data', function () {
    $owner = prospectWorkspaceOwner();

    $lead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Alice VIP follow-up',
        'service_type' => 'Consulting',
        'description' => 'Alice needs a confidential setup.',
        'contact_name' => 'Alice Sensitive',
        'contact_email' => 'anonymized.person@example.com',
        'contact_phone' => '+1 555 0155',
        'street1' => '123 Privacy Ave',
        'city' => 'Montreal',
        'archive_reason' => 'Contains personal notes',
        'lost_reason' => 'Asked to call Alice directly',
        'archived_at' => now(),
        'archived_by_user_id' => $owner->id,
        'meta' => [
            'request_type' => 'demo_request',
            'budget' => 4200,
            'source_campaign_name' => 'North Campaign',
            'company_name' => 'Sensitive Company Name',
        ],
    ]);

    $note = LeadNote::query()->create([
        'request_id' => $lead->id,
        'user_id' => $owner->id,
        'body' => 'Sensitive note about Alice.',
    ]);

    $media = \App\Models\LeadMedia::query()->create([
        'request_id' => $lead->id,
        'user_id' => $owner->id,
        'path' => 'lead-media/sensitive-proof.pdf',
        'original_name' => 'alice-proof.pdf',
        'mime' => 'application/pdf',
        'size' => 5120,
    ]);

    $task = Task::query()->create([
        'account_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'request_id' => $lead->id,
        'title' => 'Call Alice Sensitive',
        'description' => 'Discuss private details with Alice.',
    ]);

    $salesLog = ActivityLog::record($owner, $lead, 'sales_note_added', [
        'activity_source' => 'phase_4_sales_activity',
        'sales_activity_action' => 'sales_note_added',
        'sales_activity_type' => 'note',
        'logged_via' => 'crm_sales_activity',
        'note' => 'Alice prefers evening callbacks.',
        'private_note' => 'Do not keep this value.',
    ], 'Alice prefers evening callbacks.');

    $createdLog = ActivityLog::record($owner, $lead, 'created', [
        'title' => 'Alice VIP follow-up',
        'service_type' => 'Consulting',
    ], 'Request created');

    $this->actingAs($owner)
        ->patchJson(route('prospects.anonymize', $lead), [
            'anonymization_reason' => 'GDPR cleanup',
        ])
        ->assertOk()
        ->assertJsonPath('request.contact_name', null)
        ->assertJsonPath('request.contact_email', null)
        ->assertJsonPath('request.meta.privacy.anonymized_by_user_id', $owner->id);

    $lead->refresh();
    $note->refresh();
    $task->refresh();
    $salesLog->refresh();
    $createdLog->refresh();

    expect($lead->isAnonymized())->toBeTrue()
        ->and($lead->contact_name)->toBeNull()
        ->and($lead->contact_email)->toBeNull()
        ->and($lead->contact_phone)->toBeNull()
        ->and($lead->description)->toBeNull()
        ->and($lead->street1)->toBeNull()
        ->and($lead->city)->toBeNull()
        ->and($lead->archive_reason)->toBeNull()
        ->and($lead->lost_reason)->toBeNull()
        ->and(data_get($lead->meta, 'request_type'))->toBe('demo_request')
        ->and((float) data_get($lead->meta, 'budget'))->toBe(4200.0)
        ->and(data_get($lead->meta, 'source_campaign_name'))->toBe('North Campaign')
        ->and(data_get($lead->meta, 'company_name'))->toBeNull()
        ->and(data_get($lead->meta, 'privacy.anonymization_reason'))->toBe('GDPR cleanup')
        ->and(data_get($lead->meta, 'privacy.contact_email_sha1'))->toBe(sha1('anonymized.person@example.com'))
        ->and(data_get($lead->meta, 'privacy.contact_phone_sha1'))->toBe(sha1('15550155'))
        ->and(data_get($lead->meta, 'privacy.notes_scrubbed_count'))->toBe(1)
        ->and(data_get($lead->meta, 'privacy.media_deleted_count'))->toBe(1)
        ->and(data_get($lead->meta, 'privacy.tasks_detached_count'))->toBe(1);

    expect($note->body)->toBe('[Anonymized prospect note]');
    expect(\App\Models\LeadMedia::query()->whereKey($media->id)->exists())->toBeFalse();
    expect($task->request_id)->toBeNull()
        ->and($task->customer_id)->toBeNull()
        ->and($task->title)->toBe('Anonymized prospect task #'.$task->id)
        ->and($task->description)->toBeNull();

    expect($salesLog->description)->toBe('Commercial note added')
        ->and(data_get($salesLog->properties, 'sales_activity_action'))->toBe('sales_note_added')
        ->and(data_get($salesLog->properties, 'sales_activity_type'))->toBe('note')
        ->and(data_get($salesLog->properties, 'note'))->toBeNull()
        ->and(data_get($salesLog->properties, 'private_note'))->toBeNull();

    expect($createdLog->description)->toBe('Prospect created')
        ->and(data_get($createdLog->properties, 'service_type'))->toBe('Consulting')
        ->and(data_get($createdLog->properties, 'title'))->toBeNull();

    expect(ActivityLog::query()
        ->where('subject_type', $lead->getMorphClass())
        ->where('subject_id', $lead->id)
        ->where('action', 'anonymized')
        ->exists())->toBeTrue();
});

it('blocks invalid anonymization flows and prevents restoring anonymized prospects', function () {
    $owner = prospectWorkspaceOwner();

    $customer = Customer::query()->create([
        'user_id' => $owner->id,
        'company_name' => 'Linked Customer',
        'first_name' => 'Linked',
        'last_name' => 'Customer',
        'email' => 'linked.customer@example.com',
        'phone' => '+1 555 0199',
    ]);

    $activeLead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Active prospect',
        'contact_name' => 'Active Prospect',
        'contact_email' => 'active.prospect@example.com',
    ]);

    $linkedLead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Linked archived prospect',
        'contact_name' => 'Linked Prospect',
        'contact_email' => 'linked.prospect@example.com',
        'archived_at' => now(),
        'archived_by_user_id' => $owner->id,
    ]);

    $archivedLead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Archived anonymizable prospect',
        'contact_name' => 'Archived Prospect',
        'contact_email' => 'archived.anonymize@example.com',
        'archived_at' => now(),
        'archived_by_user_id' => $owner->id,
    ]);

    $this->actingAs($owner)
        ->patchJson(route('prospects.anonymize', $activeLead), [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('lead');

    $this->actingAs($owner)
        ->patchJson(route('prospects.anonymize', $linkedLead), [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('lead');

    $this->actingAs($owner)
        ->patchJson(route('prospects.anonymize', $archivedLead), [
            'anonymization_reason' => 'Retention expiry',
        ])
        ->assertOk();

    $archivedLead->refresh();

    expect($archivedLead->isAnonymized())->toBeTrue();

    $this->actingAs($owner)
        ->post(route('prospects.restore', $archivedLead), [], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('lead');

    $this->actingAs($owner)
        ->patch(route('prospects.archive', $archivedLead), [
            'archive_reason' => 'This should not be accepted',
        ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('lead');

    $archivedLead->refresh();

    expect($activeLead->fresh()?->isAnonymized())->toBeFalse()
        ->and($linkedLead->fresh()?->isAnonymized())->toBeFalse()
        ->and($archivedLead->archive_reason)->toBeNull();
});

it('soft deletes only archived anonymized prospects and keeps the audit trail', function () {
    $owner = prospectWorkspaceOwner();

    $activeLead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Active deletion candidate',
        'contact_name' => 'Active Candidate',
        'contact_email' => 'active.delete@example.com',
    ]);

    $archivedLead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Archived only candidate',
        'contact_name' => 'Archived Candidate',
        'contact_email' => 'archived.delete@example.com',
        'archived_at' => now(),
        'archived_by_user_id' => $owner->id,
    ]);

    $anonymizedLead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Archived anonymized candidate',
        'contact_name' => 'Archived Anonymized',
        'contact_email' => 'anonymized.delete@example.com',
        'archived_at' => now(),
        'archived_by_user_id' => $owner->id,
    ]);

    $this->actingAs($owner)
        ->patchJson(route('prospects.anonymize', $anonymizedLead), [
            'anonymization_reason' => 'Retention cleanup',
        ])
        ->assertOk();

    $this->actingAs($owner)
        ->deleteJson(route('prospects.destroy', $activeLead))
        ->assertStatus(422)
        ->assertJsonValidationErrors('lead');

    $this->actingAs($owner)
        ->deleteJson(route('prospects.destroy', $archivedLead))
        ->assertStatus(422)
        ->assertJsonValidationErrors('lead');

    $this->actingAs($owner)
        ->deleteJson(route('prospects.destroy', $anonymizedLead))
        ->assertOk()
        ->assertJsonPath('message', 'Prospect deleted.');

    $anonymizedLead = LeadRequest::withTrashed()->findOrFail($anonymizedLead->id);

    expect(LeadRequest::query()->whereKey($activeLead->id)->exists())->toBeTrue()
        ->and(LeadRequest::query()->whereKey($archivedLead->id)->exists())->toBeTrue()
        ->and(LeadRequest::query()->whereKey($anonymizedLead->id)->exists())->toBeFalse()
        ->and($anonymizedLead->trashed())->toBeTrue()
        ->and($anonymizedLead->deleted_at)->not->toBeNull()
        ->and($anonymizedLead->deleted_by_user_id)->toBe($owner->id);

    expect(ActivityLog::query()
        ->where('subject_type', $anonymizedLead->getMorphClass())
        ->where('subject_id', $anonymizedLead->id)
        ->where('action', 'deleted')
        ->exists())->toBeTrue();

    $this->actingAs($owner)
        ->getJson(route('prospects.show', $anonymizedLead->id))
        ->assertNotFound();
});
