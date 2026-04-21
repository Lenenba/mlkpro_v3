<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
});

test('sales inbox route returns prioritized commercial queues for open opportunities', function () {
    $referenceTime = Carbon::parse('2026-04-25 09:00:00');
    $owner = salesInboxPhaseSixOwner();
    $assignee = salesInboxTeamMember($owner, 'Pipeline owner', ['sales.manage', 'quotes.view'])['member'];

    $overdueCustomer = salesInboxCustomer($owner, 'Overdue Quoted Co', 'overdue-quoted@example.test');
    $overdueLead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $overdueCustomer->id,
        'assigned_team_member_id' => $assignee->id,
        'status' => LeadRequest::STATUS_QUOTE_SENT,
        'title' => 'Overdue quote opportunity',
        'service_type' => 'Maintenance',
        'created_at' => '2026-04-20 09:00:00',
    ]);
    Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $overdueCustomer->id,
        'request_id' => $overdueLead->id,
        'job_title' => 'Overdue maintenance quote',
        'status' => 'sent',
        'subtotal' => 1000,
        'total' => 1000,
        'currency_code' => 'USD',
        'next_follow_up_at' => '2026-04-24 09:00:00',
        'created_at' => '2026-04-21 08:00:00',
    ]);

    $noNextActionCustomer = salesInboxCustomer($owner, 'No Action Co', 'no-action@example.test');
    LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $noNextActionCustomer->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Contacted opportunity without next action',
        'service_type' => 'Inspection',
        'created_at' => '2026-04-22 09:00:00',
    ]);

    $quotedCustomer = salesInboxCustomer($owner, 'Quoted Queue Co', 'quoted-queue@example.test');
    $quotedLead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $quotedCustomer->id,
        'status' => LeadRequest::STATUS_QUOTE_SENT,
        'title' => 'Quoted opportunity with next action',
        'service_type' => 'Installation',
        'created_at' => '2026-04-19 09:00:00',
    ]);
    Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $quotedCustomer->id,
        'request_id' => $quotedLead->id,
        'job_title' => 'Quoted queue proposal',
        'status' => 'sent',
        'subtotal' => 2000,
        'total' => 2000,
        'currency_code' => 'USD',
        'next_follow_up_at' => '2026-04-26 09:00:00',
        'created_at' => '2026-04-20 10:00:00',
    ]);

    $needsQuoteCustomer = salesInboxCustomer($owner, 'Needs Quote Co', 'needs-quote@example.test');
    LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $needsQuoteCustomer->id,
        'status' => LeadRequest::STATUS_QUALIFIED,
        'title' => 'Qualified opportunity awaiting quote',
        'service_type' => 'Consulting',
        'next_follow_up_at' => '2026-04-27 09:00:00',
        'created_at' => '2026-04-18 09:00:00',
    ]);

    $activeCustomer = salesInboxCustomer($owner, 'Active Queue Co', 'active-queue@example.test');
    LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $activeCustomer->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Active contacted opportunity',
        'service_type' => 'Repairs',
        'next_follow_up_at' => '2026-04-28 11:00:00',
        'created_at' => '2026-04-23 09:00:00',
    ]);

    $wonCustomer = salesInboxCustomer($owner, 'Won Closed Co', 'won-closed@example.test');
    $wonLead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $wonCustomer->id,
        'status' => LeadRequest::STATUS_WON,
        'title' => 'Closed won opportunity',
        'service_type' => 'Upgrade',
        'created_at' => '2026-04-18 08:00:00',
    ]);
    Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $wonCustomer->id,
        'request_id' => $wonLead->id,
        'job_title' => 'Closed won quote',
        'status' => 'accepted',
        'subtotal' => 1500,
        'total' => 1500,
        'currency_code' => 'USD',
        'accepted_at' => '2026-04-22 12:00:00',
        'created_at' => '2026-04-19 08:30:00',
    ]);

    $response = $this->actingAs($owner)
        ->getJson(route('crm.sales-inbox.index', [
            'reference_time' => $referenceTime->toIso8601String(),
            'per_page' => 5,
        ]))
        ->assertOk()
        ->assertJsonPath('count', 5)
        ->assertJsonPath('stats.total', 5)
        ->assertJsonPath('stats.overdue', 1)
        ->assertJsonPath('stats.no_next_action', 1)
        ->assertJsonPath('stats.quoted', 1)
        ->assertJsonPath('stats.needs_quote', 1)
        ->assertJsonPath('stats.active', 1)
        ->assertJsonPath('stats.open_amount', 3000)
        ->assertJsonPath('stats.weighted_open_amount', 2250)
        ->assertJsonPath('queues.0.key', 'overdue')
        ->assertJsonPath('queues.0.count', 1)
        ->assertJsonPath('queues.0.amount_total', 1000)
        ->assertJsonPath('queues.2.key', 'quoted')
        ->assertJsonPath('queues.2.count', 1)
        ->assertJsonPath('queues.2.amount_total', 2000);

    expect(collect($response->json('items'))->pluck('queue')->all())
        ->toBe(['overdue', 'no_next_action', 'quoted', 'needs_quote', 'active']);
});

test('sales inbox route filters by queue and search while keeping the sales inbox component contract', function () {
    $referenceTime = Carbon::parse('2026-04-25 09:00:00');
    $owner = salesInboxPhaseSixOwner();

    $northwind = salesInboxCustomer($owner, 'Northwind Group', 'northwind@example.test');
    $northwindLead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $northwind->id,
        'status' => LeadRequest::STATUS_QUOTE_SENT,
        'title' => 'Northwind quoting',
        'service_type' => 'Install',
        'created_at' => '2026-04-18 09:00:00',
    ]);
    $northwindQuote = Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $northwind->id,
        'request_id' => $northwindLead->id,
        'job_title' => 'Northwind expansion quote',
        'status' => 'sent',
        'subtotal' => 1200,
        'total' => 1200,
        'currency_code' => 'USD',
        'next_follow_up_at' => '2026-04-26 09:00:00',
        'created_at' => '2026-04-19 10:00:00',
    ]);

    $otherCustomer = salesInboxCustomer($owner, 'Blue Valley', 'blue-valley@example.test');
    LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $otherCustomer->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Blue Valley active follow-up',
        'service_type' => 'Audit',
        'next_follow_up_at' => '2026-04-27 12:00:00',
        'created_at' => '2026-04-23 09:00:00',
    ]);

    $this->actingAs($owner)
        ->get(route('crm.sales-inbox.index', [
            'reference_time' => $referenceTime->toIso8601String(),
            'queue' => 'quoted',
            'search' => 'Northwind',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('CRM/SalesInbox')
            ->where('count', 1)
            ->where('filters.queue', 'quoted')
            ->where('filters.search', 'Northwind')
            ->where('items.0.queue', 'quoted')
            ->where('items.0.customer.name', 'Northwind Group')
            ->where('items.0.crm_links.subject.type', 'quote')
            ->where('items.0.crm_links.primary.type', 'request')
            ->where('items.0.crm_links.request.id', $northwindLead->id)
            ->where('items.0.crm_links.quote.id', $northwindQuote->id)
            ->where('items.0.primary_subject_type', 'quote')
            ->where('items.0.primary_subject_id', $northwindQuote->id)
        );
});

test('sales inbox route allows sales managers and blocks regular members', function () {
    $referenceTime = Carbon::parse('2026-04-25 09:00:00');
    $owner = salesInboxPhaseSixOwner();
    $manager = salesInboxTeamMember($owner, 'Sales manager', ['sales.manage', 'quotes.view']);
    $plainMember = salesInboxTeamMember($owner, 'Quotes only', ['quotes.view'], 'member');

    $customer = salesInboxCustomer($owner, 'Access Test Co', 'access-test@example.test');
    LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Access test opportunity',
        'service_type' => 'Maintenance',
        'next_follow_up_at' => '2026-04-27 10:00:00',
    ]);

    $this->actingAs($manager['user'])
        ->getJson(route('crm.sales-inbox.index', [
            'reference_time' => $referenceTime->toIso8601String(),
        ]))
        ->assertOk();

    $this->actingAs($plainMember['user'])
        ->getJson(route('crm.sales-inbox.index', [
            'reference_time' => $referenceTime->toIso8601String(),
        ]))
        ->assertForbidden();
});

function salesInboxPhaseSixRoleId(string $name, string $description): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $description],
    )->id;
}

function salesInboxPhaseSixOwner(array $overrides = []): User
{
    return User::factory()->create(array_replace_recursive([
        'role_id' => salesInboxPhaseSixRoleId('owner', 'Phase six owner role'),
        'company_type' => 'services',
        'onboarding_completed_at' => now(),
        'two_factor_exempt' => false,
    ], $overrides));
}

/**
 * @return array{user: User, member: TeamMember}
 */
function salesInboxTeamMember(User $owner, string $name, array $permissions, string $role = 'sales_manager'): array
{
    $roleName = $role === 'member' ? 'employee' : 'sales_manager';
    $user = User::factory()->create([
        'role_id' => salesInboxPhaseSixRoleId($roleName, 'Phase six team member role'),
        'name' => $name,
        'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
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

function salesInboxCustomer(User $owner, string $companyName, string $email): Customer
{
    return Customer::factory()->create([
        'user_id' => $owner->id,
        'company_name' => $companyName,
        'email' => $email,
    ]);
}
