<?php

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Work;
use App\Services\TipAllocationService;
use App\Http\Middleware\EnsureCompanyFeature;
use App\Http\Middleware\EnsureDemoSafeMode;
use App\Http\Middleware\EnsureTwoFactorVerified;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutMiddleware(EnsureCompanyFeature::class);
    $this->withoutMiddleware(EnsureDemoSafeMode::class);
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
});

function createTipsOwner(): User
{
    $role = Role::query()->firstOrCreate(
        ['name' => 'owner'],
        ['description' => 'Account owner role']
    );

    return User::query()->create([
        'name' => 'Tips Owner',
        'email' => 'tips-owner-' . uniqid('', true) . '@example.com',
        'role_id' => $role->id,
        'password' => 'password',
        'company_name' => 'Tips Company',
        'company_type' => 'services',
        'company_features' => ['invoices' => true],
        'onboarding_completed_at' => now(),
        'email_verified_at' => now(),
    ]);
}

function createTipsEmployee(User $owner, string $name): User
{
    $role = Role::query()->firstOrCreate(
        ['name' => 'employee'],
        ['description' => 'Employee role']
    );

    $employee = User::query()->create([
        'name' => $name,
        'email' => 'tips-employee-' . uniqid('', true) . '@example.com',
        'role_id' => $role->id,
        'password' => 'password',
        'company_type' => 'services',
        'onboarding_completed_at' => now(),
        'email_verified_at' => now(),
    ]);

    TeamMember::query()->create([
        'account_id' => $owner->id,
        'user_id' => $employee->id,
        'role' => 'member',
        'title' => 'Technician',
        'permissions' => ['jobs.view', 'tasks.view'],
        'is_active' => true,
    ]);

    return $employee;
}

function createInvoiceTipPayment(
    User $owner,
    ?User $tipAssignee,
    float $amount,
    float $tipAmount,
    ?\Illuminate\Support\Carbon $paidAt = null,
    string $status = 'completed'
): Payment {
    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
    ]);

    $work = Work::factory()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
    ]);

    $invoice = Invoice::query()->create([
        'work_id' => $work->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'status' => 'sent',
        'total' => $amount,
    ]);

    $payment = Payment::query()->create([
        'invoice_id' => $invoice->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'amount' => $amount,
        'tip_amount' => $tipAmount,
        'tip_type' => 'fixed',
        'tip_base_amount' => $amount,
        'charged_total' => $amount + $tipAmount,
        'tip_assignee_user_id' => $tipAssignee?->id,
        'method' => 'card',
        'status' => $status,
        'paid_at' => $paidAt ?? now(),
    ]);

    app(TipAllocationService::class)->syncForPayment($payment);

    return $payment;
}

test('owner tips dashboard shows filtered tip data and stats', function () {
    $owner = createTipsOwner();
    $employee = createTipsEmployee($owner, 'Team Member One');

    createInvoiceTipPayment($owner, $employee, 100, 10, now()->subHour());
    createInvoiceTipPayment($owner, $employee, 80, 5, now()->subMinutes(10));

    $response = $this
        ->actingAs($owner)
        ->get(route('payments.tips.index'));

    $response->assertOk();
    $response->assertInertia(fn(Assert $page) => $page
        ->component('Tips/OwnerIndex')
        ->where('stats.total_tips', 15)
        ->where('stats.reservation_count', 2)
        ->has('payments.data', 2)
        ->where('payments.data.0.team_member_name', 'Team Member One')
    );
});

test('owner tips export returns csv with tip columns', function () {
    $owner = createTipsOwner();
    $employee = createTipsEmployee($owner, 'Team Member Export');

    createInvoiceTipPayment($owner, $employee, 120, 12, now());

    $response = $this
        ->actingAs($owner)
        ->get(route('payments.tips.export'));

    $response->assertOk();
    $content = $response->streamedContent();

    expect($content)->toContain('tip_amount');
    expect($content)->toContain('Team Member Export');
});

test('team member tips page shows only own tips and supports anonymized customers', function () {
    $owner = createTipsOwner();
    $memberA = createTipsEmployee($owner, 'Member A');
    $memberB = createTipsEmployee($owner, 'Member B');

    createInvoiceTipPayment($owner, $memberA, 100, 10, now()->subMinutes(20));
    createInvoiceTipPayment($owner, $memberB, 100, 7, now()->subMinutes(10));

    $response = $this
        ->actingAs($memberA)
        ->get(route('my-earnings.tips.index'));

    $response->assertOk();
    $response->assertInertia(fn(Assert $page) => $page
        ->component('Tips/MemberIndex')
        ->where('stats.period_total', 10)
        ->has('payments.data', 1)
    );

    $anonymized = $this
        ->actingAs($memberA)
        ->get(route('my-earnings.tips.index', ['anonymize_customers' => 1]));

    $anonymized->assertOk();
    $anonymized->assertInertia(fn(Assert $page) => $page
        ->component('Tips/MemberIndex')
        ->where('payments.data.0.customer_name', fn($value) => str_starts_with((string) $value, 'Customer #'))
    );
});

test('team member tips page uses net allocation after partial reversal', function () {
    $owner = createTipsOwner();
    $member = createTipsEmployee($owner, 'Member Reversal');

    $payment = createInvoiceTipPayment($owner, $member, 100, 10, now()->subMinutes(20), 'reversed');
    $payment->forceFill([
        'tip_reversed_amount' => 4,
        'tip_reversed_at' => now()->subMinutes(10),
        'tip_reversal_rule' => 'prorata',
    ])->save();
    app(TipAllocationService::class)->reverseForPayment($payment->fresh(), 4, TipAllocationService::RULE_PRORATA, [], true);

    $response = $this
        ->actingAs($member)
        ->get(route('my-earnings.tips.index'));

    $response->assertOk();
    $response->assertInertia(fn(Assert $page) => $page
        ->component('Tips/MemberIndex')
        ->where('stats.period_total', 6)
        ->where('payments.data.0.status', 'reversed')
        ->where('payments.data.0.tip_amount', 6)
    );
});
