<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
});

test('manager dashboard route returns weighted pipeline, stage aging, and queue pressure for sales managers', function () {
    $referenceTime = Carbon::parse('2026-04-25 09:00:00');
    $owner = managerDashboardPhaseSixOwner();

    $overdueCustomer = managerDashboardPhaseSixCustomer($owner, 'Northwind Quote Co', 'northwind-quote@example.test');
    $overdueLead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $overdueCustomer->id,
        'status' => LeadRequest::STATUS_QUOTE_SENT,
        'title' => 'Northwind rollout',
        'service_type' => 'Install',
    ]);
    managerDashboardPhaseSixBackdate($overdueLead, '2026-04-01 09:00:00');
    Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $overdueCustomer->id,
        'request_id' => $overdueLead->id,
        'job_title' => 'Northwind rollout quote',
        'status' => 'sent',
        'subtotal' => 1000,
        'total' => 1000,
        'currency_code' => 'USD',
        'next_follow_up_at' => '2026-04-24 09:00:00',
        'created_at' => '2026-04-02 09:00:00',
    ]);

    $qualifiedCustomer = managerDashboardPhaseSixCustomer($owner, 'Qualified Services', 'qualified-services@example.test');
    $qualifiedLead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $qualifiedCustomer->id,
        'status' => LeadRequest::STATUS_QUALIFIED,
        'title' => 'Qualified maintenance',
        'service_type' => 'Maintenance',
        'next_follow_up_at' => '2026-04-27 09:00:00',
    ]);
    managerDashboardPhaseSixBackdate($qualifiedLead, '2026-04-10 09:00:00');

    $intakeCustomer = managerDashboardPhaseSixCustomer($owner, 'Fresh Intake Co', 'fresh-intake@example.test');
    $intakeLead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $intakeCustomer->id,
        'status' => LeadRequest::STATUS_NEW,
        'title' => 'Fresh intake lead',
        'service_type' => 'Inspection',
    ]);
    managerDashboardPhaseSixBackdate($intakeLead, '2026-04-24 09:00:00');

    $standaloneCustomer = managerDashboardPhaseSixCustomer($owner, 'Orphan Quote Co', 'orphan-quote@example.test');
    $standaloneQuote = Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $standaloneCustomer->id,
        'job_title' => 'Standalone upsell quote',
        'status' => 'sent',
        'subtotal' => 800,
        'total' => 800,
        'currency_code' => 'USD',
        'next_follow_up_at' => '2026-04-26 09:00:00',
    ]);
    managerDashboardPhaseSixBackdate($standaloneQuote, '2026-03-20 09:00:00');

    $wonCustomer = managerDashboardPhaseSixCustomer($owner, 'Victory Co', 'victory@example.test');
    $wonLead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $wonCustomer->id,
        'status' => LeadRequest::STATUS_WON,
        'title' => 'Closed won deal',
        'service_type' => 'Upgrade',
        'created_at' => '2026-04-05 09:00:00',
    ]);
    Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $wonCustomer->id,
        'request_id' => $wonLead->id,
        'job_title' => 'Closed won proposal',
        'status' => 'accepted',
        'subtotal' => 3000,
        'total' => 3000,
        'currency_code' => 'USD',
        'accepted_at' => '2026-04-12 09:00:00',
        'created_at' => '2026-04-06 09:00:00',
    ]);

    $lostCustomer = managerDashboardPhaseSixCustomer($owner, 'Lost Co', 'lost@example.test');
    $lostLead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $lostCustomer->id,
        'status' => LeadRequest::STATUS_LOST,
        'title' => 'Closed lost deal',
        'service_type' => 'Audit',
        'created_at' => '2026-04-03 09:00:00',
    ]);
    Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $lostCustomer->id,
        'request_id' => $lostLead->id,
        'job_title' => 'Closed lost proposal',
        'status' => 'declined',
        'subtotal' => 500,
        'total' => 500,
        'currency_code' => 'USD',
        'created_at' => '2026-04-04 09:00:00',
    ]);

    $this->actingAs($owner)
        ->getJson(route('crm.manager-dashboard.index', [
            'reference_time' => $referenceTime->toIso8601String(),
        ]))
        ->assertOk()
        ->assertJsonPath('summary.open_count', 4)
        ->assertJsonPath('summary.open_amount', 1800)
        ->assertJsonPath('summary.weighted_open_amount', 1350)
        ->assertJsonPath('summary.month_to_date_won_amount', 3000)
        ->assertJsonPath('summary.month_to_date_won_count', 1)
        ->assertJsonPath('summary.overdue_next_actions', 1)
        ->assertJsonPath('summary.quote_pull_through.total', 4)
        ->assertJsonPath('summary.quote_pull_through.won', 1)
        ->assertJsonPath('summary.quote_pull_through.open', 2)
        ->assertJsonPath('summary.quote_pull_through.lost', 1)
        ->assertJsonPath('summary.quote_pull_through.rate', 25)
        ->assertJsonPath('weighted_pipeline.1.key', 'best_case')
        ->assertJsonPath('weighted_pipeline.1.weighted_amount', 1350)
        ->assertJsonPath('stage_aging.0.key', 'intake')
        ->assertJsonPath('stage_aging.0.count', 1)
        ->assertJsonPath('stage_aging.0.average_age_days', 1)
        ->assertJsonPath('stage_aging.2.key', 'qualified')
        ->assertJsonPath('stage_aging.2.count', 1)
        ->assertJsonPath('stage_aging.2.average_age_days', 15)
        ->assertJsonPath('stage_aging.3.key', 'quoted')
        ->assertJsonPath('stage_aging.3.count', 2)
        ->assertJsonPath('stage_aging.3.average_age_days', 30)
        ->assertJsonPath('stage_aging.3.overdue_next_actions', 1)
        ->assertJsonPath('next_actions.0.key', 'overdue')
        ->assertJsonPath('next_actions.0.count', 1)
        ->assertJsonPath('next_actions.1.key', 'scheduled')
        ->assertJsonPath('next_actions.1.count', 2)
        ->assertJsonPath('next_actions.2.key', 'none')
        ->assertJsonPath('next_actions.2.count', 1)
        ->assertJsonPath('wins.0.key', 'month_to_date')
        ->assertJsonPath('wins.0.count', 1)
        ->assertJsonPath('wins.0.amount_total', 3000)
        ->assertJsonPath('queues.0.key', 'overdue')
        ->assertJsonPath('queues.0.count', 1)
        ->assertJsonPath('queues.0.weighted_amount', 750)
        ->assertJsonPath('queues.1.key', 'no_next_action')
        ->assertJsonPath('queues.1.count', 1)
        ->assertJsonPath('queues.2.key', 'quoted')
        ->assertJsonPath('queues.2.count', 1)
        ->assertJsonPath('queues.3.key', 'needs_quote')
        ->assertJsonPath('queues.3.count', 1)
        ->assertJsonPath('queues.4.key', 'active')
        ->assertJsonPath('queues.4.count', 0)
        ->assertJsonPath('attention_items.0.queue', 'overdue')
        ->assertJsonPath('attention_items.1.queue', 'no_next_action')
        ->assertJsonPath('attention_items.2.queue', 'quoted')
        ->assertJsonPath('attention_items.3.queue', 'needs_quote');
});

test('manager dashboard route keeps filters and component contract for drill-downs', function () {
    $referenceTime = Carbon::parse('2026-04-25 09:00:00');
    $owner = managerDashboardPhaseSixOwner();

    $northwind = managerDashboardPhaseSixCustomer($owner, 'Northwind Group', 'northwind@example.test');
    $northwindLead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $northwind->id,
        'status' => LeadRequest::STATUS_QUOTE_SENT,
        'title' => 'Northwind rollout',
        'service_type' => 'Install',
        'created_at' => '2026-04-12 09:00:00',
    ]);
    Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $northwind->id,
        'request_id' => $northwindLead->id,
        'job_title' => 'Northwind rollout quote',
        'status' => 'sent',
        'subtotal' => 1200,
        'total' => 1200,
        'currency_code' => 'USD',
        'next_follow_up_at' => '2026-04-27 09:00:00',
        'created_at' => '2026-04-13 09:00:00',
    ]);

    $southwind = managerDashboardPhaseSixCustomer($owner, 'Southwind Group', 'southwind@example.test');
    $southwindLead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $southwind->id,
        'status' => LeadRequest::STATUS_WON,
        'title' => 'Southwind expansion',
        'service_type' => 'Audit',
        'created_at' => '2026-04-01 09:00:00',
    ]);
    Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $southwind->id,
        'request_id' => $southwindLead->id,
        'job_title' => 'Southwind won quote',
        'status' => 'accepted',
        'subtotal' => 900,
        'total' => 900,
        'currency_code' => 'USD',
        'accepted_at' => '2026-04-05 09:00:00',
        'created_at' => '2026-04-02 09:00:00',
    ]);

    $this->actingAs($owner)
        ->get(route('crm.manager-dashboard.index', [
            'reference_time' => $referenceTime->toIso8601String(),
            'search' => 'Northwind',
            'customer_id' => $northwind->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('CRM/ManagerDashboard')
            ->where('filters.search', 'Northwind')
            ->where('filters.customer_id', $northwind->id)
            ->where('summary.open_count', 1)
            ->where('summary.weighted_open_amount', 900)
            ->where('summary.quote_pull_through.total', 1)
            ->where('summary.quote_pull_through.won', 0)
            ->where('weighted_pipeline.1.key', 'best_case')
            ->where('weighted_pipeline.1.weighted_amount', 900)
            ->where('attention_items.0.customer.name', 'Northwind Group')
            ->where('attention_items.0.crm_links.subject.type', 'quote')
            ->where('attention_items.0.crm_links.primary.type', 'request')
            ->where('attention_items.0.crm_links.request.id', $northwindLead->id)
            ->where('options.customers.0.label', 'Northwind Group')
        );
});

test('manager dashboard route allows owners and sales managers, blocks regular members, and hides products workspaces', function () {
    $referenceTime = Carbon::parse('2026-04-25 09:00:00');
    $owner = managerDashboardPhaseSixOwner();
    $manager = managerDashboardTeamMember($owner, 'Sales manager', ['sales.manage', 'quotes.view']);
    $plainMember = managerDashboardTeamMember($owner, 'Quotes only', ['quotes.view'], 'member');
    $productsOwner = managerDashboardPhaseSixOwner([
        'company_type' => 'products',
        'email' => 'products-owner@example.test',
    ]);

    $customer = managerDashboardPhaseSixCustomer($owner, 'Access Test Co', 'access-test@example.test');
    LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Access test lead',
        'service_type' => 'Maintenance',
        'next_follow_up_at' => '2026-04-27 10:00:00',
    ]);

    $this->actingAs($owner)
        ->getJson(route('crm.manager-dashboard.index', [
            'reference_time' => $referenceTime->toIso8601String(),
        ]))
        ->assertOk();

    $this->actingAs($manager['user'])
        ->getJson(route('crm.manager-dashboard.index', [
            'reference_time' => $referenceTime->toIso8601String(),
        ]))
        ->assertOk();

    $this->actingAs($plainMember['user'])
        ->getJson(route('crm.manager-dashboard.index', [
            'reference_time' => $referenceTime->toIso8601String(),
        ]))
        ->assertForbidden();

    $this->actingAs($productsOwner)
        ->getJson(route('crm.manager-dashboard.index', [
            'reference_time' => $referenceTime->toIso8601String(),
        ]))
        ->assertNotFound();
});

function managerDashboardPhaseSixRoleId(string $name, string $description): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $description],
    )->id;
}

function managerDashboardPhaseSixOwner(array $overrides = []): User
{
    return User::factory()->create(array_replace_recursive([
        'role_id' => managerDashboardPhaseSixRoleId('owner', 'Phase six manager dashboard owner role'),
        'company_type' => 'services',
        'onboarding_completed_at' => now(),
        'two_factor_exempt' => false,
    ], $overrides));
}

/**
 * @return array{user: User, member: TeamMember}
 */
function managerDashboardTeamMember(User $owner, string $name, array $permissions, string $role = 'sales_manager'): array
{
    $roleName = $role === 'member' ? 'employee' : 'sales_manager';
    $user = User::factory()->create([
        'role_id' => managerDashboardPhaseSixRoleId($roleName, 'Phase six manager dashboard team role'),
        'name' => $name,
        'email' => strtolower(str_replace(' ', '.', $name)).'.manager-dashboard@example.test',
        'company_type' => 'services',
        'onboarding_completed_at' => now(),
        'two_factor_exempt' => false,
    ]);

    $member = TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $user->id,
        'role' => $role,
        'permissions' => $permissions,
        'is_active' => true,
    ]);

    return [
        'user' => $user,
        'member' => $member,
    ];
}

function managerDashboardPhaseSixCustomer(User $owner, string $companyName, string $email): Customer
{
    return Customer::factory()->create([
        'user_id' => $owner->id,
        'company_name' => $companyName,
        'email' => $email,
    ]);
}

function managerDashboardPhaseSixBackdate(Model $model, string $timestamp): void
{
    $attributes = [
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ];

    $model->newQuery()->whereKey($model->getKey())->update($attributes);
    $model->forceFill($attributes);
}
