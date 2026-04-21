<?php

use App\Models\Customer;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('request sales activity endpoint logs canonical sales notes and returns enriched activity payload', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
    ]);

    $lead = LeadRequest::create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_NEW,
        'title' => 'Phase four request',
    ]);

    $response = $this->actingAs($owner)
        ->postJson(route('crm.sales-activities.requests.store', $lead), [
            'action' => 'sales_note_added',
            'note' => 'Prospect asked for a revised estimate after the call.',
            'description' => 'Revised estimate requested',
        ])
        ->assertCreated();

    expect($response->json('activity.action'))->toBe('sales_note_added')
        ->and($response->json('activity.description'))->toBe('Revised estimate requested')
        ->and($response->json('activity.properties.note'))->toBe('Prospect asked for a revised estimate after the call.')
        ->and($response->json('activity.sales_activity.type'))->toBe('note')
        ->and($response->json('activity.sales_activity.activity_key'))->toBe('sales_note_added');
});

test('quote sales activity endpoint resolves quick actions into canonical next actions with due dates', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
    ]);

    $customer = Customer::create([
        'user_id' => $owner->id,
        'first_name' => 'Quick',
        'last_name' => 'Action',
        'company_name' => 'Quick Action Inc.',
        'email' => 'quick-action@example.com',
    ]);

    $quote = Quote::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'job_title' => 'Phase four quote',
        'status' => 'sent',
        'subtotal' => 850,
        'total' => 850,
        'initial_deposit' => 0,
    ]);

    $reference = Carbon::parse('2026-04-20 10:00:00');

    try {
        Carbon::setTestNow($reference);

        $response = $this->actingAs($owner)
            ->postJson(route('crm.sales-activities.quotes.store', $quote), [
                'quick_action' => 'callback_tomorrow',
                'note' => 'Call again tomorrow morning.',
            ])
            ->assertCreated();
    } finally {
        Carbon::setTestNow();
    }

    expect($response->json('activity.action'))->toBe('sales_next_action_scheduled')
        ->and($response->json('activity.properties.quick_action'))->toBe('callback_tomorrow')
        ->and($response->json('activity.properties.next_follow_up_at'))->toBe($reference->copy()->addDay()->toIso8601String())
        ->and($response->json('activity.sales_activity.type'))->toBe('next_action')
        ->and($response->json('activity.sales_activity.due_at'))->toBe($reference->copy()->addDay()->toIso8601String());
});

test('phase four sales activity endpoints reject legacy actions', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
    ]);

    $customer = Customer::create([
        'user_id' => $owner->id,
        'first_name' => 'Legacy',
        'last_name' => 'Rejected',
        'company_name' => 'Legacy Rejected Inc.',
        'email' => 'legacy-rejected@example.com',
    ]);

    $this->actingAs($owner)
        ->postJson(route('crm.sales-activities.customers.store', $customer), [
            'action' => 'quote_follow_up_scheduled',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['action']);
});

test('request and quote detail payloads expose quick actions and enriched sales activity metadata', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
    ]);

    $customer = Customer::create([
        'user_id' => $owner->id,
        'first_name' => 'Detail',
        'last_name' => 'Payload',
        'company_name' => 'Detail Payload Inc.',
        'email' => 'detail-payload@example.com',
    ]);

    $lead = LeadRequest::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Detail request',
    ]);

    $quote = Quote::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'request_id' => $lead->id,
        'job_title' => 'Detail quote',
        'status' => 'sent',
        'subtotal' => 1200,
        'total' => 1200,
        'initial_deposit' => 0,
    ]);

    $this->actingAs($owner)
        ->postJson(route('crm.sales-activities.requests.store', $lead), [
            'action' => 'sales_call_no_answer',
            'due_at' => '2026-04-21 09:30:00',
        ])
        ->assertCreated();

    $this->actingAs($owner)
        ->postJson(route('crm.sales-activities.quotes.store', $quote), [
            'action' => 'sales_meeting_scheduled',
            'description' => 'On-site estimate booked',
            'note' => 'Bring revised scope options to the appointment.',
            'due_at' => '2026-04-22 14:00:00',
        ])
        ->assertCreated();

    $this->actingAs($owner)
        ->getJson(route('request.show', $lead))
        ->assertOk()
        ->assertJsonPath('canLogSalesActivity', true)
        ->assertJsonPath('salesActivityQuickActions.0.id', 'call_logged')
        ->assertJsonPath('salesActivityManualActions.0.action', 'sales_note_added')
        ->assertJsonPath('salesActivityManualActions.0.activity_key', 'sales_note_added')
        ->assertJsonPath('activity.0.sales_activity.activity_key', 'sales_call_no_answer')
        ->assertJsonPath('activity.0.sales_activity.type', 'call_outcome')
        ->assertJsonPath('activity.0.sales_activity.due_at', Carbon::parse('2026-04-21 09:30:00')->toIso8601String());

    $this->actingAs($owner)
        ->get(route('customer.quote.show', $quote))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Quote/Show')
            ->where('canLogSalesActivity', true)
            ->where('salesActivityQuickActions.0.id', 'call_logged')
            ->where('salesActivityManualActions.0.action', 'sales_note_added')
            ->where('salesActivityManualActions.0.activity_key', 'sales_note_added')
            ->where('activity.0.user.name', $owner->name)
            ->where('activity.0.properties.note', 'Bring revised scope options to the appointment.')
            ->where('activity.0.sales_activity.activity_key', 'sales_meeting_scheduled')
            ->where('activity.0.sales_activity.type', 'meeting')
            ->where('activity.0.sales_activity.due_at', Carbon::parse('2026-04-22 14:00:00')->toIso8601String())
        );
});

test('customer detail payload exposes sales timeline metadata for related request activity', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
    ]);

    $customer = Customer::create([
        'user_id' => $owner->id,
        'first_name' => 'Customer',
        'last_name' => 'Timeline',
        'company_name' => 'Customer Timeline Inc.',
        'email' => 'customer-timeline-phase-four@example.com',
    ]);

    $lead = LeadRequest::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Customer sales follow-up',
    ]);

    $this->actingAs($owner)
        ->postJson(route('crm.sales-activities.requests.store', $lead), [
            'action' => 'sales_call_quote_discussed',
            'description' => 'Discussed project scope and sent recap',
            'note' => 'Customer wants a revised version after discussing the options.',
            'due_at' => '2026-04-23 11:00:00',
        ])
        ->assertCreated();

    $this->actingAs($owner)
        ->get(route('customer.show', $customer))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Customer/Show')
            ->where('canLogSalesActivity', true)
            ->where('salesActivityQuickActions.0.id', 'call_logged')
            ->where('salesActivityManualActions.0.action', 'sales_note_added')
            ->where('salesActivityManualActions.0.activity_key', 'sales_note_added')
            ->where('activity.0.subject', 'Request')
            ->where('activity.0.user.name', $owner->name)
            ->where('activity.0.sales_activity.activity_key', 'sales_call_quote_discussed')
            ->where('activity.0.sales_activity.type', 'call_outcome')
            ->where('activity.0.sales_activity.due_at', Carbon::parse('2026-04-23 11:00:00')->toIso8601String())
        );
});
