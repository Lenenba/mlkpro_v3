<?php

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\LeadMedia;
use App\Models\LeadNote;
use App\Models\ProspectInteraction;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\TeamMember;
use App\Models\Task;
use App\Models\User;
use App\Services\Prospects\ProspectDuplicateDetectionService;
use Illuminate\Http\UploadedFile;

function prospectDuplicateOwner(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'company_type' => 'services',
        'company_features' => [
            'requests' => true,
        ],
        'onboarding_completed_at' => now(),
    ], $attributes));
}

it('scores and ranks duplicate prospects with explicit reason codes', function () {
    $owner = prospectDuplicateOwner();
    $assigneeUser = User::factory()->create(['name' => 'Closer']);
    $assignee = TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $assigneeUser->id,
        'is_active' => true,
    ]);

    $lead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Northwind expansion',
        'contact_name' => 'Alicia Tremblay',
        'contact_email' => 'alicia@example.com',
        'contact_phone' => '+1 (514) 555-0101',
        'street1' => '123 Main Street',
        'city' => 'Montreal',
        'postal_code' => 'H2H 2H2',
        'meta' => [
            'company_name' => 'Northwind Electric',
        ],
    ]);

    $strongDuplicate = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'assigned_team_member_id' => $assignee->id,
        'status' => LeadRequest::STATUS_NEW,
        'title' => 'Callback from trade show',
        'contact_name' => 'Alicia T.',
        'contact_email' => 'ALICIA@example.com',
        'contact_phone' => '+1 514-555-0101',
    ]);

    $scoredDuplicate = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_NEW,
        'title' => 'Northwind office move',
        'contact_name' => 'Alicia Tremblay',
        'street1' => '123 Main Street',
        'city' => 'Montreal',
        'postal_code' => 'H2H2H2',
        'meta' => [
            'company_name' => 'northwind electric',
        ],
    ]);

    LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_NEW,
        'title' => 'Weak company-only overlap',
        'contact_name' => 'Different Contact',
        'meta' => [
            'company_name' => 'Northwind Electric',
        ],
    ]);

    LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_NEW,
        'title' => 'Archived exact duplicate',
        'contact_name' => 'Alicia Tremblay',
        'contact_email' => 'alicia@example.com',
        'archived_at' => now(),
        'archived_by_user_id' => $owner->id,
    ]);

    $duplicates = app(ProspectDuplicateDetectionService::class)->forLead($lead);

    expect($duplicates)->toHaveCount(2)
        ->and($duplicates[0]['id'])->toBe($strongDuplicate->id)
        ->and($duplicates[0]['duplicate_score'])->toBe(100)
        ->and($duplicates[0]['duplicate_reason_codes'])->toContain('email_exact', 'phone_exact')
        ->and($duplicates[1]['id'])->toBe($scoredDuplicate->id)
        ->and($duplicates[1]['duplicate_score'])->toBeGreaterThanOrEqual(95)
        ->and($duplicates[1]['duplicate_reason_codes'])->toContain('name_exact', 'company_exact');
});

it('exposes ranked duplicate metadata on the prospect detail payload', function () {
    $owner = prospectDuplicateOwner();

    $lead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Prospect under review',
        'contact_name' => 'Jamie Collins',
        'contact_email' => 'jamie@example.com',
        'contact_phone' => '+1 438 555 0102',
        'street1' => '89 Rue Centrale',
        'city' => 'Quebec',
        'postal_code' => 'G1G 1G1',
        'meta' => [
            'company_name' => 'Central Works',
        ],
    ]);

    $duplicate = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_NEW,
        'title' => 'Possible duplicate',
        'contact_name' => 'Jamie Collins',
        'contact_email' => 'JAMIE@example.com',
        'contact_phone' => '+1-438-555-0102',
        'meta' => [
            'company_name' => 'Central Works',
        ],
    ]);

    $this->actingAs($owner)
        ->getJson(route('prospects.show', $lead))
        ->assertOk()
        ->assertJsonPath('duplicates.0.id', $duplicate->id)
        ->assertJsonPath('duplicates.0.duplicate_score', 100)
        ->assertJsonPath('duplicates.0.duplicate_reason_codes.0', 'email_exact')
        ->assertJsonPath('duplicates.0.duplicate_reason_codes.1', 'phone_exact');
});

it('warns before manual prospect creation when json duplicate matches exist', function () {
    $owner = prospectDuplicateOwner();
    $json = fn () => ['Accept' => 'application/json'];

    $existing = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_NEW,
        'title' => 'Existing matching prospect',
        'contact_name' => 'Morgan Lee',
        'contact_email' => 'morgan@example.com',
        'contact_phone' => '+1 514 555 0110',
    ]);

    $payload = [
        'channel' => 'phone',
        'title' => 'New inbound callback',
        'contact_name' => 'Morgan Lee',
        'contact_email' => 'morgan@example.com',
        'contact_phone' => '+1 514 555 0110',
    ];

    $this->actingAs($owner)
        ->post(route('prospects.store'), $payload, $json())
        ->assertStatus(409)
        ->assertJsonPath('duplicate_alert.context', 'create')
        ->assertJsonPath('duplicate_alert.entries.0.duplicates.0.id', $existing->id);

    expect(LeadRequest::query()->where('user_id', $owner->id)->count())->toBe(1);

    $this->actingAs($owner)
        ->post(route('prospects.store'), [
            ...$payload,
            'ignore_duplicates' => true,
        ], $json())
        ->assertCreated()
        ->assertJsonPath('message', 'Prospect created successfully.');

    expect(LeadRequest::query()->where('user_id', $owner->id)->count())->toBe(2);
});

it('warns before csv import when rows match existing prospects', function () {
    $owner = prospectDuplicateOwner();
    $json = fn () => ['Accept' => 'application/json'];

    $existing = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_NEW,
        'title' => 'Existing import duplicate',
        'contact_name' => 'Casey Jordan',
        'contact_email' => 'casey@example.com',
        'contact_phone' => '+1 438 555 0111',
    ]);

    $duplicateFile = UploadedFile::fake()->createWithContent(
        'prospects.csv',
        implode("\n", [
            'name,email,phone,service_type',
            'Casey Jordan,casey@example.com,+1 438 555 0111,Inspection',
        ])
    );

    $this->actingAs($owner)
        ->post(route('prospects.import'), [
            'file' => $duplicateFile,
        ], $json())
        ->assertStatus(409)
        ->assertJsonPath('duplicate_alert.context', 'import')
        ->assertJsonPath('duplicate_alert.entries.0.row_number', 2)
        ->assertJsonPath('duplicate_alert.entries.0.duplicates.0.id', $existing->id);

    expect(LeadRequest::query()->where('user_id', $owner->id)->count())->toBe(1);

    $continueFile = UploadedFile::fake()->createWithContent(
        'prospects.csv',
        implode("\n", [
            'name,email,phone,service_type',
            'Casey Jordan,casey@example.com,+1 438 555 0111,Inspection',
        ])
    );

    $this->actingAs($owner)
        ->post(route('prospects.import'), [
            'file' => $continueFile,
            'ignore_duplicates' => true,
        ], $json())
        ->assertOk()
        ->assertJsonPath('message', 'Prospects imported successfully.')
        ->assertJsonPath('imported', 1);

    expect(LeadRequest::query()->where('user_id', $owner->id)->count())->toBe(2);
});

it('warns before converting a duplicate prospect to a quote', function () {
    $owner = prospectDuplicateOwner([
        'company_features' => [
            'requests' => true,
            'quotes' => true,
        ],
    ]);
    $json = fn () => ['Accept' => 'application/json'];

    $lead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_QUALIFIED,
        'title' => 'Prospect ready to convert',
        'contact_name' => 'Jordan Miles',
        'contact_email' => 'jordan@example.com',
        'contact_phone' => '+1 514 555 0112',
    ]);

    $duplicate = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_NEW,
        'title' => 'Earlier duplicate prospect',
        'contact_name' => 'Jordan Miles',
        'contact_email' => 'jordan@example.com',
    ]);

    $payload = [
        'create_customer' => true,
        'customer_name' => 'Jordan Miles',
        'contact_name' => 'Jordan Miles',
        'contact_email' => 'jordan@example.com',
        'job_title' => 'Converted quote',
    ];

    $this->actingAs($owner)
        ->post(route('prospects.convert', $lead), $payload, $json())
        ->assertStatus(409)
        ->assertJsonPath('duplicate_alert.context', 'convert')
        ->assertJsonPath('duplicate_alert.entries.0.duplicates.0.id', $duplicate->id);

    $this->actingAs($owner)
        ->post(route('prospects.convert', $lead), [
            ...$payload,
            'ignore_duplicates' => true,
        ], $json())
        ->assertOk()
        ->assertJsonPath('message', 'Prospect converted to quote.');
});

it('merges prospects without deleting the source duplicate', function () {
    $owner = prospectDuplicateOwner([
        'company_features' => [
            'requests' => true,
            'quotes' => true,
        ],
    ]);
    $json = fn () => ['Accept' => 'application/json'];

    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
    ]);

    $primary = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'status_updated_at' => now(),
        'last_activity_at' => now(),
        'title' => 'Primary retained prospect',
        'contact_name' => 'Taylor Primary',
        'contact_email' => 'taylor.primary@example.com',
    ]);

    $secondary = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_NEW,
        'status_updated_at' => now(),
        'last_activity_at' => now(),
        'title' => 'Duplicate source prospect',
        'contact_name' => 'Taylor Duplicate',
        'contact_email' => 'taylor.duplicate@example.com',
        'contact_phone' => '+1 514 555 0120',
        'next_follow_up_at' => now()->addDay(),
        'meta' => [
            'company_name' => 'Merge Works',
        ],
    ]);

    $quote = Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'property_id' => null,
        'job_title' => 'Source quote',
        'status' => 'sent',
        'request_id' => $secondary->id,
    ]);

    $note = LeadNote::query()->create([
        'request_id' => $secondary->id,
        'user_id' => $owner->id,
        'body' => 'Initial discovery note.',
    ]);

    $media = LeadMedia::query()->create([
        'request_id' => $secondary->id,
        'user_id' => $owner->id,
        'path' => 'prospects/merge/source-brief.pdf',
        'original_name' => 'source-brief.pdf',
        'mime' => 'application/pdf',
        'size' => 1024,
    ]);

    $interaction = ProspectInteraction::query()->create([
        'request_id' => $secondary->id,
        'user_id' => $owner->id,
        'type' => 'call',
        'description' => 'Discussed the project scope.',
    ]);

    $openTask = Task::query()->create([
        'account_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'request_id' => $secondary->id,
        'title' => 'Open follow-up',
        'status' => Task::STATUS_TODO,
        'priority' => Task::PRIORITY_HIGH,
        'due_date' => now()->toDateString(),
    ]);

    $closedTask = Task::query()->create([
        'account_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'request_id' => $secondary->id,
        'title' => 'Closed follow-up',
        'status' => Task::STATUS_DONE,
        'priority' => Task::PRIORITY_NORMAL,
        'due_date' => now()->subDay()->toDateString(),
        'completed_at' => now(),
    ]);

    $this->actingAs($owner)
        ->post(route('prospects.merge', $primary), [
            'source_id' => $secondary->id,
        ], $json())
        ->assertOk()
        ->assertJsonPath('message', 'Prospect merged.')
        ->assertJsonPath('lead.id', $primary->id)
        ->assertJsonPath('lead.customer_id', $customer->id)
        ->assertJsonPath('lead.contact_phone', '+1 514 555 0120')
        ->assertJsonPath('summary.notes_transferred', 1)
        ->assertJsonPath('summary.documents_transferred', 1)
        ->assertJsonPath('summary.interactions_transferred', 1)
        ->assertJsonPath('summary.open_tasks_transferred', 1)
        ->assertJsonPath('summary.closed_tasks_retained', 1)
        ->assertJsonPath('summary.quote_transferred', true)
        ->assertJsonPath('summary.quote_id', $quote->id);

    $primary->refresh();
    $secondary->refresh();
    $quote->refresh();
    $note->refresh();
    $media->refresh();
    $interaction->refresh();
    $openTask->refresh();
    $closedTask->refresh();

    expect(LeadRequest::query()->count())->toBe(2)
        ->and($primary->customer_id)->toBe($customer->id)
        ->and($primary->contact_phone)->toBe('+1 514 555 0120')
        ->and($quote->request_id)->toBe($primary->id)
        ->and($note->request_id)->toBe($primary->id)
        ->and($media->request_id)->toBe($primary->id)
        ->and($interaction->request_id)->toBe($primary->id)
        ->and($openTask->request_id)->toBe($primary->id)
        ->and($closedTask->request_id)->toBe($secondary->id)
        ->and($secondary->archived_at)->not->toBeNull()
        ->and($secondary->archived_by_user_id)->toBe($owner->id)
        ->and($secondary->duplicate_of_prospect_id)->toBe($primary->id)
        ->and($secondary->merged_into_prospect_id)->toBe($primary->id)
        ->and($secondary->archive_reason)->toBe("Merged into prospect #{$primary->id}");

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

it('blocks merging prospects linked to different customers', function () {
    $owner = prospectDuplicateOwner([
        'company_features' => [
            'requests' => true,
            'quotes' => true,
        ],
    ]);
    $json = fn () => ['Accept' => 'application/json'];

    $primaryCustomer = Customer::factory()->create([
        'user_id' => $owner->id,
    ]);
    $secondaryCustomer = Customer::factory()->create([
        'user_id' => $owner->id,
    ]);

    $primary = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $primaryCustomer->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'status_updated_at' => now(),
        'last_activity_at' => now(),
        'title' => 'Primary with customer',
    ]);

    $secondary = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $secondaryCustomer->id,
        'status' => LeadRequest::STATUS_NEW,
        'status_updated_at' => now(),
        'last_activity_at' => now(),
        'title' => 'Secondary with another customer',
    ]);

    $this->actingAs($owner)
        ->post(route('prospects.merge', $primary), [
            'source_id' => $secondary->id,
        ], $json())
        ->assertStatus(422)
        ->assertJsonValidationErrors('source_id');

    $primary->refresh();
    $secondary->refresh();

    expect(LeadRequest::query()->count())->toBe(2)
        ->and($primary->customer_id)->toBe($primaryCustomer->id)
        ->and($secondary->archived_at)->toBeNull()
        ->and($secondary->duplicate_of_prospect_id)->toBeNull()
        ->and($secondary->merged_into_prospect_id)->toBeNull();
});
