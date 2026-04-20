<?php

use App\Models\Playbook;
use App\Models\PlaybookRun;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\SavedSegment;
use App\Models\Task;
use App\Models\TeamMember;
use App\Models\User;
use App\Queries\Quotes\BuildQuoteRecoveryIndexData;
use App\Services\Playbooks\PlaybookExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('adds phase three playbook tables with expected columns', function () {
    expect(Schema::hasTable('playbooks'))->toBeTrue()
        ->and(Schema::hasTable('playbook_runs'))->toBeTrue()
        ->and(Schema::hasColumns('playbooks', [
            'user_id',
            'saved_segment_id',
            'created_by_user_id',
            'updated_by_user_id',
            'module',
            'name',
            'action_key',
            'action_payload',
            'schedule_type',
            'schedule_timezone',
            'schedule_day_of_week',
            'schedule_time',
            'next_run_at',
            'last_run_at',
            'is_active',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('playbook_runs', [
            'user_id',
            'playbook_id',
            'saved_segment_id',
            'requested_by_user_id',
            'module',
            'action_key',
            'origin',
            'status',
            'selected_count',
            'processed_count',
            'success_count',
            'failed_count',
            'skipped_count',
            'scheduled_for',
            'started_at',
            'finished_at',
            'summary',
        ]))->toBeTrue();
});

it('persists and casts playbook and run fields', function () {
    $owner = User::factory()->create(['company_type' => 'services']);
    $actor = User::factory()->create(['company_type' => 'services']);
    $savedSegment = SavedSegment::create([
        'user_id' => $owner->id,
        'created_by_user_id' => $actor->id,
        'updated_by_user_id' => $actor->id,
        'module' => SavedSegment::MODULE_REQUEST,
        'name' => 'Request due soon',
    ]);

    $nextRunAt = Carbon::parse('2026-04-21 09:00:00');
    $lastRunAt = Carbon::parse('2026-04-20 09:30:00');
    $scheduledFor = Carbon::parse('2026-04-21 09:00:00');
    $startedAt = Carbon::parse('2026-04-21 09:00:05');
    $finishedAt = Carbon::parse('2026-04-21 09:01:12');

    $playbook = Playbook::create([
        'user_id' => $owner->id,
        'saved_segment_id' => $savedSegment->id,
        'created_by_user_id' => $actor->id,
        'updated_by_user_id' => $actor->id,
        'module' => SavedSegment::MODULE_REQUEST,
        'name' => 'Assign due leads',
        'action_key' => 'assign_selected',
        'action_payload' => [
            'assignee_id' => 44,
        ],
        'schedule_type' => Playbook::SCHEDULE_WEEKLY,
        'schedule_timezone' => 'America/Toronto',
        'schedule_day_of_week' => 1,
        'schedule_time' => '09:00',
        'next_run_at' => $nextRunAt,
        'last_run_at' => $lastRunAt,
        'is_active' => true,
    ]);

    $run = PlaybookRun::create([
        'user_id' => $owner->id,
        'playbook_id' => $playbook->id,
        'saved_segment_id' => $savedSegment->id,
        'requested_by_user_id' => $actor->id,
        'module' => SavedSegment::MODULE_REQUEST,
        'action_key' => 'assign_selected',
        'origin' => PlaybookRun::ORIGIN_SCHEDULED,
        'status' => PlaybookRun::STATUS_COMPLETED,
        'selected_count' => 8,
        'processed_count' => 8,
        'success_count' => 7,
        'failed_count' => 1,
        'skipped_count' => 0,
        'scheduled_for' => $scheduledFor,
        'started_at' => $startedAt,
        'finished_at' => $finishedAt,
        'summary' => [
            'message' => '7 updated, 1 failed.',
        ],
    ]);

    $freshPlaybook = $playbook->fresh();
    $freshRun = $run->fresh();

    expect($freshPlaybook)->not->toBeNull()
        ->and($freshPlaybook->action_payload)->toBe([
            'assignee_id' => 44,
        ])
        ->and($freshPlaybook->schedule_type)->toBe(Playbook::SCHEDULE_WEEKLY)
        ->and($freshPlaybook->schedule_day_of_week)->toBe(1)
        ->and($freshPlaybook->schedule_time)->toBe('09:00')
        ->and($freshPlaybook->next_run_at)->toBeInstanceOf(Carbon::class)
        ->and($freshPlaybook->last_run_at)->toBeInstanceOf(Carbon::class)
        ->and($freshPlaybook->next_run_at?->equalTo($nextRunAt))->toBeTrue()
        ->and($freshPlaybook->last_run_at?->equalTo($lastRunAt))->toBeTrue()
        ->and($freshPlaybook->is_active)->toBeTrue()
        ->and($freshRun)->not->toBeNull()
        ->and($freshRun->origin)->toBe(PlaybookRun::ORIGIN_SCHEDULED)
        ->and($freshRun->status)->toBe(PlaybookRun::STATUS_COMPLETED)
        ->and($freshRun->selected_count)->toBe(8)
        ->and($freshRun->processed_count)->toBe(8)
        ->and($freshRun->success_count)->toBe(7)
        ->and($freshRun->failed_count)->toBe(1)
        ->and($freshRun->skipped_count)->toBe(0)
        ->and($freshRun->scheduled_for)->toBeInstanceOf(Carbon::class)
        ->and($freshRun->started_at)->toBeInstanceOf(Carbon::class)
        ->and($freshRun->finished_at)->toBeInstanceOf(Carbon::class)
        ->and($freshRun->summary)->toBe([
            'message' => '7 updated, 1 failed.',
        ]);
});

it('exposes playbook and run relations', function () {
    $owner = User::factory()->create(['company_type' => 'services']);
    $actor = User::factory()->create(['company_type' => 'services']);
    $savedSegment = SavedSegment::create([
        'user_id' => $owner->id,
        'module' => SavedSegment::MODULE_QUOTE,
        'name' => 'Viewed not accepted',
    ]);

    $playbook = Playbook::create([
        'user_id' => $owner->id,
        'saved_segment_id' => $savedSegment->id,
        'created_by_user_id' => $actor->id,
        'updated_by_user_id' => $actor->id,
        'module' => SavedSegment::MODULE_QUOTE,
        'name' => 'Create quote follow-up task',
        'action_key' => 'create_follow_up_task',
    ]);

    $run = PlaybookRun::create([
        'user_id' => $owner->id,
        'playbook_id' => $playbook->id,
        'saved_segment_id' => $savedSegment->id,
        'requested_by_user_id' => $actor->id,
        'module' => SavedSegment::MODULE_QUOTE,
        'action_key' => 'create_follow_up_task',
        'origin' => PlaybookRun::ORIGIN_MANUAL,
        'status' => PlaybookRun::STATUS_PENDING,
    ]);

    expect($playbook->savedSegment->is($savedSegment))->toBeTrue()
        ->and($playbook->user->is($owner))->toBeTrue()
        ->and($playbook->createdBy->is($actor))->toBeTrue()
        ->and($playbook->runs->first()?->is($run))->toBeTrue()
        ->and($savedSegment->playbooks->first()?->is($playbook))->toBeTrue()
        ->and($savedSegment->playbookRuns->first()?->is($run))->toBeTrue()
        ->and($run->playbook?->is($playbook))->toBeTrue()
        ->and($run->savedSegment?->is($savedSegment))->toBeTrue()
        ->and($run->requestedBy?->is($actor))->toBeTrue();
});

it('preserves run audit data when a playbook is deleted', function () {
    $owner = User::factory()->create(['company_type' => 'services']);
    $savedSegment = SavedSegment::create([
        'user_id' => $owner->id,
        'module' => SavedSegment::MODULE_CUSTOMER,
        'name' => 'Inactive customers',
    ]);

    $playbook = Playbook::create([
        'user_id' => $owner->id,
        'saved_segment_id' => $savedSegment->id,
        'module' => SavedSegment::MODULE_CUSTOMER,
        'name' => 'Tag inactive customers',
        'action_key' => 'tag_selected',
    ]);

    $run = PlaybookRun::create([
        'user_id' => $owner->id,
        'playbook_id' => $playbook->id,
        'saved_segment_id' => $savedSegment->id,
        'module' => SavedSegment::MODULE_CUSTOMER,
        'action_key' => 'tag_selected',
        'origin' => PlaybookRun::ORIGIN_MANUAL,
        'status' => PlaybookRun::STATUS_FAILED,
        'selected_count' => 3,
        'processed_count' => 3,
        'success_count' => 2,
        'failed_count' => 1,
    ]);

    $playbook->delete();

    expect($run->fresh())->not->toBeNull()
        ->and($run->fresh()?->playbook_id)->toBeNull()
        ->and($run->fresh()?->saved_segment_id)->toBe($savedSegment->id)
        ->and($run->fresh()?->failed_count)->toBe(1);
});

it('executes a request playbook manually and stores bulk-style run summary', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_features' => [
            'requests' => true,
        ],
    ]);

    $assignee = TeamMember::factory()->create([
        'account_id' => $owner->id,
    ]);

    $customer = Customer::create([
        'user_id' => $owner->id,
        'first_name' => 'Request',
        'last_name' => 'Playbook',
        'company_name' => 'Request Playbook Co',
        'email' => 'request-playbook@example.com',
        'salutation' => 'Mr',
    ]);

    $matchingLeadOne = LeadRequest::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_NEW,
        'title' => 'Playbook request alpha',
        'service_type' => 'Cleaning',
    ]);

    $matchingLeadTwo = LeadRequest::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_NEW,
        'title' => 'Playbook request beta',
        'service_type' => 'Cleaning',
    ]);

    LeadRequest::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_NEW,
        'title' => 'Other request gamma',
        'service_type' => 'Cleaning',
    ]);

    $segment = SavedSegment::create([
        'user_id' => $owner->id,
        'module' => SavedSegment::MODULE_REQUEST,
        'name' => 'Playbook requests',
        'search_term' => 'Playbook request',
    ]);

    $playbook = Playbook::create([
        'user_id' => $owner->id,
        'saved_segment_id' => $segment->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'module' => SavedSegment::MODULE_REQUEST,
        'name' => 'Assign matching requests',
        'action_key' => 'assign_selected',
        'action_payload' => [
            'assigned_team_member_id' => $assignee->id,
        ],
        'schedule_type' => Playbook::SCHEDULE_MANUAL,
        'is_active' => true,
    ]);

    $run = app(PlaybookExecutionService::class)->executeManual($playbook, $owner);

    $summary = $run->summary ?? [];

    expect($run->status)->toBe(PlaybookRun::STATUS_COMPLETED)
        ->and($run->selected_count)->toBe(2)
        ->and($run->processed_count)->toBe(2)
        ->and($run->success_count)->toBe(2)
        ->and($run->failed_count)->toBe(0)
        ->and($run->skipped_count)->toBe(0)
        ->and($summary['message'] ?? null)->toBe('Requests updated.')
        ->and($summary['ids'] ?? null)->toBe([$matchingLeadOne->id, $matchingLeadTwo->id])
        ->and($summary['processed_ids'] ?? null)->toBe([$matchingLeadOne->id, $matchingLeadTwo->id])
        ->and($summary['selected_count'] ?? null)->toBe(2)
        ->and($summary['processed_count'] ?? null)->toBe(2)
        ->and($summary['success_count'] ?? null)->toBe(2)
        ->and($summary['failed_count'] ?? null)->toBe(0)
        ->and($summary['skipped_count'] ?? null)->toBe(0)
        ->and($summary['errors'] ?? null)->toBe([])
        ->and($matchingLeadOne->fresh()?->assigned_team_member_id)->toBe($assignee->id)
        ->and($matchingLeadTwo->fresh()?->assigned_team_member_id)->toBe($assignee->id)
        ->and($playbook->fresh()?->last_run_at)->not->toBeNull()
        ->and($segment->fresh()?->cached_count)->toBe(2)
        ->and(ActivityLog::query()->where('action', 'bulk_updated')->count())->toBe(2);
});

it('creates and runs a request playbook through crm endpoints', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_features' => [
            'requests' => true,
        ],
    ]);

    $assignee = TeamMember::factory()->create([
        'account_id' => $owner->id,
    ]);

    $customer = Customer::create([
        'user_id' => $owner->id,
        'first_name' => 'Endpoint',
        'last_name' => 'Customer',
        'company_name' => 'Endpoint Playbook Co',
        'email' => 'endpoint-playbook@example.com',
        'salutation' => 'Mr',
    ]);

    $matchingLeadOne = LeadRequest::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_NEW,
        'title' => 'Endpoint playbook alpha',
        'service_type' => 'Cleaning',
    ]);

    $matchingLeadTwo = LeadRequest::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_NEW,
        'title' => 'Endpoint playbook beta',
        'service_type' => 'Cleaning',
    ]);

    LeadRequest::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_NEW,
        'title' => 'Different lead',
        'service_type' => 'Cleaning',
    ]);

    $segment = SavedSegment::create([
        'user_id' => $owner->id,
        'module' => SavedSegment::MODULE_REQUEST,
        'name' => 'Endpoint request segment',
        'search_term' => 'Endpoint playbook',
    ]);

    $createResponse = $this->actingAs($owner)->postJson(route('crm.playbooks.store'), [
        'saved_segment_id' => $segment->id,
        'name' => 'Endpoint request playbook',
        'action_key' => 'assign_selected',
        'action_payload' => [
            'assigned_team_member_id' => $assignee->id,
        ],
    ]);

    $createResponse->assertCreated()
        ->assertJsonPath('playbook.module', SavedSegment::MODULE_REQUEST)
        ->assertJsonPath('playbook.saved_segment.id', $segment->id)
        ->assertJsonPath('playbook.action_key', 'assign_selected')
        ->assertJsonPath('playbook.schedule_type', Playbook::SCHEDULE_MANUAL);

    $playbookId = $createResponse->json('playbook.id');

    $runResponse = $this->actingAs($owner)->postJson(route('crm.playbooks.run', $playbookId));

    $runResponse->assertOk()
        ->assertJsonPath('run.playbook_id', $playbookId)
        ->assertJsonPath('run.status', PlaybookRun::STATUS_COMPLETED)
        ->assertJsonPath('run.selected_count', 2)
        ->assertJsonPath('run.processed_count', 2)
        ->assertJsonPath('run.success_count', 2)
        ->assertJsonPath('run.failed_count', 0)
        ->assertJsonPath('run.skipped_count', 0)
        ->assertJsonPath('run.summary.message', 'Requests updated.')
        ->assertJsonPath('run.summary.processed_ids', [$matchingLeadOne->id, $matchingLeadTwo->id]);

    expect($matchingLeadOne->fresh()?->assigned_team_member_id)->toBe($assignee->id)
        ->and($matchingLeadTwo->fresh()?->assigned_team_member_id)->toBe($assignee->id)
        ->and(PlaybookRun::query()->count())->toBe(1);
});

it('blocks team members from creating or running crm playbooks', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_features' => [
            'requests' => true,
        ],
    ]);
    $member = User::factory()->create(['company_type' => 'services']);

    TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $member->id,
        'permissions' => ['requests.view'],
        'is_active' => true,
    ]);

    $segment = SavedSegment::create([
        'user_id' => $owner->id,
        'module' => SavedSegment::MODULE_REQUEST,
        'name' => 'Member blocked segment',
        'search_term' => 'blocked',
    ]);

    $playbook = Playbook::create([
        'user_id' => $owner->id,
        'saved_segment_id' => $segment->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'module' => SavedSegment::MODULE_REQUEST,
        'name' => 'Member blocked playbook',
        'action_key' => 'update_status',
        'action_payload' => [
            'status' => LeadRequest::STATUS_CONTACTED,
        ],
        'schedule_type' => Playbook::SCHEDULE_MANUAL,
        'is_active' => true,
    ]);

    $this->actingAs($member)
        ->postJson(route('crm.playbooks.store'), [
            'saved_segment_id' => $segment->id,
            'name' => 'Member should not create',
            'action_key' => 'update_status',
        ])
        ->assertForbidden();

    $this->actingAs($member)
        ->postJson(route('crm.playbooks.run', $playbook))
        ->assertForbidden();

    expect(PlaybookRun::query()->count())->toBe(0);
});

it('blocks crm playbook endpoints when the backing module feature is unavailable', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_features' => [
            'requests' => false,
        ],
    ]);

    $segment = SavedSegment::create([
        'user_id' => $owner->id,
        'module' => SavedSegment::MODULE_REQUEST,
        'name' => 'Feature gated request segment',
        'search_term' => 'Feature gated',
    ]);

    $playbook = Playbook::create([
        'user_id' => $owner->id,
        'saved_segment_id' => $segment->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'module' => SavedSegment::MODULE_REQUEST,
        'name' => 'Feature gated playbook',
        'action_key' => 'update_status',
        'action_payload' => [
            'status' => LeadRequest::STATUS_CONTACTED,
        ],
        'schedule_type' => Playbook::SCHEDULE_MANUAL,
        'is_active' => true,
    ]);

    $this->actingAs($owner)
        ->postJson(route('crm.playbooks.store'), [
            'saved_segment_id' => $segment->id,
            'name' => 'Blocked playbook creation',
            'action_key' => 'update_status',
        ])
        ->assertForbidden();

    $this->actingAs($owner)
        ->postJson(route('crm.playbooks.run', $playbook))
        ->assertForbidden();

    expect(PlaybookRun::query()->count())->toBe(0);
});

it('executes a customer archive playbook manually', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
    ]);

    $customerOne = Customer::create([
        'user_id' => $owner->id,
        'first_name' => 'Customer',
        'last_name' => 'One',
        'company_name' => 'Customer Archive One',
        'email' => 'customer-archive-one@example.com',
        'salutation' => 'Mr',
        'is_active' => true,
        'is_vip' => true,
    ]);

    $customerTwo = Customer::create([
        'user_id' => $owner->id,
        'first_name' => 'Customer',
        'last_name' => 'Two',
        'company_name' => 'Customer Archive Two',
        'email' => 'customer-archive-two@example.com',
        'salutation' => 'Mr',
        'is_active' => true,
        'is_vip' => true,
    ]);

    Customer::create([
        'user_id' => $owner->id,
        'first_name' => 'Customer',
        'last_name' => 'Three',
        'company_name' => 'Customer Keep Active',
        'email' => 'customer-keep-active@example.com',
        'salutation' => 'Mr',
        'is_active' => true,
        'is_vip' => false,
    ]);

    $segment = SavedSegment::create([
        'user_id' => $owner->id,
        'module' => SavedSegment::MODULE_CUSTOMER,
        'name' => 'VIP active customers',
        'filters' => [
            'status' => 'active',
            'is_vip' => true,
        ],
        'sort' => [
            'column' => 'company_name',
            'direction' => 'asc',
        ],
    ]);

    $playbook = Playbook::create([
        'user_id' => $owner->id,
        'saved_segment_id' => $segment->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'module' => SavedSegment::MODULE_CUSTOMER,
        'name' => 'Archive VIP customers',
        'action_key' => 'archive',
        'schedule_type' => Playbook::SCHEDULE_MANUAL,
        'is_active' => true,
    ]);

    $run = app(PlaybookExecutionService::class)->executeManual($playbook, $owner);

    expect($run->status)->toBe(PlaybookRun::STATUS_COMPLETED)
        ->and($run->selected_count)->toBe(2)
        ->and($run->processed_count)->toBe(2)
        ->and($run->success_count)->toBe(2)
        ->and($run->failed_count)->toBe(0)
        ->and($run->skipped_count)->toBe(0)
        ->and($run->summary['message'] ?? null)->toBe('Customers archived.')
        ->and($customerOne->fresh()?->is_active)->toBeFalse()
        ->and($customerTwo->fresh()?->is_active)->toBeFalse();
});

it('executes a quote follow-up task playbook manually', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_features' => [
            'quotes' => true,
            'tasks' => true,
        ],
    ]);

    $customer = Customer::create([
        'user_id' => $owner->id,
        'first_name' => 'Quote',
        'last_name' => 'Playbook',
        'company_name' => 'Quote Playbook Co',
        'email' => 'quote-playbook@example.com',
        'salutation' => 'Mr',
    ]);

    $referenceTime = Carbon::parse('2026-04-20 10:00:00');

    try {
        Carbon::setTestNow($referenceTime);

        $matchingQuote = Quote::create([
            'user_id' => $owner->id,
            'customer_id' => $customer->id,
            'job_title' => 'Viewed quote for playbook',
            'status' => 'sent',
            'subtotal' => 1800,
            'total' => 1800,
            'last_sent_at' => $referenceTime->copy()->subDays(2),
            'last_viewed_at' => $referenceTime->copy()->subHours(3),
            'follow_up_count' => 1,
        ]);

        Quote::create([
            'user_id' => $owner->id,
            'customer_id' => $customer->id,
            'job_title' => 'Due quote only',
            'status' => 'sent',
            'subtotal' => 900,
            'total' => 900,
            'next_follow_up_at' => $referenceTime->copy()->addHours(3),
            'follow_up_count' => 1,
        ]);

        $segment = SavedSegment::create([
            'user_id' => $owner->id,
            'module' => SavedSegment::MODULE_QUOTE,
            'name' => 'Viewed quotes',
            'filters' => [
                'queue' => BuildQuoteRecoveryIndexData::QUEUE_VIEWED_NOT_ACCEPTED,
            ],
        ]);

        $playbook = Playbook::create([
            'user_id' => $owner->id,
            'saved_segment_id' => $segment->id,
            'created_by_user_id' => $owner->id,
            'updated_by_user_id' => $owner->id,
            'module' => SavedSegment::MODULE_QUOTE,
            'name' => 'Create recovery tasks',
            'action_key' => 'create_follow_up_task',
            'action_payload' => [
                'due_date' => '2026-04-23',
            ],
            'schedule_type' => Playbook::SCHEDULE_MANUAL,
            'is_active' => true,
        ]);

        $run = app(PlaybookExecutionService::class)->executeManual($playbook, $owner);
        $task = Task::query()->latest('id')->first();

        expect($run->status)->toBe(PlaybookRun::STATUS_COMPLETED)
            ->and($run->selected_count)->toBe(1)
            ->and($run->processed_count)->toBe(1)
            ->and($run->success_count)->toBe(1)
            ->and($run->failed_count)->toBe(0)
            ->and($run->skipped_count)->toBe(0)
            ->and($run->summary['message'] ?? null)->toBe('Recovery tasks created.')
            ->and($task)->not->toBeNull()
            ->and($task?->customer_id)->toBe($customer->id)
            ->and($task?->request_id)->toBeNull()
            ->and(optional($task?->due_date)->toDateString())->toBe('2026-04-23')
            ->and(ActivityLog::query()->where('action', 'quote_follow_up_task_created')->count())->toBe(1)
            ->and($matchingQuote->fresh()?->archived_at)->toBeNull();
    } finally {
        Carbon::setTestNow();
    }
});

it('fails a playbook run when the action is incompatible with the segment module', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_features' => [
            'requests' => true,
        ],
    ]);

    $customer = Customer::create([
        'user_id' => $owner->id,
        'first_name' => 'Bad',
        'last_name' => 'Action',
        'company_name' => 'Bad Action Co',
        'email' => 'bad-action@example.com',
        'salutation' => 'Mr',
    ]);

    $lead = LeadRequest::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_NEW,
        'title' => 'Bad action request',
        'service_type' => 'Cleaning',
    ]);

    $segment = SavedSegment::create([
        'user_id' => $owner->id,
        'module' => SavedSegment::MODULE_REQUEST,
        'name' => 'Bad action segment',
        'search_term' => 'Bad action',
    ]);

    $playbook = Playbook::create([
        'user_id' => $owner->id,
        'saved_segment_id' => $segment->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'module' => SavedSegment::MODULE_REQUEST,
        'name' => 'Unsupported request action',
        'action_key' => 'archive',
        'schedule_type' => Playbook::SCHEDULE_MANUAL,
        'is_active' => true,
    ]);

    $run = app(PlaybookExecutionService::class)->executeManual($playbook, $owner);

    expect($run->status)->toBe(PlaybookRun::STATUS_FAILED)
        ->and($run->selected_count)->toBe(1)
        ->and($run->processed_count)->toBe(0)
        ->and($run->success_count)->toBe(0)
        ->and($run->failed_count)->toBe(0)
        ->and($run->skipped_count)->toBe(1)
        ->and($run->summary['message'] ?? null)->toBe('Unsupported request playbook action [archive].')
        ->and($run->summary['errors'] ?? null)->toBe(['Unsupported request playbook action [archive].'])
        ->and($lead->fresh()?->status)->toBe(LeadRequest::STATUS_NEW);
});

it('fails a quote task playbook when the tasks feature is unavailable', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_features' => [
            'quotes' => true,
            'tasks' => false,
        ],
    ]);

    $customer = Customer::create([
        'user_id' => $owner->id,
        'first_name' => 'Feature',
        'last_name' => 'Gate',
        'company_name' => 'Feature Gate Co',
        'email' => 'feature-gate@example.com',
        'salutation' => 'Mr',
    ]);

    $referenceTime = Carbon::parse('2026-04-20 10:00:00');

    try {
        Carbon::setTestNow($referenceTime);

        Quote::create([
            'user_id' => $owner->id,
            'customer_id' => $customer->id,
            'job_title' => 'Feature gated quote',
            'status' => 'sent',
            'subtotal' => 1200,
            'total' => 1200,
            'last_sent_at' => $referenceTime->copy()->subDays(2),
            'last_viewed_at' => $referenceTime->copy()->subHours(2),
            'follow_up_count' => 1,
        ]);

        $segment = SavedSegment::create([
            'user_id' => $owner->id,
            'module' => SavedSegment::MODULE_QUOTE,
            'name' => 'Feature gated viewed quotes',
            'filters' => [
                'queue' => BuildQuoteRecoveryIndexData::QUEUE_VIEWED_NOT_ACCEPTED,
            ],
        ]);

        $playbook = Playbook::create([
            'user_id' => $owner->id,
            'saved_segment_id' => $segment->id,
            'created_by_user_id' => $owner->id,
            'updated_by_user_id' => $owner->id,
            'module' => SavedSegment::MODULE_QUOTE,
            'name' => 'Feature gated tasks',
            'action_key' => 'create_follow_up_task',
            'schedule_type' => Playbook::SCHEDULE_MANUAL,
            'is_active' => true,
        ]);

        $run = app(PlaybookExecutionService::class)->executeManual($playbook, $owner);

        expect($run->status)->toBe(PlaybookRun::STATUS_FAILED)
            ->and($run->selected_count)->toBe(1)
            ->and($run->processed_count)->toBe(0)
            ->and($run->success_count)->toBe(0)
            ->and($run->failed_count)->toBe(0)
            ->and($run->skipped_count)->toBe(1)
            ->and($run->summary['message'] ?? null)->toBe('Tasks module is unavailable for this account.')
            ->and($run->summary['errors'] ?? null)->toBe(['Tasks module is unavailable for this account.'])
            ->and(Task::query()->count())->toBe(0);
    } finally {
        Carbon::setTestNow();
    }
});
