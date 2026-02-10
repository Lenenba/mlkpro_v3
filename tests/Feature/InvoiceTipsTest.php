<?php

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use App\Models\Work;
use App\Services\TipAllocationService;
use App\Services\StripeInvoiceService;
use App\Http\Middleware\EnsureCompanyFeature;
use App\Http\Middleware\EnsureDemoSafeMode;
use App\Http\Middleware\EnsureTwoFactorVerified;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

beforeEach(function () {
    $this->withoutMiddleware(EnsureCompanyFeature::class);
    $this->withoutMiddleware(EnsureDemoSafeMode::class);
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
    $this->withoutMiddleware(ValidateCsrfToken::class);
});

function createOwnerForInvoiceTips(): User
{
    $role = Role::query()->firstOrCreate(
        ['name' => 'owner'],
        ['description' => 'Account owner role']
    );

    return User::query()->create([
        'name' => 'Invoice Tip Owner',
        'email' => 'owner-tip-' . uniqid('', true) . '@example.com',
        'role_id' => $role->id,
        'password' => 'password',
        'company_name' => 'Tip Company',
        'company_type' => 'services',
        'company_features' => ['invoices' => true],
        'onboarding_completed_at' => now(),
        'payment_methods' => ['cash', 'card'],
        'email_verified_at' => now(),
    ]);
}

test('invoice balance logic ignores tip amount and uses payment amount only', function () {
    $user = createOwnerForInvoiceTips();
    $customer = Customer::factory()->create([
        'user_id' => $user->id,
    ]);
    $work = Work::factory()->create([
        'user_id' => $user->id,
        'customer_id' => $customer->id,
    ]);

    $invoice = Invoice::create([
        'work_id' => $work->id,
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'status' => 'sent',
        'total' => 100,
    ]);

    $payment = Payment::query()->create([
        'invoice_id' => $invoice->id,
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'amount' => 90,
        'tip_amount' => 10,
        'tip_type' => 'fixed',
        'tip_base_amount' => 90,
        'charged_total' => 100,
        'method' => 'card',
        'status' => 'completed',
        'paid_at' => now(),
    ]);

    $invoice->refreshPaymentStatus();

    expect($payment)->not->toBeNull();
    expect((float) $payment->amount)->toBe(90.0);
    expect((float) $payment->tip_amount)->toBe(10.0);
    expect($payment->tip_type)->toBe('fixed');
    expect((float) $payment->tip_base_amount)->toBe(90.0);
    expect((float) $payment->charged_total)->toBe(100.0);

    $invoice->refresh();
    expect($invoice->status)->toBe('partial');
    expect($invoice->amount_paid)->toBe(90.0);
    expect($invoice->balance_due)->toBe(10.0);
});

test('stripe invoice sync stores tip amount from checkout metadata', function () {
    $user = createOwnerForInvoiceTips();
    $customer = Customer::factory()->create([
        'user_id' => $user->id,
    ]);
    $work = Work::factory()->create([
        'user_id' => $user->id,
        'customer_id' => $customer->id,
    ]);

    $invoice = Invoice::create([
        'work_id' => $work->id,
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'status' => 'sent',
        'total' => 80,
    ]);

    $payment = app(StripeInvoiceService::class)->recordPaymentFromCheckoutSession([
        'id' => 'cs_test_tip_01',
        'payment_status' => 'paid',
        'payment_intent' => 'pi_test_tip_01',
        'amount_total' => 9200,
        'metadata' => [
            'invoice_id' => (string) $invoice->id,
            'payment_amount' => '80.00',
            'tip_amount' => '12.00',
            'tip_type' => 'percent',
            'tip_percent' => '15.00',
            'tip_base_amount' => '80.00',
            'charged_total' => '92.00',
        ],
    ]);

    expect($payment)->not->toBeNull();
    expect((float) $payment->amount)->toBe(80.0);
    expect((float) $payment->tip_amount)->toBe(12.0);
    expect($payment->tip_type)->toBe('percent');
    expect((float) $payment->tip_percent)->toBe(15.0);
    expect((float) $payment->tip_base_amount)->toBe(80.0);
    expect((float) $payment->charged_total)->toBe(92.0);

    $invoice->refresh();
    expect($invoice->status)->toBe('paid');
    expect($invoice->amount_paid)->toBe(80.0);
    expect($invoice->balance_due)->toBe(0.0);
});

test('owner can reverse tip and allocation is updated', function () {
    $owner = createOwnerForInvoiceTips();
    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
    ]);
    $work = Work::factory()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
    ]);

    $invoice = Invoice::create([
        'work_id' => $work->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'status' => 'sent',
        'total' => 120,
    ]);

    $payment = Payment::query()->create([
        'invoice_id' => $invoice->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'amount' => 120,
        'tip_amount' => 12,
        'tip_type' => 'fixed',
        'tip_base_amount' => 120,
        'charged_total' => 132,
        'tip_assignee_user_id' => $owner->id,
        'method' => 'card',
        'status' => 'completed',
        'paid_at' => now(),
    ]);

    app(TipAllocationService::class)->syncForPayment($payment);

    $response = $this
        ->actingAs($owner)
        ->post(route('payment.tip-reverse', $payment), [
            'amount' => 5,
            'rule' => 'prorata',
            'reason' => 'Customer request',
        ]);

    $response->assertRedirect();

    $payment->refresh();
    $allocation = $payment->tipAllocations()->first();

    expect((float) $payment->tip_reversed_amount)->toBe(5.0);
    expect($payment->status)->toBe('reversed');
    expect($payment->tip_reversal_rule)->toBe('prorata');
    expect((float) ($allocation?->reversed_amount ?? 0))->toBe(5.0);
});
