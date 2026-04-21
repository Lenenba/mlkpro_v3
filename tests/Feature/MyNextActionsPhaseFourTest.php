<?php

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\Task;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\CRM\MyNextActionsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('my next actions service aggregates request quote task and sales activity items', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
    ]);

    $customer = Customer::create([
        'user_id' => $owner->id,
        'first_name' => 'Pipeline',
        'last_name' => 'Customer',
        'company_name' => 'Pipeline Customer Inc.',
        'email' => 'pipeline-customer@example.com',
    ]);

    $referenceTime = Carbon::parse('2026-04-20 09:00:00');

    $lead = LeadRequest::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Call back prospect',
        'next_follow_up_at' => $referenceTime->copy()->addHours(2),
    ]);

    $quote = Quote::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'request_id' => $lead->id,
        'job_title' => 'Kitchen renovation',
        'status' => 'sent',
        'subtotal' => 1800,
        'total' => 1800,
        'initial_deposit' => 0,
        'next_follow_up_at' => $referenceTime->copy()->addHours(4),
    ]);

    $task = Task::create([
        'account_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'customer_id' => $customer->id,
        'request_id' => $lead->id,
        'title' => 'Prepare updated proposal',
        'status' => 'todo',
        'due_date' => $referenceTime->copy()->addDay()->toDateString(),
    ]);

    ActivityLog::record($owner, $customer, 'sales_meeting_scheduled', [
        'meeting_at' => $referenceTime->copy()->addHours(6)->toIso8601String(),
        'sales_activity_action' => 'sales_meeting_scheduled',
    ], 'On-site estimate booked');

    $result = app(MyNextActionsService::class)->execute($owner, [
        'reference_time' => $referenceTime,
    ]);

    expect($result['count'])->toBe(4)
        ->and($result['stats']['by_source'])->toMatchArray([
            'request_follow_up' => 1,
            'quote_follow_up' => 1,
            'task' => 1,
            'sales_activity' => 1,
        ]);

    $items = collect($result['items'])->keyBy('id');

    expect($items->get('request-'.$lead->id.'-follow-up'))
        ->not->toBeNull()
        ->and(data_get($items->get('request-'.$lead->id.'-follow-up'), 'subject_type'))->toBe('request')
        ->and(data_get($items->get('request-'.$lead->id.'-follow-up'), 'customer.name'))->toBe('Pipeline Customer Inc.');

    expect($items->get('quote-'.$quote->id.'-follow-up'))
        ->not->toBeNull()
        ->and(data_get($items->get('quote-'.$quote->id.'-follow-up'), 'subject_type'))->toBe('quote')
        ->and(data_get($items->get('quote-'.$quote->id.'-follow-up'), 'subject_title'))->toContain('Kitchen renovation');

    expect($items->get('task-'.$task->id))
        ->not->toBeNull()
        ->and(data_get($items->get('task-'.$task->id), 'source'))->toBe('task')
        ->and(data_get($items->get('task-'.$task->id), 'is_all_day'))->toBeTrue();

    $salesActivityItem = collect($result['items'])
        ->first(fn (array $item): bool => $item['source'] === 'sales_activity');

    expect($salesActivityItem)
        ->not->toBeNull()
        ->and(data_get($salesActivityItem, 'subject_type'))->toBe('customer')
        ->and(data_get($salesActivityItem, 'activity.activity_key'))->toBe('sales_meeting_scheduled')
        ->and(data_get($salesActivityItem, 'activity.description'))->toBe('On-site estimate booked');
});

test('my next actions service removes closed next actions and avoids duplicate task or quote follow up rows', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
    ]);

    $customer = Customer::create([
        'user_id' => $owner->id,
        'first_name' => 'Dedup',
        'last_name' => 'Customer',
        'company_name' => 'Dedup Customer Inc.',
        'email' => 'dedup-customer@example.com',
    ]);

    $referenceTime = Carbon::parse('2026-04-20 09:00:00');
    $scheduledAt = $referenceTime->copy()->addDay()->setTime(9, 30);

    $quoteWithDuplicateFollowUp = Quote::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'job_title' => 'Duplicate follow-up quote',
        'status' => 'sent',
        'subtotal' => 900,
        'total' => 900,
        'initial_deposit' => 0,
        'next_follow_up_at' => $scheduledAt,
    ]);

    ActivityLog::record($owner, $quoteWithDuplicateFollowUp, 'quote_follow_up_scheduled', [
        'next_follow_up_at' => $scheduledAt->toIso8601String(),
    ], 'Quote follow-up scheduled');

    $quoteWithClosedAction = Quote::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'job_title' => 'Closed follow-up quote',
        'status' => 'sent',
        'subtotal' => 1100,
        'total' => 1100,
        'initial_deposit' => 0,
    ]);

    ActivityLog::record($owner, $quoteWithClosedAction, 'sales_next_action_scheduled', [
        'next_follow_up_at' => $referenceTime->copy()->addHours(5)->toIso8601String(),
    ], 'Next action scheduled');
    ActivityLog::record($owner, $quoteWithClosedAction, 'sales_next_action_cleared', [
        'sales_activity_action' => 'sales_next_action_cleared',
    ], 'Next action cleared');

    $quoteWithRecoveryTask = Quote::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'job_title' => 'Task backed quote',
        'status' => 'sent',
        'subtotal' => 1250,
        'total' => 1250,
        'initial_deposit' => 0,
    ]);

    $task = Task::create([
        'account_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'customer_id' => $customer->id,
        'title' => 'Recovery call for task backed quote',
        'status' => 'todo',
        'due_date' => $referenceTime->copy()->addDays(2)->toDateString(),
    ]);

    ActivityLog::record($owner, $quoteWithRecoveryTask, 'quote_follow_up_task_created', [
        'task_id' => $task->id,
        'task_title' => $task->title,
        'task_due_date' => $task->due_date?->toDateString(),
    ], 'Recovery task created from quote');

    $result = app(MyNextActionsService::class)->execute($owner, [
        'reference_time' => $referenceTime,
    ]);

    $items = collect($result['items']);

    expect($items->filter(fn (array $item): bool => $item['subject_type'] === 'quote' && $item['subject_id'] === $quoteWithDuplicateFollowUp->id)->count())->toBe(1)
        ->and($items->contains(fn (array $item): bool => $item['id'] === 'quote-'.$quoteWithDuplicateFollowUp->id.'-follow-up'))->toBeFalse()
        ->and($items->contains(fn (array $item): bool => $item['source'] === 'sales_activity' && $item['subject_type'] === 'quote' && $item['subject_id'] === $quoteWithDuplicateFollowUp->id))->toBeTrue()
        ->and($items->contains(fn (array $item): bool => $item['subject_type'] === 'quote' && $item['subject_id'] === $quoteWithClosedAction->id))->toBeFalse()
        ->and($items->contains(fn (array $item): bool => $item['id'] === 'task-'.$task->id))->toBeTrue()
        ->and($items->contains(fn (array $item): bool => $item['source'] === 'sales_activity' && $item['subject_type'] === 'quote' && $item['subject_id'] === $quoteWithRecoveryTask->id))->toBeFalse();
});

test('my next actions service scopes team member task visibility to the assigned member', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
    ]);

    $memberUser = User::factory()->create([
        'company_type' => 'services',
    ]);

    $otherUser = User::factory()->create([
        'company_type' => 'services',
    ]);

    $member = TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $memberUser->id,
        'role' => 'member',
        'permissions' => ['tasks.view'],
    ]);

    $otherMember = TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $otherUser->id,
        'role' => 'member',
        'permissions' => ['tasks.view'],
    ]);

    $customer = Customer::create([
        'user_id' => $owner->id,
        'first_name' => 'Scoped',
        'last_name' => 'Customer',
        'company_name' => 'Scoped Customer Inc.',
        'email' => 'scoped-customer@example.com',
    ]);

    $referenceTime = Carbon::parse('2026-04-20 09:00:00');

    $visibleTask = Task::create([
        'account_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'customer_id' => $customer->id,
        'assigned_team_member_id' => $member->id,
        'title' => 'Visible member task',
        'status' => 'todo',
        'due_date' => $referenceTime->copy()->addDay()->toDateString(),
    ]);

    Task::create([
        'account_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'customer_id' => $customer->id,
        'assigned_team_member_id' => $otherMember->id,
        'title' => 'Other member task',
        'status' => 'todo',
        'due_date' => $referenceTime->copy()->addDay()->toDateString(),
    ]);

    Task::create([
        'account_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'customer_id' => $customer->id,
        'title' => 'Unassigned task',
        'status' => 'todo',
        'due_date' => $referenceTime->copy()->addDay()->toDateString(),
    ]);

    Quote::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'job_title' => 'Quote hidden without quote permission',
        'status' => 'sent',
        'subtotal' => 700,
        'total' => 700,
        'initial_deposit' => 0,
        'next_follow_up_at' => $referenceTime->copy()->addHours(3),
    ]);

    $result = app(MyNextActionsService::class)->execute($memberUser, [
        'reference_time' => $referenceTime,
    ]);

    expect($result['count'])->toBe(1)
        ->and($result['stats']['by_source'])->toMatchArray([
            'task' => 1,
        ])
        ->and(data_get($result, 'items.0.id'))->toBe('task-'.$visibleTask->id)
        ->and(data_get($result, 'items.0.assignee.id'))->toBe($member->id);
});

test('my next actions workspace applies overdue filters and exposes the sales activity item payload', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
    ]);

    $customer = Customer::create([
        'user_id' => $owner->id,
        'first_name' => 'Workspace',
        'last_name' => 'Customer',
        'company_name' => 'Workspace Customer Inc.',
        'email' => 'workspace-customer@example.com',
    ]);

    $referenceTime = Carbon::parse('2026-04-21 10:00:00');

    LeadRequest::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Today follow-up request',
        'next_follow_up_at' => $referenceTime->copy()->addHours(3),
    ]);

    $quote = Quote::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'job_title' => 'Workspace quote',
        'status' => 'sent',
        'subtotal' => 1450,
        'total' => 1450,
        'initial_deposit' => 0,
        'next_follow_up_at' => $referenceTime->copy()->addDay(),
    ]);

    ActivityLog::record($owner, $quote, 'sales_next_action_scheduled', [
        'next_follow_up_at' => $referenceTime->copy()->subHours(2)->toIso8601String(),
        'sales_activity_action' => 'sales_next_action_scheduled',
    ], 'Urgent renewal call');

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
            ->where('stats.overdue', 1)
            ->has('items', 1)
            ->where('items.0.source', 'sales_activity')
            ->where('items.0.subject_type', 'quote')
            ->where('items.0.activity.activity_key', 'sales_next_action_scheduled')
            ->where('items.0.activity.description', 'Urgent renewal call')
        );
});

test('my next actions workspace respects team member task scope', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
    ]);

    $memberUser = User::factory()->create([
        'company_type' => 'services',
    ]);

    $otherUser = User::factory()->create([
        'company_type' => 'services',
    ]);

    $member = TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $memberUser->id,
        'role' => 'member',
        'permissions' => ['tasks.view'],
    ]);

    $otherMember = TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $otherUser->id,
        'role' => 'member',
        'permissions' => ['tasks.view'],
    ]);

    $customer = Customer::create([
        'user_id' => $owner->id,
        'first_name' => 'Member',
        'last_name' => 'Scoped',
        'company_name' => 'Member Scoped Inc.',
        'email' => 'member-scoped@example.com',
    ]);

    $referenceTime = Carbon::parse('2026-04-21 10:00:00');

    $visibleTask = Task::create([
        'account_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'customer_id' => $customer->id,
        'assigned_team_member_id' => $member->id,
        'title' => 'Visible workspace task',
        'status' => 'todo',
        'due_date' => $referenceTime->copy()->addDay()->toDateString(),
    ]);

    Task::create([
        'account_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'customer_id' => $customer->id,
        'assigned_team_member_id' => $otherMember->id,
        'title' => 'Hidden workspace task',
        'status' => 'todo',
        'due_date' => $referenceTime->copy()->addDay()->toDateString(),
    ]);

    $this->actingAs($memberUser)
        ->get(route('crm.next-actions.index', [
            'reference_time' => $referenceTime->toIso8601String(),
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('CRM/MyNextActions')
            ->where('count', 1)
            ->where('stats.total', 1)
            ->has('items', 1)
            ->where('items.0.id', 'task-'.$visibleTask->id)
            ->where('items.0.source', 'task')
            ->where('items.0.assignee.id', $member->id)
        );
});

test('my next actions workspace paginates the card feed', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
    ]);

    $customer = Customer::create([
        'user_id' => $owner->id,
        'first_name' => 'Paged',
        'last_name' => 'Customer',
        'company_name' => 'Paged Customer Inc.',
        'email' => 'paged-customer@example.com',
    ]);

    $referenceTime = Carbon::parse('2026-04-21 10:00:00');

    foreach (range(1, 7) as $index) {
        Task::create([
            'account_id' => $owner->id,
            'created_by_user_id' => $owner->id,
            'customer_id' => $customer->id,
            'title' => 'Paged workspace task '.$index,
            'status' => 'todo',
            'due_date' => $referenceTime->copy()->addDays($index)->toDateString(),
        ]);
    }

    $this->actingAs($owner)
        ->get(route('crm.next-actions.index', [
            'reference_time' => $referenceTime->toIso8601String(),
            'per_page' => 6,
            'page' => 2,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('CRM/MyNextActions')
            ->where('filters.per_page', 6)
            ->where('count', 7)
            ->where('pagination.current_page', 2)
            ->where('pagination.last_page', 2)
            ->where('pagination.per_page', 6)
            ->where('pagination.total', 7)
            ->where('pagination.from', 7)
            ->where('pagination.to', 7)
            ->has('items', 1)
            ->where('items.0.source', 'task')
        );
});
