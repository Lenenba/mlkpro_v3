<?php

use App\Models\Customer;
use App\Models\ProspectStatusHistory;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

function prospectStatusOwner(array $attributes = []): User
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

it('records the initial status history for a manually created prospect', function () {
    $owner = prospectStatusOwner();

    $this->actingAs($owner)->post(route('prospects.store'), [
        'channel' => 'phone',
        'title' => 'Initial status prospect',
        'contact_name' => 'Status Alpha',
        'contact_email' => 'status.alpha@example.com',
    ])->assertRedirect();

    $lead = LeadRequest::query()->where('user_id', $owner->id)->latest('id')->firstOrFail();
    $history = ProspectStatusHistory::query()->where('request_id', $lead->id)->latest('id')->first();

    expect($history)->not->toBeNull()
        ->and($history?->user_id)->toBe($owner->id)
        ->and($history?->from_status)->toBeNull()
        ->and($history?->to_status)->toBe(LeadRequest::STATUS_NEW)
        ->and(data_get($history?->metadata, 'source'))->toBe('manual');
});

it('records status transitions with comment and exposes them on the prospect detail payload', function () {
    $owner = prospectStatusOwner();

    $lead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_NEW,
        'status_updated_at' => now(),
        'last_activity_at' => now(),
        'title' => 'Pipeline follow-up',
        'contact_name' => 'Status Bravo',
        'contact_email' => 'status.bravo@example.com',
    ]);

    $this->actingAs($owner)->putJson(route('prospects.update', $lead), [
        'status' => LeadRequest::STATUS_CONTACTED,
        'status_comment' => 'Reached the prospect and confirmed the project scope.',
    ])->assertOk();

    $lead->refresh();

    $history = ProspectStatusHistory::query()
        ->where('request_id', $lead->id)
        ->latest('id')
        ->first();

    expect($history)->not->toBeNull()
        ->and($history?->user_id)->toBe($owner->id)
        ->and($history?->from_status)->toBe(LeadRequest::STATUS_NEW)
        ->and($history?->to_status)->toBe(LeadRequest::STATUS_CONTACTED)
        ->and($history?->comment)->toBe('Reached the prospect and confirmed the project scope.');

    $this->actingAs($owner)
        ->getJson(route('prospects.show', $lead))
        ->assertOk()
        ->assertJsonPath('lead.status_histories.0.to_status', LeadRequest::STATUS_CONTACTED)
        ->assertJsonPath('lead.status_histories.0.comment', 'Reached the prospect and confirmed the project scope.')
        ->assertJsonPath('lead.status_histories.0.user.id', $owner->id);
});

it('records bulk status transitions only for prospects whose status actually changed', function () {
    $owner = prospectStatusOwner();

    $firstLead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_NEW,
        'status_updated_at' => now(),
        'last_activity_at' => now(),
        'title' => 'Bulk first',
    ]);

    $secondLead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'status_updated_at' => now(),
        'last_activity_at' => now(),
        'title' => 'Bulk second',
    ]);

    $this->actingAs($owner)->patchJson(route('prospects.bulk'), [
        'ids' => [$firstLead->id, $secondLead->id],
        'status' => LeadRequest::STATUS_CONTACTED,
    ])->assertOk()
        ->assertJsonPath('message', 'Prospects updated.');

    $firstHistory = ProspectStatusHistory::query()
        ->where('request_id', $firstLead->id)
        ->latest('id')
        ->first();
    $secondHistoryCount = ProspectStatusHistory::query()
        ->where('request_id', $secondLead->id)
        ->count();

    expect($firstHistory)->not->toBeNull()
        ->and($firstHistory?->from_status)->toBe(LeadRequest::STATUS_NEW)
        ->and($firstHistory?->to_status)->toBe(LeadRequest::STATUS_CONTACTED)
        ->and(data_get($firstHistory?->metadata, 'source'))->toBe('bulk_update')
        ->and($secondHistoryCount)->toBe(0);
});

it('records status history when a prospect is converted to a quote', function () {
    $owner = prospectStatusOwner();

    $lead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'status_updated_at' => now(),
        'last_activity_at' => now(),
        'title' => 'Convert me',
        'contact_name' => 'Status Charlie',
        'contact_email' => 'status.charlie@example.com',
    ]);

    $this->actingAs($owner)->postJson(route('prospects.convert', $lead), [
        'create_customer' => true,
        'customer_name' => 'Charlie Client',
        'contact_name' => 'Status Charlie',
        'contact_email' => 'status.charlie@example.com',
        'job_title' => 'Converted quote',
    ])->assertOk()
        ->assertJsonPath('message', 'Prospect converted to quote.');

    $lead->refresh();

    $history = ProspectStatusHistory::query()
        ->where('request_id', $lead->id)
        ->latest('id')
        ->first();

    expect($history)->not->toBeNull()
        ->and($history?->from_status)->toBe(LeadRequest::STATUS_CONTACTED)
        ->and($history?->to_status)->toBe(LeadRequest::STATUS_QUALIFIED)
        ->and(data_get($history?->metadata, 'source'))->toBe('quote_conversion')
        ->and(data_get($history?->metadata, 'quote_id'))->toBeInt();
});

it('records automatic status history when quote status sync updates a prospect', function () {
    $owner = prospectStatusOwner();
    $customer = Customer::query()->create([
        'user_id' => $owner->id,
        'company_name' => 'Quote Sync Customer',
        'first_name' => 'Quote',
        'last_name' => 'Sync',
        'email' => 'quote.sync@example.com',
        'phone' => '+1 555 0177',
    ]);

    $lead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'status_updated_at' => now(),
        'last_activity_at' => now(),
        'title' => 'Quote sync prospect',
    ]);

    $quote = Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'property_id' => null,
        'job_title' => 'Quote sync',
        'status' => 'sent',
        'request_id' => $lead->id,
    ]);

    $quote->syncRequestStatusFromQuote();
    $lead->refresh();

    $history = ProspectStatusHistory::query()
        ->where('request_id', $lead->id)
        ->latest('id')
        ->first();

    expect($lead->status)->toBe(LeadRequest::STATUS_QUOTE_SENT)
        ->and($history)->not->toBeNull()
        ->and($history?->user_id)->toBeNull()
        ->and($history?->from_status)->toBe(LeadRequest::STATUS_CONTACTED)
        ->and($history?->to_status)->toBe(LeadRequest::STATUS_QUOTE_SENT)
        ->and(data_get($history?->metadata, 'source'))->toBe('quote_status_sync')
        ->and((int) data_get($history?->metadata, 'quote_id'))->toBe($quote->id);
});
