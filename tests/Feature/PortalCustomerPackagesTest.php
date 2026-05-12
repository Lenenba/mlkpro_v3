<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerPackage;
use App\Models\CustomerPackageUsage;
use App\Models\Invoice;
use App\Models\OfferPackage;
use App\Models\Role;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function portalPackagesRoleId(string $name): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $name.' role']
    )->id;
}

function portalPackagesOwner(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'role_id' => portalPackagesRoleId('owner'),
        'company_type' => 'services',
        'company_name' => 'Portal Packages Co',
        'company_features' => [
            'customers' => true,
            'products' => true,
            'services' => true,
            'sales' => true,
        ],
    ], $attributes));
}

function portalPackagesClient(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'role_id' => portalPackagesRoleId('client'),
        'email' => 'portal-packages-client-'.Str::lower(Str::random(10)).'@example.com',
    ], $attributes));
}

function portalPackagesCustomer(User $owner, User $client, array $attributes = []): Customer
{
    return Customer::factory()->create(array_merge([
        'user_id' => $owner->id,
        'portal_user_id' => $client->id,
        'portal_access' => true,
        'company_name' => 'North & Co',
    ], $attributes));
}

function portalPackagesOffer(User $owner, array $attributes = []): OfferPackage
{
    return OfferPackage::query()->create(array_merge([
        'user_id' => $owner->id,
        'name' => 'Monthly visits',
        'type' => OfferPackage::TYPE_FORFAIT,
        'status' => OfferPackage::STATUS_ACTIVE,
        'description' => 'Four monthly visits',
        'price' => 400,
        'currency_code' => 'CAD',
        'included_quantity' => 4,
        'unit_type' => OfferPackage::UNIT_VISIT,
        'is_public' => true,
        'is_recurring' => true,
        'recurrence_frequency' => OfferPackage::RECURRENCE_MONTHLY,
        'renewal_notice_days' => 7,
        'metadata' => [
            'recurrence' => [
                'carry_over_unused_balance' => true,
            ],
        ],
    ], $attributes));
}

function portalPackagesInvoice(User $owner, Customer $customer): Invoice
{
    $work = Work::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'job_title' => 'Portal package invoice',
        'instructions' => 'Invoice for package',
        'status' => Work::STATUS_IN_PROGRESS,
    ]);

    return Invoice::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'work_id' => $work->id,
        'status' => 'sent',
        'total' => 400,
        'currency_code' => 'CAD',
    ]);
}

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
});

it('shows the client package ledger with balances, carry over, invoices, and usage', function () {
    $owner = portalPackagesOwner();
    $client = portalPackagesClient();
    $customer = portalPackagesCustomer($owner, $client);
    $offer = portalPackagesOffer($owner);
    $invoice = portalPackagesInvoice($owner, $customer);

    $package = CustomerPackage::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'offer_package_id' => $offer->id,
        'invoice_id' => $invoice->id,
        'status' => CustomerPackage::STATUS_ACTIVE,
        'starts_at' => now()->startOfMonth()->toDateString(),
        'expires_at' => now()->endOfMonth()->toDateString(),
        'initial_quantity' => 5,
        'consumed_quantity' => 1,
        'remaining_quantity' => 4,
        'unit_type' => OfferPackage::UNIT_VISIT,
        'price_paid' => 400,
        'currency_code' => 'CAD',
        'is_recurring' => true,
        'recurrence_frequency' => OfferPackage::RECURRENCE_MONTHLY,
        'recurrence_status' => CustomerPackage::RECURRENCE_ACTIVE,
        'current_period_starts_at' => now()->startOfMonth()->toDateString(),
        'current_period_ends_at' => now()->endOfMonth()->toDateString(),
        'next_renewal_at' => now()->addMonthNoOverflow()->startOfMonth()->toDateString(),
        'metadata' => [
            'recurrence' => [
                'period_allocation_quantity' => 4,
                'carry_over_unused_balance' => true,
                'carried_over_quantity' => 1,
            ],
        ],
    ]);

    CustomerPackageUsage::query()->create([
        'customer_package_id' => $package->id,
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'created_by_user_id' => $owner->id,
        'quantity' => 1,
        'used_at' => now(),
        'note' => 'First visit',
        'metadata' => ['source' => 'manual'],
    ]);

    $this->actingAs($client)
        ->get(route('portal.packages.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Portal/Packages/Index')
            ->where('customer.id', $customer->id)
            ->where('stats.total', 1)
            ->where('stats.active', 1)
            ->where('stats.remaining_quantity', 4)
            ->where('stats.carried_over_quantity', 1)
            ->has('packages', 1)
            ->where('packages.0.id', $package->id)
            ->where('packages.0.remaining_quantity', 4)
            ->where('packages.0.carried_over_quantity', 1)
            ->has('packages.0.invoices', 1)
            ->has('packages.0.usages', 1)
        );
});

it('records a client renewal request on the package and customer timeline', function () {
    $owner = portalPackagesOwner();
    $client = portalPackagesClient();
    $customer = portalPackagesCustomer($owner, $client);
    $offer = portalPackagesOffer($owner);

    $package = CustomerPackage::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'offer_package_id' => $offer->id,
        'status' => CustomerPackage::STATUS_ACTIVE,
        'starts_at' => now()->toDateString(),
        'initial_quantity' => 4,
        'consumed_quantity' => 2,
        'remaining_quantity' => 2,
        'unit_type' => OfferPackage::UNIT_VISIT,
        'price_paid' => 400,
        'currency_code' => 'CAD',
        'is_recurring' => true,
        'recurrence_frequency' => OfferPackage::RECURRENCE_MONTHLY,
        'recurrence_status' => CustomerPackage::RECURRENCE_ACTIVE,
        'metadata' => [
            'recurrence' => [
                'period_allocation_quantity' => 4,
                'carry_over_unused_balance' => true,
                'carried_over_quantity' => 0,
            ],
        ],
    ]);

    $this->actingAs($client)
        ->post(route('portal.packages.renewal-request', $package), [
            'note' => 'Please renew this package for next month.',
        ])
        ->assertRedirect();

    expect(data_get($package->fresh()->metadata, 'portal.requests.renewal.note'))
        ->toBe('Please renew this package for next month.')
        ->and(ActivityLog::query()
            ->where('subject_type', (new Customer)->getMorphClass())
            ->where('subject_id', $customer->id)
            ->where('action', 'customer_package_portal_renewal_requested')
            ->exists())->toBeTrue();
});

it('does not allow a client to request changes on another customer package', function () {
    $owner = portalPackagesOwner();
    $allowedClient = portalPackagesClient();
    $otherClient = portalPackagesClient();
    $allowedCustomer = portalPackagesCustomer($owner, $allowedClient);
    portalPackagesCustomer($owner, $otherClient);
    $offer = portalPackagesOffer($owner);

    $package = CustomerPackage::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $allowedCustomer->id,
        'offer_package_id' => $offer->id,
        'status' => CustomerPackage::STATUS_ACTIVE,
        'starts_at' => now()->toDateString(),
        'initial_quantity' => 4,
        'consumed_quantity' => 0,
        'remaining_quantity' => 4,
        'unit_type' => OfferPackage::UNIT_VISIT,
        'price_paid' => 400,
        'currency_code' => 'CAD',
        'is_recurring' => true,
        'recurrence_frequency' => OfferPackage::RECURRENCE_MONTHLY,
        'recurrence_status' => CustomerPackage::RECURRENCE_ACTIVE,
    ]);

    $this->actingAs($otherClient)
        ->postJson(route('portal.packages.cancellation-request', $package), [
            'note' => 'Cancel it.',
        ])
        ->assertNotFound();
});
