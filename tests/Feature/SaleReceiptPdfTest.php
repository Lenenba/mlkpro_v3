<?php

use App\Models\Customer;
use App\Models\Sale;
use App\Models\User;

test('sale receipt and order pdf views display the tenant logo without forcing a square crop', function () {
    $owner = User::factory()->create([
        'company_name' => 'Marché Boréal',
        'company_logo' => 'https://assets.example.test/marche-boreal-wide.png',
        'company_type' => 'products',
        'company_features' => ['sales' => true],
    ]);
    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
        'company_name' => 'Client Démo',
    ]);
    $sale = Sale::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => Sale::STATUS_PENDING,
        'subtotal' => 42,
        'tax_total' => 0,
        'discount_total' => 0,
        'delivery_fee' => 0,
        'total' => 42,
        'fulfillment_method' => 'pickup',
    ]);

    $viewData = [
        'sale' => $sale,
        'customer' => $customer,
        'company' => $owner,
        'items' => collect([
            [
                'title' => 'Produit test',
                'quantity' => 1,
                'unit_price' => 42,
                'total' => 42,
                'sku' => 'TEST-42',
            ],
        ]),
        'payments' => collect(),
        'totalPaid' => 0,
        'depositAmount' => 0,
    ];

    foreach (['pdf.sale-receipt', 'pdf.order'] as $view) {
        $html = view($view, $viewData)->render();

        expect($html)->toContain('Marché Boréal')
            ->and($html)->toContain('https://assets.example.test/marche-boreal-wide.png')
            ->and($html)->toContain('object-fit: contain')
            ->and($html)->not->toContain('customers/customer.png');
    }
});

test('sale receipt pdf keeps the company name and omits the legacy placeholder without a custom logo', function () {
    $owner = User::factory()->create([
        'company_name' => 'Marché Sans Logo',
        'company_logo' => null,
        'company_type' => 'products',
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
        'subtotal' => 25,
        'tax_total' => 0,
        'discount_total' => 0,
        'delivery_fee' => 0,
        'total' => 25,
        'fulfillment_method' => 'pickup',
    ]);

    $html = view('pdf.sale-receipt', [
        'sale' => $sale,
        'customer' => $customer,
        'company' => $owner,
        'items' => collect(),
        'payments' => collect(),
        'totalPaid' => 0,
        'depositAmount' => 0,
    ])->render();

    expect($html)->toContain('Marché Sans Logo')
        ->and($html)->not->toContain('class="logo"')
        ->and($html)->not->toContain('customers/customer.png');

    $response = $this->actingAs($owner)->get(route('sales.receipt', $sale));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
});
