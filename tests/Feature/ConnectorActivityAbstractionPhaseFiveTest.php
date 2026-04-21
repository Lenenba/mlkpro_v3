<?php

use App\Models\Customer;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\User;
use App\Services\CRM\ConnectorActivityLogService;
use App\Services\CRM\Connectors\CrmConnectorRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('exposes connector-ready definitions for gmail and outlook', function () {
    $definitions = app(CrmConnectorRegistry::class)->definitions();

    expect(collect($definitions)->pluck('key')->all())->toBe(['gmail', 'outlook'])
        ->and($definitions[0]['supports_message_events'])->toBeTrue()
        ->and($definitions[0]['supports_meeting_events'])->toBeFalse()
        ->and($definitions[0]['auth_strategy'])->toBe('oauth')
        ->and($definitions[1]['supports_message_events'])->toBeTrue()
        ->and($definitions[1]['supports_meeting_events'])->toBeTrue()
        ->and($definitions[1]['capabilities'])->toContain('meeting_completed');
});

test('connector activity log service records canonical gmail message events with crm context', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
    ]);

    $customer = Customer::create([
        'user_id' => $owner->id,
        'first_name' => 'Connector',
        'last_name' => 'Message',
        'company_name' => 'Connector Message Inc.',
        'email' => 'connector-message@example.com',
    ]);

    $lead = LeadRequest::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Connector message lead',
    ]);

    $quote = Quote::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'request_id' => $lead->id,
        'job_title' => 'Connector message quote',
        'status' => 'sent',
        'subtotal' => 900,
        'total' => 900,
        'initial_deposit' => 0,
    ]);

    app(ConnectorActivityLogService::class)->logMessageEvent(
        $owner,
        $quote,
        'gmail',
        'received',
        [
            'from_email' => 'prospect@gmail.com',
            'gmail_message_id' => 'gmail_msg_42',
            'thread_id' => 'gmail_thread_9',
        ]
    );

    $this->actingAs($owner)
        ->get(route('customer.show', $customer))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Customer/Show')
            ->where('activity.0.subject', 'Quote')
            ->where('activity.0.action', 'message_email_received')
            ->where('activity.0.is_message_event', true)
            ->where('activity.0.message_event.event_key', 'message_email_received')
            ->where('activity.0.message_event.provider', 'gmail')
            ->where('activity.0.message_event.source', 'connector_sync')
            ->where('activity.0.message_event.email', 'prospect@gmail.com')
            ->where('activity.0.message_event.provider_message_id', 'gmail_msg_42')
            ->where('activity.0.message_event.external_message_id', 'gmail_thread_9')
            ->where('activity.0.crm_links.primary.type', 'quote')
            ->where('activity.0.crm_links.request.id', $lead->id)
            ->where('activity.0.crm_links.customer.id', $customer->id)
        );
});

test('connector activity log service records canonical outlook meeting events with crm context', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
    ]);

    $customer = Customer::create([
        'user_id' => $owner->id,
        'first_name' => 'Connector',
        'last_name' => 'Meeting',
        'company_name' => 'Connector Meeting Inc.',
        'email' => 'connector-meeting@example.com',
    ]);

    $lead = LeadRequest::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Connector meeting lead',
    ]);

    app(ConnectorActivityLogService::class)->logMeetingEvent(
        $owner,
        $lead,
        'outlook',
        'completed',
        [
            'event_id' => 'outlook_evt_77',
            'start_at' => '2026-04-21T14:00:00+00:00',
            'completed_at' => '2026-04-21T15:00:00+00:00',
            'location' => 'Teams',
            'meeting_url' => 'https://teams.microsoft.com/example',
            'organizer_email' => 'sales@contoso.test',
        ]
    );

    $this->actingAs($owner)
        ->get(route('customer.show', $customer))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Customer/Show')
            ->where('activity.0.subject', 'Request')
            ->where('activity.0.action', 'meeting_completed')
            ->where('activity.0.is_meeting_event', true)
            ->where('activity.0.meeting_event.event_key', 'meeting_completed')
            ->where('activity.0.meeting_event.provider', 'outlook')
            ->where('activity.0.meeting_event.external_meeting_id', 'outlook_evt_77')
            ->where('activity.0.meeting_event.location', 'Teams')
            ->where('activity.0.meeting_event.conference_url', 'https://teams.microsoft.com/example')
            ->where('activity.0.meeting_event.organizer_email', 'sales@contoso.test')
            ->where('activity.0.crm_links.primary.type', 'request')
            ->where('activity.0.crm_links.request.id', $lead->id)
            ->where('activity.0.crm_links.customer.id', $customer->id)
        );
});
