<?php

use App\Models\ActivityLog;
use App\Models\Request as LeadRequest;
use App\Models\Task;
use App\Models\TeamMember;
use App\Models\User;
use App\Notifications\ProspectFollowUpReminderNotification;
use App\Services\ProspectFollowUpReminderService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
});

test('prospects follow-up reminders command notifies due today and overdue prospect tasks', function () {
    $owner = User::factory()->create([
        'company_features' => [
            'requests' => true,
            'tasks' => true,
        ],
        'company_timezone' => 'America/Toronto',
        'locale' => 'en',
    ]);

    $taskAssigneeUser = User::factory()->create([
        'locale' => 'en',
    ]);
    $prospectAssigneeUser = User::factory()->create([
        'locale' => 'fr',
    ]);

    $taskAssignee = TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $taskAssigneeUser->id,
        'is_active' => true,
    ]);
    $prospectAssignee = TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $prospectAssigneeUser->id,
        'is_active' => true,
    ]);

    $lead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'assigned_team_member_id' => $prospectAssignee->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Reminder prospect',
        'contact_name' => 'Reminder Prospect',
        'contact_email' => 'reminder.prospect@example.com',
    ]);

    $dueTodayTask = Task::query()->create([
        'account_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'assigned_team_member_id' => $taskAssignee->id,
        'request_id' => $lead->id,
        'title' => 'Call back today',
        'status' => Task::STATUS_TODO,
        'priority' => Task::PRIORITY_HIGH,
        'due_date' => '2026-04-25',
    ]);

    $overdueTask = Task::query()->create([
        'account_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'request_id' => $lead->id,
        'title' => 'Send overdue recap',
        'status' => Task::STATUS_IN_PROGRESS,
        'priority' => Task::PRIORITY_URGENT,
        'due_date' => '2026-04-24',
    ]);

    $closedTask = Task::query()->create([
        'account_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'request_id' => $lead->id,
        'title' => 'Already done follow-up',
        'status' => Task::STATUS_DONE,
        'priority' => Task::PRIORITY_NORMAL,
        'due_date' => '2026-04-25',
    ]);

    $nonProspectTask = Task::query()->create([
        'account_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'title' => 'Internal standalone task',
        'status' => Task::STATUS_TODO,
        'priority' => Task::PRIORITY_NORMAL,
        'due_date' => '2026-04-25',
    ]);

    $archivedLead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Archived prospect',
        'contact_name' => 'Archived Prospect',
        'archived_at' => Carbon::parse('2026-04-24 18:00:00'),
    ]);

    $archivedLeadTask = Task::query()->create([
        'account_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'request_id' => $archivedLead->id,
        'title' => 'Archived follow-up',
        'status' => Task::STATUS_TODO,
        'priority' => Task::PRIORITY_HIGH,
        'due_date' => '2026-04-25',
    ]);

    $this->artisan('prospects:follow-up-reminders', [
        '--date' => '2026-04-25 09:00:00',
    ])
        ->assertExitCode(0);

    Notification::assertSentTo($owner, ProspectFollowUpReminderNotification::class, function (ProspectFollowUpReminderNotification $notification) use ($dueTodayTask) {
        return $notification->task->is($dueTodayTask)
            && $notification->type === ProspectFollowUpReminderNotification::TYPE_DUE_TODAY;
    });

    Notification::assertSentTo($owner, ProspectFollowUpReminderNotification::class, function (ProspectFollowUpReminderNotification $notification) use ($overdueTask) {
        return $notification->task->is($overdueTask)
            && $notification->type === ProspectFollowUpReminderNotification::TYPE_OVERDUE;
    });

    Notification::assertSentTo($taskAssigneeUser, ProspectFollowUpReminderNotification::class, function (ProspectFollowUpReminderNotification $notification) use ($dueTodayTask) {
        return $notification->task->is($dueTodayTask)
            && $notification->type === ProspectFollowUpReminderNotification::TYPE_DUE_TODAY;
    });

    Notification::assertSentTo($prospectAssigneeUser, ProspectFollowUpReminderNotification::class, function (ProspectFollowUpReminderNotification $notification) use ($overdueTask) {
        return $notification->task->is($overdueTask)
            && $notification->type === ProspectFollowUpReminderNotification::TYPE_OVERDUE;
    });

    Notification::assertNotSentTo($owner, ProspectFollowUpReminderNotification::class, function (ProspectFollowUpReminderNotification $notification) use ($closedTask, $nonProspectTask, $archivedLeadTask) {
        return $notification->task->is($closedTask)
            || $notification->task->is($nonProspectTask)
            || $notification->task->is($archivedLeadTask);
    });

    $this->assertDatabaseHas('activity_logs', [
        'subject_type' => $dueTodayTask->getMorphClass(),
        'subject_id' => $dueTodayTask->id,
        'action' => ProspectFollowUpReminderService::ACTION_DUE_TODAY,
        'description' => 'Prospect follow-up due today reminder sent',
    ]);

    $this->assertDatabaseHas('activity_logs', [
        'subject_type' => $lead->getMorphClass(),
        'subject_id' => $lead->id,
        'action' => ProspectFollowUpReminderService::ACTION_OVERDUE,
        'description' => 'Prospect follow-up overdue reminder sent',
    ]);
});

test('prospects follow-up reminders command does not send duplicates on the same local day', function () {
    $owner = User::factory()->create([
        'company_features' => [
            'requests' => true,
            'tasks' => true,
        ],
        'company_timezone' => 'America/Toronto',
    ]);

    $lead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Duplicate reminder prospect',
        'contact_name' => 'Duplicate Reminder Prospect',
    ]);

    $task = Task::query()->create([
        'account_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'request_id' => $lead->id,
        'title' => 'Morning follow-up',
        'status' => Task::STATUS_TODO,
        'priority' => Task::PRIORITY_NORMAL,
        'due_date' => '2026-04-25',
    ]);

    $this->artisan('prospects:follow-up-reminders', [
        '--date' => '2026-04-25 08:00:00',
    ])->assertExitCode(0);

    $this->artisan('prospects:follow-up-reminders', [
        '--date' => '2026-04-25 14:00:00',
    ])
        ->assertExitCode(0);

    expect(ActivityLog::query()
        ->where('subject_type', $task->getMorphClass())
        ->where('subject_id', $task->id)
        ->where('action', ProspectFollowUpReminderService::ACTION_DUE_TODAY)
        ->count())->toBe(1);
});
