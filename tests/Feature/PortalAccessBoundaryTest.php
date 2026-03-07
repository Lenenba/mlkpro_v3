<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Str;

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
