<?php

use App\Models\Task;
use App\Services\TaskTimingService;
use Carbon\Carbon as BaseCarbon;
use Illuminate\Support\Carbon as IlluminateCarbon;

uses(Tests\TestCase::class);

test('completion reason checks accept mixed carbon implementations', function () {
    $dueDate = BaseCarbon::parse('2026-04-10', 'America/Toronto')->startOfDay();
    $completedAt = IlluminateCarbon::parse('2026-04-11 09:30:00', 'America/Toronto');

    expect(TaskTimingService::shouldRequireCompletionReason($dueDate, $completedAt))->toBeTrue()
        ->and(TaskTimingService::isDueDateInFuture(
            $dueDate,
            BaseCarbon::parse('2026-04-09 12:00:00', 'America/Toronto')
        ))->toBeTrue();
});

test('cancelled tasks do not expose a timing status', function () {
    $task = new Task([
        'status' => Task::STATUS_CANCELLED,
        'due_date' => '2026-04-10',
        'cancelled_at' => '2026-04-10 09:00:00',
    ]);

    expect(TaskTimingService::resolveTimingStatus($task))->toBeNull();
});
