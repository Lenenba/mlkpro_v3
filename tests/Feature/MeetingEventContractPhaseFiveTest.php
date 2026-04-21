<?php

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Request as LeadRequest;
use App\Models\User;
use App\Support\CRM\MeetingEventTaxonomy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('defines canonical and legacy meeting event actions for phase five', function () {
    $canonical = MeetingEventTaxonomy::definition('meeting_scheduled');
    $legacy = MeetingEventTaxonomy::definition('sales_meeting_completed');

    expect($canonical)->not->toBeNull()
        ->and($canonical['lifecycle_state'])->toBe(MeetingEventTaxonomy::LIFECYCLE_SCHEDULED)
        ->and($canonical['timeline_variant'])->toBe('meeting')
        ->and($canonical['legacy'])->toBeFalse()
        ->and($legacy)->not->toBeNull()
        ->and($legacy['event_key'])->toBe('meeting_completed')
        ->and($legacy['lifecycle_state'])->toBe(MeetingEventTaxonomy::LIFECYCLE_COMPLETED)
        ->and($legacy['legacy'])->toBeTrue()
        ->and(MeetingEventTaxonomy::isMeetingEvent('sales_meeting_scheduled'))->toBeTrue()
        ->and(MeetingEventTaxonomy::isMeetingEvent('email_sent'))->toBeFalse();
});

it('serializes meeting event metadata on activity logs and filters them with the meeting scope', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
    ]);

    $customer = Customer::create([
        'user_id' => $owner->id,
        'first_name' => 'Meeting',
        'last_name' => 'Contract',
        'company_name' => 'Meeting Contract Inc.',
        'email' => 'meeting-contract@example.com',
    ]);

    $meetingAt = Carbon::parse('2026-04-21 14:30:00');

    $meetingLog = ActivityLog::record($owner, $customer, 'sales_meeting_scheduled', [
        'calendar_provider' => 'google',
        'source' => 'crm_manual',
        'meeting_id' => 'gcal_evt_42',
        'meeting_at' => $meetingAt->toIso8601String(),
        'end_at' => $meetingAt->copy()->addHour()->toIso8601String(),
        'conference_url' => 'https://meet.google.com/example',
        'location' => 'Customer office',
    ], 'Discovery meeting scheduled');

    $genericLog = ActivityLog::record($owner, $customer, 'updated', [], 'Customer updated');

    $meetingPayload = $meetingLog->fresh()->toArray();
    $genericPayload = $genericLog->fresh()->toArray();

    expect($meetingPayload['is_meeting_event'])->toBeTrue()
        ->and($meetingPayload['meeting_event']['event_key'])->toBe('meeting_scheduled')
        ->and($meetingPayload['meeting_event']['lifecycle_state'])->toBe('scheduled')
        ->and($meetingPayload['meeting_event']['provider'])->toBe('google')
        ->and($meetingPayload['meeting_event']['external_meeting_id'])->toBe('gcal_evt_42')
        ->and($meetingPayload['meeting_event']['start_at'])->toBe($meetingAt->toIso8601String())
        ->and($meetingPayload['meeting_event']['conference_url'])->toBe('https://meet.google.com/example')
        ->and($meetingPayload['meeting_event']['legacy'])->toBeTrue()
        ->and($genericPayload['is_meeting_event'])->toBeFalse()
        ->and($genericPayload['meeting_event'])->toBeNull()
        ->and(ActivityLog::query()->meetingEvent()->pluck('id')->all())->toBe([$meetingLog->id]);
});

test('customer show exposes meeting event metadata in the activity payload', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
    ]);

    $customer = Customer::create([
        'user_id' => $owner->id,
        'first_name' => 'Customer',
        'last_name' => 'Meeting',
        'company_name' => 'Meeting Customer Inc.',
        'email' => 'customer-meeting@example.com',
    ]);

    $lead = LeadRequest::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Meeting lead',
    ]);

    ActivityLog::record($owner, $lead, 'meeting_completed', [
        'provider' => 'outlook',
        'source' => 'calendar_sync',
        'start_at' => '2026-04-21T14:30:00+00:00',
        'completed_at' => '2026-04-21T15:15:00+00:00',
        'location' => 'Zoom',
        'request_id' => $lead->id,
    ], 'Intro meeting completed');

    $this->actingAs($owner)
        ->get(route('customer.show', $customer))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Customer/Show')
            ->where('activity.0.subject', 'Request')
            ->where('activity.0.is_meeting_event', true)
            ->where('activity.0.meeting_event.event_key', 'meeting_completed')
            ->where('activity.0.meeting_event.lifecycle_state', 'completed')
            ->where('activity.0.meeting_event.provider', 'outlook')
            ->where('activity.0.meeting_event.source', 'calendar_sync')
            ->where('activity.0.meeting_event.location', 'Zoom')
            ->where('activity.0.meeting_event.request_id', $lead->id)
        );
});
