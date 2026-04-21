<?php

use App\Jobs\RetryLeadQuoteEmailJob;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\User;
use App\Models\Work;
use App\Notifications\InvoiceAvailableNotification;
use App\Notifications\SendQuoteNotification;
use App\Services\FinanceApprovalService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

test('quote manual send records canonical outgoing email metadata and crm links', function () {
    Notification::fake();

    $owner = User::factory()->create([
        'company_type' => 'services',
    ]);

    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
        'email' => 'quote-phase-five@example.com',
    ]);

    $lead = LeadRequest::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Phase five quote request',
    ]);

    $quote = Quote::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'request_id' => $lead->id,
        'job_title' => 'Phase five quote',
        'status' => 'draft',
        'subtotal' => 850,
        'total' => 850,
        'initial_deposit' => 0,
    ]);

    $this->actingAs($owner)
        ->post(route('quote.send.email', $quote))
        ->assertRedirect()
        ->assertSessionHas('success');

    $log = ActivityLog::query()
        ->where('subject_type', $quote->getMorphClass())
        ->where('subject_id', $quote->id)
        ->where('action', 'message_email_sent')
        ->latest('id')
        ->first();

    expect($quote->fresh()->status)->toBe('sent')
        ->and($log)->not->toBeNull()
        ->and($log?->message_event['event_key'])->toBe('message_email_sent')
        ->and($log?->message_event['source'])->toBe('quote_manual_send')
        ->and($log?->message_event['email'])->toBe('quote-phase-five@example.com')
        ->and($log?->crm_links['primary']['type'])->toBe('quote')
        ->and($log?->crm_links['request']['id'])->toBe($lead->id)
        ->and($log?->crm_links['customer']['id'])->toBe($customer->id)
        ->and(ActivityLog::query()->where('subject_type', $quote->getMorphClass())->where('subject_id', $quote->id)->where('action', 'email_sent')->exists())->toBeFalse();

    Notification::assertSentTo($customer, SendQuoteNotification::class);
});

test('invoice manual send records canonical outgoing email metadata and crm links', function () {
    Notification::fake();

    $owner = User::factory()->create([
        'company_type' => 'services',
    ]);

    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
        'email' => 'invoice-phase-five@example.com',
    ]);

    $lead = LeadRequest::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_QUOTE_SENT,
        'title' => 'Phase five invoice request',
    ]);

    $quote = Quote::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'request_id' => $lead->id,
        'job_title' => 'Phase five invoice quote',
        'status' => 'sent',
        'subtotal' => 1500,
        'total' => 1500,
        'initial_deposit' => 0,
    ]);

    $work = Work::factory()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'quote_id' => $quote->id,
    ]);

    $invoice = Invoice::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'work_id' => $work->id,
        'status' => 'draft',
        'approval_status' => FinanceApprovalService::APPROVAL_STATUS_APPROVED,
        'approved_by_user_id' => $owner->id,
        'approved_at' => now(),
        'total' => 1500,
    ]);

    $this->actingAs($owner)
        ->post(route('invoice.send.email', $invoice))
        ->assertRedirect()
        ->assertSessionHas('success');

    $log = ActivityLog::query()
        ->where('subject_type', $invoice->getMorphClass())
        ->where('subject_id', $invoice->id)
        ->where('action', 'message_email_sent')
        ->latest('id')
        ->first();

    expect($invoice->fresh()->status)->toBe('sent')
        ->and($log)->not->toBeNull()
        ->and($log?->message_event['event_key'])->toBe('message_email_sent')
        ->and($log?->message_event['source'])->toBe('invoice_manual_send')
        ->and($log?->crm_links['primary']['type'])->toBe('request')
        ->and($log?->crm_links['request']['id'])->toBe($lead->id)
        ->and($log?->crm_links['quote']['id'])->toBe($quote->id)
        ->and($log?->crm_links['customer']['id'])->toBe($customer->id)
        ->and(ActivityLog::query()->where('subject_type', $invoice->getMorphClass())->where('subject_id', $invoice->id)->where('action', 'email_sent')->exists())->toBeFalse();

    Notification::assertSentTo($customer, InvoiceAvailableNotification::class);
});

test('retry lead quote email job records canonical failed and retry scheduled message events', function () {
    Queue::fake();
    config(['queue.default' => 'sync']);

    $owner = User::factory()->create([
        'company_type' => 'services',
    ]);

    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
        'email' => 'retry-phase-five@example.com',
    ]);

    $lead = LeadRequest::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_QUOTE_SENT,
        'title' => 'Retry phase five request',
    ]);

    $quote = Quote::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'request_id' => $lead->id,
        'job_title' => 'Retry phase five quote',
        'status' => 'sent',
        'subtotal' => 980,
        'total' => 980,
        'initial_deposit' => 0,
    ]);

    Notification::shouldReceive('sendNow')
        ->once()
        ->andThrow(new RuntimeException('mail down'));
    Notification::shouldReceive('send')
        ->zeroOrMoreTimes()
        ->andReturnNull();

    (new RetryLeadQuoteEmailJob($quote->id, $lead->id, 1))->handle();

    $quoteFailure = ActivityLog::query()
        ->where('subject_type', $quote->getMorphClass())
        ->where('subject_id', $quote->id)
        ->where('action', 'message_email_failed')
        ->latest('id')
        ->first();

    $leadFailure = ActivityLog::query()
        ->where('subject_type', $lead->getMorphClass())
        ->where('subject_id', $lead->id)
        ->where('action', 'message_email_failed')
        ->latest('id')
        ->first();

    $retryScheduled = ActivityLog::query()
        ->where('subject_type', $lead->getMorphClass())
        ->where('subject_id', $lead->id)
        ->where('action', 'message_email_retry_scheduled')
        ->latest('id')
        ->first();

    expect($quoteFailure)->not->toBeNull()
        ->and($quoteFailure?->message_event['source'])->toBe('lead_form_retry')
        ->and($quoteFailure?->message_event['retry_attempt'])->toBe(1)
        ->and($leadFailure)->not->toBeNull()
        ->and($leadFailure?->crm_links['quote']['id'])->toBe($quote->id)
        ->and($retryScheduled)->not->toBeNull()
        ->and($retryScheduled?->message_event['event_key'])->toBe('message_email_retry_scheduled')
        ->and($retryScheduled?->message_event['retry_attempt'])->toBe(2)
        ->and($retryScheduled?->message_event['delay_minutes'])->toBe(15)
        ->and(ActivityLog::query()->where('action', 'lead_email_retry_scheduled')->exists())->toBeFalse();

    Queue::assertPushed(
        RetryLeadQuoteEmailJob::class,
        fn (RetryLeadQuoteEmailJob $job) => $job->quoteId === $quote->id
            && $job->leadId === $lead->id
            && $job->attempt === 2
    );
});
