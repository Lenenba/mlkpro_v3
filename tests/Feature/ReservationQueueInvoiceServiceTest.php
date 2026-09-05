<?php

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ReservationQueueItem;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\FinanceApprovalService;
use App\Services\ReservationQueueCheckoutService;
use App\Services\ReservationQueueInvoiceService;
use App\Services\ReservationQueueService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

function createQueueInvoiceFoundationOwner(): User
{
    $roleId = Role::query()->firstOrCreate(
        ['name' => 'owner'],
        ['description' => 'Account owner role']
    )->id;

    return User::query()->create([
        'name' => 'Queue Invoice Owner',
        'email' => 'queue-invoice-owner-'.Str::random(10).'@example.com',
        'password' => 'password',
        'role_id' => $roleId,
        'company_type' => 'services',
        'currency_code' => 'CAD',
        'payment_methods' => ['cash', 'card'],
        'default_payment_method' => 'cash',
        'onboarding_completed_at' => now(),
    ]);
}

function createQueueInvoiceFoundationService(User $owner): Product
{
    $category = ProductCategory::query()->create([
        'name' => 'Queue invoice category '.Str::random(8),
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
    ]);

    return Product::query()->create([
        'name' => 'Brushing seul',
        'description' => 'Brushing and styling service',
        'category_id' => $category->id,
        'user_id' => $owner->id,
        'item_type' => Product::ITEM_TYPE_SERVICE,
        'tracking_type' => 'none',
        'price' => 35.00,
        'tax_rate' => 14.975,
        'currency_code' => 'CAD',
        'stock' => 0,
        'minimum_stock' => 0,
    ]);
}

function createQueueInvoiceFoundationMember(User $owner): TeamMember
{
    $roleId = Role::query()->firstOrCreate(
        ['name' => 'employee'],
        ['description' => 'Employee role']
    )->id;

    $employee = User::query()->create([
        'name' => 'Karim Benali',
        'email' => 'queue-invoice-member-'.Str::random(10).'@example.com',
        'password' => 'password',
        'role_id' => $roleId,
        'onboarding_completed_at' => now(),
    ]);

    return TeamMember::query()->create([
        'account_id' => $owner->id,
        'user_id' => $employee->id,
        'role' => 'member',
        'title' => 'Stylist',
        'permissions' => [],
        'is_active' => true,
    ]);
}

test('it creates one draft invoice with immutable queue, customer, service, and employee snapshots', function () {
    $owner = createQueueInvoiceFoundationOwner();
    $service = createQueueInvoiceFoundationService($owner);
    $member = createQueueInvoiceFoundationMember($owner);
    $customer = Customer::query()->create([
        'user_id' => $owner->id,
        'first_name' => 'Amina',
        'last_name' => 'Diallo',
        'company_name' => 'Salon Éclat',
        'email' => 'amina.diallo@example.com',
        'phone' => '+15145550101',
    ]);
    $ticket = ReservationQueueItem::query()->create([
        'account_id' => $owner->id,
        'client_id' => $customer->id,
        'service_id' => $service->id,
        'team_member_id' => $member->id,
        'created_by_user_id' => $owner->id,
        'item_type' => ReservationQueueItem::TYPE_TICKET,
        'source' => 'staff',
        'queue_number' => 'T-0806-001',
        'status' => ReservationQueueItem::STATUS_AWAITING_PAYMENT,
        'estimated_duration_minutes' => 30,
        'metadata' => [
            'checkout' => [
                'service_name' => 'Brushing seul',
                'base_amount' => 35.00,
                'currency_code' => 'CAD',
            ],
        ],
    ]);

    $billing = app(ReservationQueueInvoiceService::class);
    $invoice = $billing->findOrCreateForQueueItem($ticket);
    $service->forceFill([
        'price' => 99,
        'tax_rate' => 0,
    ])->save();
    $again = $billing->findOrCreateForQueueItem($ticket);
    $line = $invoice->items->sole();

    expect($invoice->id)->toBe($again->id)
        ->and($invoice->status)->toBe('draft')
        ->and($invoice->approval_status)->toBe(FinanceApprovalService::APPROVAL_STATUS_APPROVED)
        ->and($invoice->approved_by_user_id)->toBe($owner->id)
        ->and($invoice->approved_at)->not->toBeNull()
        ->and($invoice->source)->toBe(ReservationQueueInvoiceService::SOURCE_RESERVATION_QUEUE)
        ->and($invoice->reservation_queue_item_id)->toBe($ticket->id)
        ->and($invoice->customer_id)->toBe($customer->id)
        ->and($invoice->work_id)->toBeNull()
        ->and((float) $invoice->subtotal)->toBe(35.0)
        ->and((float) $invoice->tax_total)->toBe(5.24)
        ->and((float) $invoice->total)->toBe(40.24)
        ->and($invoice->currency_code)->toBe('CAD')
        ->and($invoice->billing_snapshot)->toMatchArray([
            'version' => 1,
            'currency_code' => 'CAD',
            'subtotal' => 35,
            'tax_rate' => 14.975,
            'tax_total' => 5.24,
            'invoice_total' => 40.24,
            'tip_base_amount' => 35,
            'tax_breakdown' => [[
                'code' => 'service_tax',
                'name' => 'Tax',
                'rate' => 14.975,
                'taxable_amount' => 35,
                'amount' => 5.24,
            ]],
        ])
        ->and($invoice->customer_snapshot)->toMatchArray([
            'type' => 'customer',
            'customer_id' => $customer->id,
            'name' => 'Salon Éclat',
            'email' => 'amina.diallo@example.com',
            'phone' => '+15145550101',
            'queue_number' => 'T-0806-001',
        ])
        ->and($line->title)->toBe('Brushing seul')
        ->and($line->description)->toBe('Brushing and styling service')
        ->and($line->assigned_team_member_id)->toBe($member->id)
        ->and($line->assignee_name)->toBe('Karim Benali')
        ->and((float) $line->unit_price)->toBe(35.0)
        ->and((float) $line->total)->toBe(35.0)
        ->and($line->currency_code)->toBe('CAD')
        ->and($line->meta)->toMatchArray([
            'source' => ReservationQueueInvoiceService::SOURCE_RESERVATION_QUEUE,
            'reservation_queue_item_id' => $ticket->id,
            'queue_number' => 'T-0806-001',
        ])
        ->and(data_get($line->meta, 'billing.subtotal'))->toBe(35)
        ->and(data_get($line->meta, 'billing.tax_total'))->toBe(5.24)
        ->and(data_get($line->meta, 'billing.invoice_total'))->toBe(40.24);

    expect(Invoice::query()->where('reservation_queue_item_id', $ticket->id)->count())->toBe(1)
        ->and($ticket->fresh()->checkoutInvoice?->id)->toBe($invoice->id)
        ->and($invoice->reservationQueueItem?->id)->toBe($ticket->id);
});

test('it retains guest contact details without forcing a customer or work link', function () {
    $owner = createQueueInvoiceFoundationOwner();
    $service = createQueueInvoiceFoundationService($owner);
    $member = createQueueInvoiceFoundationMember($owner);
    $ticket = ReservationQueueItem::query()->create([
        'account_id' => $owner->id,
        'service_id' => $service->id,
        'team_member_id' => $member->id,
        'created_by_user_id' => $owner->id,
        'item_type' => ReservationQueueItem::TYPE_TICKET,
        'source' => 'kiosk_guest',
        'queue_number' => 'T-0806-002',
        'status' => ReservationQueueItem::STATUS_AWAITING_PAYMENT,
        'estimated_duration_minutes' => 30,
        'metadata' => [
            'guest_name' => 'Client de passage',
            'guest_phone' => '+15145550102',
            'guest_email' => 'passage@example.com',
            'checkout' => [
                'service_name' => 'Brushing seul',
                'base_amount' => 35.00,
                'currency_code' => 'CAD',
            ],
        ],
    ]);

    $invoice = app(ReservationQueueInvoiceService::class)->findOrCreateForQueueItem($ticket);

    expect($invoice->customer_id)->toBeNull()
        ->and($invoice->work_id)->toBeNull()
        ->and((float) $invoice->subtotal)->toBe(35.0)
        ->and((float) $invoice->tax_total)->toBe(5.24)
        ->and((float) $invoice->total)->toBe(40.24)
        ->and($invoice->customer_snapshot)->toMatchArray([
            'type' => 'guest',
            'customer_id' => null,
            'name' => 'Client de passage',
            'email' => 'passage@example.com',
            'phone' => '+15145550102',
            'queue_number' => 'T-0806-002',
        ]);
});

test('it repairs one legacy unpaid draft queue invoice with an immutable taxed billing snapshot', function () {
    $owner = createQueueInvoiceFoundationOwner();
    $service = createQueueInvoiceFoundationService($owner);
    $member = createQueueInvoiceFoundationMember($owner);
    $ticket = ReservationQueueItem::query()->create([
        'account_id' => $owner->id,
        'service_id' => $service->id,
        'team_member_id' => $member->id,
        'created_by_user_id' => $owner->id,
        'item_type' => ReservationQueueItem::TYPE_TICKET,
        'source' => 'staff',
        'queue_number' => 'T-LEGACY-DRAFT',
        'status' => ReservationQueueItem::STATUS_AWAITING_PAYMENT,
        'estimated_duration_minutes' => 30,
        'metadata' => [
            'checkout' => [
                'service_name' => 'Brushing seul',
                'base_amount' => 35.00,
                'currency_code' => 'CAD',
            ],
        ],
    ]);
    $legacyInvoice = Invoice::query()->create([
        'reservation_queue_item_id' => $ticket->id,
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'status' => 'draft',
        'approval_status' => FinanceApprovalService::APPROVAL_STATUS_DRAFT,
        'source' => ReservationQueueInvoiceService::SOURCE_RESERVATION_QUEUE,
        'subtotal' => null,
        'tax_total' => null,
        'billing_snapshot' => null,
        'total' => 35.00,
        'currency_code' => 'CAD',
    ]);
    $legacyLine = InvoiceItem::query()->create([
        'invoice_id' => $legacyInvoice->id,
        'assigned_team_member_id' => $member->id,
        'title' => 'Legacy brushing',
        'quantity' => 1,
        'unit_price' => 35.00,
        'currency_code' => 'CAD',
        'total' => 35.00,
        'meta' => [
            'legacy_reference' => 'legacy-line-1',
            'service' => ['base_amount' => 35],
        ],
    ]);

    $billing = app(ReservationQueueInvoiceService::class);
    $repaired = $billing->findOrCreateForQueueItem($ticket);
    $again = $billing->findOrCreateForQueueItem($ticket);
    $line = $legacyLine->fresh();

    expect($repaired->id)->toBe($legacyInvoice->id)
        ->and($again->id)->toBe($legacyInvoice->id)
        ->and($repaired->status)->toBe('draft')
        ->and($repaired->approval_status)->toBe(FinanceApprovalService::APPROVAL_STATUS_APPROVED)
        ->and($repaired->approved_by_user_id)->toBe($owner->id)
        ->and($repaired->approved_at)->not->toBeNull()
        ->and((float) $repaired->subtotal)->toBe(35.0)
        ->and((float) $repaired->tax_total)->toBe(5.24)
        ->and((float) $repaired->total)->toBe(40.24)
        ->and($repaired->billing_snapshot)->toMatchArray([
            'version' => 1,
            'subtotal' => 35,
            'tax_rate' => 14.975,
            'tax_total' => 5.24,
            'invoice_total' => 40.24,
            'tip_base_amount' => 35,
        ])
        ->and($line?->id)->toBe($legacyLine->id)
        ->and($line?->title)->toBe('Brushing seul')
        ->and((float) $line?->unit_price)->toBe(35.0)
        ->and((float) $line?->total)->toBe(35.0)
        ->and(data_get($line?->meta, 'legacy_reference'))->toBe('legacy-line-1')
        ->and(data_get($line?->meta, 'billing.version'))->toBe(1)
        ->and(data_get($line?->meta, 'billing.tax_total'))->toBe(5.24)
        ->and(data_get($line?->meta, 'service.invoice_total'))->toBe(40.24)
        ->and(ActivityLog::query()
            ->where('subject_type', $legacyInvoice->getMorphClass())
            ->where('subject_id', $legacyInvoice->id)
            ->where('action', 'queue_billing_snapshot_repaired')
            ->count())->toBe(1);
});

test('it never repairs a legacy paid queue invoice', function () {
    $owner = createQueueInvoiceFoundationOwner();
    $service = createQueueInvoiceFoundationService($owner);
    $ticket = ReservationQueueItem::query()->create([
        'account_id' => $owner->id,
        'service_id' => $service->id,
        'created_by_user_id' => $owner->id,
        'item_type' => ReservationQueueItem::TYPE_TICKET,
        'source' => 'staff',
        'queue_number' => 'T-LEGACY-PAID',
        'status' => ReservationQueueItem::STATUS_DONE,
        'estimated_duration_minutes' => 30,
        'metadata' => [
            'checkout' => [
                'service_name' => 'Brushing seul',
                'base_amount' => 35.00,
                'currency_code' => 'CAD',
            ],
        ],
    ]);
    $legacyInvoice = Invoice::query()->create([
        'reservation_queue_item_id' => $ticket->id,
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'status' => 'paid',
        'approval_status' => FinanceApprovalService::APPROVAL_STATUS_APPROVED,
        'source' => ReservationQueueInvoiceService::SOURCE_RESERVATION_QUEUE,
        'subtotal' => null,
        'tax_total' => null,
        'billing_snapshot' => null,
        'total' => 35.00,
        'currency_code' => 'CAD',
    ]);
    $legacyLine = InvoiceItem::query()->create([
        'invoice_id' => $legacyInvoice->id,
        'title' => 'Paid legacy brushing',
        'quantity' => 1,
        'unit_price' => 35.00,
        'currency_code' => 'CAD',
        'total' => 35.00,
        'meta' => ['legacy_reference' => 'paid-line'],
    ]);
    Payment::query()->create([
        'invoice_id' => $legacyInvoice->id,
        'reservation_queue_item_id' => $ticket->id,
        'user_id' => $owner->id,
        'amount' => 35.00,
        'currency_code' => 'CAD',
        'tip_amount' => 0,
        'tip_type' => 'none',
        'tip_base_amount' => 35.00,
        'charged_total' => 35.00,
        'method' => 'cash',
        'provider' => 'manual',
        'status' => Payment::STATUS_COMPLETED,
        'paid_at' => now('UTC'),
    ]);

    $unchanged = app(ReservationQueueInvoiceService::class)->findOrCreateForQueueItem($ticket);

    expect($unchanged->id)->toBe($legacyInvoice->id)
        ->and($unchanged->status)->toBe('paid')
        ->and($unchanged->subtotal)->toBeNull()
        ->and($unchanged->tax_total)->toBeNull()
        ->and($unchanged->billing_snapshot)->toBeNull()
        ->and((float) $unchanged->total)->toBe(35.0)
        ->and($legacyLine->fresh()?->title)->toBe('Paid legacy brushing')
        ->and(data_get($legacyLine->fresh()?->meta, 'billing'))->toBeNull()
        ->and(ActivityLog::query()
            ->where('subject_type', $legacyInvoice->getMorphClass())
            ->where('subject_id', $legacyInvoice->id)
            ->where('action', 'queue_billing_snapshot_repaired')
            ->exists())->toBeFalse();
});

test('it freezes authoritative tax pricing when a service is finished', function () {
    Notification::fake();

    $owner = createQueueInvoiceFoundationOwner();
    $service = createQueueInvoiceFoundationService($owner);
    $ticket = ReservationQueueItem::query()->create([
        'account_id' => $owner->id,
        'service_id' => $service->id,
        'created_by_user_id' => $owner->id,
        'item_type' => ReservationQueueItem::TYPE_TICKET,
        'source' => 'staff',
        'queue_number' => 'T-0806-003',
        'status' => ReservationQueueItem::STATUS_IN_SERVICE,
        'estimated_duration_minutes' => 30,
        'metadata' => [
            // This custom metadata must never override the catalog at finish time.
            'checkout' => [
                'pricing_version' => 1,
                'subtotal' => 0,
                'tax_breakdown' => [
                    ['code' => 'tampered', 'amount' => 999],
                    ['code' => 'leftover', 'amount' => 999],
                ],
                'tax_total' => 0,
                'invoice_total' => 0,
            ],
        ],
    ]);

    $updated = app(ReservationQueueService::class)->transition(
        $ticket,
        'finish',
        $owner,
        [
            'business_preset' => 'salon',
            'queue_mode_enabled' => true,
            'queue_dispatch_mode' => ReservationQueueService::DISPATCH_MODE_FIFO,
            'queue_assignment_mode' => ReservationQueueService::ASSIGNMENT_MODE_PER_STAFF,
        ]
    );

    expect($updated->status)->toBe(ReservationQueueItem::STATUS_AWAITING_PAYMENT)
        ->and(data_get($updated->metadata, 'checkout'))->toMatchArray([
            'pricing_version' => 1,
            'base_amount' => 35,
            'subtotal' => 35,
            'tax_rate' => 14.975,
            'tax_total' => 5.24,
            'invoice_total' => 40.24,
            'currency_code' => 'CAD',
        ])
        ->and(data_get($updated->metadata, 'checkout.tax_breakdown'))->toBe([[
            'code' => 'service_tax',
            'name' => 'Tax',
            'rate' => 14.975,
            'taxable_amount' => 35,
            'amount' => 5.24,
        ]]);

    $service->forceFill([
        'price' => 80,
        'tax_rate' => 5,
    ])->save();

    $summary = app(ReservationQueueService::class)->checkoutSummary($updated->fresh());

    expect($summary)->toMatchArray([
        'base_amount' => 35,
        'subtotal' => 35,
        'tax_rate' => 14.975,
        'tax_total' => 5.24,
        'invoice_total' => 40.24,
        'requires_payment' => true,
    ]);

    $invoice = app(ReservationQueueInvoiceService::class)->findOrCreateForQueueItem($updated->fresh());

    expect((float) $invoice->subtotal)->toBe(35.0)
        ->and((float) $invoice->tax_total)->toBe(5.24)
        ->and((float) $invoice->total)->toBe(40.24)
        ->and(data_get($invoice->billing_snapshot, 'tax_rate'))->toBe(14.975);
});

test('queue checkout charges taxes plus a tip calculated only on the pre-tax subtotal', function () {
    Notification::fake();

    $owner = createQueueInvoiceFoundationOwner();
    $service = createQueueInvoiceFoundationService($owner);
    $member = createQueueInvoiceFoundationMember($owner);
    $settings = [
        'business_preset' => 'salon',
        'queue_mode_enabled' => true,
        'queue_dispatch_mode' => ReservationQueueService::DISPATCH_MODE_FIFO,
        'queue_assignment_mode' => ReservationQueueService::ASSIGNMENT_MODE_PER_STAFF,
    ];
    $ticket = ReservationQueueItem::query()->create([
        'account_id' => $owner->id,
        'service_id' => $service->id,
        'team_member_id' => $member->id,
        'created_by_user_id' => $owner->id,
        'item_type' => ReservationQueueItem::TYPE_TICKET,
        'source' => 'staff',
        'queue_number' => 'T-0806-004',
        'status' => ReservationQueueItem::STATUS_IN_SERVICE,
        'estimated_duration_minutes' => 30,
    ]);

    $awaitingPayment = app(ReservationQueueService::class)->transition(
        $ticket,
        'finish',
        $owner,
        $settings
    );
    $result = app(ReservationQueueCheckoutService::class)->checkout(
        $awaitingPayment,
        [
            'method' => 'cash',
            'tip_enabled' => true,
            'tip_mode' => 'percent',
            'tip_percent' => 15,
        ],
        $owner,
        $settings
    );

    $invoice = $result['invoice']->fresh();
    $payment = $result['payment']->fresh();

    expect((float) $invoice->subtotal)->toBe(35.0)
        ->and((float) $invoice->tax_total)->toBe(5.24)
        ->and((float) $invoice->total)->toBe(40.24)
        ->and($invoice->status)->toBe('paid')
        ->and((float) $payment->amount)->toBe(40.24)
        ->and((float) $payment->tip_base_amount)->toBe(35.0)
        ->and((float) $payment->tip_percent)->toBe(15.0)
        ->and((float) $payment->tip_amount)->toBe(5.25)
        ->and((float) $payment->charged_total)->toBe(45.49)
        ->and($payment->tip_assignee_user_id)->toBe($member->user_id)
        ->and($result['queue_item']->status)->toBe(ReservationQueueItem::STATUS_DONE);
});
