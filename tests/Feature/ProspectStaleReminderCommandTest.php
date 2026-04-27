<?php

use App\Models\ActivityLog;
use App\Models\Request as LeadRequest;
use App\Models\TeamMember;
use App\Models\User;
use App\Notifications\ProspectStaleReminderNotification;
use App\Services\ProspectStaleReminderService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
});

it('notifies owners and assignees about stale prospects once per local day', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_features' => [
            'requests' => true,
            'team_members' => true,
        ],
        'company_timezone' => 'America/Toronto',
        'onboarding_completed_at' => now(),
    ]);

    $assigneeUser = User::factory()->create([
        'company_type' => 'services',
        'onboarding_completed_at' => now(),
    ]);
    $disabledAssigneeUser = User::factory()->create([
        'company_type' => 'services',
        'onboarding_completed_at' => now(),
        'notification_settings' => [
            'channels' => ['in_app' => true, 'push' => true],
            'categories' => ['crm' => false],
        ],
    ]);

    $assignee = TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $assigneeUser->id,
        'role' => 'sales_manager',
        'permissions' => ['quotes.view'],
        'is_active' => true,
    ]);
    $disabledAssignee = TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $disabledAssigneeUser->id,
        'role' => 'sales_manager',
        'permissions' => ['quotes.view'],
        'is_active' => true,
    ]);

    $staleLead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'assigned_team_member_id' => $assignee->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'status_updated_at' => Carbon::parse('2026-04-17 09:00:00'),
        'last_activity_at' => Carbon::parse('2026-04-17 09:00:00'),
        'title' => 'Stale lead',
        'contact_name' => 'Stale Lead',
        'contact_email' => 'stale@example.com',
    ]);

    $disabledLead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'assigned_team_member_id' => $disabledAssignee->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'status_updated_at' => Carbon::parse('2026-04-16 10:00:00'),
        'last_activity_at' => Carbon::parse('2026-04-16 10:00:00'),
        'title' => 'Disabled stale lead',
        'contact_name' => 'Disabled Stale Lead',
        'contact_email' => 'disabled-stale@example.com',
    ]);

    $freshLead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'status_updated_at' => Carbon::parse('2026-04-24 10:00:00'),
        'last_activity_at' => Carbon::parse('2026-04-24 10:00:00'),
        'title' => 'Fresh lead',
        'contact_name' => 'Fresh Lead',
        'contact_email' => 'fresh@example.com',
    ]);

    $lostLead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_LOST,
        'status_updated_at' => Carbon::parse('2026-04-16 10:00:00'),
        'last_activity_at' => Carbon::parse('2026-04-16 10:00:00'),
        'title' => 'Lost lead',
        'contact_name' => 'Lost Lead',
        'contact_email' => 'lost@example.com',
    ]);

    $archivedLead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'status_updated_at' => Carbon::parse('2026-04-16 10:00:00'),
        'last_activity_at' => Carbon::parse('2026-04-16 10:00:00'),
        'archived_at' => Carbon::parse('2026-04-24 18:00:00'),
        'title' => 'Archived lead',
        'contact_name' => 'Archived Lead',
        'contact_email' => 'archived@example.com',
    ]);

    $this->artisan('prospects:stale-reminders', [
        '--date' => '2026-04-25 10:00:00',
    ])->assertExitCode(0);

    Notification::assertSentTo($owner, ProspectStaleReminderNotification::class, function (ProspectStaleReminderNotification $notification) use ($staleLead) {
        return $notification->lead->is($staleLead);
    });

    Notification::assertSentTo($assigneeUser, ProspectStaleReminderNotification::class, function (ProspectStaleReminderNotification $notification) use ($staleLead) {
        return $notification->lead->is($staleLead);
    });

    Notification::assertNotSentTo($disabledAssigneeUser, ProspectStaleReminderNotification::class, function (ProspectStaleReminderNotification $notification) use ($disabledLead) {
        return $notification->lead->is($disabledLead);
    });

    Notification::assertNotSentTo($owner, ProspectStaleReminderNotification::class, function (ProspectStaleReminderNotification $notification) use ($freshLead, $lostLead, $archivedLead) {
        return $notification->lead->is($freshLead)
            || $notification->lead->is($lostLead)
            || $notification->lead->is($archivedLead);
    });

    $this->assertDatabaseHas('activity_logs', [
        'subject_type' => $staleLead->getMorphClass(),
        'subject_id' => $staleLead->id,
        'action' => ProspectStaleReminderService::ACTION_STALE,
        'description' => 'Prospect stale reminder sent',
    ]);

    $this->artisan('prospects:stale-reminders', [
        '--date' => '2026-04-25 14:00:00',
    ])->assertExitCode(0);

    expect(ActivityLog::query()
        ->where('subject_type', $staleLead->getMorphClass())
        ->where('subject_id', $staleLead->id)
        ->where('action', ProspectStaleReminderService::ACTION_STALE)
        ->count())->toBe(1);
});
