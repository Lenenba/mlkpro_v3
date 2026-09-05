<?php

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\User;
use App\Models\Work;

it('separates settled invoice collections from point of sale revenue on the dashboard', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_features' => [
            'invoices' => true,
            'sales' => true,
        ],
    ]);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $work = Work::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'job_title' => 'Dashboard finance split',
        'instructions' => 'Keep invoice and POS cash flows separate.',
        'status' => Work::STATUS_COMPLETED,
    ]);
    $invoice = Invoice::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'work_id' => $work->id,
        'status' => 'partial',
        'subtotal' => 100,
        'tax_total' => 0,
        'total' => 100,
    ]);
    Payment::query()->create([
        'invoice_id' => $invoice->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'amount' => 40,
        'method' => 'card',
        'status' => Payment::STATUS_PAID,
        'paid_at' => now(),
    ]);
    Payment::query()->create([
        'invoice_id' => $invoice->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'amount' => 15,
        'method' => 'cash',
        'status' => Payment::STATUS_PENDING,
        'paid_at' => null,
    ]);

    $sale = Sale::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => Sale::STATUS_PAID,
        'subtotal' => 70,
        'tax_total' => 0,
        'total' => 70,
        'paid_at' => now(),
    ]);
    Payment::query()->create([
        'sale_id' => $sale->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'amount' => 70,
        'method' => 'card',
        'status' => Payment::STATUS_COMPLETED,
        'paid_at' => now(),
    ]);

    $response = $this->actingAs($owner)->getJson(route('dashboard', ['fresh' => 1]));

    $response
        ->assertOk()
        ->assertJsonPath('stats.revenue_billed', 100)
        ->assertJsonPath('stats.revenue_paid', 40)
        ->assertJsonPath('stats.revenue_outstanding', 60)
        ->assertJsonPath('stats.payments_month', 40)
        ->assertJsonPath('stats.pos_revenue_paid', 70)
        ->assertJsonPath('stats.pos_payments_month', 70);

    expect(array_sum($response->json('revenueSeries.values')))->toEqual(40);
});
