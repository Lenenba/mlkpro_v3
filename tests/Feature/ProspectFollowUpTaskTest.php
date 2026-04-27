<?php

use App\Models\Request as LeadRequest;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Carbon;

function prospectFollowUpOwner(array $attributes = []): User
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

it('creates a prospect follow-up task with priority and exposes it on the prospect detail payload', function () {
    $owner = prospectFollowUpOwner();

    $lead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'status_updated_at' => now(),
        'last_activity_at' => now(),
        'title' => 'Follow-up creation prospect',
        'contact_name' => 'Follow-up Alpha',
        'contact_email' => 'follow-up.alpha@example.com',
    ]);

    $this->actingAs($owner)
        ->postJson(route('task.store'), [
            'standalone' => true,
            'title' => 'Call back after sending brochure',
            'description' => 'Priority follow-up task',
            'status' => Task::STATUS_TODO,
            'priority' => Task::PRIORITY_URGENT,
            'due_date' => '2026-04-25',
            'request_id' => $lead->id,
        ])
        ->assertCreated()
        ->assertJsonPath('task.priority', Task::PRIORITY_URGENT)
        ->assertJsonPath('task.request_id', $lead->id)
        ->assertJsonPath('task.request.id', $lead->id);

    $this->actingAs($owner)
        ->getJson(route('prospects.show', $lead))
        ->assertOk()
        ->assertJsonPath('lead.tasks.0.title', 'Call back after sending brochure')
        ->assertJsonPath('lead.tasks.0.priority', Task::PRIORITY_URGENT)
        ->assertJsonPath('lead.tasks.0.request_id', $lead->id);
});

it('relinks an existing task to a prospect and keeps the selected follow-up priority', function () {
    $owner = prospectFollowUpOwner();

    $lead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'status_updated_at' => now(),
        'last_activity_at' => now(),
        'title' => 'Relink target prospect',
        'contact_name' => 'Follow-up Bravo',
        'contact_email' => 'follow-up.bravo@example.com',
    ]);

    $task = Task::query()->create([
        'account_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'title' => 'Existing admin task',
        'status' => Task::STATUS_TODO,
        'priority' => Task::PRIORITY_NORMAL,
        'due_date' => '2026-04-25',
    ]);

    $this->actingAs($owner)
        ->putJson(route('task.update', $task), [
            'standalone' => true,
            'title' => 'Existing admin task',
            'description' => 'Now linked to the prospect pipeline.',
            'status' => Task::STATUS_TODO,
            'priority' => Task::PRIORITY_HIGH,
            'due_date' => '2026-04-25',
            'request_id' => $lead->id,
        ])
        ->assertOk()
        ->assertJsonPath('task.priority', Task::PRIORITY_HIGH)
        ->assertJsonPath('task.request_id', $lead->id)
        ->assertJsonPath('task.request.id', $lead->id);

    $task->refresh();

    expect($task->request_id)->toBe($lead->id)
        ->and($task->priority)->toBe(Task::PRIORITY_HIGH);
});

it('filters the task index by priority and follow-up state for prospect-linked work', function () {
    Carbon::setTestNow(Carbon::parse('2026-04-25 09:00:00'));

    try {
        $owner = prospectFollowUpOwner([
            'company_timezone' => 'America/Toronto',
        ]);

        $lead = LeadRequest::query()->create([
            'user_id' => $owner->id,
            'status' => LeadRequest::STATUS_CONTACTED,
            'status_updated_at' => now(),
            'last_activity_at' => now(),
            'title' => 'Index filter prospect',
            'contact_name' => 'Follow-up Charlie',
            'contact_email' => 'follow-up.charlie@example.com',
        ]);

        $urgentToday = Task::query()->create([
            'account_id' => $owner->id,
            'created_by_user_id' => $owner->id,
            'request_id' => $lead->id,
            'title' => 'Urgent task for today',
            'status' => Task::STATUS_TODO,
            'priority' => Task::PRIORITY_URGENT,
            'due_date' => '2026-04-25',
        ]);

        Task::query()->create([
            'account_id' => $owner->id,
            'created_by_user_id' => $owner->id,
            'request_id' => $lead->id,
            'title' => 'Overdue open task',
            'status' => Task::STATUS_IN_PROGRESS,
            'priority' => Task::PRIORITY_HIGH,
            'due_date' => '2026-04-24',
        ]);

        Task::query()->create([
            'account_id' => $owner->id,
            'created_by_user_id' => $owner->id,
            'request_id' => $lead->id,
            'title' => 'Closed overdue task',
            'status' => Task::STATUS_DONE,
            'priority' => Task::PRIORITY_URGENT,
            'due_date' => '2026-04-23',
        ]);

        $this->actingAs($owner)
            ->getJson(route('task.index', [
                'priority' => Task::PRIORITY_URGENT,
                'follow_up' => 'today',
            ]))
            ->assertOk()
            ->assertJsonPath('filters.priority', Task::PRIORITY_URGENT)
            ->assertJsonPath('filters.follow_up', 'today')
            ->assertJsonCount(1, 'tasks.data')
            ->assertJsonPath('tasks.data.0.id', $urgentToday->id)
            ->assertJsonPath('tasks.data.0.request.id', $lead->id)
            ->assertJsonPath('tasks.data.0.request.title', 'Index filter prospect');

        $this->actingAs($owner)
            ->getJson(route('task.index', [
                'follow_up' => 'overdue',
            ]))
            ->assertOk()
            ->assertJsonPath('filters.follow_up', 'overdue')
            ->assertJsonCount(1, 'tasks.data')
            ->assertJsonPath('tasks.data.0.title', 'Overdue open task');
    } finally {
        Carbon::setTestNow();
    }
});
