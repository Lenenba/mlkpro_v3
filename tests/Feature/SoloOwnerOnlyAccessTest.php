<?php

use App\Enums\BillingPeriod;
use App\Models\Billing\StripeSubscription;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\PlatformSetting;
use App\Models\ReservationResource;
use App\Models\ReservationSetting;
use App\Models\Role;
use App\Models\Task;
use App\Models\TeamMember;
use App\Models\TeamMemberShift;
use App\Models\User;
use App\Models\WeeklyAvailability;
use App\Models\Work;
use App\Services\CompanyFeatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function soloOwnerOnlyRoleId(string $name): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $name.' role']
    )->id;
}

function createOwnerOnlySoloTenant(array $overrides = []): User
{
    return User::query()->create(array_merge([
        'name' => 'Solo Owner',
        'email' => 'solo-owner@example.test',
        'password' => 'password',
        'role_id' => soloOwnerOnlyRoleId('owner'),
        'company_type' => 'services',
        'company_sector' => 'service_general',
        'currency_code' => 'CAD',
        'onboarding_completed_at' => now(),
        'company_features' => [
            'team_members' => true,
            'presence' => true,
        ],
    ], $overrides));
}

function assignSoloSubscription(User $owner, string $planCode = 'solo_pro'): void
{
    $planId = Plan::query()->where('code', $planCode)->value('id');
    expect($planId)->not->toBeNull();

    $priceId = 'price_test_'.$planCode.'_cad';

    PlanPrice::query()
        ->where('plan_id', $planId)
        ->where('currency_code', 'CAD')
        ->where('billing_period', BillingPeriod::MONTHLY->value)
        ->update([
            'stripe_price_id' => $priceId,
            'is_active' => true,
        ]);

    $planPriceId = PlanPrice::query()
        ->where('plan_id', $planId)
        ->where('currency_code', 'CAD')
        ->where('billing_period', BillingPeriod::MONTHLY->value)
        ->value('id');

    StripeSubscription::query()->create([
        'user_id' => $owner->id,
        'stripe_id' => 'sub_test_'.$planCode,
        'stripe_customer_id' => 'cus_test_'.$planCode,
        'price_id' => $priceId,
        'currency_code' => 'CAD',
        'plan_code' => $planCode,
        'plan_price_id' => $planPriceId,
        'billing_period' => BillingPeriod::MONTHLY->value,
        'status' => 'active',
    ]);
}

function setStaleSoloPlanModules(string $planCode = 'solo_pro'): void
{
    $planModules = CompanyFeatureService::defaultPlanModules();
    $planModules[$planCode] = array_fill_keys(array_keys($planModules[$planCode] ?? []), true);

    PlatformSetting::setValue('plan_modules', $planModules);
}

beforeEach(function () {
    config()->set('billing.provider_effective', 'stripe');
    config()->set('billing.provider', 'stripe');
});

test('owner-only solo plans keep team and presence modules unavailable even with manual feature overrides', function () {
    $owner = createOwnerOnlySoloTenant();
    assignSoloSubscription($owner);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.account.features', fn ($features) => ! collect($features)->has('team_members')
                && ! collect($features)->has('presence'))
        );

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('team.index'))
        ->assertForbidden()
        ->assertJsonPath('message', 'Module unavailable for your plan.');

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('presence.index'))
        ->assertForbidden()
        ->assertJsonPath('message', 'Module unavailable for your plan.');
});

test('solo pro ignores stale platform plan module flags and hides unavailable dashboard modules', function () {
    setStaleSoloPlanModules('solo_pro');

    $owner = createOwnerOnlySoloTenant([
        'company_type' => 'products',
        'company_features' => [
            'sales' => true,
            'campaigns' => true,
            'plan_scans' => true,
        ],
    ]);
    assignSoloSubscription($owner, 'solo_pro');

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('DashboardProductsOwner')
            ->where('auth.account.features', fn ($features) => collect($features)->has('sales')
                && ! collect($features)->has('campaigns')
                && ! collect($features)->has('assistant')
                && ! collect($features)->has('plan_scans')
                && ! collect($features)->has('loyalty')
                && ! collect($features)->has('planning')
                && ! collect($features)->has('reservations')
                && ! collect($features)->has('team_members')
                && ! collect($features)->has('presence'))
            ->where('marketingKpis', null)
        );
});

test('solo pro billing settings do not mark assistant or loyalty as included when stored plan modules are stale', function () {
    setStaleSoloPlanModules('solo_pro');

    $owner = createOwnerOnlySoloTenant([
        'company_type' => 'products',
        'company_features' => [
            'sales' => true,
            'campaigns' => true,
        ],
    ]);
    assignSoloSubscription($owner, 'solo_pro');

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->get(route('settings.billing.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('assistantAddon.included', false)
            ->where('assistantAddon.enabled', false)
            ->where('loyaltyProgram.feature_enabled', false)
        );
});

test('solo pro usage alerts ignore unavailable modules when stale plan settings still enable them', function () {
    setStaleSoloPlanModules('solo_pro');

    $owner = createOwnerOnlySoloTenant([
        'company_type' => 'services',
        'company_features' => [
            'campaigns' => true,
            'plan_scans' => true,
        ],
    ]);
    assignSoloSubscription($owner, 'solo_pro');

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('auth.account.features', fn ($features) => ! collect($features)->has('campaigns')
                && ! collect($features)->has('assistant')
                && ! collect($features)->has('plan_scans')
                && ! collect($features)->has('loyalty')
                && ! collect($features)->has('planning')
                && ! collect($features)->has('reservations')
                && ! collect($features)->has('team_members')
                && ! collect($features)->has('presence'))
            ->where('marketingKpis', null)
            ->where('usage_limits.items', fn ($items) => ! collect($items)->pluck('key')->contains('assistant_requests')
                && ! collect($items)->pluck('key')->contains('plan_scan_quotes')
                && ! collect($items)->pluck('key')->contains('team_members'))
        );
});

test('owner-only solo plans hide collaborative settings and usage limits', function () {
    $owner = createOwnerOnlySoloTenant([
        'company_type' => 'products',
        'company_time_settings' => [
            'auto_clock_in' => true,
            'auto_clock_out' => true,
            'manual_clock' => true,
        ],
    ]);
    assignSoloSubscription($owner);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->get(route('settings.company.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('company.time_settings', null)
            ->where('auth.account.features', fn ($features) => ! collect($features)->has('team_members')
                && ! collect($features)->has('presence'))
            ->where('usage_limits.items', fn ($items) => collect($items)->pluck('key')->contains('team_members') === false)
        );
});

test('owner-only solo plans ignore submitted presence setting updates', function () {
    $owner = createOwnerOnlySoloTenant([
        'company_type' => 'products',
        'company_time_settings' => [
            'auto_clock_in' => true,
            'auto_clock_out' => true,
            'manual_clock' => true,
        ],
    ]);
    assignSoloSubscription($owner);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->from(route('settings.company.edit'))
        ->put(route('settings.company.update'), [
            'company_name' => 'Solo Owner',
            'company_type' => 'products',
            'company_time_settings' => [
                'auto_clock_in' => false,
                'auto_clock_out' => false,
                'manual_clock' => false,
            ],
        ])
        ->assertRedirect(route('settings.company.edit'))
        ->assertSessionHas('success', 'Company settings updated.');

    expect($owner->fresh()->company_time_settings)->toBe([
        'auto_clock_in' => true,
        'auto_clock_out' => true,
        'manual_clock' => true,
    ]);
});

test('owner-only solo plans do not expose team members in global search even if stale rows exist', function () {
    $owner = createOwnerOnlySoloTenant([
        'company_type' => 'services',
        'company_features' => [
            'team_members' => true,
            'presence' => true,
            'performance' => true,
        ],
    ]);
    assignSoloSubscription($owner, 'solo_growth');

    $memberUser = User::factory()->create([
        'name' => 'Hidden Employee',
        'email' => 'hidden-employee@example.test',
    ]);

    TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $memberUser->id,
        'is_active' => true,
        'title' => 'Estimator',
    ]);

    $response = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('global.search', ['q' => 'Hidden']));

    $response->assertOk();
    expect($response->json('groups'))->toBeArray()
        ->and(collect($response->json('groups'))->firstWhere('type', 'employees'))
        ->toBeNull();
});

test('owner-only solo plans hide stale team assignments in job create and edit screens', function () {
    $owner = createOwnerOnlySoloTenant([
        'company_type' => 'services',
    ]);
    assignSoloSubscription($owner, 'solo_pro');

    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
        'salutation' => 'Mr',
    ]);

    $memberUser = User::factory()->create([
        'name' => 'Hidden Dispatcher',
        'email' => 'hidden-dispatcher@example.test',
    ]);

    $member = TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $memberUser->id,
        'is_active' => true,
        'title' => 'Dispatcher',
    ]);

    $work = Work::factory()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'job_title' => 'Existing solo job',
        'start_date' => now()->toDateString(),
        'status' => Work::STATUS_SCHEDULED,
    ]);
    $work->teamMembers()->sync([$member->id]);

    Task::query()->create([
        'account_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'assigned_team_member_id' => $member->id,
        'customer_id' => $customer->id,
        'product_id' => null,
        'work_id' => $work->id,
        'title' => 'Assigned visit',
        'description' => null,
        'status' => 'todo',
        'due_date' => now()->toDateString(),
        'start_time' => '09:00:00',
        'end_time' => '10:00:00',
        'completed_at' => null,
    ]);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->get(route('work.create', $customer))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('teamMembers', [])
            ->where('tasks.0.assigned_team_member_id', null)
            ->where('tasks.0.assignee', null)
        );

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->get(route('work.edit', $work))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('teamMembers', [])
            ->where('work.team_members', [])
        );
});

test('owner-only solo plans ignore submitted team members when creating or updating jobs', function () {
    $owner = createOwnerOnlySoloTenant([
        'company_type' => 'services',
    ]);
    assignSoloSubscription($owner, 'solo_pro');

    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
        'salutation' => 'Mr',
    ]);

    $memberUser = User::factory()->create([
        'name' => 'Hidden Tech',
        'email' => 'hidden-tech@example.test',
    ]);

    $member = TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $memberUser->id,
        'is_active' => true,
        'title' => 'Technician',
    ]);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->post(route('work.store'), [
            'customer_id' => $customer->id,
            'job_title' => 'Solo scheduled job',
            'start_date' => now()->toDateString(),
            'status' => Work::STATUS_SCHEDULED,
            'team_member_ids' => [$member->id],
        ])
        ->assertRedirect();

    $createdWork = Work::query()
        ->where('user_id', $owner->id)
        ->where('job_title', 'Solo scheduled job')
        ->latest('id')
        ->firstOrFail();

    expect($createdWork->teamMembers()->count())->toBe(0)
        ->and(
            Task::query()
                ->where('work_id', $createdWork->id)
                ->pluck('assigned_team_member_id')
                ->filter()
                ->isEmpty()
        )->toBeTrue();

    $createdWork->teamMembers()->sync([$member->id]);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->put(route('work.update', $createdWork), [
            'customer_id' => $customer->id,
            'job_title' => 'Solo scheduled job updated',
            'start_date' => now()->toDateString(),
            'status' => Work::STATUS_SCHEDULED,
            'team_member_ids' => [$member->id],
        ])
        ->assertRedirect();

    expect($createdWork->fresh()->teamMembers()->count())->toBe(0);
});

test('owner-only solo plans hide stale assignee data in task index and show screens', function () {
    $owner = createOwnerOnlySoloTenant([
        'company_type' => 'services',
    ]);
    assignSoloSubscription($owner, 'solo_pro');

    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
        'salutation' => 'Mr',
    ]);

    $memberUser = User::factory()->create([
        'name' => 'Hidden Scheduler',
        'email' => 'hidden-scheduler@example.test',
    ]);

    $member = TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $memberUser->id,
        'is_active' => true,
        'title' => 'Scheduler',
    ]);

    $task = Task::query()->create([
        'account_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'assigned_team_member_id' => $member->id,
        'customer_id' => $customer->id,
        'product_id' => null,
        'work_id' => null,
        'title' => 'Solo task',
        'description' => 'Task with stale assignee',
        'status' => 'todo',
        'due_date' => now()->toDateString(),
        'start_time' => null,
        'end_time' => null,
        'completed_at' => null,
    ]);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->get(route('task.index', ['view' => 'team']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('teamMembers', [])
            ->where('canViewTeam', false)
            ->where('filters.view', 'board')
            ->where('tasks.data.0.assigned_team_member_id', null)
            ->where('tasks.data.0.assignee', null)
        );

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->get(route('task.show', $task))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('teamMembers', [])
            ->where('task.assigned_team_member_id', null)
            ->where('task.assignee', null)
        );
});

test('owner-only solo plans ignore submitted assignees when creating updating or assigning tasks', function () {
    $owner = createOwnerOnlySoloTenant([
        'company_type' => 'services',
    ]);
    assignSoloSubscription($owner, 'solo_pro');

    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
        'salutation' => 'Mr',
    ]);

    $memberUser = User::factory()->create([
        'name' => 'Hidden Assignee',
        'email' => 'hidden-assignee@example.test',
    ]);

    $member = TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $memberUser->id,
        'is_active' => true,
        'title' => 'Assignee',
    ]);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->post(route('task.store'), [
            'standalone' => true,
            'title' => 'Owner only task',
            'status' => 'todo',
            'due_date' => now()->toDateString(),
            'assigned_team_member_id' => $member->id,
        ])
        ->assertRedirect();

    $task = Task::query()
        ->where('account_id', $owner->id)
        ->where('title', 'Owner only task')
        ->latest('id')
        ->firstOrFail();

    expect($task->assigned_team_member_id)->toBeNull();

    $task->update(['assigned_team_member_id' => $member->id]);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->put(route('task.update', $task), [
            'standalone' => true,
            'title' => 'Owner only task updated',
            'status' => 'todo',
            'due_date' => now()->toDateString(),
            'assigned_team_member_id' => $member->id,
        ])
        ->assertRedirect();

    expect($task->fresh()->assigned_team_member_id)->toBeNull();

    $task->update(['assigned_team_member_id' => $member->id]);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->patch(route('task.assign', $task), [
            'assigned_team_member_id' => $member->id,
        ])
        ->assertRedirect();

    expect($task->fresh()->assigned_team_member_id)->toBeNull();
});

test('owner-only solo growth plans expose planning as a read-only owner view', function () {
    $owner = createOwnerOnlySoloTenant([
        'company_type' => 'services',
    ]);
    assignSoloSubscription($owner, 'solo_growth');

    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
        'salutation' => 'Mr',
    ]);

    $memberUser = User::factory()->create([
        'name' => 'Hidden Planner',
        'email' => 'hidden-planner@example.test',
    ]);

    $member = TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $memberUser->id,
        'is_active' => true,
        'title' => 'Planner',
    ]);

    $work = Work::factory()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'job_title' => 'Solo planning job',
        'start_date' => now()->toDateString(),
        'start_time' => '09:00:00',
        'end_time' => '11:00:00',
        'status' => Work::STATUS_SCHEDULED,
    ]);
    $work->teamMembers()->sync([$member->id]);

    Task::query()->create([
        'account_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'assigned_team_member_id' => $member->id,
        'customer_id' => $customer->id,
        'product_id' => null,
        'work_id' => null,
        'title' => 'Solo planning task',
        'description' => null,
        'status' => 'todo',
        'due_date' => now()->toDateString(),
        'start_time' => '13:00:00',
        'end_time' => '14:00:00',
        'completed_at' => null,
    ]);

    TeamMemberShift::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $member->id,
        'created_by_user_id' => $owner->id,
        'kind' => 'leave',
        'status' => 'pending',
        'title' => 'Hidden leave',
        'shift_date' => now()->toDateString(),
        'start_time' => '00:00:00',
        'end_time' => '23:59:00',
    ]);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->get(route('planning.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('planning.pending_count', 0)
            ->where('teamMembers', [])
            ->where('canManage', false)
            ->where('canApproveTimeOff', false)
            ->where('selfTeamMemberId', null)
            ->where('pendingRequests', [])
            ->where('timeOffSummary.today', [])
            ->where('timeOffSummary.week', [])
            ->where('events', fn ($events) => count($events) === 2
                && collect($events)->pluck('extendedProps.kind')->sort()->values()->all() === ['task', 'work']
                && collect($events)->pluck('extendedProps.team_member_id')->filter()->isEmpty()
                && collect($events)->pluck('title')->contains('Solo planning job')
                && collect($events)->pluck('title')->contains('Solo planning task')
                && collect($events)->pluck('title')->filter(fn ($title) => str_contains((string) $title, 'Team ·'))->isEmpty())
        );
});

test('owner-only solo growth plans block planning shift mutations', function () {
    $owner = createOwnerOnlySoloTenant([
        'company_type' => 'services',
    ]);
    assignSoloSubscription($owner, 'solo_growth');

    $memberUser = User::factory()->create([
        'name' => 'Hidden Shift Member',
        'email' => 'hidden-shift-member@example.test',
    ]);

    $member = TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $memberUser->id,
        'is_active' => true,
        'title' => 'Shift Member',
    ]);

    $shift = TeamMemberShift::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $member->id,
        'created_by_user_id' => $owner->id,
        'kind' => 'leave',
        'status' => 'pending',
        'title' => 'Existing leave',
        'shift_date' => now()->toDateString(),
        'start_time' => '00:00:00',
        'end_time' => '23:59:00',
    ]);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->postJson(route('planning.shifts.store'), [
            'kind' => 'leave',
            'team_member_id' => $member->id,
            'shift_date' => now()->toDateString(),
        ])
        ->assertForbidden();

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->patchJson(route('planning.shifts.update', $shift), [
            'shift_date' => now()->toDateString(),
            'start_time' => '09:00',
            'end_time' => '10:00',
        ])
        ->assertForbidden();

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->patchJson(route('planning.shifts.status', $shift), [
            'status' => 'approved',
        ])
        ->assertForbidden();

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->deleteJson(route('planning.shifts.destroy', $shift))
        ->assertForbidden();
});

test('owner-only solo growth plans expose reservations in a limited mode and hide stale team scheduling data', function () {
    $owner = createOwnerOnlySoloTenant([
        'company_type' => 'services',
        'company_sector' => 'salon',
    ]);
    assignSoloSubscription($owner, 'solo_growth');

    $memberUser = User::factory()->create([
        'name' => 'Hidden Stylist',
        'email' => 'hidden-stylist@example.test',
    ]);

    $member = TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $memberUser->id,
        'is_active' => true,
        'title' => 'Stylist',
    ]);

    WeeklyAvailability::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $member->id,
        'day_of_week' => 1,
        'start_time' => '09:00:00',
        'end_time' => '17:00:00',
        'is_active' => true,
    ]);

    ReservationSetting::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $member->id,
        'buffer_minutes' => 10,
        'slot_interval_minutes' => 30,
        'min_notice_minutes' => 0,
        'max_advance_days' => 30,
        'cancellation_cutoff_hours' => 12,
        'allow_client_cancel' => true,
        'allow_client_reschedule' => true,
    ]);

    ReservationResource::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $member->id,
        'name' => 'Chair 1',
        'type' => 'chair',
        'capacity' => 1,
        'is_active' => true,
    ]);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->get(route('reservation.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('teamMembers', [])
            ->where('settings.owner_only_mode', true)
            ->where('settings.slot_booking_enabled', false)
            ->where('settings.queue_mode_enabled', false)
        );

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->get(route('settings.reservations.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('teamMembers', [])
            ->where('weeklyAvailabilities', [])
            ->where('teamSettings', [])
            ->where('accountSettings.owner_only_mode', true)
            ->where('accountSettings.slot_booking_enabled', false)
            ->where('accountSettings.allow_client_reschedule', false)
            ->where('resources.0.team_member_id', null)
        );
});

test('owner-only solo growth plans block staff reservation booking flows and queue screens', function () {
    $owner = createOwnerOnlySoloTenant([
        'company_type' => 'services',
        'company_sector' => 'salon',
    ]);
    assignSoloSubscription($owner, 'solo_growth');

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->postJson(route('reservation.store'), [])
        ->assertForbidden();

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('reservation.slots', [
            'range_start' => now()->startOfDay()->toIso8601String(),
            'range_end' => now()->addDay()->endOfDay()->toIso8601String(),
        ]))
        ->assertOk()
        ->assertJsonPath('slots', []);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->get(route('reservation.screen'))
        ->assertNotFound();
});
