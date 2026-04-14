<?php

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Work;
use App\Notifications\InvoiceAvailableNotification;
use App\Services\FinanceApprovalService;
use Illuminate\Support\Facades\Notification;

test('owners can send an invoice email from the invoices module and drafts become sent', function () {
    Notification::fake();

    $owner = User::factory()->create();

    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
        'email' => 'invoice-client@example.com',
    ]);

    $work = Work::factory()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'job_title' => 'Invoice send test',
    ]);

    $invoice = Invoice::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'work_id' => $work->id,
        'status' => 'draft',
        'approval_status' => FinanceApprovalService::APPROVAL_STATUS_APPROVED,
        'approved_by_user_id' => $owner->id,
        'approved_at' => now(),
        'total' => 150.00,
    ]);

    $this->actingAs($owner)
        ->post(route('invoice.send.email', $invoice))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($invoice->fresh()->status)->toBe('sent')
        ->and(ActivityLog::query()->where('subject_id', $invoice->id)->where('action', 'email_sent')->exists())->toBeTrue()
        ->and(ActivityLog::query()->where('subject_id', $invoice->id)->where('action', 'status_changed')->exists())->toBeTrue();

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

test('invoice send email action rejects invoices without a customer email', function () {
    Notification::fake();

    $owner = User::factory()->create();

    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
        'email' => '',
    ]);

    $work = Work::factory()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'job_title' => 'Invoice missing email test',
    ]);

    $invoice = Invoice::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'work_id' => $work->id,
        'status' => 'draft',
        'approval_status' => FinanceApprovalService::APPROVAL_STATUS_APPROVED,
        'approved_by_user_id' => $owner->id,
        'approved_at' => now(),
        'total' => 95.00,
    ]);

    $this->actingAs($owner)
        ->post(route('invoice.send.email', $invoice))
        ->assertRedirect()
        ->assertSessionHas('warning');

    Notification::assertNothingSent();
});

test('invoice send email action rejects invoices that are still pending finance approval', function () {
    Notification::fake();

    $owner = User::factory()->create();

    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
        'email' => 'invoice-review@example.com',
    ]);

    $work = Work::factory()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'job_title' => 'Invoice finance approval gate',
    ]);

    $invoice = Invoice::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'work_id' => $work->id,
        'status' => 'draft',
        'approval_status' => FinanceApprovalService::APPROVAL_STATUS_SUBMITTED,
        'current_approver_role_key' => 'admin',
        'current_approval_level' => 1,
        'total' => 240.00,
    ]);

    $this->actingAs($owner)
        ->post(route('invoice.send.email', $invoice))
        ->assertRedirect()
        ->assertSessionHas('warning');

    Notification::assertNothingSent();
});
