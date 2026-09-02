<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Quote;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function phase7PortalRoleId(string $name): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $name.' role']
    )->id;
}

function phase7CreatePortalOwner(array $attributes = []): User
{
    $defaults = [
        'name' => 'Phase7 Portal Owner',
        'email' => 'phase7-portal-owner-'.Str::lower(Str::random(10)).'@example.com',
        'password' => 'password',
        'role_id' => phase7PortalRoleId('owner'),
        'company_type' => 'services',
        'onboarding_completed_at' => now(),
    ];

    return User::query()->create(array_merge($defaults, $attributes));
}

function phase7CreatePortalClient(array $attributes = []): User
{
    $defaults = [
        'name' => 'Phase7 Portal Client',
        'email' => 'phase7-portal-client-'.Str::lower(Str::random(10)).'@example.com',
        'password' => 'password',
        'role_id' => phase7PortalRoleId('client'),
        'onboarding_completed_at' => now(),
    ];

    return User::query()->create(array_merge($defaults, $attributes));
}

function phase7CreatePortalCustomer(User $owner, User $portalUser, array $attributes = []): Customer
{
    $defaults = [
        'user_id' => $owner->id,
        'portal_user_id' => $portalUser->id,
        'portal_access' => true,
        'first_name' => 'Portal',
        'last_name' => 'Customer',
        'company_name' => 'Portal Customer',
        'email' => 'portal-customer-'.Str::lower(Str::random(10)).'@example.com',
        'phone' => '+15145550000',
    ];

    return Customer::query()->create(array_merge($defaults, $attributes));
}

function phase7CreatePortalInvoice(User $owner, Customer $customer): Invoice
{
    $work = Work::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'job_title' => 'Portal Invoice Work',
        'instructions' => 'Portal invoice work',
        'status' => Work::STATUS_IN_PROGRESS,
    ]);

    return Invoice::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'work_id' => $work->id,
        'status' => 'sent',
        'total' => 150.00,
    ]);
}

function phase7CreatePortalQuote(User $owner, Customer $customer): Quote
{
    return Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'job_title' => 'Portal Quote',
        'status' => 'sent',
        'subtotal' => 200.00,
        'total' => 200.00,
        'initial_deposit' => 0,
    ]);
}

function phase7CreatePortalWork(User $owner, Customer $customer): Work
{
    return Work::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'job_title' => 'Portal Work',
        'instructions' => 'Portal work proof',
        'status' => Work::STATUS_IN_PROGRESS,
    ]);
}

function phase7CreatePortalSale(User $owner, Customer $customer): Sale
{
    return Sale::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => Sale::STATUS_PENDING,
        'subtotal' => 75.00,
        'tax_total' => 0,
        'discount_rate' => 0,
        'discount_total' => 0,
        'total' => 75.00,
        'fulfillment_method' => 'delivery',
        'fulfillment_status' => Sale::FULFILLMENT_PENDING,
        'source' => 'portal',
    ]);
}

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
});

it('logs out a client whose portal access was disabled', function () {
    $owner = phase7CreatePortalOwner();
    $client = phase7CreatePortalClient();
    phase7CreatePortalCustomer($owner, $client, [
        'portal_access' => false,
    ]);

    $response = $this->actingAs($client)->get(route('dashboard'));

    $response
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors([
            'email' => __('ui.auth.portal_access_disabled'),
        ]);
    $this->assertGuest();
});

it('returns 403 json without clearing the web session when portal access was disabled', function () {
    $owner = phase7CreatePortalOwner();
    $client = phase7CreatePortalClient();
    phase7CreatePortalCustomer($owner, $client, [
        'portal_access' => false,
    ]);

    $this->actingAs($client)
        ->getJson(route('portal.invoices.index'))
        ->assertForbidden()
        ->assertJsonPath('message', __('ui.auth.portal_access_disabled'));
    $this->assertAuthenticatedAs($client);
});

it('keeps an html impersonation session active when the client portal is disabled', function () {
    $owner = phase7CreatePortalOwner();
    $client = phase7CreatePortalClient();
    $impersonator = phase7CreatePortalClient([
        'role_id' => phase7PortalRoleId('superadmin'),
    ]);
    phase7CreatePortalCustomer($owner, $client, [
        'portal_access' => false,
    ]);

    $this->actingAs($client)
        ->withSession(['impersonator_id' => $impersonator->id])
        ->get(route('dashboard'))
        ->assertOk();
    $this->assertAuthenticatedAs($client);
});

it('forbids portal invoice access for an unrelated client', function () {
    $owner = phase7CreatePortalOwner();
    $allowedClient = phase7CreatePortalClient();
    $otherClient = phase7CreatePortalClient();

    $allowedCustomer = phase7CreatePortalCustomer($owner, $allowedClient);
    phase7CreatePortalCustomer($owner, $otherClient);
    $invoice = phase7CreatePortalInvoice($owner, $allowedCustomer);

    $this->actingAs($otherClient)
        ->getJson(route('portal.invoices.show', $invoice))
        ->assertForbidden();
});

it('shows only the connected customer invoice and payment history', function () {
    $owner = phase7CreatePortalOwner();
    $client = phase7CreatePortalClient();
    $otherClient = phase7CreatePortalClient();

    $customer = phase7CreatePortalCustomer($owner, $client);
    $otherCustomer = phase7CreatePortalCustomer($owner, $otherClient);
    $invoice = phase7CreatePortalInvoice($owner, $customer);
    phase7CreatePortalInvoice($owner, $otherCustomer);

    Payment::query()->create([
        'invoice_id' => $invoice->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'amount' => 40,
        'tip_amount' => 5,
        'charged_total' => 45,
        'method' => 'cash',
        'provider' => 'manual',
        'status' => Payment::STATUS_COMPLETED,
        'paid_at' => now(),
    ]);

    $this->actingAs($client)
        ->get(route('portal.invoices.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Portal/InvoicesIndex')
            ->has('invoices.data', 1)
            ->where('invoices.data.0.id', $invoice->id)
            ->where('invoices.data.0.total_paid', 40)
            ->where('invoices.data.0.balance_due', 110)
            ->has('invoices.data.0.payments', 1)
            ->where('invoices.data.0.payments.0.amount', 40)
            ->where('invoices.data.0.payments.0.tip_amount', 5)
        );
});

it('exposes a signed receipt download link for a paid invoice in the client portal', function () {
    $owner = phase7CreatePortalOwner();
    $client = phase7CreatePortalClient();
    $customer = phase7CreatePortalCustomer($owner, $client);
    $invoice = phase7CreatePortalInvoice($owner, $customer);
    $invoice->forceFill([
        'status' => 'paid',
        'receipt_delivery_status' => 'failed',
        'receipt_delivery_last_error' => 'provider secret diagnostics',
    ])->save();

    $this->actingAs($client)
        ->get(route('portal.invoices.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Portal/InvoicesIndex')
            ->where('invoices.data.0.receipt_url', fn ($url) => is_string($url)
                && str_contains($url, '/pay/invoices/'.$invoice->id.'/receipt')
                && str_contains($url, 'signature='))
        );

    $this->actingAs($client)
        ->get(route('portal.invoices.show', $invoice))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Portal/InvoiceShow')
            ->missing('invoice.receipt_delivery_last_error')
            ->missing('invoice.receipt_delivery_claim_token')
            ->where('invoice.receipt_url', fn ($url) => is_string($url)
                && str_contains($url, '/pay/invoices/'.$invoice->id.'/receipt')
                && str_contains($url, 'signature='))
        );
});

it('forbids portal quote actions for an unrelated client', function () {
    $owner = phase7CreatePortalOwner();
    $allowedClient = phase7CreatePortalClient();
    $otherClient = phase7CreatePortalClient();

    $allowedCustomer = phase7CreatePortalCustomer($owner, $allowedClient);
    phase7CreatePortalCustomer($owner, $otherClient);
    $quote = phase7CreatePortalQuote($owner, $allowedCustomer);

    $this->actingAs($otherClient)
        ->postJson(route('portal.quotes.accept', $quote))
        ->assertForbidden();
});

it('forbids portal work proof access for an unrelated client', function () {
    $owner = phase7CreatePortalOwner();
    $allowedClient = phase7CreatePortalClient();
    $otherClient = phase7CreatePortalClient();

    $allowedCustomer = phase7CreatePortalCustomer($owner, $allowedClient);
    phase7CreatePortalCustomer($owner, $otherClient);
    $work = phase7CreatePortalWork($owner, $allowedCustomer);

    $this->actingAs($otherClient)
        ->getJson(route('portal.works.proofs', $work))
        ->assertForbidden();
});

it('hides portal orders for an unrelated client', function () {
    $owner = phase7CreatePortalOwner([
        'company_type' => 'products',
    ]);
    $allowedClient = phase7CreatePortalClient();
    $otherClient = phase7CreatePortalClient();

    $allowedCustomer = phase7CreatePortalCustomer($owner, $allowedClient);
    phase7CreatePortalCustomer($owner, $otherClient);
    $sale = phase7CreatePortalSale($owner, $allowedCustomer);

    $this->actingAs($otherClient)
        ->getJson(route('portal.orders.show', $sale))
        ->assertNotFound();
});

it('opens portal product orders for any tenant with product and sales capabilities', function () {
    $owner = phase7CreatePortalOwner([
        'company_type' => 'services',
        'company_features' => [
            'products' => true,
            'sales' => true,
        ],
    ]);
    $client = phase7CreatePortalClient();
    $customer = phase7CreatePortalCustomer($owner, $client);
    $sale = phase7CreatePortalSale($owner, $customer);

    $this->actingAs($client)
        ->getJson(route('portal.orders.index'))
        ->assertOk()
        ->assertJsonPath('company.id', $owner->id)
        ->assertJsonPath('customer.id', $customer->id);

    $this->actingAs($client)
        ->getJson(route('portal.orders.show', $sale))
        ->assertOk()
        ->assertJsonPath('order.id', $sale->id);
});

it('keeps portal product orders closed when either required capability is disabled', function () {
    $owner = phase7CreatePortalOwner([
        'company_type' => 'services',
        'company_features' => [
            'products' => false,
            'sales' => true,
        ],
    ]);
    $client = phase7CreatePortalClient();
    phase7CreatePortalCustomer($owner, $client);

    $this->actingAs($client)
        ->getJson(route('portal.orders.index'))
        ->assertForbidden();
});
