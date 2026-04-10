<?php

use App\Models\Customer;
use App\Models\Task;
use App\Models\User;
use App\Models\Work;
use App\Notifications\InvoiceAvailableNotification;
use App\Services\TaskBillingService;
use Illuminate\Support\Facades\Notification;

test('task billing sends an invoice email when it creates a sent invoice', function () {
    Notification::fake();

    $owner = User::factory()->create([
        'company_type' => 'services',
    ]);

    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
        'email' => 'client-invoice@example.com',
        'portal_access' => false,
    ]);

    $work = Work::factory()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => Work::STATUS_IN_PROGRESS,
        'billing_mode' => 'per_task',
        'billing_grouping' => 'single',
        'total' => 120,
    ]);

    $task = Task::query()->create([
        'account_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'customer_id' => $customer->id,
        'work_id' => $work->id,
        'title' => 'Install equipment',
        'status' => 'done',
        'billable' => true,
        'due_date' => now()->toDateString(),
    ]);

    app(TaskBillingService::class)->handleTaskCompleted($task, $owner);

    $invoice = $work->fresh()->invoice;

    expect($invoice)->not->toBeNull()
        ->and($invoice->status)->toBe('sent');

    Notification::assertSentTo($customer, InvoiceAvailableNotification::class, function (InvoiceAvailableNotification $notification) use ($customer, $invoice) {
        $mailMessage = $notification->toMail($customer);

        return in_array($notification->subject, ['New invoice available', 'Nouvelle facture disponible'], true)
            && str_contains((string) $notification->actionUrl, (string) $invoice->id)
            && count($mailMessage->rawAttachments) === 1
            && ($mailMessage->rawAttachments[0]['name'] ?? null) === 'invoice-'.($invoice->number ?: $invoice->id).'.pdf'
            && ($mailMessage->rawAttachments[0]['options']['mime'] ?? null) === 'application/pdf'
            && str_starts_with((string) ($mailMessage->rawAttachments[0]['data'] ?? ''), '%PDF');
    });
});

test('task billing does not send an invoice email while the invoice stays draft for periodic grouping', function () {
    Notification::fake();

    $owner = User::factory()->create([
        'company_type' => 'services',
    ]);

    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
        'email' => 'client-periodic@example.com',
    ]);

    $work = Work::factory()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => Work::STATUS_IN_PROGRESS,
        'billing_mode' => 'per_task',
        'billing_grouping' => 'periodic',
        'billing_cycle' => 'weekly',
        'total' => 120,
    ]);

    $task = Task::query()->create([
        'account_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'customer_id' => $customer->id,
        'work_id' => $work->id,
        'title' => 'Weekly maintenance',
        'status' => 'done',
        'billable' => true,
        'due_date' => now()->toDateString(),
    ]);

    app(TaskBillingService::class)->handleTaskCompleted($task, $owner);

    $invoice = $work->fresh()->invoice;

    expect($invoice)->not->toBeNull()
        ->and($invoice->status)->toBe('draft');

    Notification::assertNothingSent();
});
