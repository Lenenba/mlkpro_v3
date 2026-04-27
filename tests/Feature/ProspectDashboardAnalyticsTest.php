<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\Customer;
use App\Models\Request as LeadRequest;
use App\Models\SavedSegment;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function prospectDashboardOwner(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'company_type' => 'services',
        'company_features' => [
            'requests' => true,
            'team_members' => true,
        ],
        'onboarding_completed_at' => now(),
    ], $attributes));
}

beforeEach(function () {
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
});

it('returns the prospect dashboard analytics contract on the prospects workspace json response', function () {
    $owner = prospectDashboardOwner();

    $assigneeAUser = User::factory()->create(['name' => 'Taylor Owner']);
    $assigneeA = TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $assigneeAUser->id,
        'is_active' => true,
    ]);

    $assigneeBUser = User::factory()->create(['name' => 'Jordan Manager']);
    $assigneeB = TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $assigneeBUser->id,
        'is_active' => true,
    ]);

    $customer = Customer::query()->create([
        'user_id' => $owner->id,
        'company_name' => 'Converted Co',
        'first_name' => 'Converted',
        'last_name' => 'Contact',
        'email' => 'converted@example.test',
        'phone' => '+1 555 0300',
    ]);

    $referenceTime = Carbon::parse('2026-04-25 10:00:00');

    try {
        Carbon::setTestNow($referenceTime);

        $createProspect = function (string $createdAt, array $attributes): LeadRequest {
            Carbon::setTestNow(Carbon::parse($createdAt));

            return LeadRequest::query()->create($attributes);
        };

        $createProspect('2026-04-24 09:00:00', [
            'user_id' => $owner->id,
            'assigned_team_member_id' => $assigneeA->id,
            'status' => LeadRequest::STATUS_CONTACTED,
            'channel' => 'phone',
            'title' => 'Follow-up today prospect',
            'next_follow_up_at' => Carbon::parse('2026-04-25 15:00:00'),
        ]);

        $createProspect('2026-04-10 11:00:00', [
            'user_id' => $owner->id,
            'assigned_team_member_id' => $assigneeA->id,
            'status' => LeadRequest::STATUS_LOST,
            'channel' => 'email',
            'title' => 'Lost prospect',
            'lost_reason' => 'budget',
        ]);

        $createProspect('2026-03-30 08:00:00', [
            'user_id' => $owner->id,
            'status' => LeadRequest::STATUS_QUALIFIED,
            'channel' => 'phone',
            'title' => 'Overdue prospect',
            'next_follow_up_at' => Carbon::parse('2026-04-24 17:00:00'),
        ]);

        $createProspect('2026-04-21 12:00:00', [
            'user_id' => $owner->id,
            'assigned_team_member_id' => $assigneeB->id,
            'status' => LeadRequest::STATUS_WON,
            'channel' => 'referral',
            'title' => 'Won prospect',
        ]);

        $createProspect('2026-04-05 09:00:00', [
            'user_id' => $owner->id,
            'customer_id' => $customer->id,
            'assigned_team_member_id' => $assigneeB->id,
            'status' => LeadRequest::STATUS_CONVERTED,
            'channel' => 'web',
            'title' => 'Converted prospect',
            'converted_at' => Carbon::parse('2026-04-15 09:00:00'),
        ]);

        $createProspect('2026-04-22 09:00:00', [
            'user_id' => $owner->id,
            'assigned_team_member_id' => $assigneeA->id,
            'status' => LeadRequest::STATUS_CONTACTED,
            'channel' => 'email',
            'title' => 'Archived prospect',
            'archived_at' => Carbon::parse('2026-04-23 09:00:00'),
        ]);

        Carbon::setTestNow($referenceTime);

        $response = $this->actingAs($owner)->getJson(route('prospects.index'));

        $response->assertOk()
            ->assertJsonPath('analytics.kind', 'prospect_dashboard_v1')
            ->assertJsonPath('analytics.summary.total', 5)
            ->assertJsonPath('analytics.summary.new_this_week', 2)
            ->assertJsonPath('analytics.summary.new_this_month', 4)
            ->assertJsonPath('analytics.summary.due_today', 1)
            ->assertJsonPath('analytics.summary.overdue', 1)
            ->assertJsonPath('analytics.summary.won', 1)
            ->assertJsonPath('analytics.summary.lost', 1)
            ->assertJsonPath('analytics.summary.converted', 1)
            ->assertJsonPath('analytics.summary.conversion_created_count', 5)
            ->assertJsonPath('analytics.summary.conversion_converted_count', 1)
            ->assertJsonPath('analytics.summary.conversion_rate', 20)
            ->assertJsonPath('analytics.summary.avg_conversion_days', 10);

        $payload = $response->json();
        $byStatus = collect(data_get($payload, 'analytics.by_status', []))->keyBy('status');
        $bySource = collect(data_get($payload, 'analytics.by_source', []))->keyBy('source');
        $byAssignee = collect(data_get($payload, 'analytics.by_assignee', []));

        expect((int) data_get($byStatus->get(LeadRequest::STATUS_CONTACTED), 'total'))->toBe(1)
            ->and((int) data_get($byStatus->get(LeadRequest::STATUS_QUALIFIED), 'total'))->toBe(1)
            ->and((int) data_get($byStatus->get(LeadRequest::STATUS_WON), 'total'))->toBe(1)
            ->and((int) data_get($byStatus->get(LeadRequest::STATUS_LOST), 'total'))->toBe(1)
            ->and((int) data_get($byStatus->get(LeadRequest::STATUS_CONVERTED), 'total'))->toBe(1);

        expect((int) data_get($bySource->get('phone'), 'total'))->toBe(2)
            ->and((int) data_get($bySource->get('email'), 'lost'))->toBe(1)
            ->and((int) data_get($bySource->get('referral'), 'won'))->toBe(1)
            ->and((int) data_get($bySource->get('web_form'), 'converted'))->toBe(1)
            ->and((float) data_get($bySource->get('web_form'), 'rate'))->toBe(100.0);

        $assigneeAStats = $byAssignee->firstWhere('assignee_id', $assigneeA->id);
        $assigneeBStats = $byAssignee->firstWhere('assignee_id', $assigneeB->id);
        $unassignedStats = $byAssignee->firstWhere('assignee_id', null);

        expect((int) data_get($assigneeAStats, 'total'))->toBe(2)
            ->and((int) data_get($assigneeAStats, 'due_today'))->toBe(1)
            ->and((int) data_get($assigneeAStats, 'lost'))->toBe(1)
            ->and((string) data_get($assigneeAStats, 'name'))->toBe('Taylor Owner')
            ->and((int) data_get($assigneeBStats, 'total'))->toBe(2)
            ->and((int) data_get($assigneeBStats, 'won'))->toBe(1)
            ->and((int) data_get($assigneeBStats, 'converted'))->toBe(1)
            ->and((string) data_get($assigneeBStats, 'name'))->toBe('Jordan Manager')
            ->and((int) data_get($unassignedStats, 'total'))->toBe(1)
            ->and((int) data_get($unassignedStats, 'overdue'))->toBe(1)
            ->and(data_get($unassignedStats, 'name'))->toBeNull();
    } finally {
        Carbon::setTestNow();
    }
});

it('hydrates the prospect dashboard analytics contract in inertia responses', function () {
    $owner = prospectDashboardOwner();

    $referenceTime = Carbon::parse('2026-04-25 10:00:00');

    try {
        Carbon::setTestNow($referenceTime);

        LeadRequest::query()->create([
            'user_id' => $owner->id,
            'status' => LeadRequest::STATUS_CONTACTED,
            'channel' => 'phone',
            'title' => 'Prospect dashboard inertia',
            'created_at' => Carbon::parse('2026-04-24 09:00:00'),
            'next_follow_up_at' => Carbon::parse('2026-04-25 15:00:00'),
        ]);

        $this->actingAs($owner)
            ->get(route('prospects.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Request/Index')
                ->where('analytics.kind', 'prospect_dashboard_v1')
                ->where('analytics.summary.total', 1)
                ->where('analytics.summary.new_this_week', 1)
                ->where('analytics.summary.due_today', 1)
                ->has('analytics.by_status', 8)
                ->has('analytics.by_source', 1)
                ->has('analytics.by_assignee', 1)
            );
    } finally {
        Carbon::setTestNow();
    }
});

it('allows sales managers into the prospects workspace while keeping legacy requests owner only', function () {
    $owner = prospectDashboardOwner();
    $lead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_NEW,
        'title' => 'Manager visible prospect',
        'contact_name' => 'Manager Prospect',
        'contact_email' => 'manager.prospect@example.test',
    ]);

    SavedSegment::query()->create([
        'user_id' => $owner->id,
        'module' => SavedSegment::MODULE_REQUEST,
        'name' => 'Owner only request segment',
    ]);

    $managerUser = User::factory()->create([
        'company_type' => 'services',
        'onboarding_completed_at' => now(),
    ]);

    TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $managerUser->id,
        'role' => 'sales_manager',
        'permissions' => ['sales.manage', 'quotes.view'],
        'is_active' => true,
    ]);

    $plainUser = User::factory()->create([
        'company_type' => 'services',
        'onboarding_completed_at' => now(),
    ]);

    TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $plainUser->id,
        'role' => 'member',
        'permissions' => ['quotes.view'],
        'is_active' => true,
    ]);

    $this->actingAs($managerUser)
        ->getJson(route('prospects.index'))
        ->assertOk()
        ->assertJsonPath('canManageSavedSegments', false)
        ->assertJsonCount(0, 'savedSegments')
        ->assertJsonPath('analytics.kind', 'prospect_dashboard_v1');

    $this->actingAs($managerUser)
        ->getJson(route('prospects.show', $lead))
        ->assertOk()
        ->assertJsonPath('lead.id', $lead->id);

    $this->actingAs($managerUser)
        ->putJson(route('prospects.update', $lead), [
            'status' => LeadRequest::STATUS_CONTACTED,
        ])
        ->assertOk()
        ->assertJsonPath('request.status', LeadRequest::STATUS_CONTACTED);

    $this->actingAs($managerUser)
        ->getJson(route('request.index'))
        ->assertForbidden();

    $this->actingAs($plainUser)
        ->getJson(route('prospects.index'))
        ->assertForbidden();

    $this->actingAs($plainUser)
        ->getJson(route('prospects.show', $lead))
        ->assertForbidden();
});
