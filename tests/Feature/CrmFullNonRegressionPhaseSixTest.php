<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
});

test('quoted crm flow stays consistent across detail views workspaces and phase six opportunity surfaces', function () {
    $referenceTime = Carbon::parse('2026-04-25 09:00:00');
    $owner = crmFullRegressionPhaseSixOwner();
    $customer = crmFullRegressionPhaseSixCustomer($owner, 'Regression Revenue Co', 'regression-revenue@example.test');

    $lead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_QUOTE_SENT,
        'title' => 'Phase six regression request',
        'service_type' => 'Maintenance',
        'created_at' => '2026-04-20 09:00:00',
    ]);

    $quote = Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'request_id' => $lead->id,
        'job_title' => 'Phase six regression quote',
        'status' => 'sent',
        'subtotal' => 2000,
        'total' => 2200,
        'currency_code' => 'USD',
        'initial_deposit' => 0,
        'next_follow_up_at' => '2026-04-26 09:00:00',
        'created_at' => '2026-04-21 10:00:00',
    ]);

    try {
        Carbon::setTestNow(Carbon::parse('2026-04-21 09:00:00'));
        ActivityLog::record($owner, $lead, 'sales_note_added', [
            'customer_id' => $customer->id,
            'request_id' => $lead->id,
            'note' => 'Need revised scope before final send.',
        ], 'Qualification note recorded');

        Carbon::setTestNow(Carbon::parse('2026-04-21 10:00:00'));
        ActivityLog::record($owner, $quote, 'message_email_sent', [
            'email' => $customer->email,
            'source' => 'quote_manual_send',
            'customer_id' => $customer->id,
            'request_id' => $lead->id,
            'quote_id' => $quote->id,
        ], 'Quote email sent');

        Carbon::setTestNow(Carbon::parse('2026-04-21 11:00:00'));
        ActivityLog::record($owner, $quote, 'sales_next_action_scheduled', [
            'next_follow_up_at' => '2026-04-24T15:00:00+00:00',
            'sales_activity_action' => 'sales_next_action_scheduled',
            'customer_id' => $customer->id,
            'request_id' => $lead->id,
            'quote_id' => $quote->id,
        ], 'Urgent discovery call');

        Carbon::setTestNow(Carbon::parse('2026-04-21 12:00:00'));
        ActivityLog::record($owner, $customer, 'meeting_scheduled', [
            'provider' => 'google',
            'source' => 'calendar_sync',
            'start_at' => '2026-04-27T14:00:00+00:00',
            'location' => 'Customer site',
            'customer_id' => $customer->id,
        ], 'Customer meeting booked');
    } finally {
        Carbon::setTestNow();
    }

    $this->actingAs($owner)
        ->getJson(route('request.show', $lead))
        ->assertOk()
        ->assertJsonPath('lead.id', $lead->id)
        ->assertJsonPath('activity.0.action', 'sales_note_added')
        ->assertJsonPath('activity.0.sales_activity.activity_key', 'sales_note_added')
        ->assertJsonPath('activity.0.crm_links.primary.type', 'request')
        ->assertJsonPath('activity.0.crm_links.customer.id', $customer->id);

    $this->actingAs($owner)
        ->get(route('customer.quote.show', $quote))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Quote/Show')
            ->where('activity.0.action', 'sales_next_action_scheduled')
            ->where('activity.0.sales_activity.activity_key', 'sales_next_action_scheduled')
            ->where('activity.0.sales_activity.due_at', '2026-04-24T15:00:00+00:00')
            ->where('activity.0.crm_links.primary.type', 'quote')
            ->where('activity.0.crm_links.request.id', $lead->id)
            ->where('activity.1.action', 'message_email_sent')
            ->where('activity.1.is_message_event', true)
            ->where('activity.1.message_event.event_key', 'message_email_sent')
            ->where('activity.1.crm_links.request.id', $lead->id)
        );

    $this->actingAs($owner)
        ->get(route('customer.show', $customer))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Customer/Show')
            ->where('customer.requests.0.id', $lead->id)
            ->where('customer.requests.0.quote.id', $quote->id)
            ->where('lastInteraction.action', 'meeting_scheduled')
            ->has('activity', 4)
            ->where('activity.0.subject', 'Customer')
            ->where('activity.0.is_meeting_event', true)
            ->where('activity.1.subject', 'Quote')
            ->where('activity.1.sales_activity.activity_key', 'sales_next_action_scheduled')
            ->where('activity.2.subject', 'Quote')
            ->where('activity.2.is_message_event', true)
            ->where('activity.3.subject', 'Request')
            ->where('activity.3.sales_activity.activity_key', 'sales_note_added')
        );

    Sanctum::actingAs($owner);

    $this->getJson("/api/v1/pipeline?entityType=quote&entityId={$quote->id}")
        ->assertOk()
        ->assertJsonPath('opportunity.key', "request:{$lead->id}")
        ->assertJsonPath('opportunity.stage.key', 'quoted')
        ->assertJsonPath('opportunity.crm_links.subject.type', 'quote')
        ->assertJsonPath('opportunity.crm_links.primary.type', 'request')
        ->assertJsonPath('opportunity.crm_links.request.id', $lead->id)
        ->assertJsonPath('opportunity.crm_links.quote.id', $quote->id)
        ->assertJsonPath('opportunity.crm_links.customer.id', $customer->id);

    $this->actingAs($owner)
        ->get(route('crm.next-actions.index', [
            'reference_time' => $referenceTime->toIso8601String(),
            'due_state' => 'overdue',
            'search' => 'Urgent',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('CRM/MyNextActions')
            ->where('filters.due_state', 'overdue')
            ->where('filters.search', 'Urgent')
            ->where('count', 1)
            ->where('stats.total', 1)
            ->where('items.0.source', 'sales_activity')
            ->where('items.0.subject_type', 'quote')
            ->where('items.0.activity.description', 'Urgent discovery call')
        );

    $this->actingAs($owner)
        ->get(route('crm.sales-inbox.index', [
            'reference_time' => $referenceTime->toIso8601String(),
            'search' => 'Regression',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('CRM/SalesInbox')
            ->where('count', 1)
            ->where('stats.total', 1)
            ->where('stats.quoted', 1)
            ->where('items.0.queue', 'quoted')
            ->where('items.0.crm_links.subject.type', 'quote')
            ->where('items.0.crm_links.primary.type', 'request')
            ->where('items.0.crm_links.request.id', $lead->id)
            ->where('items.0.crm_links.quote.id', $quote->id)
            ->where('items.0.primary_subject_type', 'quote')
            ->where('items.0.primary_subject_id', $quote->id)
        );

    $this->actingAs($owner)
        ->get(route('crm.manager-dashboard.index', [
            'reference_time' => $referenceTime->toIso8601String(),
            'search' => 'Regression',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('CRM/ManagerDashboard')
            ->where('summary.open_count', 1)
            ->where('summary.open_amount', 2200)
            ->where('summary.weighted_open_amount', 1650)
            ->where('summary.quote_pull_through.total', 1)
            ->where('summary.quote_pull_through.open', 1)
            ->where('summary.quote_pull_through.won', 0)
            ->where('attention_items.0.crm_links.subject.type', 'quote')
            ->where('attention_items.0.crm_links.primary.type', 'request')
            ->where('attention_items.0.crm_links.request.id', $lead->id)
            ->where('attention_items.0.crm_links.quote.id', $quote->id)
        );
});

test('won downstream crm flow stays request anchored in pipeline while remaining out of open sales queues', function () {
    $referenceTime = Carbon::parse('2026-04-25 09:00:00');
    $owner = crmFullRegressionPhaseSixOwner([
        'email' => 'won-flow-owner@example.test',
    ]);
    $customer = crmFullRegressionPhaseSixCustomer($owner, 'Won Revenue Co', 'won-revenue@example.test');

    $lead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_WON,
        'title' => 'Phase six won request',
        'service_type' => 'Upgrade',
        'created_at' => '2026-04-18 09:00:00',
    ]);

    $quote = Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'request_id' => $lead->id,
        'job_title' => 'Phase six won quote',
        'status' => 'accepted',
        'subtotal' => 1800,
        'total' => 1800,
        'currency_code' => 'USD',
        'initial_deposit' => 0,
        'accepted_at' => '2026-04-22 08:00:00',
        'created_at' => '2026-04-19 08:00:00',
    ]);

    $work = Work::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'quote_id' => $quote->id,
        'job_title' => 'Phase six won work',
        'instructions' => 'Deliver the accepted scope.',
        'status' => 'in_progress',
        'start_date' => '2026-04-23',
        'end_date' => '2026-04-23',
        'start_time' => '09:00:00',
        'end_time' => '17:00:00',
        'is_all_day' => false,
        'later' => false,
        'ends' => 'Never',
        'frequencyNumber' => 1,
        'frequency' => 'Weekly',
        'totalVisits' => 1,
        'repeatsOn' => [],
        'subtotal' => 1800,
        'total' => 1800,
        'created_at' => '2026-04-23 09:00:00',
    ]);

    $quote->update([
        'work_id' => $work->id,
    ]);

    $invoice = Invoice::query()->create([
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'work_id' => $work->id,
        'status' => 'partial',
        'total' => 1800,
        'created_at' => '2026-04-24 09:00:00',
    ]);

    Sanctum::actingAs($owner);

    $this->getJson("/api/v1/pipeline?entityType=invoice&entityId={$invoice->id}")
        ->assertOk()
        ->assertJsonPath('opportunity.key', "request:{$lead->id}")
        ->assertJsonPath('opportunity.stage.key', 'won')
        ->assertJsonPath('opportunity.crm_links.subject.type', 'quote')
        ->assertJsonPath('opportunity.crm_links.primary.type', 'request')
        ->assertJsonPath('opportunity.crm_links.request.id', $lead->id)
        ->assertJsonPath('opportunity.crm_links.quote.id', $quote->id)
        ->assertJsonPath('opportunity.crm_links.job.id', $work->id)
        ->assertJsonPath('opportunity.crm_links.invoice.id', $invoice->id);

    $this->actingAs($owner)
        ->getJson(route('crm.sales-inbox.index', [
            'reference_time' => $referenceTime->toIso8601String(),
        ]))
        ->assertOk()
        ->assertJsonPath('count', 0)
        ->assertJsonPath('stats.total', 0);

    $this->actingAs($owner)
        ->get(route('crm.manager-dashboard.index', [
            'reference_time' => $referenceTime->toIso8601String(),
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('CRM/ManagerDashboard')
            ->where('summary.open_count', 0)
            ->where('summary.weighted_open_amount', 0)
            ->where('summary.month_to_date_won_amount', 1800)
            ->where('summary.month_to_date_won_count', 1)
            ->where('summary.quote_pull_through.total', 1)
            ->where('summary.quote_pull_through.won', 1)
            ->where('summary.quote_pull_through.open', 0)
            ->where('attention_items', [])
        );
});

function crmFullRegressionPhaseSixRoleId(string $name, string $description): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $description],
    )->id;
}

function crmFullRegressionPhaseSixOwner(array $overrides = []): User
{
    return User::factory()->create(array_replace_recursive([
        'role_id' => crmFullRegressionPhaseSixRoleId('owner', 'Phase six full regression owner role'),
        'company_type' => 'services',
        'onboarding_completed_at' => now(),
        'two_factor_exempt' => false,
    ], $overrides));
}

function crmFullRegressionPhaseSixCustomer(User $owner, string $companyName, string $email): Customer
{
    return Customer::factory()->create([
        'user_id' => $owner->id,
        'company_name' => $companyName,
        'email' => $email,
    ]);
}
