<?php

use App\Models\Customer;
use App\Models\Task;
use App\Models\User;
use App\Models\Work;
use Illuminate\Support\Carbon;

test('scheduled work generates tasks for all planned dates', function () {
    $user = User::factory()->create();

    $customer = Customer::factory()->create([
        'user_id' => $user->id,
        'portal_access' => true,
        'salutation' => 'Mr',
    ]);

    $startDate = Carbon::today();

    $payload = [
        'customer_id' => $customer->id,
        'job_title' => 'Scheduled job',
        'start_date' => $startDate->toDateString(),
        'frequency' => 'Daily',
        'frequencyNumber' => 1,
        'totalVisits' => 3,
        'repeatsOn' => [],
        'status' => Work::STATUS_SCHEDULED,
    ];

    $this->actingAs($user)
        ->post(route('work.store'), $payload)
        ->assertRedirect();

    $work = Work::where('customer_id', $customer->id)->first();

    expect($work)->not->toBeNull();

    $taskDates = Task::query()
        ->where('work_id', $work->id)
        ->orderBy('due_date')
        ->get()
        ->map(fn (Task $task) => $task->due_date?->toDateString())
        ->filter()
        ->values()
        ->all();

    $expectedDates = collect(range(0, 2))
        ->map(fn ($offset) => $startDate->copy()->addDays($offset)->toDateString())
        ->all();

    expect($taskDates)->toEqual($expectedDates);
});
