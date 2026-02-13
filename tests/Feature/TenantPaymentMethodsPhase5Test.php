<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use App\Models\Work;
use App\Services\TenantPaymentMethodGuardService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function phase5RoleId(string $name): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $name . ' role']
    )->id;
}

function phase5CreateOwner(array $attributes = []): User
{
    $defaults = [
        'name' => 'Phase5 Owner',
        'email' => 'owner-' . Str::lower(Str::random(10)) . '@example.com',
        'password' => 'password',
        'role_id' => phase5RoleId('owner'),
        'company_type' => 'services',
        'onboarding_completed_at' => now(),
        'payment_methods' => ['cash', 'card'],
        'default_payment_method' => 'card',
        'company_features' => ['invoices' => true, 'sales' => true],
    ];

    return User::query()->create(array_merge($defaults, $attributes));
}

function phase5CreateClient(array $attributes = []): User
{
    $defaults = [
        'name' => 'Phase5 Client',
        'email' => 'client-' . Str::lower(Str::random(10)) . '@example.com',
        'password' => 'password',
        'role_id' => phase5RoleId('client'),
        'onboarding_completed_at' => now(),
    ];

    return User::query()->create(array_merge($defaults, $attributes));
}

function phase5CreateCustomerFor(User $owner, ?User $portalUser = null): Customer
{
    return Customer::query()->create([
        'user_id' => $owner->id,
        'portal_user_id' => $portalUser?->id,
        'portal_access' => $portalUser !== null,
        'first_name' => 'Client',
        'last_name' => 'Phase5',
        'company_name' => 'Phase5 Customer',
        'email' => 'customer-' . Str::lower(Str::random(12)) . '@example.com',
        'phone' => '+15145550000',
        'salutation' => 'Mr',
    ]);
}

function phase5CreateInvoiceFor(User $owner, Customer $customer, float $total = 120.00): Invoice
{
    $work = Work::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'job_title' => 'Phase5 Invoice',
        'instructions' => 'Phase5 invoice test',
        'status' => Work::STATUS_IN_PROGRESS,
    ]);

    return Invoice::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'work_id' => $work->id,
        'status' => 'sent',
        'total' => $total,
    ]);
}

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
});

test('stripe-only tenant rejects disallowed invoice payment methods in web and json flows', function () {
    $owner = phase5CreateOwner([
        'payment_methods' => ['card'],
        'default_payment_method' => 'card',
    ]);
    $customer = phase5CreateCustomerFor($owner);
    $invoice = phase5CreateInvoiceFor($owner, $customer);

    $this->actingAs($owner)
        ->from(route('invoice.show', $invoice))
        ->post(route('payment.store', $invoice), [
            'amount' => 20.00,
            'method' => 'cash',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors(['method']);

    $this->actingAs($owner)
        ->postJson(route('payment.store', $invoice), [
            'amount' => 20.00,
            'method' => 'other',
        ])
        ->assertStatus(422)
        ->assertJson([
            'code' => TenantPaymentMethodGuardService::ERROR_CODE,
            'message' => TenantPaymentMethodGuardService::ERROR_MESSAGE,
        ]);

    expect(Payment::query()->where('invoice_id', $invoice->id)->count())->toBe(0);
});

test('cash-only tenant rejects stripe for internal and public invoice payment endpoints', function () {
    $owner = phase5CreateOwner([
        'payment_methods' => ['cash'],
        'default_payment_method' => 'cash',
    ]);
    $customer = phase5CreateCustomerFor($owner);
    $invoice = phase5CreateInvoiceFor($owner, $customer);

    $this->actingAs($owner)
        ->postJson(route('payment.store', $invoice), [
            'amount' => 20.00,
            'method' => 'stripe',
        ])
        ->assertStatus(422)
        ->assertJson([
            'code' => TenantPaymentMethodGuardService::ERROR_CODE,
            'message' => TenantPaymentMethodGuardService::ERROR_MESSAGE,
        ]);

    $publicPayUrl = URL::temporarySignedRoute(
        'public.invoices.pay',
        now()->addMinutes(30),
        ['invoice' => $invoice->id]
    );

    $this->postJson($publicPayUrl, [
        'amount' => 20.00,
        'method' => 'stripe',
    ])
        ->assertStatus(422)
        ->assertJson([
            'code' => TenantPaymentMethodGuardService::ERROR_CODE,
            'message' => TenantPaymentMethodGuardService::ERROR_MESSAGE,
        ]);

    expect(Payment::query()->where('invoice_id', $invoice->id)->count())->toBe(0);
});

test('cash-and-stripe tenant accepts both methods on invoice payments', function () {
    $owner = phase5CreateOwner([
        'payment_methods' => ['cash', 'card'],
        'default_payment_method' => 'card',
    ]);
    $customer = phase5CreateCustomerFor($owner);
    $invoice = phase5CreateInvoiceFor($owner, $customer, 200.00);

    $cashResponse = $this->actingAs($owner)
        ->postJson(route('payment.store', $invoice), [
            'amount' => 50.00,
            'method' => 'cash',
        ]);
    $cashResponse->assertCreated();

    $stripeResponse = $this->actingAs($owner)
        ->postJson(route('payment.store', $invoice), [
            'amount' => 80.00,
            'method' => 'stripe',
        ]);
    $stripeResponse->assertCreated();

    $cashPaymentId = (int) $cashResponse->json('payment.id');
    $stripePaymentId = (int) $stripeResponse->json('payment.id');

    $cashPayment = Payment::query()->findOrFail($cashPaymentId);
    $stripePayment = Payment::query()->findOrFail($stripePaymentId);

    expect($cashPayment->method)->toBe('cash')
        ->and($cashPayment->status)->toBe(Payment::STATUS_PENDING)
        ->and($cashPayment->paid_at)->toBeNull()
        ->and($stripePayment->method)->toBe('card')
        ->and($stripePayment->status)->toBe(Payment::STATUS_COMPLETED)
        ->and($stripePayment->paid_at)->not->toBeNull();
});

test('portal invoice payment endpoint enforces tenant payment policy', function () {
    $owner = phase5CreateOwner([
        'payment_methods' => ['card'],
        'default_payment_method' => 'card',
    ]);
    $portalClient = phase5CreateClient();
    $customer = phase5CreateCustomerFor($owner, $portalClient);
    $invoice = phase5CreateInvoiceFor($owner, $customer);

    $this->actingAs($portalClient)
        ->postJson(route('portal.invoices.payments.store', $invoice), [
            'amount' => 30.00,
            'method' => 'cash',
        ])
        ->assertStatus(422)
        ->assertJson([
            'code' => TenantPaymentMethodGuardService::ERROR_CODE,
            'message' => TenantPaymentMethodGuardService::ERROR_MESSAGE,
        ]);

    expect(Payment::query()->where('invoice_id', $invoice->id)->count())->toBe(0);
});

test('invoice show page receives one payment method for single-method tenants', function () {
    $owner = phase5CreateOwner([
        'payment_methods' => ['cash'],
        'default_payment_method' => 'cash',
    ]);
    $customer = phase5CreateCustomerFor($owner);
    $invoice = phase5CreateInvoiceFor($owner, $customer);

    $this->actingAs($owner)
        ->get(route('invoice.show', $invoice))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Invoice/Show')
            ->where('paymentMethodSettings.enabled_methods_internal', ['cash'])
            ->where('paymentMethodSettings.enabled_methods', ['cash'])
            ->where('paymentMethodSettings.default_method_internal', 'cash')
            ->where('paymentMethodSettings.default_method', 'cash')
        );
});

test('invoice show page receives multiple payment methods when tenant enables several options', function () {
    $owner = phase5CreateOwner([
        'payment_methods' => ['cash', 'card'],
        'default_payment_method' => 'card',
    ]);
    $customer = phase5CreateCustomerFor($owner);
    $invoice = phase5CreateInvoiceFor($owner, $customer);

    $this->actingAs($owner)
        ->get(route('invoice.show', $invoice))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Invoice/Show')
            ->where('paymentMethodSettings.enabled_methods_internal', ['cash', 'card'])
            ->where('paymentMethodSettings.enabled_methods', ['cash', 'stripe'])
            ->where('paymentMethodSettings.default_method_internal', 'card')
            ->where('paymentMethodSettings.default_method', 'stripe')
        );
});

test('public invoice screen omits unauthorized methods from payment settings', function () {
    $owner = phase5CreateOwner([
        'payment_methods' => ['card'],
        'default_payment_method' => 'card',
    ]);
    $customer = phase5CreateCustomerFor($owner);
    $invoice = phase5CreateInvoiceFor($owner, $customer);

    $publicShowUrl = URL::temporarySignedRoute(
        'public.invoices.show',
        now()->addMinutes(30),
        ['invoice' => $invoice->id]
    );

    $this->get($publicShowUrl)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/InvoicePay')
            ->where('paymentMethodSettings.enabled_methods_internal', ['card'])
            ->where('paymentMethodSettings.enabled_methods', ['stripe'])
            ->where('paymentMethodSettings.default_method_internal', 'card')
            ->where('paymentMethodSettings.default_method', 'stripe')
        );
});

