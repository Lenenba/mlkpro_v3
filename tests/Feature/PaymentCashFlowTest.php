<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\LoyaltyPointLedger;
use App\Models\LoyaltyProgram;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
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

function createSaleProduct(User $owner, array $attributes = []): Product
{
    $category = ProductCategory::query()->create([
        'name' => 'Test category ' . Str::random(6),
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
    ]);

    return Product::query()->create(array_merge([
        'name' => 'Product ' . Str::random(5),
        'description' => 'Test product',
        'category_id' => $category->id,
        'user_id' => $owner->id,
        'item_type' => Product::ITEM_TYPE_PRODUCT,
        'tracking_type' => 'none',
        'price' => 100.00,
        'stock' => 20,
        'minimum_stock' => 0,
    ], $attributes));
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

    $customer->refresh();
    $loyaltyProgram = LoyaltyProgram::query()->where('user_id', $owner->id)->first();
    $loyaltyEntry = LoyaltyPointLedger::query()
        ->where('payment_id', $payment->id)
        ->where('event', LoyaltyPointLedger::EVENT_ACCRUAL)
        ->first();

    expect($loyaltyProgram)->not->toBeNull()
        ->and($loyaltyEntry)->not->toBeNull()
        ->and((int) $loyaltyEntry->points)->toBe(120)
        ->and((int) $customer->loyalty_points_balance)->toBe(120);
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

    $customer->refresh();

    expect((int) $customer->loyalty_points_balance)->toBe(0)
        ->and(
            LoyaltyPointLedger::query()
                ->where('payment_id', $payment->id)
                ->where('event', LoyaltyPointLedger::EVENT_ACCRUAL)
                ->exists()
        )->toBeFalse();
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

    $customer->refresh();
    $loyaltyEntry = LoyaltyPointLedger::query()
        ->where('payment_id', $payment->id)
        ->where('event', LoyaltyPointLedger::EVENT_ACCRUAL)
        ->first();

    expect($loyaltyEntry)->not->toBeNull()
        ->and((int) $loyaltyEntry->points)->toBe(90)
        ->and((int) $customer->loyalty_points_balance)->toBe(90);
});

test('refund status removes loyalty points earned by the payment', function () {
    $owner = createOwnerUser([
        'company_type' => 'services',
        'payment_methods' => ['cash', 'card'],
        'default_payment_method' => 'card',
        'company_features' => ['invoices' => true],
    ]);

    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
    ]);

    $payment = Payment::query()->create([
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'amount' => 75.00,
        'method' => 'card',
        'status' => Payment::STATUS_COMPLETED,
        'paid_at' => now(),
    ]);

    $customer->refresh();
    expect((int) $customer->loyalty_points_balance)->toBe(75);

    $payment->update([
        'status' => Payment::STATUS_REFUNDED,
    ]);

    $customer->refresh();
    $refundEntry = LoyaltyPointLedger::query()
        ->where('payment_id', $payment->id)
        ->where('event', LoyaltyPointLedger::EVENT_REFUND)
        ->first();

    expect($refundEntry)->not->toBeNull()
        ->and((int) $refundEntry->points)->toBe(-75)
        ->and((int) $customer->loyalty_points_balance)->toBe(0);
});

test('pos sale can redeem loyalty points at checkout', function () {
    $owner = createOwnerUser([
        'company_type' => 'products',
        'payment_methods' => ['cash', 'card'],
        'default_payment_method' => 'cash',
        'company_features' => ['sales' => true],
    ]);

    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
        'loyalty_points_balance' => 120,
    ]);

    LoyaltyProgram::query()->create([
        'user_id' => $owner->id,
        'is_enabled' => true,
        'points_per_currency_unit' => 1,
        'minimum_spend' => 0,
        'rounding_mode' => 'floor',
        'points_label' => 'points',
    ]);

    $product = createSaleProduct($owner, [
        'price' => 100.00,
        'stock' => 10,
    ]);

    $this->actingAs($owner)
        ->post(route('sales.store'), [
            'customer_id' => $customer->id,
            'status' => Sale::STATUS_PENDING,
            'notes' => 'POS loyalty redeem test',
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'price' => 100.00,
                    'description' => 'Test item',
                ],
            ],
            'loyalty_points_redeem' => 40,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $sale = Sale::query()->latest('id')->first();
    $customer->refresh();

    $redemption = LoyaltyPointLedger::query()
        ->where('user_id', $owner->id)
        ->where('customer_id', $customer->id)
        ->where('event', LoyaltyPointLedger::EVENT_REDEMPTION)
        ->latest('id')
        ->first();

    expect($sale)->not->toBeNull()
        ->and((int) $sale->loyalty_points_redeemed)->toBe(40)
        ->and((float) $sale->loyalty_discount_total)->toBe(40.0)
        ->and((float) $sale->total)->toBe(60.0)
        ->and((int) $customer->loyalty_points_balance)->toBe(80)
        ->and($redemption)->not->toBeNull()
        ->and((int) $redemption->points)->toBe(-40)
        ->and((float) $redemption->amount)->toBe(40.0);
});

test('canceling pending sale restores redeemed loyalty points', function () {
    $owner = createOwnerUser([
        'company_type' => 'products',
        'payment_methods' => ['cash'],
        'default_payment_method' => 'cash',
        'company_features' => ['sales' => true],
    ]);

    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
        'loyalty_points_balance' => 75,
    ]);

    LoyaltyProgram::query()->create([
        'user_id' => $owner->id,
        'is_enabled' => true,
        'points_per_currency_unit' => 1,
        'minimum_spend' => 0,
        'rounding_mode' => 'floor',
        'points_label' => 'points',
    ]);

    $product = createSaleProduct($owner, [
        'price' => 50.00,
        'stock' => 10,
    ]);

    $this->actingAs($owner)
        ->post(route('sales.store'), [
            'customer_id' => $customer->id,
            'status' => Sale::STATUS_PENDING,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'price' => 50.00,
                    'description' => 'Cancelable item',
                ],
            ],
            'loyalty_points_redeem' => 25,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $sale = Sale::query()->latest('id')->firstOrFail();
    $customer->refresh();

    expect((int) $customer->loyalty_points_balance)->toBe(50)
        ->and((int) $sale->loyalty_points_redeemed)->toBe(25);

    $this->actingAs($owner)
        ->patch(route('sales.status.update', $sale), [
            'status' => Sale::STATUS_CANCELED,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $sale->refresh();
    $customer->refresh();

    expect($sale->status)->toBe(Sale::STATUS_CANCELED)
        ->and((int) $sale->loyalty_points_redeemed)->toBe(0)
        ->and((float) $sale->loyalty_discount_total)->toBe(0.0)
        ->and((int) $customer->loyalty_points_balance)->toBe(75)
        ->and(
            LoyaltyPointLedger::query()
                ->where('user_id', $owner->id)
                ->where('customer_id', $customer->id)
                ->where('event', LoyaltyPointLedger::EVENT_REDEMPTION_REVERSAL)
                ->exists()
        )->toBeTrue();
});
