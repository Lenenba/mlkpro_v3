<?php

use App\Models\ActivityLog;
use App\Models\ProspectStatusHistory;
use App\Models\Request as LeadRequest;
use App\Models\Task;
use App\Models\TaskStatusHistory;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

function prospectLossOwner(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'company_type' => 'services',
        'company_features' => [
            'requests' => true,
            'tasks' => true,
        ],
        'onboarding_completed_at' => now(),
    ], $attributes));
}

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
});

it('marks a prospect as lost with a structured reason, comment, and optional task closure', function () {
    $owner = prospectLossOwner();

    $lead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'status_updated_at' => now(),
        'last_activity_at' => now(),
        'next_follow_up_at' => now()->addDays(3),
        'title' => 'Loss flow prospect',
        'contact_name' => 'Loss Flow',
        'contact_email' => 'loss.flow@example.com',
    ]);

    $openTask = Task::query()->create([
        'account_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'request_id' => $lead->id,
        'title' => 'Call the prospect back',
        'status' => Task::STATUS_TODO,
        'due_date' => now()->toDateString(),
    ]);

    $closedTask = Task::query()->create([
        'account_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'request_id' => $lead->id,
        'title' => 'Already done',
        'status' => Task::STATUS_DONE,
        'due_date' => now()->toDateString(),
        'completed_at' => now(),
    ]);

    $this->actingAs($owner)->putJson(route('prospects.update', $lead), [
        'status' => LeadRequest::STATUS_LOST,
        'lost_reason' => 'budget',
        'lost_comment' => 'Budget frozen until next quarter.',
        'close_open_tasks' => true,
    ])->assertOk()
        ->assertJsonPath('request.status', LeadRequest::STATUS_LOST)
        ->assertJsonPath('request.lost_reason', 'budget');

    $lead->refresh();
    $openTask->refresh();
    $closedTask->refresh();

    $history = ProspectStatusHistory::query()
        ->where('request_id', $lead->id)
        ->latest('id')
        ->first();
    $taskHistory = TaskStatusHistory::query()
        ->where('task_id', $openTask->id)
        ->latest('id')
        ->first();
    $activity = ActivityLog::query()
        ->where('subject_type', $lead->getMorphClass())
        ->where('subject_id', $lead->id)
        ->where('action', 'updated')
        ->latest('id')
        ->first();

    expect($lead->status)->toBe(LeadRequest::STATUS_LOST)
        ->and($lead->lost_reason)->toBe('budget')
        ->and($lead->next_follow_up_at)->toBeNull()
        ->and(data_get($lead->meta, 'loss.code'))->toBe('budget')
        ->and(data_get($lead->meta, 'loss.comment'))->toBe('Budget frozen until next quarter.')
        ->and((int) data_get($lead->meta, 'loss.lost_by_user_id'))->toBe($owner->id)
        ->and($history)->not->toBeNull()
        ->and($history?->comment)->toBe('Budget frozen until next quarter.')
        ->and(data_get($history?->metadata, 'source'))->toBe('manual_status_change')
        ->and(data_get($history?->metadata, 'lost_reason'))->toBe('budget')
        ->and(data_get($history?->metadata, 'lost_comment'))->toBe('Budget frozen until next quarter.')
        ->and((int) data_get($history?->metadata, 'closed_open_task_count'))->toBe(1)
        ->and($activity)->not->toBeNull()
        ->and($activity?->description)->toBe('Prospect marked as lost')
        ->and(data_get($activity?->properties, 'lost_reason'))->toBe('budget')
        ->and(data_get($activity?->properties, 'lost_comment'))->toBe('Budget frozen until next quarter.')
        ->and((int) data_get($activity?->properties, 'closed_open_task_count'))->toBe(1)
        ->and($openTask->status)->toBe(Task::STATUS_CANCELLED)
        ->and($openTask->cancelled_at)->not->toBeNull()
        ->and($openTask->cancellation_reason)->toContain('Prospect marked as lost')
        ->and($taskHistory)->not->toBeNull()
        ->and($taskHistory?->action)->toBe('cancelled')
        ->and($taskHistory?->to_status)->toBe(Task::STATUS_CANCELLED)
        ->and(data_get($taskHistory?->metadata, 'source'))->toBe('prospect_lost')
        ->and($closedTask->status)->toBe(Task::STATUS_DONE);
});

it('rejects unknown lost reason codes', function () {
    $owner = prospectLossOwner();

    $lead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_NEW,
        'status_updated_at' => now(),
        'last_activity_at' => now(),
        'title' => 'Validation prospect',
    ]);

    $this->actingAs($owner)->putJson(route('prospects.update', $lead), [
        'status' => LeadRequest::STATUS_LOST,
        'lost_reason' => 'free-text-reason',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('lost_reason');
});

it('bulk marks prospects as lost and can close their open tasks', function () {
    $owner = prospectLossOwner();

    $firstLead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'status_updated_at' => now(),
        'last_activity_at' => now(),
        'next_follow_up_at' => now()->addDay(),
        'title' => 'Bulk lost one',
    ]);

    $secondLead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_QUALIFIED,
        'status_updated_at' => now(),
        'last_activity_at' => now(),
        'next_follow_up_at' => now()->addDays(2),
        'title' => 'Bulk lost two',
    ]);

    $firstTask = Task::query()->create([
        'account_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'request_id' => $firstLead->id,
        'title' => 'Bulk follow-up one',
        'status' => Task::STATUS_TODO,
        'due_date' => now()->toDateString(),
    ]);

    $secondTask = Task::query()->create([
        'account_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'request_id' => $secondLead->id,
        'title' => 'Bulk follow-up two',
        'status' => Task::STATUS_IN_PROGRESS,
        'due_date' => now()->toDateString(),
    ]);

    $this->actingAs($owner)->patchJson(route('prospects.bulk'), [
        'ids' => [$firstLead->id, $secondLead->id],
        'status' => LeadRequest::STATUS_LOST,
        'lost_reason' => 'duplicate',
        'lost_comment' => 'Merged into the main opportunity.',
        'close_open_tasks' => true,
    ])->assertOk()
        ->assertJsonPath('message', 'Prospects updated.');

    $firstLead->refresh();
    $secondLead->refresh();
    $firstTask->refresh();
    $secondTask->refresh();

    $firstHistory = ProspectStatusHistory::query()
        ->where('request_id', $firstLead->id)
        ->latest('id')
        ->first();
    $secondHistory = ProspectStatusHistory::query()
        ->where('request_id', $secondLead->id)
        ->latest('id')
        ->first();

    expect($firstLead->status)->toBe(LeadRequest::STATUS_LOST)
        ->and($secondLead->status)->toBe(LeadRequest::STATUS_LOST)
        ->and($firstLead->lost_reason)->toBe('duplicate')
        ->and($secondLead->lost_reason)->toBe('duplicate')
        ->and(data_get($firstLead->meta, 'loss.comment'))->toBe('Merged into the main opportunity.')
        ->and(data_get($secondLead->meta, 'loss.comment'))->toBe('Merged into the main opportunity.')
        ->and($firstTask->status)->toBe(Task::STATUS_CANCELLED)
        ->and($secondTask->status)->toBe(Task::STATUS_CANCELLED)
        ->and(data_get($firstHistory?->metadata, 'source'))->toBe('bulk_update')
        ->and(data_get($secondHistory?->metadata, 'source'))->toBe('bulk_update')
        ->and((int) data_get($firstHistory?->metadata, 'closed_open_task_count'))->toBe(1)
        ->and((int) data_get($secondHistory?->metadata, 'closed_open_task_count'))->toBe(1);

    $this->assertDatabaseHas('task_status_histories', [
        'task_id' => $firstTask->id,
        'action' => 'cancelled',
        'to_status' => Task::STATUS_CANCELLED,
    ]);

    $this->assertDatabaseHas('task_status_histories', [
        'task_id' => $secondTask->id,
        'action' => 'cancelled',
        'to_status' => Task::STATUS_CANCELLED,
    ]);
});
