<?php

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('request detail payload projects connector ingested events in occurred at order beside sales activity', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
    ]);

    $customer = Customer::create([
        'user_id' => $owner->id,
        'first_name' => 'Request',
        'last_name' => 'Connector',
        'company_name' => 'Request Connector Inc.',
        'email' => 'request-connector@example.com',
    ]);

    $lead = LeadRequest::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Request connector timeline',
        'contact_email' => 'request-connector@example.com',
    ]);

    $ingest = function (array $payload) use ($owner) {
        Sanctum::actingAs($owner, ['crm:write']);

        $this->postJson(route('api.integrations.crm.connector_events.store'), $payload)
            ->assertCreated();
    };

    try {
        Carbon::setTestNow(Carbon::parse('2026-04-21 08:00:00'));
        ActivityLog::record($owner, $lead, 'sales_next_action_scheduled', [
            'next_follow_up_at' => '2026-04-22T15:00:00+00:00',
            'note' => 'Call after the connector-imported updates.',
        ], 'Follow-up scheduled');
    } finally {
        Carbon::setTestNow();
    }

    $ingest([
        'connector_key' => 'outlook',
        'family' => 'meeting',
        'event' => 'completed',
        'subject_type' => 'request',
        'subject_id' => $lead->id,
        'payload' => [
            'event_id' => 'request_outlook_evt_1',
            'start_at' => '2026-04-21T10:00:00+00:00',
            'completed_at' => '2026-04-21T11:00:00+00:00',
            'location' => 'Teams',
            'meeting_url' => 'https://teams.microsoft.com/request-connector',
            'organizer_email' => 'sales@example.test',
        ],
    ]);

    $ingest([
        'connector_key' => 'gmail',
        'family' => 'message',
        'event' => 'received',
        'subject_type' => 'request',
        'subject_id' => $lead->id,
        'payload' => [
            'from_email' => 'prospect@example.com',
            'gmail_message_id' => 'request_gmail_msg_1',
            'thread_id' => 'request_gmail_thread_1',
            'received_at' => '2026-04-21T10:30:00+00:00',
        ],
    ]);

    $this->actingAs($owner)
        ->getJson(route('request.show', $lead))
        ->assertOk()
        ->assertJsonPath('activity.0.action', 'meeting_completed')
        ->assertJsonPath('activity.0.is_meeting_event', true)
        ->assertJsonPath('activity.0.meeting_event.provider', 'outlook')
        ->assertJsonPath('activity.0.meeting_event.location', 'Teams')
        ->assertJsonPath('activity.0.crm_links.primary.type', 'request')
        ->assertJsonPath('activity.0.crm_links.customer.id', $customer->id)
        ->assertJsonPath('activity.1.action', 'message_email_received')
        ->assertJsonPath('activity.1.is_message_event', true)
        ->assertJsonPath('activity.1.message_event.provider', 'gmail')
        ->assertJsonPath('activity.1.message_event.provider_message_id', 'request_gmail_msg_1')
        ->assertJsonPath('activity.1.message_event.external_message_id', 'request_gmail_thread_1')
        ->assertJsonPath('activity.1.crm_links.primary.type', 'request')
        ->assertJsonPath('activity.2.action', 'sales_next_action_scheduled')
        ->assertJsonPath('activity.2.sales_activity.activity_key', 'sales_next_action_scheduled');
});

test('quote detail payload keeps connector email events ordered by occurred at and linked to quote context', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
    ]);

    $customer = Customer::create([
        'user_id' => $owner->id,
        'first_name' => 'Quote',
        'last_name' => 'Connector',
        'company_name' => 'Quote Connector Inc.',
        'email' => 'quote-connector@example.com',
    ]);

    $lead = LeadRequest::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_QUOTE_SENT,
        'title' => 'Quote connector lead',
    ]);

    $quote = Quote::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'request_id' => $lead->id,
        'job_title' => 'Quote connector timeline',
        'status' => 'sent',
        'subtotal' => 900,
        'total' => 900,
        'initial_deposit' => 0,
    ]);

    try {
        Carbon::setTestNow(Carbon::parse('2026-04-21 12:00:00'));
        ActivityLog::record($owner, $quote, 'sales_call_quote_discussed', [
            'next_follow_up_at' => '2026-04-22T14:00:00+00:00',
            'note' => 'Keep manual follow-up above older connector activity.',
        ], 'Quote reviewed by phone');
    } finally {
        Carbon::setTestNow();
    }

    Sanctum::actingAs($owner, ['crm:write']);
    $this->postJson(route('api.integrations.crm.connector_events.store'), [
        'connector_key' => 'gmail',
        'family' => 'message',
        'event' => 'sent',
        'subject_type' => 'quote',
        'subject_id' => $quote->id,
        'payload' => [
            'to_email' => 'quote-connector@example.com',
            'gmail_message_id' => 'quote_gmail_msg_7',
            'thread_id' => 'quote_gmail_thread_7',
            'sent_at' => '2026-04-21T10:00:00+00:00',
        ],
    ])->assertCreated();

    $this->actingAs($owner)
        ->get(route('customer.quote.show', $quote))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Quote/Show')
            ->where('activity.0.action', 'sales_call_quote_discussed')
            ->where('activity.0.sales_activity.activity_key', 'sales_call_quote_discussed')
            ->where('activity.1.action', 'message_email_sent')
            ->where('activity.1.is_message_event', true)
            ->where('activity.1.message_event.provider', 'gmail')
            ->where('activity.1.message_event.provider_message_id', 'quote_gmail_msg_7')
            ->where('activity.1.message_event.external_message_id', 'quote_gmail_thread_7')
            ->where('activity.1.crm_links.primary.type', 'quote')
            ->where('activity.1.crm_links.request.id', $lead->id)
            ->where('activity.1.crm_links.customer.id', $customer->id)
        );
});

test('customer detail keeps last interaction and mixed subjects coherent when connector ingress spans customer request and quote', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
    ]);

    $customer = Customer::create([
        'user_id' => $owner->id,
        'first_name' => 'Customer',
        'last_name' => 'Connector',
        'company_name' => 'Customer Connector Inc.',
        'email' => 'customer-connector@example.com',
    ]);

    $lead = LeadRequest::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Customer connector lead',
    ]);

    $quote = Quote::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'request_id' => $lead->id,
        'job_title' => 'Customer connector quote',
        'status' => 'sent',
        'subtotal' => 1500,
        'total' => 1500,
        'initial_deposit' => 0,
    ]);

    try {
        Carbon::setTestNow(Carbon::parse('2026-04-21 09:00:00'));
        ActivityLog::record($owner, $lead, 'sales_note_added', [
            'note' => 'Keep the legacy sales note visible after connector sync.',
        ], 'Sales note saved');
    } finally {
        Carbon::setTestNow();
    }

    Sanctum::actingAs($owner, ['crm:write']);
    $this->postJson(route('api.integrations.crm.connector_events.store'), [
        'connector_key' => 'gmail',
        'family' => 'message',
        'event' => 'received',
        'subject_type' => 'customer',
        'subject_id' => $customer->id,
        'payload' => [
            'from_email' => 'customer-connector@example.com',
            'gmail_message_id' => 'customer_gmail_msg_20',
            'thread_id' => 'customer_gmail_thread_20',
            'received_at' => '2026-04-21T12:30:00+00:00',
        ],
    ])->assertCreated();

    $this->postJson(route('api.integrations.crm.connector_events.store'), [
        'connector_key' => 'outlook',
        'family' => 'meeting',
        'event' => 'completed',
        'subject_type' => 'request',
        'subject_id' => $lead->id,
        'payload' => [
            'event_id' => 'customer_outlook_evt_20',
            'start_at' => '2026-04-21T11:00:00+00:00',
            'completed_at' => '2026-04-21T11:30:00+00:00',
            'location' => 'Zoom',
            'meeting_url' => 'https://zoom.us/j/customer-connector',
            'organizer_email' => 'owner@example.test',
        ],
    ])->assertCreated();

    $this->postJson(route('api.integrations.crm.connector_events.store'), [
        'connector_key' => 'gmail',
        'family' => 'message',
        'event' => 'received',
        'subject_type' => 'quote',
        'subject_id' => $quote->id,
        'payload' => [
            'from_email' => 'customer-connector@example.com',
            'gmail_message_id' => 'quote_gmail_msg_20',
            'thread_id' => 'quote_gmail_thread_20',
            'received_at' => '2026-04-21T10:30:00+00:00',
        ],
    ])->assertCreated();

    $this->actingAs($owner)
        ->get(route('customer.show', $customer))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Customer/Show')
            ->has('activity', 4)
            ->where('lastInteraction.action', 'message_email_received')
            ->where('lastInteraction.subject', 'Customer')
            ->where('activity.0.subject', 'Customer')
            ->where('activity.0.is_message_event', true)
            ->where('activity.0.message_event.provider', 'gmail')
            ->where('activity.0.crm_links.primary.type', 'customer')
            ->where('activity.1.subject', 'Request')
            ->where('activity.1.is_meeting_event', true)
            ->where('activity.1.meeting_event.provider', 'outlook')
            ->where('activity.1.crm_links.primary.type', 'request')
            ->where('activity.2.subject', 'Quote')
            ->where('activity.2.is_message_event', true)
            ->where('activity.2.message_event.provider', 'gmail')
            ->where('activity.2.crm_links.request.id', $lead->id)
            ->where('activity.3.subject', 'Request')
            ->where('activity.3.sales_activity.activity_key', 'sales_note_added')
        );
});
