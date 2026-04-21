<?php

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('pipeline api exposes a request anchored opportunity projection for request only flows', function () {
    Carbon::setTestNow('2026-04-21 09:00:00');

    $owner = phaseSixOpportunityOwner();

    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
        'company_name' => 'Opportunity Projection Client',
        'email' => 'opportunity-projection@example.test',
    ]);

    $lead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_QUALIFIED,
        'title' => 'Request anchored opportunity',
        'service_type' => 'Maintenance',
        'next_follow_up_at' => '2026-04-22 10:00:00',
    ]);

    Sanctum::actingAs($owner);

    $this->getJson("/api/v1/pipeline?entityType=request&entityId={$lead->id}")
        ->assertOk()
        ->assertJsonPath('opportunity.mode', 'request_quote_projection')
        ->assertJsonPath('opportunity.key', "request:{$lead->id}")
        ->assertJsonPath('opportunity.is_projection', true)
        ->assertJsonPath('opportunity.is_persisted', false)
        ->assertJsonPath('opportunity.title', 'Request anchored opportunity')
        ->assertJsonPath('opportunity.customer_id', $customer->id)
        ->assertJsonPath('opportunity.stage.key', 'qualified')
        ->assertJsonPath('opportunity.stage.state', 'open')
        ->assertJsonPath('opportunity.forecast.category', 'pipeline')
        ->assertJsonPath('opportunity.forecast.probability_percent', 50)
        ->assertJsonPath('opportunity.forecast.amount', null)
        ->assertJsonPath('opportunity.forecast.weighted_amount', null)
        ->assertJsonPath('opportunity.next_action.source_type', 'request')
        ->assertJsonPath('opportunity.next_action.source_id', $lead->id)
        ->assertJsonPath('opportunity.next_action.at', '2026-04-22T10:00:00+00:00')
        ->assertJsonPath('opportunity.next_action.is_overdue', false)
        ->assertJsonPath('opportunity.anchors.request_id', $lead->id)
        ->assertJsonPath('opportunity.anchors.quote_id', null)
        ->assertJsonPath('opportunity.statuses.request', LeadRequest::STATUS_QUALIFIED)
        ->assertJsonPath('opportunity.validation.mode', 'request_quote_first')
        ->assertJsonPath('opportunity.validation.requires_opportunity', false)
        ->assertJsonPath('opportunity.crm_links.subject.type', 'request')
        ->assertJsonPath('opportunity.crm_links.subject.id', $lead->id)
        ->assertJsonPath('opportunity.crm_links.primary.type', 'request')
        ->assertJsonPath('opportunity.crm_links.request.id', $lead->id)
        ->assertJsonPath('opportunity.crm_links.quote', null)
        ->assertJsonPath('opportunity.crm_links.customer.id', $customer->id);
});

test('pipeline api exposes a quote weighted opportunity projection without creating a persisted opportunity model', function () {
    Carbon::setTestNow('2026-04-21 09:00:00');

    $owner = phaseSixOpportunityOwner();

    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
        'company_name' => 'Quoted Opportunity Client',
        'email' => 'quoted-opportunity@example.test',
    ]);

    $lead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_QUOTE_SENT,
        'title' => 'Quoted opportunity request',
        'service_type' => 'Consulting',
    ]);

    $quote = Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'request_id' => $lead->id,
        'job_title' => 'Quoted opportunity proposal',
        'status' => 'sent',
        'subtotal' => 2000,
        'total' => 2400,
        'currency_code' => 'USD',
        'next_follow_up_at' => '2026-04-21 13:30:00',
    ]);

    Sanctum::actingAs($owner);

    $this->getJson("/api/v1/pipeline?entityType=quote&entityId={$quote->id}")
        ->assertOk()
        ->assertJsonPath('opportunity.key', "request:{$lead->id}")
        ->assertJsonPath('opportunity.title', 'Quoted opportunity proposal')
        ->assertJsonPath('opportunity.stage.key', 'quoted')
        ->assertJsonPath('opportunity.stage.state', 'open')
        ->assertJsonPath('opportunity.amount.subtotal', 2000)
        ->assertJsonPath('opportunity.amount.total', 2400)
        ->assertJsonPath('opportunity.amount.currency_code', 'USD')
        ->assertJsonPath('opportunity.forecast.category', 'best_case')
        ->assertJsonPath('opportunity.forecast.probability_percent', 75)
        ->assertJsonPath('opportunity.forecast.amount', 2400)
        ->assertJsonPath('opportunity.forecast.weighted_amount', 1800)
        ->assertJsonPath('opportunity.next_action.source_type', 'quote')
        ->assertJsonPath('opportunity.next_action.source_id', $quote->id)
        ->assertJsonPath('opportunity.next_action.is_overdue', false)
        ->assertJsonPath('opportunity.anchors.request_id', $lead->id)
        ->assertJsonPath('opportunity.anchors.quote_id', $quote->id)
        ->assertJsonPath('opportunity.statuses.quote', 'sent')
        ->assertJsonPath('opportunity.crm_links.subject.type', 'quote')
        ->assertJsonPath('opportunity.crm_links.subject.id', $quote->id)
        ->assertJsonPath('opportunity.crm_links.primary.type', 'request')
        ->assertJsonPath('opportunity.crm_links.primary.id', $lead->id)
        ->assertJsonPath('opportunity.crm_links.request.id', $lead->id)
        ->assertJsonPath('opportunity.crm_links.quote.id', $quote->id)
        ->assertJsonPath('opportunity.crm_links.customer.id', $customer->id)
        ->assertJsonPath('opportunity.crm_links.anchors.0.type', 'quote')
        ->assertJsonPath('opportunity.crm_links.anchors.1.type', 'request');
});

test('pipeline api keeps the same opportunity projection key for downstream invoice flows once a quote is won', function () {
    Carbon::setTestNow('2026-04-24 09:00:00');

    $owner = phaseSixOpportunityOwner();

    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
        'company_name' => 'Won Opportunity Client',
        'email' => 'won-opportunity@example.test',
    ]);

    $lead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_WON,
        'title' => 'Won opportunity request',
        'service_type' => 'Landscaping',
    ]);

    $quote = Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'request_id' => $lead->id,
        'job_title' => 'Won opportunity quote',
        'status' => 'accepted',
        'subtotal' => 1000,
        'total' => 1200,
        'currency_code' => 'USD',
        'accepted_at' => '2026-04-23 08:00:00',
        'next_follow_up_at' => '2026-04-23 14:00:00',
    ]);

    $work = Work::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'quote_id' => $quote->id,
        'job_title' => 'Won opportunity job',
        'instructions' => 'Continue delivery after the accepted quote.',
        'status' => 'in_progress',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDay()->toDateString(),
        'start_time' => '09:00:00',
        'end_time' => '17:00:00',
        'is_all_day' => false,
        'later' => false,
        'ends' => 'Never',
        'frequencyNumber' => 1,
        'frequency' => 'Weekly',
        'totalVisits' => 1,
        'repeatsOn' => [],
        'subtotal' => 1000,
        'total' => 1200,
    ]);

    $quote->update(['work_id' => $work->id]);

    $invoice = Invoice::query()->create([
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'work_id' => $work->id,
        'status' => 'partial',
        'total' => 700,
    ]);

    Sanctum::actingAs($owner);

    $this->getJson("/api/v1/pipeline?entityType=invoice&entityId={$invoice->id}")
        ->assertOk()
        ->assertJsonPath('opportunity.key', "request:{$lead->id}")
        ->assertJsonPath('opportunity.stage.key', 'won')
        ->assertJsonPath('opportunity.stage.state', 'won')
        ->assertJsonPath('opportunity.forecast.category', 'closed_won')
        ->assertJsonPath('opportunity.forecast.probability_percent', 100)
        ->assertJsonPath('opportunity.forecast.amount', 1200)
        ->assertJsonPath('opportunity.forecast.weighted_amount', 1200)
        ->assertJsonPath('opportunity.next_action.source_type', 'quote')
        ->assertJsonPath('opportunity.next_action.source_id', $quote->id)
        ->assertJsonPath('opportunity.next_action.is_overdue', true)
        ->assertJsonPath('opportunity.anchors.request_id', $lead->id)
        ->assertJsonPath('opportunity.anchors.quote_id', $quote->id)
        ->assertJsonPath('opportunity.anchors.job_id', $work->id)
        ->assertJsonPath('opportunity.anchors.invoice_id', $invoice->id)
        ->assertJsonPath('opportunity.statuses.job', 'in_progress')
        ->assertJsonPath('opportunity.statuses.invoice', 'partial')
        ->assertJsonPath('opportunity.timestamps.won_at', '2026-04-23T08:00:00+00:00')
        ->assertJsonPath('opportunity.crm_links.subject.type', 'quote')
        ->assertJsonPath('opportunity.crm_links.primary.type', 'request')
        ->assertJsonPath('opportunity.crm_links.request.id', $lead->id)
        ->assertJsonPath('opportunity.crm_links.quote.id', $quote->id)
        ->assertJsonPath('opportunity.crm_links.job.id', $work->id)
        ->assertJsonPath('opportunity.crm_links.invoice.id', $invoice->id)
        ->assertJsonPath('opportunity.crm_links.anchors.2.type', 'customer')
        ->assertJsonPath('opportunity.crm_links.anchors.3.type', 'job')
        ->assertJsonPath('opportunity.crm_links.anchors.4.type', 'invoice');
});

test('pipeline api marks declined quote projections as closed lost while preserving the quote amount anchor', function () {
    Carbon::setTestNow('2026-04-24 09:00:00');

    $owner = phaseSixOpportunityOwner();

    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
        'company_name' => 'Lost Opportunity Client',
        'email' => 'lost-opportunity@example.test',
    ]);

    $lead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_LOST,
        'title' => 'Lost opportunity request',
        'service_type' => 'Cleaning',
    ]);

    $quote = Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'request_id' => $lead->id,
        'job_title' => 'Lost opportunity quote',
        'status' => 'declined',
        'subtotal' => 900,
        'total' => 1000,
        'currency_code' => 'USD',
    ]);

    Sanctum::actingAs($owner);

    $this->getJson("/api/v1/pipeline?entityType=quote&entityId={$quote->id}")
        ->assertOk()
        ->assertJsonPath('opportunity.key', "request:{$lead->id}")
        ->assertJsonPath('opportunity.stage.key', 'lost')
        ->assertJsonPath('opportunity.stage.state', 'lost')
        ->assertJsonPath('opportunity.forecast.category', 'closed_lost')
        ->assertJsonPath('opportunity.forecast.probability_percent', 0)
        ->assertJsonPath('opportunity.forecast.amount', 1000)
        ->assertJsonPath('opportunity.forecast.weighted_amount', 0)
        ->assertJsonPath('opportunity.anchors.quote_id', $quote->id)
        ->assertJsonPath('opportunity.statuses.quote', 'declined');
});

function phaseSixOpportunityOwner(): User
{
    return User::factory()->create([
        'role_id' => phaseSixOpportunityRoleId('owner', 'Phase six opportunity owner role'),
        'onboarding_completed_at' => now(),
        'two_factor_exempt' => false,
    ]);
}

function phaseSixOpportunityRoleId(string $name, string $description): int
{
    return Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $description],
    )->id;
}
