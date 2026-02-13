<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Str;

function createOwnerUser(array $attributes = []): User
{
    $roleId = Role::query()->firstOrCreate(
        ['name' => 'owner'],
        ['description' => 'Account owner role']
    )->id;

    $defaults = [
        'name' => 'Owner Test',
        'email' => 'owner-' . Str::random(8) . '@example.com',
        'password' => 'password',
        'role_id' => $roleId,
        'company_type' => 'services',
        'onboarding_completed_at' => now(),
        'payment_methods' => ['cash'],
        'default_payment_method' => 'cash',
        'company_features' => ['invoices' => true, 'sales' => true],
    ];

    return User::query()->create(array_merge($defaults, $attributes));
}

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
});

test('invoice cash payment is created as pending and can be marked as paid', function () {
    $owner = createOwnerUser([
        'company_type' => 'services',
        'payment_methods' => ['cash'],
        'default_payment_method' => 'cash',
        'company_features' => ['invoices' => true],
    ]);

    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
    ]);

    $invoice = Invoice::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'work_id' => Work::query()->create([
            'user_id' => $owner->id,
            'customer_id' => $customer->id,
            'job_title' => 'Invoice cash flow test',
            'instructions' => 'Test instructions',
            'status' => Work::STATUS_IN_PROGRESS,
        ])->id,
        'status' => 'sent',
        'total' => 120.00,
    ]);

    $this->actingAs($owner)
        ->post(route('payment.store', $invoice), [
            'amount' => 120.00,
            'method' => 'cash',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $payment = Payment::query()
        ->where('invoice_id', $invoice->id)
        ->latest('id')
        ->first();

    expect($payment)->not->toBeNull()
        ->and($payment->status)->toBe(Payment::STATUS_PENDING)
        ->and($payment->paid_at)->toBeNull();

    expect($invoice->refresh()->status)->toBe('sent');

    $this->actingAs($owner)
        ->patch(route('payment.mark-paid', $payment))
        ->assertRedirect();

    $payment->refresh();
    $invoice->refresh();

    expect($payment->status)->toBe(Payment::STATUS_PAID)
        ->and($payment->paid_at)->not->toBeNull()
        ->and($invoice->status)->toBe('paid')
        ->and(ActivityLog::query()->where('action', 'cash_marked_paid')->where('subject_id', $payment->id)->exists())
        ->toBeTrue();
});

test('sale manual cash payment is created as pending', function () {
    $owner = createOwnerUser([
        'company_type' => 'products',
        'payment_methods' => ['cash'],
        'default_payment_method' => 'cash',
        'company_features' => ['sales' => true],
    ]);

    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
    ]);

    $sale = Sale::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => Sale::STATUS_PENDING,
        'source' => 'portal',
        'subtotal' => 60.00,
        'tax_total' => 0,
        'total' => 60.00,
    ]);

    $this->actingAs($owner)
        ->post(route('sales.payments.store', $sale), [
            'amount' => 60.00,
            'method' => 'cash',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $payment = Payment::query()
        ->where('sale_id', $sale->id)
        ->latest('id')
        ->first();

    expect($payment)->not->toBeNull()
        ->and($payment->status)->toBe(Payment::STATUS_PENDING)
        ->and($payment->paid_at)->toBeNull()
        ->and($sale->refresh()->status)->toBe(Sale::STATUS_PENDING);
});

test('marking pending sale cash payment as paid updates sale status', function () {
    $owner = createOwnerUser([
        'company_type' => 'products',
        'payment_methods' => ['cash'],
        'default_payment_method' => 'cash',
        'company_features' => ['sales' => true],
    ]);

    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
    ]);

    $sale = Sale::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => Sale::STATUS_PENDING,
        'source' => 'portal',
        'subtotal' => 90.00,
        'tax_total' => 0,
        'total' => 90.00,
    ]);

    $payment = Payment::query()->create([
        'sale_id' => $sale->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'amount' => 90.00,
        'method' => 'cash',
        'status' => Payment::STATUS_PENDING,
        'paid_at' => null,
    ]);

    $this->actingAs($owner)
        ->patch(route('payment.mark-paid', $payment))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($payment->refresh()->status)->toBe(Payment::STATUS_PAID)
        ->and($sale->refresh()->status)->toBe(Sale::STATUS_PAID)
        ->and($sale->payment_status)->toBe(Sale::STATUS_PAID);
});
