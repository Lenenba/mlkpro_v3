<?php

use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

function taskIndexScaleOwner(array $attributes = []): User
{
    return User::factory()->create(array_replace_recursive([
        'company_type' => 'services',
        'company_timezone' => 'America/Toronto',
        'company_features' => [
            'tasks' => true,
        ],
        'onboarding_completed_at' => now(),
    ], $attributes));
}

/**
 * @param  array<string, int>  $statusCounts
 */
function createTaskIndexScaleTasks(User $owner, array $statusCounts, string $dueDate): void
{
    $now = now();
    $rows = [];

    foreach ($statusCounts as $status => $count) {
        foreach (range(1, $count) as $position) {
            $rows[] = [
                'account_id' => $owner->id,
                'created_by_user_id' => $owner->id,
                'title' => sprintf('%s task %03d', $status, $position),
                'status' => $status,
                'priority' => Task::PRIORITY_NORMAL,
                'due_date' => $dueDate,
                'completed_at' => $status === Task::STATUS_DONE ? $now : null,
                'cancelled_at' => $status === Task::STATUS_CANCELLED ? $now : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
    }

    collect($rows)
        ->chunk(200)
        ->each(fn ($chunk) => Task::query()->insert($chunk->all()));
}

test('task board bounds its payload without losing exact totals or status representation', function () {
    $owner = taskIndexScaleOwner();
    $perStatus = 130;
    $total = $perStatus * count(Task::STATUSES);

    createTaskIndexScaleTasks(
        $owner,
        array_fill_keys(Task::STATUSES, $perStatus),
        now()->addWeek()->toDateString()
    );

    $response = $this->actingAs($owner)
        ->getJson(route('task.index', ['view' => 'board']))
        ->assertOk()
        ->assertJsonPath('filters.view', 'board')
        ->assertJsonPath('count', $total)
        ->assertJsonPath('stats.total', $total)
        ->assertJsonPath('stats.todo', $perStatus)
        ->assertJsonPath('stats.in_progress', $perStatus)
        ->assertJsonPath('stats.done', $perStatus)
        ->assertJsonPath('stats.cancelled', $perStatus)
        ->assertJsonPath('taskWindow.available_count', $total)
        ->assertJsonPath('taskWindow.matching_count', $total)
        ->assertJsonPath('taskWindow.truncated', true);

    $tasks = collect($response->json('tasks.data'));
    $loadedCount = $response->json('taskWindow.loaded_count');

    expect($loadedCount)->toBeInt()
        ->and($loadedCount)->toBe($tasks->count())
        ->and($response->json('tasks.total'))->toBe($loadedCount)
        ->and($response->json('tasks.next_page_url'))->toBeNull()
        ->and($loadedCount)->toBeLessThan($total)
        ->and($tasks->pluck('status')->unique()->sort()->values()->all())
        ->toBe(collect(Task::STATUSES)->sort()->values()->all());

    foreach (Task::STATUSES as $status) {
        expect($tasks->where('status', $status)->count())->toBeGreaterThan(0);
    }
});

test('weekly task schedule loads only its SQL date window while preserving global metrics', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-26 10:00:00', 'America/Toronto'));

    try {
        $owner = taskIndexScaleOwner();

        foreach ([
            ['Monday in range', Task::STATUS_TODO, '2026-08-24'],
            ['Sunday in range', Task::STATUS_IN_PROGRESS, '2026-08-30'],
            ['Previous Sunday', Task::STATUS_DONE, '2026-08-23'],
            ['Next Monday', Task::STATUS_CANCELLED, '2026-08-31'],
        ] as [$title, $status, $dueDate]) {
            Task::query()->create([
                'account_id' => $owner->id,
                'created_by_user_id' => $owner->id,
                'title' => $title,
                'status' => $status,
                'priority' => Task::PRIORITY_NORMAL,
                'due_date' => $dueDate,
                'completed_at' => $status === Task::STATUS_DONE ? now() : null,
                'cancelled_at' => $status === Task::STATUS_CANCELLED ? now() : null,
            ]);
        }

        $response = $this->actingAs($owner)
            ->getJson(route('task.index', [
                'view' => 'schedule',
                'range' => 'week',
            ]))
            ->assertOk()
            ->assertJsonPath('filters.view', 'schedule')
            ->assertJsonPath('filters.range', 'week')
            ->assertJsonPath('count', 4)
            ->assertJsonPath('stats.total', 4)
            ->assertJsonPath('stats.todo', 1)
            ->assertJsonPath('stats.in_progress', 1)
            ->assertJsonPath('stats.done', 1)
            ->assertJsonPath('stats.cancelled', 1)
            ->assertJsonPath('taskWindow.loaded_count', 2)
            ->assertJsonPath('taskWindow.available_count', 2)
            ->assertJsonPath('taskWindow.matching_count', 4)
            ->assertJsonPath('taskWindow.truncated', false)
            ->assertJsonPath('tasks.total', 2)
            ->assertJsonPath('tasks.next_page_url', null)
            ->assertJsonCount(2, 'tasks.data');

        expect(collect($response->json('tasks.data'))->pluck('title')->sort()->values()->all())
            ->toBe(['Monday in range', 'Sunday in range']);
    } finally {
        Carbon::setTestNow();
    }
});

test('task API keeps a real bounded pagination contract', function () {
    $owner = taskIndexScaleOwner();
    $total = 130;

    createTaskIndexScaleTasks(
        $owner,
        [Task::STATUS_TODO => $total],
        now()->addWeek()->toDateString()
    );
    Sanctum::actingAs($owner);

    $response = $this->getJson('/api/v1/tasks?per_page=25&page=2')
        ->assertOk()
        ->assertJsonCount(25, 'tasks.data')
        ->assertJsonPath('tasks.current_page', 2)
        ->assertJsonPath('tasks.per_page', 25)
        ->assertJsonPath('tasks.total', $total)
        ->assertJsonPath('taskWindow.loaded_count', 25)
        ->assertJsonPath('taskWindow.available_count', $total)
        ->assertJsonPath('taskWindow.matching_count', $total)
        ->assertJsonPath('taskWindow.truncated', true);

    expect(collect($response->json('tasks.data'))->pluck('id')->unique()->count())->toBe(25);
});
