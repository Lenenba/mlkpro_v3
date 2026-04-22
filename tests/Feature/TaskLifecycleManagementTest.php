<?php

use App\Models\Customer;
use App\Models\Task;
use App\Models\User;
use App\Notifications\ActionEmailNotification;
use App\Services\DailyAgendaService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    Http::fake([
        'https://exp.host/*' => Http::response(['data' => []], 200),
        'https://api.twilio.com/*' => Http::response(['sid' => 'SM123'], 200),
    ]);
});

test('task can be cancelled with a reason and stays preserved for history', function () {
    $owner = User::factory()->create([
        'company_features' => ['tasks' => true],
        'company_notification_settings' => [
            'task_updates' => [
                'email' => true,
                'sms' => false,
            ],
        ],
    ]);

    $customer = Customer::create([
        'user_id' => $owner->id,
        'first_name' => 'Alex',
        'last_name' => 'Client',
        'company_name' => 'North Co',
        'email' => 'alex@example.com',
        'phone' => '+15145550001',
        'salutation' => 'Mr',
    ]);

    $task = Task::create([
        'account_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'customer_id' => $customer->id,
        'title' => 'Visit client site',
        'status' => Task::STATUS_TODO,
        'due_date' => now()->toDateString(),
    ]);

    $response = $this->actingAs($owner)->putJson(route('task.update', $task), [
        'title' => $task->title,
        'description' => $task->description,
        'status' => Task::STATUS_CANCELLED,
        'standalone' => true,
        'work_id' => null,
        'customer_id' => $customer->id,
        'product_id' => null,
        'due_date' => $task->due_date?->toDateString(),
        'assigned_team_member_id' => null,
        'materials' => [],
        'cancellation_reason' => 'Client asked to reschedule next week',
    ]);

    $response->assertOk()
        ->assertJsonPath('task.status', Task::STATUS_CANCELLED)
        ->assertJsonPath('task.cancellation_reason', 'Client asked to reschedule next week');

    $task->refresh();

    expect($task->status)->toBe(Task::STATUS_CANCELLED)
        ->and($task->cancelled_at)->not->toBeNull()
        ->and($task->cancellation_reason)->toBe('Client asked to reschedule next week')
        ->and($task->completed_at)->toBeNull();

    $this->assertDatabaseHas('task_status_histories', [
        'task_id' => $task->id,
        'action' => 'cancelled',
        'to_status' => Task::STATUS_CANCELLED,
        'note' => 'Client asked to reschedule next week',
    ]);

    Notification::assertSentTo($customer, ActionEmailNotification::class, function (ActionEmailNotification $notification) {
        return str_contains(strtolower($notification->title), 'cancel');
    });

    $this->actingAs($owner)
        ->deleteJson(route('task.destroy', $task))
        ->assertStatus(422);

    expect(Task::query()->whereKey($task->id)->exists())->toBeTrue();
});

test('daily agenda marks tasks overdue at end of day instead of auto-completing them', function () {
    $owner = User::factory()->create([
        'company_features' => ['tasks' => true],
        'company_timezone' => 'America/Toronto',
        'company_notification_settings' => [
            'task_updates' => [
                'email' => true,
                'sms' => false,
            ],
        ],
    ]);

    $customer = Customer::create([
        'user_id' => $owner->id,
        'first_name' => 'Maya',
        'last_name' => 'Client',
        'company_name' => 'Late Co',
        'email' => 'maya@example.com',
        'phone' => '+15145550002',
        'salutation' => 'Mrs',
    ]);

    $task = Task::create([
        'account_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'customer_id' => $customer->id,
        'title' => 'Close maintenance visit',
        'status' => Task::STATUS_TODO,
        'due_date' => '2026-04-22',
    ]);

    $result = app(DailyAgendaService::class)->process(
        Carbon::parse('2026-04-22 19:00:00', 'America/Toronto')
    );

    $task->refresh();

    expect($result['tasks_late_today'])->toBe(1)
        ->and(in_array($task->status, Task::OPEN_STATUSES, true))->toBeTrue()
        ->and($task->delay_started_at)->not->toBeNull()
        ->and($task->end_alerted_at)->not->toBeNull()
        ->and($task->completed_at)->toBeNull()
        ->and($task->auto_completed_at)->toBeNull();

    $this->assertDatabaseHas('task_status_histories', [
        'task_id' => $task->id,
        'action' => 'overdue',
    ]);
});
