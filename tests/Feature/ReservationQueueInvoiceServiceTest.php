<?php

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ReservationQueueItem;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\ReservationQueueInvoiceService;
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
    $again = $billing->findOrCreateForQueueItem($ticket);
    $line = $invoice->items->sole();

    expect($invoice->id)->toBe($again->id)
        ->and($invoice->status)->toBe('draft')
        ->and($invoice->source)->toBe(ReservationQueueInvoiceService::SOURCE_RESERVATION_QUEUE)
        ->and($invoice->reservation_queue_item_id)->toBe($ticket->id)
        ->and($invoice->customer_id)->toBe($customer->id)
        ->and($invoice->work_id)->toBeNull()
        ->and((float) $invoice->total)->toBe(35.0)
        ->and($invoice->currency_code)->toBe('CAD')
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
        ->and($line->currency_code)->toBe('CAD')
        ->and($line->meta)->toMatchArray([
            'source' => ReservationQueueInvoiceService::SOURCE_RESERVATION_QUEUE,
            'reservation_queue_item_id' => $ticket->id,
            'queue_number' => 'T-0806-001',
        ]);

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
        ->and($invoice->customer_snapshot)->toMatchArray([
            'type' => 'guest',
            'customer_id' => null,
            'name' => 'Client de passage',
            'email' => 'passage@example.com',
            'phone' => '+15145550102',
            'queue_number' => 'T-0806-002',
        ]);
});
