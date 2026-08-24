<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\Customer;
use App\Models\CustomerPackage;
use App\Models\CustomerPackageUsage;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\OfferPackage;
use App\Models\Payment;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function offerDetailRoleId(string $name): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $name.' role']
    )->id;
}

function offerDetailOwner(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role_id' => offerDetailRoleId('owner'),
        'email' => 'offer-detail-owner-'.Str::lower(Str::random(10)).'@example.com',
        'currency_code' => 'CAD',
        'company_type' => 'services',
        'company_sector' => 'service_general',
        'onboarding_completed_at' => now(),
    ], $overrides));
}

function offerDetailCustomer(User $owner, array $overrides = []): Customer
{
    return Customer::factory()->create(array_merge([
        'user_id' => $owner->id,
        'company_name' => null,
    ], $overrides));
}

function offerDetailInvoice(
    User $owner,
    Customer $customer,
    string $status,
    float $total,
    string $currencyCode = 'CAD'
): Invoice {
    $work = Work::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'job_title' => 'Pack detail fixture',
        'instructions' => 'Fixture',
        'subtotal' => $total,
        'total' => $total,
    ]);

    return Invoice::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'work_id' => $work->id,
        'status' => $status,
        'subtotal' => $total,
        'tax_total' => 0,
        'total' => $total,
        'currency_code' => $currencyCode,
    ]);
}

function offerDetailPackLine(
    Invoice $invoice,
    OfferPackage $offer,
    float $total,
    string $currencyCode = 'CAD',
    float $quantity = 1
): InvoiceItem {
    return InvoiceItem::query()->create([
        'invoice_id' => $invoice->id,
        'title' => $offer->name,
        'quantity' => $quantity,
        'unit_price' => $total / $quantity,
        'total' => $total,
        'currency_code' => $currencyCode,
        'meta' => [
            'source' => 'offer_package',
            'offer_package_id' => $offer->id,
            'offer_package_type' => OfferPackage::TYPE_PACK,
            'offer_package_snapshot' => [
                'id' => $offer->id,
                'name' => $offer->name,
                'type' => $offer->type,
            ],
        ],
    ]);
}

function offerDetailPayment(
    User $owner,
    Customer $customer,
    Invoice $invoice,
    float $amount,
    string $currencyCode = 'CAD'
): Payment {
    return Payment::query()->create([
        'invoice_id' => $invoice->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'amount' => $amount,
        'currency_code' => $currencyCode,
        'method' => 'card',
        'status' => Payment::STATUS_COMPLETED,
        'paid_at' => now(),
    ]);
}

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
});

it('builds a pack history from non void invoice lines and settled payments', function () {
    $owner = offerDetailOwner();
    $customer = offerDetailCustomer($owner, [
        'first_name' => 'Alice',
        'last_name' => 'Martin',
        'email' => 'alice@example.com',
        'phone' => '514-555-0101',
    ]);
    $secondCustomer = offerDetailCustomer($owner);
    $otherOwner = offerDetailOwner();
    $offer = OfferPackage::query()->create([
        'user_id' => $owner->id,
        'name' => 'Pack historique',
        'type' => OfferPackage::TYPE_PACK,
        'status' => OfferPackage::STATUS_ACTIVE,
        'price' => 200,
        'currency_code' => 'CAD',
    ]);

    $paidInvoice = offerDetailInvoice($owner, $customer, 'paid', 200);
    $paidLine = offerDetailPackLine($paidInvoice, $offer, 200);
    offerDetailPayment($owner, $customer, $paidInvoice, 200);

    $partialInvoice = offerDetailInvoice($owner, $customer, 'partial', 400);
    $partialLine = offerDetailPackLine($partialInvoice, $offer, 200);
    InvoiceItem::query()->create([
        'invoice_id' => $partialInvoice->id,
        'title' => 'Service hors pack',
        'quantity' => 1,
        'unit_price' => 200,
        'total' => 200,
        'currency_code' => 'CAD',
        'meta' => ['source' => 'service'],
    ]);
    offerDetailPayment($owner, $customer, $partialInvoice, 200);
    offerDetailPayment($otherOwner, $customer, $partialInvoice, 999);
    offerDetailPayment($owner, $customer, $partialInvoice, 999, 'USD');

    $usdInvoice = offerDetailInvoice($owner, $secondCustomer, 'paid', 100, 'USD');
    offerDetailPackLine($usdInvoice, $offer, 100, 'USD');
    offerDetailPayment($owner, $secondCustomer, $usdInvoice, 100, 'USD');

    $voidInvoice = offerDetailInvoice($owner, $customer, 'void', 100);
    offerDetailPackLine($voidInvoice, $offer, 100);

    $deletedInvoice = offerDetailInvoice($owner, $customer, 'paid', 100);
    offerDetailPackLine($deletedInvoice, $offer, 100);
    $deletedInvoice->forceFill(['deleted_at' => now()])->saveQuietly();

    $otherCustomer = offerDetailCustomer($otherOwner);
    $foreignInvoice = offerDetailInvoice($otherOwner, $otherCustomer, 'paid', 999);
    offerDetailPackLine($foreignInvoice, $offer, 999);

    $response = $this->actingAs($owner)
        ->getJson(route('offer-packages.show', $offer))
        ->assertOk()
        ->assertJsonCount(3, 'sales')
        ->assertJsonPath('kpis.sold_count', 3)
        ->assertJsonPath('kpis.invoice_count', 3)
        ->assertJsonPath('kpis.assigned_customers', 2)
        ->assertJsonPath('kpis.total_revenue', 400)
        ->assertJsonPath('kpis.total_billed', 400)
        ->assertJsonPath('kpis.total_collected', 300)
        ->assertJsonPath('kpis.balance_due', 100)
        ->assertJsonPath('kpis.paid_invoice_count', 2)
        ->assertJsonPath('kpis.outstanding_invoice_count', 1)
        ->assertJsonPath('kpis.has_mixed_currencies', true)
        ->assertJsonPath('kpis.status_breakdown.paid', 2)
        ->assertJsonPath('kpis.status_breakdown.partial', 1)
        ->assertJsonPath('sales_meta.total', 3)
        ->assertJsonPath('sales_meta.displayed', 3)
        ->assertJsonCount(0, 'customers')
        ->assertJsonCount(0, 'recentUsages');

    $sales = collect($response->json('sales'))->keyBy('id');
    $partialSale = $sales->get($partialLine->id);
    $paidSale = $sales->get($paidLine->id);

    expect($partialSale)
        ->not->toBeNull()
        ->and($partialSale['total'])->toBe(200)
        ->and($partialSale['collected_amount'])->toBe(100)
        ->and($partialSale['balance_due'])->toBe(100)
        ->and($partialSale['invoice']['amount_paid'])->toBe(200)
        ->and($partialSale['invoice']['balance_due'])->toBe(200)
        ->and($partialSale['invoice']['can_view'])->toBeTrue()
        ->and($partialSale['invoice']['href'])->toContain('/invoices/'.$partialInvoice->id)
        ->and($partialSale['customer']['name'])->toBe('Alice Martin')
        ->and($partialSale['customer']['href'])->toContain('/customer/'.$customer->id)
        ->and($partialSale['payments'])->toHaveCount(1)
        ->and($partialSale['payments'][0]['amount'])->toBe(200)
        ->and($partialSale['payments'][0]['currency_code'])->toBe('CAD')
        ->and($paidSale['collected_amount'])->toBe(200);

    $breakdown = collect($response->json('kpis.currency_breakdown'))->keyBy('currency_code');

    expect($breakdown->get('CAD'))
        ->toMatchArray([
            'total_billed' => 400,
            'total_collected' => 300,
            'balance_due' => 100,
        ])
        ->and($breakdown->get('USD'))
        ->toMatchArray([
            'total_billed' => 100,
            'total_collected' => 100,
            'balance_due' => 0,
        ]);

    $this->actingAs($owner)
        ->getJson(route('offer-packages.index'))
        ->assertOk()
        ->assertJsonPath('reporting.sales.packs.sold_count', 3);
});

it('limits the displayed pack history without truncating its global kpis', function () {
    $owner = offerDetailOwner();
    $customer = offerDetailCustomer($owner);
    $offer = OfferPackage::query()->create([
        'user_id' => $owner->id,
        'name' => 'Pack historique volumineux',
        'type' => OfferPackage::TYPE_PACK,
        'status' => OfferPackage::STATUS_ACTIVE,
        'price' => 100,
        'currency_code' => 'CAD',
    ]);
    $invoice = offerDetailInvoice($owner, $customer, 'paid', 2700);
    $lastLine = null;

    foreach (range(1, 27) as $sequence) {
        $lastLine = offerDetailPackLine($invoice, $offer, 100);
        $lastLine->forceFill([
            'created_at' => now()->addSeconds($sequence),
            'updated_at' => now()->addSeconds($sequence),
        ])->saveQuietly();
    }

    offerDetailPayment($owner, $customer, $invoice, 2700);

    $this->actingAs($owner)
        ->getJson(route('offer-packages.show', $offer))
        ->assertOk()
        ->assertJsonCount(25, 'sales')
        ->assertJsonPath('sales_meta.total', 27)
        ->assertJsonPath('sales_meta.displayed', 25)
        ->assertJsonPath('kpis.sold_count', 27)
        ->assertJsonPath('kpis.invoice_count', 1)
        ->assertJsonPath('kpis.total_billed', 2700)
        ->assertJsonPath('kpis.total_collected', 2700)
        ->assertJsonPath('sales.0.id', $lastLine?->id);
});

it('masks pack invoice payments and customer pii without their policies', function () {
    $owner = offerDetailOwner();
    $customer = offerDetailCustomer($owner, [
        'first_name' => 'Lea',
        'last_name' => 'Tremblay',
        'email' => 'lea-secret@example.com',
        'phone' => '514-555-0199',
    ]);
    $offer = OfferPackage::query()->create([
        'user_id' => $owner->id,
        'name' => 'Pack protege',
        'type' => OfferPackage::TYPE_PACK,
        'status' => OfferPackage::STATUS_ACTIVE,
        'price' => 200,
        'currency_code' => 'CAD',
    ]);
    $invoice = offerDetailInvoice($owner, $customer, 'paid', 200);
    offerDetailPackLine($invoice, $offer, 200);
    offerDetailPayment($owner, $customer, $invoice, 200);

    $employee = User::factory()
        ->withRole(offerDetailRoleId('employee'))
        ->create();
    TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $employee->id,
        'role' => 'member',
        'permissions' => ['products.edit'],
        'is_active' => true,
    ]);

    $response = $this->actingAs($employee)
        ->getJson(route('offer-packages.show', $offer))
        ->assertOk()
        ->assertJsonCount(1, 'sales')
        ->assertJsonPath('kpis.total_billed', 200)
        ->assertJsonPath('kpis.total_collected', 200)
        ->assertJsonPath('sales.0.customer.id', null)
        ->assertJsonPath('sales.0.customer.name', null)
        ->assertJsonPath('sales.0.customer.email', null)
        ->assertJsonPath('sales.0.customer.phone', null)
        ->assertJsonPath('sales.0.customer.can_view', false)
        ->assertJsonPath('sales.0.customer.href', null)
        ->assertJsonPath('sales.0.invoice.id', null)
        ->assertJsonPath('sales.0.invoice.number', null)
        ->assertJsonPath('sales.0.invoice.total', null)
        ->assertJsonPath('sales.0.invoice.amount_paid', null)
        ->assertJsonPath('sales.0.invoice.can_view', false)
        ->assertJsonPath('sales.0.invoice.href', null)
        ->assertJsonCount(0, 'sales.0.payments');

    expect(json_encode($response->json()))
        ->not->toContain('lea-secret@example.com')
        ->not->toContain('514-555-0199')
        ->not->toContain((string) $invoice->number);
});

it('keeps forfait history tenant scoped and masks links and pii without entity permissions', function () {
    $owner = offerDetailOwner();
    $customer = offerDetailCustomer($owner, [
        'first_name' => 'Nora',
        'last_name' => 'Bouchard',
        'email' => 'nora@example.com',
        'phone' => '514-555-0102',
    ]);
    $offer = OfferPackage::query()->create([
        'user_id' => $owner->id,
        'name' => 'Forfait confidentiel',
        'type' => OfferPackage::TYPE_FORFAIT,
        'status' => OfferPackage::STATUS_ACTIVE,
        'price' => 500,
        'currency_code' => 'CAD',
        'included_quantity' => 5,
        'unit_type' => OfferPackage::UNIT_SESSION,
    ]);
    $invoice = offerDetailInvoice($owner, $customer, 'paid', 500);
    $package = CustomerPackage::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'offer_package_id' => $offer->id,
        'invoice_id' => $invoice->id,
        'status' => CustomerPackage::STATUS_ACTIVE,
        'starts_at' => '2026-08-01',
        'expires_at' => '2026-12-31',
        'initial_quantity' => 5,
        'consumed_quantity' => 1,
        'remaining_quantity' => 4,
        'unit_type' => OfferPackage::UNIT_SESSION,
        'price_paid' => 500,
        'currency_code' => 'CAD',
    ]);
    CustomerPackageUsage::query()->create([
        'customer_package_id' => $package->id,
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'created_by_user_id' => $owner->id,
        'quantity' => 1,
        'used_at' => '2026-08-10 10:00:00',
        'note' => 'Usage visible',
    ]);
    CustomerPackageUsage::query()->create([
        'customer_package_id' => $package->id,
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'created_by_user_id' => $owner->id,
        'quantity' => 1,
        'used_at' => '2026-08-11 10:00:00',
        'reversed_at' => '2026-08-11 11:00:00',
        'reversed_by_user_id' => $owner->id,
        'reversal_reason' => 'Correction',
        'note' => 'Usage annule',
    ]);

    $otherOwner = offerDetailOwner();
    $foreignCustomer = offerDetailCustomer($otherOwner, [
        'email' => 'foreign-secret@example.com',
    ]);
    CustomerPackage::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $foreignCustomer->id,
        'offer_package_id' => $offer->id,
        'status' => CustomerPackage::STATUS_ACTIVE,
        'starts_at' => '2026-08-01',
        'initial_quantity' => 99,
        'remaining_quantity' => 99,
        'unit_type' => OfferPackage::UNIT_SESSION,
        'price_paid' => 9999,
        'currency_code' => 'CAD',
    ]);

    $employee = User::factory()
        ->withRole(offerDetailRoleId('employee'))
        ->create();
    TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $employee->id,
        'role' => 'member',
        'permissions' => ['products.edit'],
        'is_active' => true,
    ]);

    $restrictedResponse = $this->actingAs($employee)
        ->getJson(route('offer-packages.show', $offer))
        ->assertOk()
        ->assertJsonCount(0, 'sales')
        ->assertJsonCount(1, 'customers')
        ->assertJsonCount(1, 'recentUsages')
        ->assertJsonPath('kpis.sold_count', 1)
        ->assertJsonPath('kpis.total_revenue', 500)
        ->assertJsonPath('customers.0.customer.id', null)
        ->assertJsonPath('customers.0.customer.name', null)
        ->assertJsonPath('customers.0.customer.email', null)
        ->assertJsonPath('customers.0.customer.phone', null)
        ->assertJsonPath('customers.0.customer.can_view', false)
        ->assertJsonPath('customers.0.customer.href', null)
        ->assertJsonPath('customers.0.invoice.id', null)
        ->assertJsonPath('customers.0.invoice.number', null)
        ->assertJsonPath('customers.0.invoice.can_view', false)
        ->assertJsonPath('customers.0.invoice.href', null)
        ->assertJsonPath('recentUsages.0.customer.email', null)
        ->assertJsonPath('recentUsages.0.customer.href', null);

    expect(json_encode($restrictedResponse->json()))
        ->not->toContain('nora@example.com')
        ->not->toContain('foreign-secret@example.com');

    $this->actingAs($owner)
        ->getJson(route('offer-packages.show', $offer))
        ->assertOk()
        ->assertJsonPath('customers.0.customer.name', 'Nora Bouchard')
        ->assertJsonPath('customers.0.customer.email', 'nora@example.com')
        ->assertJsonPath('customers.0.customer.can_view', true)
        ->assertJsonPath('customers.0.customer.href', route('customer.show', $customer))
        ->assertJsonPath('customers.0.invoice.can_view', true)
        ->assertJsonPath('customers.0.invoice.href', route('invoice.show', $invoice))
        ->assertJsonPath('recentUsages.0.note', 'Usage visible');
});
