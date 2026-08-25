<?php

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\OfferPackage;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Work;
use Illuminate\Support\Str;

function customerPurchasedPacksRoleId(string $name): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $name.' role']
    )->id;
}

function customerPurchasedPacksOwner(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role_id' => customerPurchasedPacksRoleId('owner'),
        'email' => 'customer-packs-owner-'.Str::lower(Str::random(10)).'@example.com',
        'company_type' => 'services',
        'company_features' => [
            'customers' => true,
            'invoices' => true,
            'sales' => false,
            'reservations' => true,
        ],
    ], $overrides));
}

function customerPurchasedPacksInvoice(
    User $owner,
    Customer $customer,
    string $status,
    float $total,
    string $currencyCode = 'CAD'
): Invoice {
    $work = Work::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'job_title' => 'Customer pack fixture',
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

function customerPurchasedPacksLine(
    Invoice $invoice,
    OfferPackage $offer,
    float $quantity = 1,
    ?array $meta = null
): InvoiceItem {
    return InvoiceItem::query()->create([
        'invoice_id' => $invoice->id,
        'title' => $offer->name,
        'description' => 'Snapshot line description',
        'quantity' => $quantity,
        'unit_price' => (float) $offer->price,
        'total' => round($quantity * (float) $offer->price, 2),
        'currency_code' => $invoice->currency_code,
        'meta' => $meta ?? [
            'source' => 'offer_package',
            'offer_package_id' => $offer->id,
            'offer_package_type' => OfferPackage::TYPE_PACK,
            'offer_package_snapshot' => [
                'id' => $offer->id,
                'name' => $offer->name,
                'type' => OfferPackage::TYPE_PACK,
                'description' => 'Immutable pack snapshot',
            ],
            'source_details' => [
                'source' => 'offer_package',
                'offer_package' => [
                    'id' => $offer->id,
                    'name' => $offer->name,
                    'type' => OfferPackage::TYPE_PACK,
                ],
            ],
        ],
    ]);
}

it('shows purchased pack snapshots on the customer while excluding invalid invoice lines', function () {
    $owner = customerPurchasedPacksOwner();
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $otherCustomer = Customer::factory()->create(['user_id' => $owner->id]);
    $otherOwner = customerPurchasedPacksOwner();
    $foreignCustomer = Customer::factory()->create(['user_id' => $otherOwner->id]);

    $pack = OfferPackage::query()->create([
        'user_id' => $owner->id,
        'name' => 'Mariage Serenite snapshot',
        'type' => OfferPackage::TYPE_PACK,
        'status' => OfferPackage::STATUS_ACTIVE,
        'description' => 'Pack purchased by the customer',
        'price' => 323,
        'currency_code' => 'CAD',
    ]);

    $cadInvoice = customerPurchasedPacksInvoice($owner, $customer, 'paid', 323);
    $cadLine = customerPurchasedPacksLine($cadInvoice, $pack);

    $usdPack = OfferPackage::query()->create([
        'user_id' => $owner->id,
        'name' => 'Pack USD snapshot',
        'type' => OfferPackage::TYPE_PACK,
        'status' => OfferPackage::STATUS_ARCHIVED,
        'price' => 50,
        'currency_code' => 'USD',
    ]);
    $usdInvoice = customerPurchasedPacksInvoice($owner, $customer, 'sent', 100, 'USD');
    customerPurchasedPacksLine($usdInvoice, $usdPack, 2);

    $voidInvoice = customerPurchasedPacksInvoice($owner, $customer, 'void', 323);
    customerPurchasedPacksLine($voidInvoice, $pack);

    $deletedInvoice = customerPurchasedPacksInvoice($owner, $customer, 'paid', 323);
    customerPurchasedPacksLine($deletedInvoice, $pack);
    $deletedInvoice->forceFill(['deleted_at' => now()])->saveQuietly();

    $otherCustomerInvoice = customerPurchasedPacksInvoice($owner, $otherCustomer, 'paid', 323);
    customerPurchasedPacksLine($otherCustomerInvoice, $pack);

    $foreignInvoice = customerPurchasedPacksInvoice($otherOwner, $foreignCustomer, 'paid', 999);
    customerPurchasedPacksLine($foreignInvoice, $pack);

    $forfaitInvoice = customerPurchasedPacksInvoice($owner, $customer, 'paid', 323);
    customerPurchasedPacksLine($forfaitInvoice, $pack, 1, [
        'source' => 'offer_package',
        'offer_package_id' => $pack->id,
        'offer_package_type' => OfferPackage::TYPE_FORFAIT,
        'offer_package_snapshot' => ['name' => 'Not a pack'],
    ]);

    $untrustedInvoice = customerPurchasedPacksInvoice($owner, $customer, 'paid', 323);
    customerPurchasedPacksLine($untrustedInvoice, $pack, 1, [
        'offer_package_id' => $pack->id,
        'offer_package_type' => OfferPackage::TYPE_PACK,
        'offer_package_snapshot' => ['name' => 'Missing source marker'],
    ]);

    $response = $this->actingAs($owner)
        ->getJson(route('customer.show', $customer))
        ->assertOk()
        ->assertJsonCount(0, 'customerPackages')
        ->assertJsonCount(2, 'customerPurchasedPacks')
        ->assertJsonPath('customerPurchasedPackSummary.total_lines', 2)
        ->assertJsonPath('customerPurchasedPackSummary.displayed', 2)
        ->assertJsonPath('customerPurchasedPackSummary.total_quantity', 3)
        ->assertJsonPath('customerPurchasedPacks.0.purchased_at', fn (string $value): bool => $value !== '')
        ->assertJsonMissingPath('customerPurchasedPacks.0.meta')
        ->assertJsonMissingPath('customerPurchasedPacks.0.offer_package_snapshot')
        ->assertJsonMissingPath('customerPurchasedPacks.0.source_details')
        ->assertJsonCount(2, 'customerPurchasedPackSummary.currency_breakdown');

    $packs = collect($response->json('customerPurchasedPacks'))->keyBy('id');
    $serializedCadPack = $packs->get($cadLine->id);

    expect($serializedCadPack)
        ->not->toBeNull()
        ->and($serializedCadPack['type'])->toBe(OfferPackage::TYPE_PACK)
        ->and($serializedCadPack['offer_package_id'])->toBe($pack->id)
        ->and($serializedCadPack['name'])->toBe('Mariage Serenite snapshot')
        ->and($serializedCadPack['description'])->toBe('Immutable pack snapshot')
        ->and($serializedCadPack['quantity'])->toBe(1)
        ->and($serializedCadPack['unit_price'])->toBe(323)
        ->and($serializedCadPack['total'])->toBe(323)
        ->and($serializedCadPack['currency_code'])->toBe('CAD')
        ->and($serializedCadPack['invoice']['id'])->toBe($cadInvoice->id)
        ->and($serializedCadPack['invoice']['number'])->toBe($cadInvoice->number)
        ->and($serializedCadPack['invoice']['status'])->toBe('paid')
        ->and($serializedCadPack['invoice']['can_view'])->toBeTrue()
        ->and($serializedCadPack['invoice']['href'])->toBe(route('invoice.show', $cadInvoice));

    $breakdown = collect($response->json('customerPurchasedPackSummary.currency_breakdown'))
        ->keyBy('currency_code');

    expect($breakdown->get('CAD'))->toMatchArray([
        'total_lines' => 1,
        'total_quantity' => 1,
        'total_spent' => 323,
    ])->and($breakdown->get('USD'))->toMatchArray([
        'total_lines' => 1,
        'total_quantity' => 2,
        'total_spent' => 100,
    ]);

    expect(json_encode($response->json()))
        ->not->toContain('Not a pack')
        ->not->toContain('Missing source marker');
});

it('does not expose purchased packs to a customer viewer without invoice permission', function () {
    $owner = customerPurchasedPacksOwner();
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $pack = OfferPackage::query()->create([
        'user_id' => $owner->id,
        'name' => 'Secret purchased pack',
        'type' => OfferPackage::TYPE_PACK,
        'status' => OfferPackage::STATUS_ACTIVE,
        'price' => 8765.43,
        'currency_code' => 'CAD',
    ]);
    $invoice = customerPurchasedPacksInvoice($owner, $customer, 'paid', 8765.43);
    customerPurchasedPacksLine($invoice, $pack);

    $employee = User::factory()
        ->withRole(customerPurchasedPacksRoleId('employee'))
        ->create();
    TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $employee->id,
        'role' => 'member',
        'permissions' => ['customers.view'],
        'is_active' => true,
    ]);

    $response = $this->actingAs($employee)
        ->getJson(route('customer.show', $customer))
        ->assertOk()
        ->assertJsonCount(0, 'customerPurchasedPacks')
        ->assertJsonPath('customerPurchasedPackSummary.total_lines', 0)
        ->assertJsonPath('customerPurchasedPackSummary.displayed', 0)
        ->assertJsonPath('customerPurchasedPackSummary.total_quantity', 0)
        ->assertJsonCount(0, 'customerPurchasedPackSummary.currency_breakdown');

    expect(json_encode($response->json()))
        ->not->toContain('Secret purchased pack')
        ->not->toContain('8765.43')
        ->not->toContain((string) $invoice->number);
});
