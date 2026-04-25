<?php

use App\Models\Request as LeadRequest;
use App\Models\TeamMember;
use App\Models\User;
use App\Notifications\ProspectLifecycleNotification;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->withoutMiddleware(ValidateCsrfToken::class);
});

function prospectNotificationOwner(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'company_type' => 'services',
        'company_features' => [
            'requests' => true,
            'quotes' => true,
            'team_members' => true,
        ],
        'onboarding_completed_at' => now(),
    ], $attributes));
}

function prospectNotificationMember(User $owner, string $role, array $permissions, array $userAttributes = []): array
{
    $user = User::factory()->create(array_merge([
        'company_type' => 'services',
        'onboarding_completed_at' => now(),
    ], $userAttributes));

    $member = TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $user->id,
        'role' => $role,
        'permissions' => $permissions,
        'is_active' => true,
    ]);

    return [$user, $member];
}

it('notifies owners when a sales manager creates a new prospect and notifies the newly assigned teammate', function () {
    $owner = prospectNotificationOwner();
    [$managerUser] = prospectNotificationMember($owner, 'sales_manager', ['sales.manage', 'quotes.view']);
    [$assigneeEnabledUser, $assigneeEnabled] = prospectNotificationMember($owner, 'sales_manager', ['quotes.view']);
    [$assigneeDisabledUser, $assigneeDisabled] = prospectNotificationMember($owner, 'sales_manager', ['quotes.view'], [
        'notification_settings' => [
            'channels' => ['in_app' => true, 'push' => true],
            'categories' => ['crm' => false],
        ],
    ]);

    $response = $this->actingAs($managerUser)->postJson(route('prospects.store'), [
        'channel' => 'phone',
        'title' => 'Manager-created prospect',
        'service_type' => 'Inspection',
        'contact_name' => 'Alex Prospect',
        'contact_email' => 'alex.prospect@example.com',
        'contact_phone' => '+1 514 555 0199',
        'meta' => [
            'request_type' => 'manager_entry',
        ],
    ])->assertCreated();

    $lead = LeadRequest::query()->findOrFail((int) $response->json('request.id'));

    Notification::assertSentTo($owner, ProspectLifecycleNotification::class, function (ProspectLifecycleNotification $notification) use ($lead) {
        return $notification->lead->is($lead)
            && $notification->event === ProspectLifecycleNotification::EVENT_CREATED;
    });

    Notification::assertNotSentTo($managerUser, ProspectLifecycleNotification::class);

    $this->actingAs($managerUser)->putJson(route('prospects.update', $lead), [
        'assigned_team_member_id' => $assigneeEnabled->id,
    ])->assertOk();

    Notification::assertSentTo($assigneeEnabledUser, ProspectLifecycleNotification::class, function (ProspectLifecycleNotification $notification) use ($lead) {
        return $notification->lead->is($lead)
            && $notification->event === ProspectLifecycleNotification::EVENT_ASSIGNED;
    });

    $this->actingAs($managerUser)->putJson(route('prospects.update', $lead), [
        'assigned_team_member_id' => $assigneeDisabled->id,
    ])->assertOk();

    Notification::assertNotSentTo($assigneeDisabledUser, ProspectLifecycleNotification::class, function (ProspectLifecycleNotification $notification) use ($lead) {
        return $notification->lead->is($lead)
            && $notification->event === ProspectLifecycleNotification::EVENT_ASSIGNED;
    });
});

it('notifies stakeholders when a manager marks a prospect as lost or converts it to a customer', function () {
    $owner = prospectNotificationOwner();
    [$managerUser] = prospectNotificationMember($owner, 'sales_manager', ['sales.manage', 'quotes.view']);
    [$assigneeUser, $assignee] = prospectNotificationMember($owner, 'sales_manager', ['quotes.view']);

    $lostLead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'assigned_team_member_id' => $assignee->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'status_updated_at' => now(),
        'last_activity_at' => now(),
        'title' => 'Lost notification prospect',
        'contact_name' => 'Lost Prospect',
        'contact_email' => 'lost@example.com',
    ]);

    $convertLead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'assigned_team_member_id' => $assignee->id,
        'status' => LeadRequest::STATUS_QUALIFIED,
        'status_updated_at' => now(),
        'last_activity_at' => now(),
        'title' => 'Converted notification prospect',
        'contact_name' => 'Converted Prospect',
        'contact_email' => 'converted@example.com',
        'contact_phone' => '+1 438 555 0101',
        'meta' => [
            'company_name' => 'Converted Prospect Inc.',
        ],
    ]);

    $this->actingAs($managerUser)->putJson(route('prospects.update', $lostLead), [
        'status' => LeadRequest::STATUS_LOST,
        'lost_reason' => 'budget',
    ])->assertOk();

    Notification::assertSentTo($owner, ProspectLifecycleNotification::class, function (ProspectLifecycleNotification $notification) use ($lostLead) {
        return $notification->lead->is($lostLead)
            && $notification->event === ProspectLifecycleNotification::EVENT_LOST;
    });

    Notification::assertSentTo($assigneeUser, ProspectLifecycleNotification::class, function (ProspectLifecycleNotification $notification) use ($lostLead) {
        return $notification->lead->is($lostLead)
            && $notification->event === ProspectLifecycleNotification::EVENT_LOST;
    });

    $this->actingAs($managerUser)->postJson(route('prospects.convert-customer', $convertLead), [
        'mode' => 'create_new',
        'contact_name' => 'Converted Prospect',
        'contact_email' => 'converted@example.com',
        'contact_phone' => '+1 438 555 0101',
        'company_name' => 'Converted Prospect Inc.',
    ])->assertOk();

    $convertLead->refresh();

    Notification::assertSentTo($owner, ProspectLifecycleNotification::class, function (ProspectLifecycleNotification $notification) use ($convertLead) {
        return $notification->lead->is($convertLead)
            && $notification->event === ProspectLifecycleNotification::EVENT_CONVERTED;
    });

    Notification::assertSentTo($assigneeUser, ProspectLifecycleNotification::class, function (ProspectLifecycleNotification $notification) use ($convertLead) {
        return $notification->lead->is($convertLead)
            && $notification->event === ProspectLifecycleNotification::EVENT_CONVERTED;
    });

    Notification::assertNotSentTo($managerUser, ProspectLifecycleNotification::class);
});
