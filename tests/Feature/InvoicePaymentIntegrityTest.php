<?php

use App\Actions\Invoices\CreateInvoicePaymentAction;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use App\Models\Work;
use App\Notifications\ActionEmailNotification;
use App\Services\StripeInvoiceService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/** @return array{owner: User, client: User, customer: Customer, invoice: Invoice, work: Work} */
function invoiceIntegrityContext(): array
{
    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_features' => ['invoices' => true, 'loyalty' => true],
        'payment_methods' => ['cash', 'card', 'bank_transfer', 'check'],
        'default_payment_method' => 'card',
    ]);
    $client = User::factory()->create([
        'role_id' => Role::query()->firstOrCreate(['name' => 'client'])->id,
    ]);
    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
        'portal_user_id' => $client->id,
        'portal_access' => true,
        'auto_validate_invoices' => false,
    ]);
    $work = Work::factory()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => Work::STATUS_IN_PROGRESS,
    ]);
    $invoice = Invoice::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'work_id' => $work->id,
        'status' => 'sent',
        'total' => 100,
        'currency_code' => 'CAD',
    ]);

    return compact('owner', 'client', 'customer', 'invoice', 'work');
}

function invoiceIntegrityPaymentUrl(Invoice $invoice, string $surface): string
{
    return match ($surface) {
        'public' => URL::temporarySignedRoute('public.invoices.pay', now()->addMinutes(30), ['invoice' => $invoice]),
        'api' => '/api/v1/portal/invoices/'.$invoice->id.'/payments',
        default => route('portal.invoices.payments.store', $invoice),
    };
}

test('client manual invoice routes cannot record a settled card payment', function (string $surface, ?string $method) {
    ['client' => $client, 'invoice' => $invoice, 'work' => $work] = invoiceIntegrityContext();
    $this->withoutMiddleware(ValidateCsrfToken::class);
    Http::preventStrayRequests();
    if ($surface !== 'public') {
        $this->actingAs($client);
    }

    $this->postJson(invoiceIntegrityPaymentUrl($invoice, $surface), [
        'amount' => 100,
        'method' => $method,
        'status' => Payment::STATUS_COMPLETED,
    ])->assertUnprocessable()->assertJsonValidationErrors('method');

    $this->assertDatabaseMissing('payments', ['invoice_id' => $invoice->id]);
    expect($invoice->fresh()->status)->toBe('sent');
    expect($work->fresh()->status)->toBe(Work::STATUS_IN_PROGRESS);
})->with(['public', 'portal', 'api'])->with(['card', 'stripe', null]);

test('client declarations remain pending until the owner confirms receipt', function (string $method, string $surface) {
    ['owner' => $owner, 'client' => $client, 'invoice' => $invoice] = invoiceIntegrityContext();
    $this->withoutMiddleware(ValidateCsrfToken::class);
    Notification::fake();
    if ($surface === 'portal') {
        $this->actingAs($client);
    }

    $response = $this->postJson(invoiceIntegrityPaymentUrl($invoice, $surface), [
        'amount' => 100,
        'method' => $method,
        'status' => Payment::STATUS_COMPLETED,
        'paid_at' => now()->toDateString(),
    ]);
    $surface === 'public' ? $response->assertRedirect() : $response->assertOk();

    $payment = $invoice->payments()->sole();
    expect($payment->status)->toBe(Payment::STATUS_PENDING)->and($payment->paid_at)->toBeNull();
    expect($invoice->fresh()->balance_due)->toBe(100.0);
    Notification::assertSentToTimes($owner, ActionEmailNotification::class, 1);

    $this->actingAs($owner)->patchJson(route('payment.mark-paid', $payment))->assertOk();
    expect($payment->fresh()->status)->toBe(Payment::STATUS_PAID);
    expect($invoice->fresh()->status)->toBe('paid');
})->with(['cash', 'bank_transfer', 'check'])->with(['public', 'portal']);

test('public and portal payment retries reuse the payment without duplicate notifications', function (string $surface) {
    ['owner' => $owner, 'client' => $client, 'invoice' => $invoice] = invoiceIntegrityContext();
    $this->withoutMiddleware(ValidateCsrfToken::class);
    Notification::fake();
    if ($surface === 'portal') {
        $this->actingAs($client);
    }
    $url = invoiceIntegrityPaymentUrl($invoice, $surface);
    $payload = ['amount' => 35, 'method' => 'cash', 'idempotency_key' => 'client-intention-1'];

    foreach (range(1, 2) as $attempt) {
        $response = $this->postJson($url, $payload);
        $surface === 'public' ? $response->assertRedirect() : $response->assertOk();
    }

    expect($invoice->payments()->count())->toBe(1);
    Notification::assertSentToTimes($owner, ActionEmailNotification::class, 1);
    expect(ActivityLog::query()->where('subject_type', Payment::class)->where('action', 'created')->count())->toBe(1);
})->with(['public', 'portal']);

test('manual owner payment accepts an idempotency header and replays even after the invoice is paid', function () {
    ['owner' => $owner, 'invoice' => $invoice] = invoiceIntegrityContext();
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $payload = ['amount' => 100, 'method' => 'card'];

    $first = $this->actingAs($owner)->postJson(route('payment.store', $invoice), $payload, ['Idempotency-Key' => 'owner-intention-1'])->assertCreated();
    $this->postJson(route('payment.store', $invoice), $payload, ['Idempotency-Key' => 'owner-intention-1'])
        ->assertCreated()->assertJsonPath('payment.id', $first->json('payment.id'));

    expect($invoice->payments()->count())->toBe(1);
    expect($invoice->fresh()->balance_due)->toBe(0.0);
});

test('a payment key cannot be reused for a different amount', function () {
    ['owner' => $owner, 'invoice' => $invoice] = invoiceIntegrityContext();
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->actingAs($owner)->postJson(route('payment.store', $invoice), [
        'amount' => 20, 'method' => 'card', 'idempotency_key' => 'one-payment',
    ])->assertCreated();

    $this->postJson(route('payment.store', $invoice), [
        'amount' => 30, 'method' => 'card', 'idempotency_key' => 'one-payment',
    ])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');

    expect($invoice->payments()->count())->toBe(1);
    expect($invoice->fresh()->balance_due)->toBe(80.0);
});

test('a stale invoice cannot allow a second payment beyond its updated balance', function () {
    ['owner' => $owner, 'invoice' => $invoice] = invoiceIntegrityContext();
    $staleInvoice = $invoice->fresh()->load('payments');
    $action = app(CreateInvoicePaymentAction::class);
    $action->execute($invoice, ['amount' => 70], 'card', $owner);

    expect(fn () => $action->execute($staleInvoice, ['amount' => 40], 'card', $owner))
        ->toThrow(ValidationException::class);

    expect($invoice->payments()->count())->toBe(1);
    expect($invoice->fresh()->balance_due)->toBe(30.0);
});

test('the same payment key remains scoped to its invoice', function () {
    ['owner' => $owner, 'invoice' => $invoice] = invoiceIntegrityContext();
    $secondInvoice = $invoice->replicate(['number']);
    $secondInvoice->save();
    $action = app(CreateInvoicePaymentAction::class);
    $attributes = ['amount' => 20, 'idempotency_key' => 'same-key-different-invoice'];

    $first = $action->execute($invoice, $attributes, 'card', $owner)['payment'];
    $second = $action->execute($secondInvoice, $attributes, 'card', $owner)['payment'];

    expect($first->id)->not->toBe($second->id);
    expect($invoice->fresh()->balance_due)->toBe(80.0);
    expect($secondInvoice->fresh()->balance_due)->toBe(80.0);
});

test('a client cannot confirm receipt of its own declared payment', function () {
    ['owner' => $owner, 'client' => $client, 'invoice' => $invoice] = invoiceIntegrityContext();
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $pending = app(CreateInvoicePaymentAction::class)->execute(
        $invoice, ['amount' => 100], 'bank_transfer', $owner, clientInitiated: true,
    )['payment'];

    $this->actingAs($client)->patchJson(route('payment.mark-paid', $pending))->assertForbidden();

    expect($pending->fresh()->status)->toBe(Payment::STATUS_PENDING);
    expect($invoice->fresh()->balance_due)->toBe(100.0);
});

test('owner confirmation does not settle an unconfirmed card payment', function () {
    ['owner' => $owner, 'invoice' => $invoice] = invoiceIntegrityContext();
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $pending = app(CreateInvoicePaymentAction::class)->execute(
        $invoice, ['amount' => 100, 'status' => Payment::STATUS_PENDING], 'card', $owner,
    )['payment'];

    $this->actingAs($owner)->patchJson(route('payment.mark-paid', $pending))->assertUnprocessable();

    expect($pending->fresh()->status)->toBe(Payment::STATUS_PENDING);
    expect($invoice->fresh()->balance_due)->toBe(100.0);
});

test('payment failure rolls back the payment invoice and loyalty changes and permits retry', function () {
    ['owner' => $owner, 'customer' => $customer, 'invoice' => $invoice, 'work' => $work] = invoiceIntegrityContext();
    $failJournal = true;
    Event::listen('eloquent.creating: '.ActivityLog::class, function (ActivityLog $log) use (&$failJournal): void {
        if ($failJournal && $log->subject_type === Payment::class) {
            throw new RuntimeException('Simulated payment journal failure');
        }
    });
    $attributes = ['amount' => 100, 'idempotency_key' => 'retry-after-failure'];
    $action = app(CreateInvoicePaymentAction::class);

    expect(fn () => $action->execute($invoice, $attributes, 'card', $owner))
        ->toThrow(RuntimeException::class, 'Simulated payment journal failure');

    $this->assertDatabaseMissing('payments', ['invoice_id' => $invoice->id]);
    $this->assertDatabaseMissing('loyalty_point_ledgers', ['customer_id' => $customer->id]);
    expect($invoice->fresh()->status)->toBe('sent');
    expect($customer->fresh()->loyalty_points_balance)->toBe(0);
    expect($work->fresh()->status)->toBe(Work::STATUS_IN_PROGRESS);

    $failJournal = false;
    $result = $action->execute($invoice, $attributes, 'card', $owner);
    expect($result['replayed'])->toBeFalse();
    expect($invoice->payments()->count())->toBe(1);
    expect($invoice->fresh()->status)->toBe('paid');
});

test('manual settlement cannot overpay an invoice that was paid elsewhere', function () {
    ['owner' => $owner, 'invoice' => $invoice] = invoiceIntegrityContext();
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $action = app(CreateInvoicePaymentAction::class);
    $pending = $action->execute($invoice, ['amount' => 100], 'bank_transfer', $owner, clientInitiated: true)['payment'];
    $action->execute($invoice, ['amount' => 100], 'card', $owner);

    $this->actingAs($owner)->patchJson(route('payment.mark-paid', $pending))->assertUnprocessable();

    expect($pending->fresh()->status)->toBe(Payment::STATUS_PENDING);
    expect($invoice->fresh()->amount_paid)->toBe(100.0);
});

test('a pending manual payment cannot be confirmed after the invoice becomes unpayable', function (string $status) {
    ['owner' => $owner, 'customer' => $customer, 'invoice' => $invoice] = invoiceIntegrityContext();
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $pending = app(CreateInvoicePaymentAction::class)->execute(
        $invoice, ['amount' => 100], 'check', $owner, clientInitiated: true,
    )['payment'];
    $invoice->update(['status' => $status]);

    $this->actingAs($owner)->patchJson(route('payment.mark-paid', $pending))->assertUnprocessable();

    expect($pending->fresh()->status)->toBe(Payment::STATUS_PENDING)->and($pending->fresh()->paid_at)->toBeNull();
    expect($customer->fresh()->loyalty_points_balance)->toBe(0);
    expect($invoice->fresh()->status)->toBe($status);
    $this->assertDatabaseMissing('loyalty_point_ledgers', ['payment_id' => $pending->id]);
})->with(['void', 'draft']);

test('card checkout remains available without recording an unconfirmed payment', function (string $surface) {
    ['client' => $client, 'invoice' => $invoice] = invoiceIntegrityContext();
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->mock(StripeInvoiceService::class, function ($mock) use ($invoice): void {
        $mock->shouldReceive('isConfigured')->once()->andReturnTrue();
        $mock->shouldReceive('createCheckoutSession')->once()
            ->withArgs(fn (Invoice $candidate, string $success, string $cancel, ?float $amount, array $tip): bool => $candidate->is($invoice) && $amount === 100.0)
            ->andReturn(['url' => 'https://checkout.stripe.test/session']);
    });
    $url = $surface === 'public'
        ? URL::temporarySignedRoute('public.invoices.stripe', now()->addMinutes(30), ['invoice' => $invoice])
        : route('portal.invoices.stripe', $invoice);
    if ($surface === 'portal') {
        $this->actingAs($client);
    }

    $this->post($url, ['amount' => 100])->assertRedirect('https://checkout.stripe.test/session');

    $this->assertDatabaseMissing('payments', ['invoice_id' => $invoice->id]);
    expect($invoice->fresh()->status)->toBe('sent');
})->with(['public', 'portal']);
