<?php

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Work;
use App\Services\TipAllocationService;

function createOwnerForTipSplit(): User
{
    $role = Role::query()->firstOrCreate(
        ['name' => 'owner'],
        ['description' => 'Owner role']
    );

    return User::query()->create([
        'name' => 'Split Owner',
        'email' => 'split-owner-' . uniqid('', true) . '@example.com',
        'role_id' => $role->id,
        'password' => 'password',
        'company_name' => 'Split Company',
        'company_type' => 'services',
        'company_store_settings' => [
            'tips' => [
                'allocation_strategy' => 'split',
            ],
        ],
        'onboarding_completed_at' => now(),
        'email_verified_at' => now(),
    ]);
}

function createSplitEmployee(User $owner, string $name): TeamMember
{
    $role = Role::query()->firstOrCreate(
        ['name' => 'employee'],
        ['description' => 'Employee role']
    );

    $employee = User::query()->create([
        'name' => $name,
        'email' => 'split-employee-' . uniqid('', true) . '@example.com',
        'role_id' => $role->id,
        'password' => 'password',
        'company_type' => 'services',
        'onboarding_completed_at' => now(),
        'email_verified_at' => now(),
    ]);

    return TeamMember::query()->create([
        'account_id' => $owner->id,
        'user_id' => $employee->id,
        'role' => 'member',
        'is_active' => true,
    ]);
}

test('tip allocations split by assignee weights when strategy is split', function () {
    $owner = createOwnerForTipSplit();
    $memberA = createSplitEmployee($owner, 'Split Member A');
    $memberB = createSplitEmployee($owner, 'Split Member B');

    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $work = Work::factory()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
    ]);
    $work->teamMembers()->sync([$memberA->id, $memberB->id]);

    $invoice = Invoice::query()->create([
        'work_id' => $work->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'status' => 'sent',
        'total' => 120,
    ]);

    InvoiceItem::query()->create([
        'invoice_id' => $invoice->id,
        'work_id' => $work->id,
        'assigned_team_member_id' => $memberA->id,
        'title' => 'Visit #1',
        'quantity' => 1,
        'unit_price' => 40,
        'total' => 40,
    ]);
    InvoiceItem::query()->create([
        'invoice_id' => $invoice->id,
        'work_id' => $work->id,
        'assigned_team_member_id' => $memberA->id,
        'title' => 'Visit #2',
        'quantity' => 1,
        'unit_price' => 40,
        'total' => 40,
    ]);
    InvoiceItem::query()->create([
        'invoice_id' => $invoice->id,
        'work_id' => $work->id,
        'assigned_team_member_id' => $memberB->id,
        'title' => 'Visit #3',
        'quantity' => 1,
        'unit_price' => 40,
        'total' => 40,
    ]);

    $payment = Payment::query()->create([
        'invoice_id' => $invoice->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'amount' => 120,
        'tip_amount' => 9,
        'tip_type' => 'fixed',
        'tip_base_amount' => 120,
        'charged_total' => 129,
        'tip_assignee_user_id' => $memberA->user_id,
        'method' => 'card',
        'status' => 'completed',
        'paid_at' => now(),
    ]);

    app(TipAllocationService::class)->syncForPayment($payment);

    $allocations = $payment->tipAllocations()->orderBy('user_id')->get();
    expect($allocations)->toHaveCount(2);

    $allocationA = $allocations->firstWhere('user_id', $memberA->user_id);
    $allocationB = $allocations->firstWhere('user_id', $memberB->user_id);

    expect((float) ($allocationA?->amount ?? 0))->toBe(6.0);
    expect((float) ($allocationB?->amount ?? 0))->toBe(3.0);
});
