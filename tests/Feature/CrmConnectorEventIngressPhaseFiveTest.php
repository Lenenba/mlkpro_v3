<?php

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('crm connector event integration endpoint respects abilities', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
    ]);

    $customer = Customer::create([
        'user_id' => $owner->id,
        'first_name' => 'Ability',
        'last_name' => 'Check',
        'company_name' => 'Ability Check Inc.',
        'email' => 'ability-check@example.com',
    ]);

    $payload = [
        'connector_key' => 'gmail',
        'family' => 'message',
        'event' => 'received',
        'subject_type' => 'customer',
        'subject_id' => $customer->id,
        'payload' => [
            'from_email' => 'prospect@example.com',
        ],
    ];

    Sanctum::actingAs($owner, ['crm:read']);
    $this->postJson(route('api.integrations.crm.connector_events.store'), $payload)
        ->assertForbidden();

    Sanctum::actingAs($owner, ['crm:write']);
    $this->postJson(route('api.integrations.crm.connector_events.store'), $payload)
        ->assertCreated()
        ->assertJsonPath('activity.action', 'message_email_received');

    expect(ActivityLog::query()->count())->toBe(1);
});

test('crm connector event integration endpoint records canonical gmail message events on quote subjects', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
    ]);

    $customer = Customer::create([
        'user_id' => $owner->id,
        'first_name' => 'Gmail',
        'last_name' => 'Ingress',
        'company_name' => 'Gmail Ingress Inc.',
        'email' => 'gmail-ingress@example.com',
    ]);

    $lead = LeadRequest::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Gmail connector lead',
    ]);

    $quote = Quote::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'request_id' => $lead->id,
        'job_title' => 'Gmail connector quote',
        'status' => 'sent',
        'subtotal' => 800,
        'total' => 800,
        'initial_deposit' => 0,
    ]);

    $occurredAt = '2026-04-21T10:15:00+00:00';

    Sanctum::actingAs($owner, ['crm:write']);
    $response = $this->postJson(route('api.integrations.crm.connector_events.store'), [
        'connector_key' => 'gmail',
        'family' => 'message',
        'event' => 'received',
        'subject_type' => 'quote',
        'subject_id' => $quote->id,
        'occurred_at' => $occurredAt,
        'payload' => [
            'from_email' => 'prospect@gmail.com',
            'gmail_message_id' => 'gmail_msg_42',
            'thread_id' => 'gmail_thread_9',
            'received_at' => $occurredAt,
        ],
    ])->assertCreated();

    $log = ActivityLog::query()->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log?->action)->toBe('message_email_received')
        ->and($log?->created_at?->toJSON())->toBe(Carbon::parse($occurredAt)->toJSON())
        ->and($log?->message_event['provider'])->toBe('gmail')
        ->and($log?->message_event['email'])->toBe('prospect@gmail.com')
        ->and($log?->message_event['provider_message_id'])->toBe('gmail_msg_42')
        ->and($log?->message_event['external_message_id'])->toBe('gmail_thread_9')
        ->and($log?->crm_links['primary']['type'])->toBe('quote')
        ->and($log?->crm_links['request']['id'])->toBe($lead->id)
        ->and($log?->crm_links['customer']['id'])->toBe($customer->id);

    $response
        ->assertJsonPath('activity.created_at', Carbon::parse($occurredAt)->toJSON())
        ->assertJsonPath('activity.message_event.provider', 'gmail')
        ->assertJsonPath('activity.message_event.source', 'connector_sync')
        ->assertJsonPath('activity.crm_links.primary.type', 'quote');
});

test('crm connector event integration endpoint records canonical outlook meeting events on request subjects', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
    ]);

    $customer = Customer::create([
        'user_id' => $owner->id,
        'first_name' => 'Outlook',
        'last_name' => 'Ingress',
        'company_name' => 'Outlook Ingress Inc.',
        'email' => 'outlook-ingress@example.com',
    ]);

    $lead = LeadRequest::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Outlook connector lead',
    ]);

    $completedAt = '2026-04-21T15:00:00+00:00';

    Sanctum::actingAs($owner, ['crm:write']);
    $response = $this->postJson(route('api.integrations.crm.connector_events.store'), [
        'connector_key' => 'outlook',
        'family' => 'meeting',
        'event' => 'completed',
        'subject_type' => 'request',
        'subject_id' => $lead->id,
        'payload' => [
            'event_id' => 'outlook_evt_77',
            'start_at' => '2026-04-21T14:00:00+00:00',
            'completed_at' => $completedAt,
            'location' => 'Teams',
            'meeting_url' => 'https://teams.microsoft.com/example',
            'organizer_email' => 'sales@contoso.test',
        ],
    ])->assertCreated();

    $log = ActivityLog::query()->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log?->action)->toBe('meeting_completed')
        ->and($log?->created_at?->toJSON())->toBe(Carbon::parse($completedAt)->toJSON())
        ->and($log?->meeting_event['provider'])->toBe('outlook')
        ->and($log?->meeting_event['external_meeting_id'])->toBe('outlook_evt_77')
        ->and($log?->meeting_event['location'])->toBe('Teams')
        ->and($log?->meeting_event['conference_url'])->toBe('https://teams.microsoft.com/example')
        ->and($log?->meeting_event['organizer_email'])->toBe('sales@contoso.test')
        ->and($log?->crm_links['primary']['type'])->toBe('request')
        ->and($log?->crm_links['request']['id'])->toBe($lead->id)
        ->and($log?->crm_links['customer']['id'])->toBe($customer->id);

    $response
        ->assertJsonPath('activity.created_at', Carbon::parse($completedAt)->toJSON())
        ->assertJsonPath('activity.meeting_event.provider', 'outlook')
        ->assertJsonPath('activity.meeting_event.source', 'calendar_sync')
        ->assertJsonPath('activity.crm_links.primary.type', 'request');
});
