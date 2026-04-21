<?php

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Work;
use App\Queries\CRM\BuildSalesPipelineIndexData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

test('sales pipeline query resolves request backed and quote only opportunities with stage board stats', function () {
    $referenceTime = Carbon::parse('2026-04-25 09:00:00');
    $owner = salesPipelinePhaseSixOwner();
    $assignee = salesPipelineTeamMember($owner, 'Pipeline closer');

    $freshCustomer = salesPipelineCustomer($owner, 'Fresh Intake Co', 'fresh-intake@example.test');
    LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $freshCustomer->id,
        'assigned_team_member_id' => $assignee->id,
        'status' => LeadRequest::STATUS_NEW,
        'title' => 'Fresh intake opportunity',
        'service_type' => 'Inspection',
        'created_at' => '2026-04-24 08:00:00',
    ]);

    $contactedCustomer = salesPipelineCustomer($owner, 'Follow Up Co', 'follow-up@example.test');
    LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $contactedCustomer->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Follow up opportunity',
        'service_type' => 'Repairs',
        'next_follow_up_at' => '2026-04-24 12:00:00',
        'created_at' => '2026-04-23 09:00:00',
    ]);

    $quotedCustomer = salesPipelineCustomer($owner, 'Quoted Project Co', 'quoted-project@example.test');
    $quotedLead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $quotedCustomer->id,
        'status' => LeadRequest::STATUS_QUOTE_SENT,
        'title' => 'Quoted opportunity',
        'service_type' => 'Landscaping',
        'created_at' => '2026-04-20 09:00:00',
    ]);
    Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $quotedCustomer->id,
        'request_id' => $quotedLead->id,
        'job_title' => 'Quoted project proposal',
        'status' => 'sent',
        'subtotal' => 2000,
        'total' => 2000,
        'currency_code' => 'USD',
        'next_follow_up_at' => '2026-04-26 09:00:00',
        'created_at' => '2026-04-21 11:00:00',
    ]);

    $wonCustomer = salesPipelineCustomer($owner, 'Won Project Co', 'won-project@example.test');
    $wonLead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $wonCustomer->id,
        'status' => LeadRequest::STATUS_WON,
        'title' => 'Won opportunity',
        'service_type' => 'Maintenance',
        'created_at' => '2026-04-18 09:00:00',
    ]);
    $wonQuote = Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $wonCustomer->id,
        'request_id' => $wonLead->id,
        'job_title' => 'Won project quote',
        'status' => 'accepted',
        'subtotal' => 3000,
        'total' => 3000,
        'currency_code' => 'USD',
        'accepted_at' => '2026-04-22 08:00:00',
        'created_at' => '2026-04-19 09:00:00',
    ]);
    $wonWork = Work::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $wonCustomer->id,
        'quote_id' => $wonQuote->id,
        'job_title' => 'Won project work',
        'instructions' => 'Deliver the accepted quote.',
        'status' => 'in_progress',
        'start_date' => '2026-04-23',
        'end_date' => '2026-04-24',
        'start_time' => '09:00:00',
        'end_time' => '17:00:00',
        'is_all_day' => false,
        'later' => false,
        'ends' => 'Never',
        'frequencyNumber' => 1,
        'frequency' => 'Weekly',
        'totalVisits' => 1,
        'repeatsOn' => [],
        'subtotal' => 3000,
        'total' => 3000,
    ]);
    $wonQuote->update(['work_id' => $wonWork->id]);
    Invoice::query()->create([
        'customer_id' => $wonCustomer->id,
        'user_id' => $owner->id,
        'work_id' => $wonWork->id,
        'status' => 'partial',
        'total' => 1500,
    ]);

    $lostCustomer = salesPipelineCustomer($owner, 'Lost Project Co', 'lost-project@example.test');
    $lostLead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $lostCustomer->id,
        'status' => LeadRequest::STATUS_LOST,
        'title' => 'Lost opportunity',
        'service_type' => 'Cleaning',
        'created_at' => '2026-04-17 09:00:00',
    ]);
    Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $lostCustomer->id,
        'request_id' => $lostLead->id,
        'job_title' => 'Lost project quote',
        'status' => 'declined',
        'subtotal' => 700,
        'total' => 700,
        'currency_code' => 'USD',
        'created_at' => '2026-04-18 08:30:00',
    ]);

    $orphanCustomer = salesPipelineCustomer($owner, 'Orphan Quote Co', 'orphan-quote@example.test');
    $orphanQuote = Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $orphanCustomer->id,
        'job_title' => 'Standalone upsell quote',
        'status' => 'sent',
        'subtotal' => 800,
        'total' => 800,
        'currency_code' => 'USD',
        'next_follow_up_at' => '2026-04-27 10:00:00',
        'created_at' => '2026-04-22 10:00:00',
    ]);

    $queryRequest = Request::create('/crm/sales-pipeline', 'GET', [
        'per_page' => 5,
        'reference_time' => $referenceTime->toIso8601String(),
    ]);

    $result = app(BuildSalesPipelineIndexData::class)->execute($owner->id, $queryRequest);
    $items = app(BuildSalesPipelineIndexData::class)->resolveCollection($owner->id, [], $referenceTime);

    expect($items)->toHaveCount(6)
        ->and($items->pluck('stage_key')->all())->toBe(['intake', 'contacted', 'quoted', 'quoted', 'won', 'lost'])
        ->and($items->pluck('key')->all())->toContain("quote:{$orphanQuote->id}");

    $quotedItem = $items->firstWhere('key', "request:{$quotedLead->id}");
    $wonItem = $items->firstWhere('key', "request:{$wonLead->id}");
    $orphanItem = $items->firstWhere('key', "quote:{$orphanQuote->id}");

    expect(data_get($quotedItem, 'weighted_amount'))->toBe(1500.0)
        ->and(data_get($quotedItem, 'customer.name'))->toBe('Quoted Project Co')
        ->and(data_get($quotedItem, 'crm_links.subject.type'))->toBe('quote')
        ->and(data_get($quotedItem, 'crm_links.primary.type'))->toBe('request')
        ->and(data_get($quotedItem, 'crm_links.request.id'))->toBe($quotedLead->id)
        ->and(data_get($quotedItem, 'crm_links.quote.id'))->not->toBeNull()
        ->and(data_get($quotedItem, 'primary_subject_type'))->toBe('quote')
        ->and(data_get($wonItem, 'stage_state'))->toBe('won')
        ->and(data_get($wonItem, 'job.status'))->toBe('in_progress')
        ->and(data_get($wonItem, 'crm_links.job.id'))->toBe($wonWork->id)
        ->and(data_get($orphanItem, 'signals.is_quote_only'))->toBeTrue();

    $paginator = $result['opportunities'];
    $board = collect($result['board'])->keyBy('key');

    expect($paginator->total())->toBe(6)
        ->and(count($paginator->items()))->toBe(5)
        ->and($result['stats'])->toMatchArray([
            'total' => 6,
            'open' => 4,
            'won' => 1,
            'lost' => 1,
            'with_quote' => 4,
            'without_quote' => 2,
            'overdue_next_actions' => 1,
            'open_amount' => 2800.0,
            'weighted_open_amount' => 2100.0,
            'closed_won_amount' => 3000.0,
        ])
        ->and(data_get($result, 'stats.by_stage.quoted'))->toBe(2)
        ->and(data_get($board->get('quoted'), 'count'))->toBe(2)
        ->and(data_get($board->get('won'), 'amount_total'))->toBe(3000.0)
        ->and(data_get($board->get('contacted'), 'overdue_next_actions'))->toBe(1);
});

test('sales pipeline query filters by stage next action and search', function () {
    $referenceTime = Carbon::parse('2026-04-25 09:00:00');
    $owner = salesPipelinePhaseSixOwner();

    $northwind = salesPipelineCustomer($owner, 'Northwind Group', 'northwind@example.test');
    $northwindLead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $northwind->id,
        'status' => LeadRequest::STATUS_QUOTE_SENT,
        'title' => 'Northwind quoting',
        'service_type' => 'Install',
    ]);
    Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $northwind->id,
        'request_id' => $northwindLead->id,
        'job_title' => 'Northwind expansion quote',
        'status' => 'sent',
        'subtotal' => 1200,
        'total' => 1200,
        'currency_code' => 'USD',
        'next_follow_up_at' => '2026-04-24 09:00:00',
    ]);

    $southwind = salesPipelineCustomer($owner, 'Southwind Group', 'southwind@example.test');
    $southwindLead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $southwind->id,
        'status' => LeadRequest::STATUS_QUOTE_SENT,
        'title' => 'Southwind quoting',
        'service_type' => 'Install',
    ]);
    Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $southwind->id,
        'request_id' => $southwindLead->id,
        'job_title' => 'Southwind rollout quote',
        'status' => 'sent',
        'subtotal' => 1800,
        'total' => 1800,
        'currency_code' => 'USD',
        'next_follow_up_at' => '2026-04-26 09:00:00',
    ]);

    $lostCustomer = salesPipelineCustomer($owner, 'Legacy Loss', 'legacy-loss@example.test');
    $lostLead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $lostCustomer->id,
        'status' => LeadRequest::STATUS_LOST,
        'title' => 'Legacy lost opportunity',
        'service_type' => 'Audit',
    ]);
    Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $lostCustomer->id,
        'request_id' => $lostLead->id,
        'job_title' => 'Legacy decline quote',
        'status' => 'declined',
        'subtotal' => 600,
        'total' => 600,
        'currency_code' => 'USD',
    ]);

    $filtered = app(BuildSalesPipelineIndexData::class)->resolveCollection($owner->id, [
        'stage' => 'quoted',
        'next_action' => 'overdue',
        'search' => 'northwind',
        'amount_min' => 1000,
        'amount_max' => 1500,
    ], $referenceTime);

    expect($filtered)->toHaveCount(1)
        ->and($filtered->first()['key'])->toBe("request:{$northwindLead->id}")
        ->and($filtered->first()['stage_key'])->toBe('quoted')
        ->and($filtered->first()['signals']['has_overdue_next_action'])->toBeTrue()
        ->and(data_get($filtered->first(), 'customer.name'))->toBe('Northwind Group');

    $lostOnly = app(BuildSalesPipelineIndexData::class)->resolveCollection($owner->id, [
        'state' => 'lost',
    ], $referenceTime);

    expect($lostOnly)->toHaveCount(1)
        ->and($lostOnly->first()['stage_key'])->toBe('lost')
        ->and($lostOnly->first()['stage_state'])->toBe('lost');
});

function salesPipelinePhaseSixOwner(): User
{
    return User::factory()->create([
        'role_id' => salesPipelinePhaseSixRoleId('owner', 'Phase six sales pipeline owner role'),
        'company_type' => 'services',
        'onboarding_completed_at' => now(),
        'two_factor_exempt' => false,
    ]);
}

function salesPipelinePhaseSixRoleId(string $name, string $description): int
{
    return Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $description],
    )->id;
}

function salesPipelineTeamMember(User $owner, string $name): TeamMember
{
    $memberUser = User::factory()->create([
        'role_id' => salesPipelinePhaseSixRoleId('sales_manager', 'Phase six sales manager role'),
        'company_type' => 'services',
        'name' => $name,
        'onboarding_completed_at' => now(),
        'two_factor_exempt' => false,
    ]);

    return TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $memberUser->id,
        'role' => 'sales_manager',
        'permissions' => ['sales.manage', 'quotes.view'],
        'is_active' => true,
    ]);
}

function salesPipelineCustomer(User $owner, string $companyName, string $email): Customer
{
    return Customer::factory()->create([
        'user_id' => $owner->id,
        'company_name' => $companyName,
        'email' => $email,
    ]);
}
