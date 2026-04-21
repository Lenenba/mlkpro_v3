<?php

use App\Models\Customer;
use App\Models\Playbook;
use App\Models\PlaybookRun;
use App\Models\Request as LeadRequest;
use App\Models\SavedSegment;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Playbooks\PlaybookSchedulerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

function playbookSchedulerOwner(array $features = []): User
{
    return User::factory()->create([
        'company_type' => 'services',
        'company_features' => array_merge([
            'requests' => true,
            'quotes' => true,
            'tasks' => true,
        ], $features),
    ]);
}

function playbookSchedulerCustomer(User $owner, string $label = 'Playbook Scheduler'): Customer
{
    $slug = strtolower(str_replace(' ', '-', $label));

    return Customer::create([
        'user_id' => $owner->id,
        'first_name' => $label,
        'last_name' => 'Customer',
        'company_name' => $label.' Co',
        'email' => $slug.'@example.com',
        'salutation' => 'Mr',
    ]);
}

it('reserves and executes a due daily playbook while advancing next run at', function () {
    $owner = playbookSchedulerOwner(['requests' => true]);
    $assignee = TeamMember::factory()->create([
        'account_id' => $owner->id,
    ]);
    $customer = playbookSchedulerCustomer($owner, 'Daily Scheduler');

    $matchingLeadOne = LeadRequest::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_NEW,
        'title' => 'Daily scheduler alpha',
        'service_type' => 'Cleaning',
    ]);

    $matchingLeadTwo = LeadRequest::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_NEW,
        'title' => 'Daily scheduler beta',
        'service_type' => 'Cleaning',
    ]);

    LeadRequest::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_NEW,
        'title' => 'Ignore this lead',
        'service_type' => 'Cleaning',
    ]);

    $segment = SavedSegment::create([
        'user_id' => $owner->id,
        'module' => SavedSegment::MODULE_REQUEST,
        'name' => 'Daily scheduler segment',
        'search_term' => 'Daily scheduler',
    ]);

    $scheduledAt = Carbon::create(2026, 4, 20, 13, 0, 0, 'UTC');
    $referenceTime = Carbon::create(2026, 4, 20, 13, 5, 0, 'UTC');

    try {
        Carbon::setTestNow($referenceTime);

        $playbook = Playbook::create([
            'user_id' => $owner->id,
            'saved_segment_id' => $segment->id,
            'created_by_user_id' => $owner->id,
            'updated_by_user_id' => $owner->id,
            'module' => SavedSegment::MODULE_REQUEST,
            'name' => 'Daily scheduler playbook',
            'action_key' => 'assign_selected',
            'action_payload' => [
                'assigned_team_member_id' => $assignee->id,
            ],
            'schedule_type' => Playbook::SCHEDULE_DAILY,
            'schedule_timezone' => 'America/Toronto',
            'schedule_time' => '09:00',
            'next_run_at' => $scheduledAt,
            'is_active' => true,
        ]);

        $summary = app(PlaybookSchedulerService::class)->runDue(null, $referenceTime);
        $run = PlaybookRun::query()->latest('id')->first();

        expect($summary['checked_count'])->toBe(1)
            ->and($summary['reserved_count'])->toBe(1)
            ->and($summary['executed_count'])->toBe(1)
            ->and($summary['failed_count'])->toBe(0)
            ->and($summary['skipped_overlap_count'])->toBe(0)
            ->and($run)->not->toBeNull()
            ->and($run?->origin)->toBe(PlaybookRun::ORIGIN_SCHEDULED)
            ->and($run?->status)->toBe(PlaybookRun::STATUS_COMPLETED)
            ->and($run?->scheduled_for?->copy()->timezone('America/Toronto')->format('Y-m-d H:i'))->toBe('2026-04-20 09:00')
            ->and($playbook->fresh()?->next_run_at?->copy()->timezone('America/Toronto')->format('Y-m-d H:i'))->toBe('2026-04-21 09:00')
            ->and($matchingLeadOne->fresh()?->assigned_team_member_id)->toBe($assignee->id)
            ->and($matchingLeadTwo->fresh()?->assigned_team_member_id)->toBe($assignee->id);
    } finally {
        Carbon::setTestNow();
    }
});

it('logs an empty weekly scheduled run and advances to the next configured weekday', function () {
    $owner = playbookSchedulerOwner(['requests' => true]);
    $customer = playbookSchedulerCustomer($owner, 'Weekly Scheduler');

    LeadRequest::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_NEW,
        'title' => 'No matching lead here',
        'service_type' => 'Cleaning',
    ]);

    $segment = SavedSegment::create([
        'user_id' => $owner->id,
        'module' => SavedSegment::MODULE_REQUEST,
        'name' => 'Weekly empty segment',
        'search_term' => 'This will not match',
    ]);

    $scheduledAt = Carbon::create(2026, 4, 20, 13, 0, 0, 'UTC');
    $referenceTime = Carbon::create(2026, 4, 20, 13, 10, 0, 'UTC');

    try {
        Carbon::setTestNow($referenceTime);

        $playbook = Playbook::create([
            'user_id' => $owner->id,
            'saved_segment_id' => $segment->id,
            'created_by_user_id' => $owner->id,
            'updated_by_user_id' => $owner->id,
            'module' => SavedSegment::MODULE_REQUEST,
            'name' => 'Weekly scheduler playbook',
            'action_key' => 'update_status',
            'action_payload' => [
                'status' => LeadRequest::STATUS_CONTACTED,
            ],
            'schedule_type' => Playbook::SCHEDULE_WEEKLY,
            'schedule_timezone' => 'America/Toronto',
            'schedule_day_of_week' => 1,
            'schedule_time' => '09:00',
            'next_run_at' => $scheduledAt,
            'is_active' => true,
        ]);

        $summary = app(PlaybookSchedulerService::class)->runDue(null, $referenceTime);
        $run = PlaybookRun::query()->latest('id')->first();

        expect($summary['checked_count'])->toBe(1)
            ->and($summary['reserved_count'])->toBe(1)
            ->and($summary['executed_count'])->toBe(1)
            ->and($run)->not->toBeNull()
            ->and($run?->origin)->toBe(PlaybookRun::ORIGIN_SCHEDULED)
            ->and($run?->status)->toBe(PlaybookRun::STATUS_COMPLETED)
            ->and($run?->selected_count)->toBe(0)
            ->and($run?->processed_count)->toBe(0)
            ->and($run?->success_count)->toBe(0)
            ->and($run?->failed_count)->toBe(0)
            ->and($run?->skipped_count)->toBe(0)
            ->and($playbook->fresh()?->next_run_at?->copy()->timezone('America/Toronto')->format('Y-m-d H:i'))->toBe('2026-04-27 09:00');
    } finally {
        Carbon::setTestNow();
    }
});

it('prevents overlapping scheduled runs for the same playbook', function () {
    $owner = playbookSchedulerOwner(['requests' => true]);
    $segment = SavedSegment::create([
        'user_id' => $owner->id,
        'module' => SavedSegment::MODULE_REQUEST,
        'name' => 'Overlap segment',
    ]);

    $scheduledAt = Carbon::create(2026, 4, 20, 13, 0, 0, 'UTC');
    $referenceTime = Carbon::create(2026, 4, 20, 13, 15, 0, 'UTC');

    try {
        Carbon::setTestNow($referenceTime);

        $playbook = Playbook::create([
            'user_id' => $owner->id,
            'saved_segment_id' => $segment->id,
            'created_by_user_id' => $owner->id,
            'updated_by_user_id' => $owner->id,
            'module' => SavedSegment::MODULE_REQUEST,
            'name' => 'Overlap scheduler playbook',
            'action_key' => 'update_status',
            'action_payload' => [
                'status' => LeadRequest::STATUS_CONTACTED,
            ],
            'schedule_type' => Playbook::SCHEDULE_DAILY,
            'schedule_timezone' => 'America/Toronto',
            'schedule_time' => '09:00',
            'next_run_at' => $scheduledAt,
            'is_active' => true,
        ]);

        PlaybookRun::create([
            'user_id' => $owner->id,
            'playbook_id' => $playbook->id,
            'saved_segment_id' => $segment->id,
            'requested_by_user_id' => $owner->id,
            'module' => SavedSegment::MODULE_REQUEST,
            'action_key' => 'update_status',
            'origin' => PlaybookRun::ORIGIN_SCHEDULED,
            'status' => PlaybookRun::STATUS_RUNNING,
            'scheduled_for' => $scheduledAt,
            'started_at' => $referenceTime->copy()->subMinute(),
        ]);

        $summary = app(PlaybookSchedulerService::class)->runDue(null, $referenceTime);

        expect($summary['checked_count'])->toBe(1)
            ->and($summary['reserved_count'])->toBe(0)
            ->and($summary['executed_count'])->toBe(0)
            ->and($summary['failed_count'])->toBe(0)
            ->and($summary['skipped_overlap_count'])->toBe(1)
            ->and(PlaybookRun::query()->count())->toBe(1)
            ->and($playbook->fresh()?->next_run_at?->copy()->timezone('America/Toronto')->format('Y-m-d H:i'))->toBe('2026-04-20 09:00');
    } finally {
        Carbon::setTestNow();
    }
});

it('marks a due scheduled request playbook as failed when the requests feature becomes unavailable', function () {
    $owner = playbookSchedulerOwner(['requests' => true]);
    $customer = playbookSchedulerCustomer($owner, 'Feature Gate Scheduler');

    $lead = LeadRequest::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_NEW,
        'title' => 'Feature gated scheduler lead',
        'service_type' => 'Cleaning',
    ]);

    $segment = SavedSegment::create([
        'user_id' => $owner->id,
        'module' => SavedSegment::MODULE_REQUEST,
        'name' => 'Feature gated scheduler segment',
        'search_term' => 'Feature gated scheduler',
    ]);

    $scheduledAt = Carbon::create(2026, 4, 20, 13, 0, 0, 'UTC');
    $referenceTime = Carbon::create(2026, 4, 20, 13, 18, 0, 'UTC');

    try {
        Carbon::setTestNow($referenceTime);

        $playbook = Playbook::create([
            'user_id' => $owner->id,
            'saved_segment_id' => $segment->id,
            'created_by_user_id' => $owner->id,
            'updated_by_user_id' => $owner->id,
            'module' => SavedSegment::MODULE_REQUEST,
            'name' => 'Feature gated scheduler playbook',
            'action_key' => 'update_status',
            'action_payload' => [
                'status' => LeadRequest::STATUS_CONTACTED,
            ],
            'schedule_type' => Playbook::SCHEDULE_DAILY,
            'schedule_timezone' => 'America/Toronto',
            'schedule_time' => '09:00',
            'next_run_at' => $scheduledAt,
            'is_active' => true,
        ]);

        $owner->forceFill([
            'company_features' => array_merge((array) ($owner->company_features ?? []), [
                'requests' => false,
            ]),
        ])->save();

        $summary = app(PlaybookSchedulerService::class)->runDue(null, $referenceTime);
        $run = PlaybookRun::query()->latest('id')->first();

        expect($summary['checked_count'])->toBe(1)
            ->and($summary['reserved_count'])->toBe(1)
            ->and($summary['executed_count'])->toBe(0)
            ->and($summary['failed_count'])->toBe(1)
            ->and($run)->not->toBeNull()
            ->and($run?->origin)->toBe(PlaybookRun::ORIGIN_SCHEDULED)
            ->and($run?->status)->toBe(PlaybookRun::STATUS_FAILED)
            ->and($run?->summary['message'] ?? null)->toBe('Requests module is unavailable for this account.')
            ->and($lead->fresh()?->status)->toBe(LeadRequest::STATUS_NEW)
            ->and($playbook->fresh()?->next_run_at?->copy()->timezone('America/Toronto')->format('Y-m-d H:i'))->toBe('2026-04-21 09:00');
    } finally {
        Carbon::setTestNow();
    }
});

it('runs scheduled playbooks from the artisan command with account scoping', function () {
    $owner = playbookSchedulerOwner(['requests' => true]);
    $otherOwner = playbookSchedulerOwner(['requests' => true]);
    $assignee = TeamMember::factory()->create([
        'account_id' => $owner->id,
    ]);
    $customer = playbookSchedulerCustomer($owner, 'Command Scheduler');
    $otherCustomer = playbookSchedulerCustomer($otherOwner, 'Other Command Scheduler');

    $targetLead = LeadRequest::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_NEW,
        'title' => 'Command scheduler target',
        'service_type' => 'Cleaning',
    ]);

    $otherLead = LeadRequest::create([
        'user_id' => $otherOwner->id,
        'customer_id' => $otherCustomer->id,
        'status' => LeadRequest::STATUS_NEW,
        'title' => 'Other command scheduler target',
        'service_type' => 'Cleaning',
    ]);

    $segment = SavedSegment::create([
        'user_id' => $owner->id,
        'module' => SavedSegment::MODULE_REQUEST,
        'name' => 'Command segment',
        'search_term' => 'Command scheduler target',
    ]);

    $otherSegment = SavedSegment::create([
        'user_id' => $otherOwner->id,
        'module' => SavedSegment::MODULE_REQUEST,
        'name' => 'Other command segment',
        'search_term' => 'Other command scheduler target',
    ]);

    $scheduledAt = Carbon::create(2026, 4, 20, 13, 0, 0, 'UTC');
    $referenceTime = Carbon::create(2026, 4, 20, 13, 20, 0, 'UTC');

    try {
        Carbon::setTestNow($referenceTime);

        Playbook::create([
            'user_id' => $owner->id,
            'saved_segment_id' => $segment->id,
            'created_by_user_id' => $owner->id,
            'updated_by_user_id' => $owner->id,
            'module' => SavedSegment::MODULE_REQUEST,
            'name' => 'Command scoped playbook',
            'action_key' => 'assign_selected',
            'action_payload' => [
                'assigned_team_member_id' => $assignee->id,
            ],
            'schedule_type' => Playbook::SCHEDULE_DAILY,
            'schedule_timezone' => 'America/Toronto',
            'schedule_time' => '09:00',
            'next_run_at' => $scheduledAt,
            'is_active' => true,
        ]);

        Playbook::create([
            'user_id' => $otherOwner->id,
            'saved_segment_id' => $otherSegment->id,
            'created_by_user_id' => $otherOwner->id,
            'updated_by_user_id' => $otherOwner->id,
            'module' => SavedSegment::MODULE_REQUEST,
            'name' => 'Other command scoped playbook',
            'action_key' => 'update_status',
            'action_payload' => [
                'status' => LeadRequest::STATUS_CONTACTED,
            ],
            'schedule_type' => Playbook::SCHEDULE_DAILY,
            'schedule_timezone' => 'America/Toronto',
            'schedule_time' => '09:00',
            'next_run_at' => $scheduledAt,
            'is_active' => true,
        ]);

        Artisan::call('playbooks:run-scheduled', [
            '--account_id' => $owner->id,
        ]);

        expect(Artisan::output())->toContain('Checked 1 playbook(s); reserved 1; executed 1; failed 0; overlap skips 0.')
            ->and($targetLead->fresh()?->assigned_team_member_id)->toBe($assignee->id)
            ->and($otherLead->fresh()?->status)->toBe(LeadRequest::STATUS_NEW)
            ->and(PlaybookRun::query()->where('origin', PlaybookRun::ORIGIN_SCHEDULED)->count())->toBe(1);
    } finally {
        Carbon::setTestNow();
    }
});
