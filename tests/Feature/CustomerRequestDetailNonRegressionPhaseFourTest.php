<?php

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('request detail preserves duplicates campaign attribution and sales activity metadata', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
    ]);

    $memberUser = User::factory()->create([
        'company_type' => 'services',
    ]);

    $member = TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $memberUser->id,
        'role' => 'member',
        'permissions' => ['requests.view'],
    ]);

    $campaign = Campaign::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'name' => 'Phase four attribution safety net',
        'type' => Campaign::TYPE_PROMOTION,
        'campaign_type' => Campaign::TYPE_PROMOTION,
        'campaign_direction' => Campaign::DIRECTION_PROSPECTING_OUTBOUND,
        'prospecting_enabled' => true,
        'offer_mode' => Campaign::OFFER_MODE_SERVICES,
        'language_mode' => Campaign::LANGUAGE_MODE_EN,
        'status' => Campaign::STATUS_DRAFT,
        'schedule_type' => Campaign::SCHEDULE_MANUAL,
        'locale' => 'en',
        'is_marketing' => true,
    ]);

    $lead = LeadRequest::create([
        'user_id' => $owner->id,
        'assigned_team_member_id' => $member->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Phase four request detail',
        'contact_name' => 'Request Contact',
        'contact_email' => 'phase-four-request@example.com',
        'contact_phone' => '5145550101',
        'meta' => [
            'source_kind' => 'campaign_prospecting',
            'source_direction' => 'outbound',
            'source_campaign_direction' => Campaign::DIRECTION_PROSPECTING_OUTBOUND,
            'source_campaign_id' => $campaign->id,
        ],
    ]);

    $duplicateLead = LeadRequest::create([
        'user_id' => $owner->id,
        'assigned_team_member_id' => $member->id,
        'status' => LeadRequest::STATUS_QUALIFIED,
        'title' => 'Potential duplicate lead',
        'contact_name' => 'Duplicate Contact',
        'contact_email' => 'phase-four-request@example.com',
        'contact_phone' => '5145550101',
    ]);

    $this->actingAs($owner)
        ->postJson(route('crm.sales-activities.requests.store', $lead), [
            'action' => 'sales_next_action_scheduled',
            'description' => 'Detailed follow-up scheduled',
            'note' => 'Need to confirm the visit slot before sending the revised scope.',
            'due_at' => '2026-04-22 09:00:00',
        ])
        ->assertCreated();

    $response = $this->actingAs($owner)->getJson(route('request.show', $lead));

    $response->assertOk()
        ->assertJsonPath('lead.id', $lead->id)
        ->assertJsonPath('duplicates.0.id', $duplicateLead->id)
        ->assertJsonPath('duplicates.0.assignee.user.name', $memberUser->name)
        ->assertJsonPath('campaignOrigin.campaign.id', $campaign->id)
        ->assertJsonPath('campaignOrigin.direction', 'outbound')
        ->assertJsonPath('assignees.0.id', $member->id)
        ->assertJsonPath('canLogSalesActivity', true)
        ->assertJsonPath('salesActivityQuickActions.0.id', 'call_logged')
        ->assertJsonPath('salesActivityManualActions.0.action', 'sales_note_added')
        ->assertJsonPath('activity.0.sales_activity.activity_key', 'sales_next_action_scheduled')
        ->assertJsonPath('activity.0.properties.note', 'Need to confirm the visit slot before sending the revised scope.')
        ->assertJsonPath('activity.0.sales_activity.due_at', Carbon::parse('2026-04-22 09:00:00')->toIso8601String())
        ->assertJsonFragment([
            'id' => LeadRequest::STATUS_CONTACTED,
            'name' => 'Contacted',
        ]);
});

test('customer detail preserves request quote linkage while aggregating request and customer sales activities', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
    ]);

    $customer = Customer::create([
        'user_id' => $owner->id,
        'first_name' => 'Customer',
        'last_name' => 'Regression',
        'company_name' => 'Customer Regression Inc.',
        'email' => 'customer-regression@example.com',
    ]);

    $lead = LeadRequest::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_QUOTE_SENT,
        'title' => 'Customer regression request',
    ]);

    $quote = Quote::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'request_id' => $lead->id,
        'job_title' => 'Customer regression quote',
        'status' => 'sent',
        'subtotal' => 1600,
        'total' => 1600,
        'initial_deposit' => 0,
    ]);

    try {
        Carbon::setTestNow(Carbon::parse('2026-04-21 09:00:00'));

        $this->actingAs($owner)
            ->postJson(route('crm.sales-activities.requests.store', $lead), [
                'action' => 'sales_call_quote_discussed',
                'description' => 'Discussed the quote line items',
                'note' => 'Customer requested one alternate option before approval.',
                'due_at' => '2026-04-23 11:00:00',
            ])
            ->assertCreated();

        Carbon::setTestNow(Carbon::parse('2026-04-21 10:00:00'));

        $this->actingAs($owner)
            ->postJson(route('crm.sales-activities.customers.store', $customer), [
                'action' => 'sales_meeting_scheduled',
                'description' => 'On-site review booked',
                'note' => 'Prepare final samples for the visit.',
                'due_at' => '2026-04-24 14:30:00',
            ])
            ->assertCreated();
    } finally {
        Carbon::setTestNow();
    }

    $this->actingAs($owner)
        ->get(route('customer.show', $customer))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Customer/Show')
            ->where('customer.id', $customer->id)
            ->where('customer.requests.0.id', $lead->id)
            ->where('customer.requests.0.title', 'Customer regression request')
            ->where('customer.requests.0.quote.id', $quote->id)
            ->where('customer.requests.0.quote.number', $quote->number)
            ->where('canLogSalesActivity', true)
            ->where('salesActivityQuickActions.0.id', 'call_logged')
            ->where('salesActivityManualActions.0.action', 'sales_note_added')
            ->has('activity', 2)
            ->where('activity.0.subject', 'Customer')
            ->where('activity.0.sales_activity.activity_key', 'sales_meeting_scheduled')
            ->where('activity.0.properties.note', 'Prepare final samples for the visit.')
            ->where('activity.0.sales_activity.due_at', Carbon::parse('2026-04-24 14:30:00')->toIso8601String())
            ->where('activity.1.subject', 'Request')
            ->where('activity.1.sales_activity.activity_key', 'sales_call_quote_discussed')
            ->where('activity.1.properties.note', 'Customer requested one alternate option before approval.')
            ->where('activity.1.sales_activity.due_at', Carbon::parse('2026-04-23 11:00:00')->toIso8601String())
        );
});
