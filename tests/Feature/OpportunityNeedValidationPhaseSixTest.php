<?php

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('pipeline api exposes a request quote first opportunity validation contract for request only flows', function () {
    $owner = phaseSixOwner();

    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
        'company_name' => 'Opportunity Validation Client',
        'email' => 'opportunity-validation@example.test',
    ]);

    $lead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Opportunity validation request',
        'service_type' => 'Maintenance',
        'next_follow_up_at' => '2026-04-22 10:00:00',
    ]);

    Sanctum::actingAs($owner);

    $this->getJson("/api/v1/pipeline?entityType=request&entityId={$lead->id}")
        ->assertOk()
        ->assertJsonPath('opportunity_validation.validated', true)
        ->assertJsonPath('opportunity_validation.mode', 'request_quote_first')
        ->assertJsonPath('opportunity_validation.requires_opportunity', false)
        ->assertJsonPath('opportunity_validation.decision', 'defer_opportunity_model')
        ->assertJsonPath('opportunity_validation.current_anchor.type', 'request')
        ->assertJsonPath('opportunity_validation.current_anchor.id', $lead->id)
        ->assertJsonPath('opportunity_validation.next_action_anchor.type', 'request')
        ->assertJsonPath('opportunity_validation.next_action_anchor.id', $lead->id)
        ->assertJsonPath('opportunity_validation.forecast_anchor', null)
        ->assertJsonPath('request.next_follow_up_at', '2026-04-22T10:00:00+00:00');

    $reasons = $this->getJson("/api/v1/pipeline?entityType=request&entityId={$lead->id}")
        ->json('opportunity_validation.reason_codes');

    expect($reasons)->toContain('request_quote_chain_covers_pipeline')
        ->toContain('request_quote_next_actions_cover_follow_up')
        ->toContain('quote_totals_cover_v1_forecast_basis');
});

test('pipeline api keeps quote as the commercial anchor for downstream sales objects without requiring an opportunity model', function () {
    $owner = phaseSixOwner();

    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
        'company_name' => 'Opportunity Downstream Client',
        'email' => 'opportunity-downstream@example.test',
    ]);

    $lead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_QUOTE_SENT,
        'title' => 'Downstream opportunity request',
        'service_type' => 'Consulting',
    ]);

    $quote = Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'request_id' => $lead->id,
        'job_title' => 'Downstream opportunity quote',
        'status' => 'sent',
        'subtotal' => 1000,
        'total' => 1200,
        'initial_deposit' => 200,
        'next_follow_up_at' => '2026-04-23 14:00:00',
    ]);

    $work = Work::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'quote_id' => $quote->id,
        'job_title' => 'Downstream opportunity job',
        'instructions' => 'Continue the approved sales flow.',
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

    $response = $this->getJson("/api/v1/pipeline?entityType=invoice&entityId={$invoice->id}")
        ->assertOk()
        ->assertJsonPath('opportunity_validation.validated', true)
        ->assertJsonPath('opportunity_validation.requires_opportunity', false)
        ->assertJsonPath('opportunity_validation.current_anchor.type', 'quote')
        ->assertJsonPath('opportunity_validation.current_anchor.id', $quote->id)
        ->assertJsonPath('opportunity_validation.forecast_anchor.type', 'quote')
        ->assertJsonPath('opportunity_validation.forecast_anchor.id', $quote->id)
        ->assertJsonPath('opportunity_validation.forecast_anchor.amount', 1200)
        ->assertJsonPath('opportunity_validation.next_action_anchor.type', 'quote')
        ->assertJsonPath('opportunity_validation.next_action_anchor.id', $quote->id)
        ->assertJsonPath('quote.next_follow_up_at', '2026-04-23T14:00:00+00:00');

    expect($response->json('opportunity_validation.promotion_triggers'))
        ->toHaveCount(3);
});

function phaseSixOwner(): User
{
    return User::factory()->create([
        'role_id' => phaseSixRoleId('owner', 'Phase six owner role'),
        'onboarding_completed_at' => now(),
        'two_factor_exempt' => false,
    ]);
}

function phaseSixRoleId(string $name, string $description): int
{
    return Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $description],
    )->id;
}
