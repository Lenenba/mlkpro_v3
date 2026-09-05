<?php

use App\Exceptions\ReceiptDeliveryInProgressException;
use App\Jobs\DeliverQueueInvoiceReceipt;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\User;
use App\Models\Work;
use App\Notifications\InvoiceAvailableNotification;
use App\Services\QueueInvoiceReceiptService;
use App\Services\SmsNotificationService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

function queueReceiptInvoice(array $overrides = []): array
{
    $owner = User::factory()->create();
    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
        'email' => 'receipt-client@example.com',
        'phone' => '+15145550199',
    ]);
    $work = Work::factory()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'job_title' => 'Queue receipt service',
    ]);
    $invoice = Invoice::query()->create(array_merge([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'work_id' => $work->id,
        'created_by_user_id' => $owner->id,
        'status' => 'paid',
        'approval_status' => 'approved',
        'subtotal' => 45,
        'tax_total' => 0,
        'total' => 45,
        'currency_code' => 'CAD',
    ], $overrides));
    InvoiceItem::query()->create([
        'invoice_id' => $invoice->id,
        'title' => 'Queue service',
        'quantity' => 1,
        'unit_price' => 45,
        'total' => 45,
        'currency_code' => 'CAD',
    ]);
    Payment::query()->create([
        'invoice_id' => $invoice->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'amount' => 45,
        'tip_amount' => 9,
        'charged_total' => 54,
        'currency_code' => 'CAD',
        'method' => 'card',
        'status' => Payment::STATUS_COMPLETED,
        'paid_at' => now(),
    ]);

    return [$owner, $customer, $invoice];
}

test('receipt is marked delivered only after the queued email worker sends it', function () {
    config()->set('queue.default', 'database');
    Queue::fake();
    Notification::fake();
    [$owner, $customer, $invoice] = queueReceiptInvoice();

    $result = app(QueueInvoiceReceiptService::class)->deliver($invoice, $owner, 'email');
    $invoice->refresh();

    expect($result['delivered'])->toBeFalse()
        ->and($result['queued'])->toBeTrue()
        ->and($result['delivery_status'])->toBe(QueueInvoiceReceiptService::STATUS_QUEUED)
        ->and($result['reason'])->toBe('queued')
        ->and($invoice->receipt_delivered_at)->toBeNull()
        ->and($invoice->receipt_delivery_status)->toBe(QueueInvoiceReceiptService::STATUS_QUEUED)
        ->and($invoice->receipt_delivery_attempts)->toBe(0);

    Queue::assertPushed(DeliverQueueInvoiceReceipt::class, fn (DeliverQueueInvoiceReceipt $job): bool => $job->invoiceId === $invoice->id && $job->channel === 'email'
    );

    app(QueueInvoiceReceiptService::class)->deliverQueued($invoice->id, $owner->id, 'email');
    $invoice->refresh();

    expect($invoice->receipt_delivered_at)->not->toBeNull()
        ->and($invoice->receipt_delivery_status)->toBe(QueueInvoiceReceiptService::STATUS_DELIVERED)
        ->and($invoice->receipt_delivery_attempts)->toBe(1);

    Notification::assertSentOnDemand(InvoiceAvailableNotification::class);
});

test('a synchronously failed receipt is reported as failed instead of queued', function () {
    config()->set('queue.default', 'sync');
    [$owner, $customer, $invoice] = queueReceiptInvoice();
    $this->mock(SmsNotificationService::class, function ($mock): void {
        $mock->shouldReceive('sendWithResult')
            ->once()
            ->andReturn([
                'ok' => false,
                'reason' => 'provider_unavailable',
                'status' => 503,
            ]);
    });

    $result = app(QueueInvoiceReceiptService::class)->deliver($invoice, $owner, 'sms');
    $invoice->refresh();

    expect($result['delivered'])->toBeFalse()
        ->and($result['queued'])->toBeFalse()
        ->and($result['delivery_status'])->toBe(QueueInvoiceReceiptService::STATUS_FAILED)
        ->and($result['reason'])->toBe('provider_unavailable')
        ->and($invoice->receipt_delivered_at)->toBeNull()
        ->and($invoice->receipt_delivery_status)->toBe(QueueInvoiceReceiptService::STATUS_FAILED)
        ->and($invoice->receipt_delivery_last_error)->toBe('provider_unavailable')
        ->and($invoice->receipt_delivery_attempts)->toBe(1);
});

test('an unexpected provider exception releases the sending claim before the retry succeeds', function () {
    config()->set('queue.default', 'database');
    [$owner, $customer, $invoice] = queueReceiptInvoice();
    $invoice->forceFill([
        'receipt_delivery' => 'sms',
        'receipt_delivery_status' => QueueInvoiceReceiptService::STATUS_QUEUED,
        'receipt_delivery_queued_at' => now(),
    ])->save();

    $calls = 0;
    $this->mock(SmsNotificationService::class, function ($mock) use (&$calls): void {
        $mock->shouldReceive('sendWithResult')
            ->twice()
            ->andReturnUsing(function () use (&$calls): array {
                $calls++;
                if ($calls === 1) {
                    throw new RuntimeException('network exploded');
                }

                return ['ok' => true, 'sid' => 'retry-success'];
            });
    });

    expect(fn () => app(QueueInvoiceReceiptService::class)->deliverQueued(
        $invoice->id,
        $owner->id,
        'sms'
    ))->toThrow(RuntimeException::class, 'network exploded');

    $invoice->refresh();
    expect($invoice->receipt_delivery_status)->toBe(QueueInvoiceReceiptService::STATUS_QUEUED)
        ->and($invoice->receipt_delivery_started_at)->toBeNull()
        ->and($invoice->receipt_delivery_claim_token)->toBeNull()
        ->and($invoice->receipt_delivery_last_error)->toBe('network exploded')
        ->and($invoice->receipt_delivery_attempts)->toBe(1);

    app(QueueInvoiceReceiptService::class)->deliverQueued($invoice->id, $owner->id, 'sms');

    $invoice->refresh();
    expect($invoice->receipt_delivery_status)->toBe(QueueInvoiceReceiptService::STATUS_DELIVERED)
        ->and($invoice->receipt_delivered_at)->not->toBeNull()
        ->and($invoice->receipt_delivery_started_at)->toBeNull()
        ->and($invoice->receipt_delivery_claim_token)->toBeNull()
        ->and($invoice->receipt_delivery_attempts)->toBe(2);
});

test('a fresh sending lease is not reclaimed by a concurrent worker', function () {
    config()->set('queue.default', 'database');
    [$owner, $customer, $invoice] = queueReceiptInvoice();
    $claimToken = (string) Str::uuid();
    $invoice->forceFill([
        'receipt_delivery' => 'sms',
        'receipt_delivery_status' => QueueInvoiceReceiptService::STATUS_SENDING,
        'receipt_delivery_queued_at' => now(),
        'receipt_delivery_started_at' => now(),
        'receipt_delivery_claim_token' => $claimToken,
        'receipt_delivery_attempts' => 1,
    ])->save();
    $this->mock(SmsNotificationService::class, function ($mock): void {
        $mock->shouldNotReceive('sendWithResult');
    });

    $job = new DeliverQueueInvoiceReceipt($invoice->id, $owner->id, 'sms');
    expect(fn () => $job->handle(app(QueueInvoiceReceiptService::class)))
        ->toThrow(ReceiptDeliveryInProgressException::class);

    $invoice->refresh();
    expect($invoice->receipt_delivery_status)->toBe(QueueInvoiceReceiptService::STATUS_SENDING)
        ->and($invoice->receipt_delivery_claim_token)->toBe($claimToken)
        ->and($invoice->receipt_delivery_attempts)->toBe(1);
});

test('an obsolete sending lease is atomically reclaimed and delivered', function () {
    config()->set('queue.default', 'database');
    [$owner, $customer, $invoice] = queueReceiptInvoice();
    $invoice->forceFill([
        'receipt_delivery' => 'sms',
        'receipt_delivery_status' => QueueInvoiceReceiptService::STATUS_SENDING,
        'receipt_delivery_queued_at' => now()->subMinutes(10),
        'receipt_delivery_started_at' => now()->subMinutes(10),
        'receipt_delivery_claim_token' => (string) Str::uuid(),
        'receipt_delivery_attempts' => 1,
    ])->save();
    $this->mock(SmsNotificationService::class, function ($mock): void {
        $mock->shouldReceive('sendWithResult')
            ->once()
            ->andReturn(['ok' => true, 'sid' => 'stale-recovery']);
    });

    app(QueueInvoiceReceiptService::class)->deliverQueued($invoice->id, $owner->id, 'sms');

    $invoice->refresh();
    expect($invoice->receipt_delivery_status)->toBe(QueueInvoiceReceiptService::STATUS_DELIVERED)
        ->and($invoice->receipt_delivery_attempts)->toBe(2)
        ->and($invoice->receipt_delivery_claim_token)->toBeNull()
        ->and($invoice->receipt_delivered_at)->not->toBeNull();
});

test('receipt email exposes the invoice snapshot taxes net tip and net charged total', function () {
    config()->set('queue.default', 'database');
    Notification::fake();
    [$owner, $customer, $invoice] = queueReceiptInvoice([
        'subtotal' => 35,
        'tax_total' => 5.24,
        'total' => 40.24,
    ]);
    $portalUser = User::factory()->create(['locale' => 'en']);
    $owner->forceFill(['locale' => 'fr'])->save();
    $customer->forceFill(['portal_user_id' => $portalUser->id])->save();
    $invoice->items()->update([
        'unit_price' => 99,
        'total' => 99,
    ]);
    $invoice->payments()->update([
        'amount' => 40.24,
        'tip_amount' => 7.25,
        'tip_reversed_amount' => 2,
        'charged_total' => 47.49,
    ]);
    $invoice->forceFill([
        'receipt_delivery' => 'email',
        'receipt_delivery_status' => QueueInvoiceReceiptService::STATUS_QUEUED,
        'receipt_delivery_queued_at' => now(),
    ])->save();

    app(QueueInvoiceReceiptService::class)->deliverQueued($invoice->id, $owner->id, 'email');

    Notification::assertSentOnDemand(
        InvoiceAvailableNotification::class,
        function (InvoiceAvailableNotification $notification): bool {
            expect($notification->details)->toMatchArray([
                'Subtotal' => '35.00 CAD',
                'Taxes' => '5.24 CAD',
                'Invoice total' => '40.24 CAD',
                'Tip' => '5.25 CAD',
                'Charged total' => '45.49 CAD',
            ]);

            return true;
        }
    );
});
