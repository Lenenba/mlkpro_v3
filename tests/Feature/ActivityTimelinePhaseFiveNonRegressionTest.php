<?php

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('request detail payload keeps sales activity while exposing message and meeting timeline metadata', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
    ]);

    $lead = LeadRequest::create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Phase five request timeline',
        'contact_email' => 'request-phase-five@example.com',
    ]);

    try {
        Carbon::setTestNow(Carbon::parse('2026-04-21 09:00:00'));
        ActivityLog::record($owner, $lead, 'sales_next_action_scheduled', [
            'next_follow_up_at' => '2026-04-23T09:30:00+00:00',
            'note' => 'Call again after the estimate review.',
        ], 'Next callback scheduled');

        Carbon::setTestNow(Carbon::parse('2026-04-21 10:00:00'));
        ActivityLog::record($owner, $lead, 'meeting_scheduled', [
            'provider' => 'google',
            'source' => 'calendar_sync',
            'start_at' => '2026-04-24T14:00:00+00:00',
            'location' => 'Customer site',
            'request_id' => $lead->id,
        ], 'On-site visit scheduled');

        Carbon::setTestNow(Carbon::parse('2026-04-21 11:00:00'));
        ActivityLog::record($owner, $lead, 'message_email_retry_scheduled', [
            'email' => 'request-phase-five@example.com',
            'source' => 'lead_form_retry',
            'scheduled_for' => '2026-04-21T11:15:00+00:00',
            'retry_attempt' => 2,
            'request_id' => $lead->id,
        ], 'Quote retry scheduled');
    } finally {
        Carbon::setTestNow();
    }

    $this->actingAs($owner)
        ->getJson(route('request.show', $lead))
        ->assertOk()
        ->assertJsonPath('activity.0.action', 'message_email_retry_scheduled')
        ->assertJsonPath('activity.0.is_message_event', true)
        ->assertJsonPath('activity.0.message_event.event_key', 'message_email_retry_scheduled')
        ->assertJsonPath('activity.0.message_event.email', 'request-phase-five@example.com')
        ->assertJsonPath('activity.0.message_event.retry_attempt', 2)
        ->assertJsonPath('activity.0.crm_links.primary.type', 'request')
        ->assertJsonPath('activity.0.crm_links.primary.id', $lead->id)
        ->assertJsonPath('activity.1.action', 'meeting_scheduled')
        ->assertJsonPath('activity.1.is_meeting_event', true)
        ->assertJsonPath('activity.1.meeting_event.provider', 'google')
        ->assertJsonPath('activity.1.meeting_event.location', 'Customer site')
        ->assertJsonPath('activity.1.crm_links.primary.type', 'request')
        ->assertJsonPath('activity.2.action', 'sales_next_action_scheduled')
        ->assertJsonPath('activity.2.sales_activity.activity_key', 'sales_next_action_scheduled')
        ->assertJsonPath('activity.2.sales_activity.due_at', '2026-04-23T09:30:00+00:00')
        ->assertJsonPath('activity.2.properties.note', 'Call again after the estimate review.');
});

test('quote detail payload exposes outgoing email timeline metadata beside sales activity entries', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
    ]);

    $customer = Customer::create([
        'user_id' => $owner->id,
        'first_name' => 'Quote',
        'last_name' => 'Timeline',
        'company_name' => 'Quote Timeline Inc.',
        'email' => 'quote-timeline@example.com',
    ]);

    $lead = LeadRequest::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_QUOTE_SENT,
        'title' => 'Quote timeline lead',
    ]);

    $quote = Quote::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'request_id' => $lead->id,
        'job_title' => 'Quote timeline quote',
        'status' => 'sent',
        'subtotal' => 1200,
        'total' => 1200,
        'initial_deposit' => 0,
    ]);

    try {
        Carbon::setTestNow(Carbon::parse('2026-04-21 09:00:00'));
        ActivityLog::record($owner, $quote, 'sales_call_quote_discussed', [
            'next_follow_up_at' => '2026-04-22T13:00:00+00:00',
            'note' => 'Customer wants a final confirmation email.',
        ], 'Quote reviewed on the phone');

        Carbon::setTestNow(Carbon::parse('2026-04-21 10:00:00'));
        ActivityLog::record($owner, $quote, 'message_email_sent', [
            'email' => 'quote-timeline@example.com',
            'source' => 'quote_manual_send',
            'request_id' => $lead->id,
            'customer_id' => $customer->id,
            'quote_id' => $quote->id,
        ], 'Quote email sent');
    } finally {
        Carbon::setTestNow();
    }

    $this->actingAs($owner)
        ->get(route('customer.quote.show', $quote))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Quote/Show')
            ->where('activity.0.action', 'message_email_sent')
            ->where('activity.0.is_message_event', true)
            ->where('activity.0.message_event.event_key', 'message_email_sent')
            ->where('activity.0.message_event.source', 'quote_manual_send')
            ->where('activity.0.message_event.email', 'quote-timeline@example.com')
            ->where('activity.0.crm_links.primary.type', 'quote')
            ->where('activity.0.crm_links.request.id', $lead->id)
            ->where('activity.1.action', 'sales_call_quote_discussed')
            ->where('activity.1.sales_activity.activity_key', 'sales_call_quote_discussed')
            ->where('activity.1.sales_activity.due_at', '2026-04-22T13:00:00+00:00')
            ->where('activity.1.properties.note', 'Customer wants a final confirmation email.')
        );
});

test('customer detail aggregates phase five message and meeting events without losing existing sales entries', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
    ]);

    $customer = Customer::create([
        'user_id' => $owner->id,
        'first_name' => 'Customer',
        'last_name' => 'Timeline',
        'company_name' => 'Customer Timeline Phase Five Inc.',
        'email' => 'customer-phase-five@example.com',
    ]);

    $lead = LeadRequest::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Customer phase five lead',
    ]);

    $quote = Quote::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'request_id' => $lead->id,
        'job_title' => 'Customer phase five quote',
        'status' => 'sent',
        'subtotal' => 1500,
        'total' => 1500,
        'initial_deposit' => 0,
    ]);

    try {
        Carbon::setTestNow(Carbon::parse('2026-04-21 09:00:00'));
        ActivityLog::record($owner, $lead, 'sales_note_added', [
            'note' => 'Customer asked for two scheduling options.',
        ], 'Discovery note saved');

        Carbon::setTestNow(Carbon::parse('2026-04-21 10:00:00'));
        ActivityLog::record($owner, $quote, 'message_email_sent', [
            'email' => 'customer-phase-five@example.com',
            'source' => 'quote_manual_send',
            'request_id' => $lead->id,
            'customer_id' => $customer->id,
            'quote_id' => $quote->id,
        ], 'Quote email sent');

        Carbon::setTestNow(Carbon::parse('2026-04-21 11:00:00'));
        ActivityLog::record($owner, $customer, 'meeting_completed', [
            'provider' => 'outlook',
            'source' => 'calendar_sync',
            'completed_at' => '2026-04-21T11:00:00+00:00',
            'location' => 'Zoom',
            'customer_id' => $customer->id,
        ], 'Discovery meeting completed');
    } finally {
        Carbon::setTestNow();
    }

    $this->actingAs($owner)
        ->get(route('customer.show', $customer))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Customer/Show')
            ->has('activity', 3)
            ->where('lastInteraction.action', 'meeting_completed')
            ->where('activity.0.subject', 'Customer')
            ->where('activity.0.is_meeting_event', true)
            ->where('activity.0.meeting_event.event_key', 'meeting_completed')
            ->where('activity.0.meeting_event.provider', 'outlook')
            ->where('activity.0.meeting_event.location', 'Zoom')
            ->where('activity.1.subject', 'Quote')
            ->where('activity.1.is_message_event', true)
            ->where('activity.1.message_event.event_key', 'message_email_sent')
            ->where('activity.1.crm_links.primary.type', 'quote')
            ->where('activity.1.crm_links.request.id', $lead->id)
            ->where('activity.2.subject', 'Request')
            ->where('activity.2.sales_activity.activity_key', 'sales_note_added')
            ->where('activity.2.properties.note', 'Customer asked for two scheduling options.')
        );
});
