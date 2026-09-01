<?php

use App\Models\Customer;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Queries\Dashboard\OwnerDashboardKpiSeriesQuery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('returns truthful tenant-scoped KPI series with aligned month-to-date comparisons', function () {
    $this->travelTo(Carbon::parse('2026-08-30 15:00:00', 'America/Toronto'));

    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_timezone' => 'America/Toronto',
        'currency_code' => 'CAD',
        'company_features' => [
            'invoices' => true,
            'expenses' => true,
        ],
    ]);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $invoice = Invoice::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => 'partial',
        'subtotal' => 1000,
        'tax_total' => 0,
        'total' => 1000,
        'currency_code' => 'CAD',
    ]);

    foreach ([
        ['amount' => 50, 'status' => Payment::STATUS_PAID, 'currency_code' => 'CAD', 'paid_at' => '2026-07-10 10:00:00'],
        ['amount' => 500, 'status' => Payment::STATUS_PAID, 'currency_code' => 'CAD', 'paid_at' => '2026-07-31 10:00:00'],
        ['amount' => 100, 'status' => Payment::STATUS_COMPLETED, 'currency_code' => 'CAD', 'paid_at' => '2026-08-10 10:00:00'],
        ['amount' => 900, 'status' => Payment::STATUS_PAID, 'currency_code' => 'USD', 'paid_at' => '2026-08-11 10:00:00'],
        ['amount' => 700, 'status' => Payment::STATUS_PENDING, 'currency_code' => 'CAD', 'paid_at' => '2026-08-12 10:00:00'],
    ] as $payment) {
        Payment::query()->create([
            'invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'user_id' => $owner->id,
            'amount' => $payment['amount'],
            'currency_code' => $payment['currency_code'],
            'method' => 'card',
            'status' => $payment['status'],
            'paid_at' => Carbon::parse($payment['paid_at'], 'America/Toronto')->utc(),
        ]);
    }

    Expense::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'title' => 'Previous aligned expense',
        'currency_code' => 'CAD',
        'subtotal' => 80,
        'total' => 80,
        'expense_date' => '2026-07-10',
        'paid_date' => '2026-07-10',
        'status' => Expense::STATUS_PAID,
    ]);
    Expense::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'title' => 'Current aligned expense',
        'currency_code' => 'CAD',
        'subtotal' => 40,
        'total' => 40,
        'expense_date' => '2026-08-10',
        'paid_date' => '2026-08-10',
        'status' => Expense::STATUS_REIMBURSED,
    ]);

    $otherOwner = User::factory()->create(['currency_code' => 'CAD']);
    $otherCustomer = Customer::factory()->create(['user_id' => $otherOwner->id]);
    $otherInvoice = Invoice::query()->create([
        'user_id' => $otherOwner->id,
        'customer_id' => $otherCustomer->id,
        'status' => 'partial',
        'subtotal' => 750,
        'tax_total' => 0,
        'total' => 750,
        'currency_code' => 'CAD',
    ]);
    Payment::query()->create([
        'invoice_id' => $otherInvoice->id,
        'customer_id' => $otherCustomer->id,
        'user_id' => $otherOwner->id,
        'amount' => 750,
        'currency_code' => 'CAD',
        'method' => 'card',
        'status' => Payment::STATUS_PAID,
        'paid_at' => Carbon::parse('2026-08-10 10:00:00', 'America/Toronto')->utc(),
    ]);
    Expense::query()->create([
        'user_id' => $otherOwner->id,
        'created_by_user_id' => $otherOwner->id,
        'title' => 'Other tenant expense',
        'currency_code' => 'CAD',
        'subtotal' => 600,
        'total' => 600,
        'expense_date' => '2026-08-10',
        'paid_date' => '2026-08-10',
        'status' => Expense::STATUS_PAID,
    ]);

    $payload = $this->actingAs($owner)
        ->getJson(route('dashboard', ['fresh' => 1]))
        ->assertOk()
        ->json();

    $revenue = data_get($payload, 'kpiSeries.revenue_paid');
    $expenses = data_get($payload, 'kpiSeries.expenses_paid');

    expect($revenue)
        ->toMatchArray([
            'labels' => [
                '2025-09',
                '2025-10',
                '2025-11',
                '2025-12',
                '2026-01',
                '2026-02',
                '2026-03',
                '2026-04',
                '2026-05',
                '2026-06',
                '2026-07',
                '2026-08',
            ],
            'values' => [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 50, 100],
            'granularity' => 'month',
            'period' => [
                'start' => '2025-09-01',
                'end' => '2026-08-30',
                'timezone' => 'America/Toronto',
                'isPartial' => true,
                'comparisonMode' => 'aligned_month_to_date',
            ],
            'unit' => ['type' => 'currency', 'code' => 'CAD'],
            'measurement' => 'flow',
            'isTemporal' => true,
            'semanticDirection' => 'higher_is_better',
            'historyStatus' => 'available',
            'comparison' => [
                'current' => 100,
                'previous' => 50,
                'delta' => 50,
                'percent' => 100,
                'direction' => 'up',
                'isFavorable' => true,
            ],
        ])
        ->and(data_get($payload, 'revenueSeries'))->toBeNull()
        ->and((float) data_get($payload, 'stats.revenue_paid'))->toBe(650.0)
        ->and((float) data_get($payload, 'stats.payments_month'))->toBe(100.0)
        ->and($expenses['values'])->toBe([0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 80, 40])
        ->and($expenses['comparison'])->toMatchArray([
            'current' => 40,
            'previous' => 80,
            'delta' => -40,
            'percent' => 50,
            'direction' => 'down',
            'isFavorable' => true,
        ]);

    foreach (['revenue_outstanding', 'quotes_open', 'works_scheduled', 'works_in_progress', 'customers_total', 'products_low_stock', 'invoices_paid', 'inventory_value'] as $key) {
        expect(data_get($payload, "kpiSeries.{$key}"))->toMatchArray([
            'labels' => [],
            'values' => [],
            'measurement' => 'current_state',
            'isTemporal' => false,
            'historyStatus' => 'requires_snapshot',
            'unavailableReason' => 'historical_snapshots_not_recorded',
            'comparison' => null,
        ]);
    }
});

it('keeps the KPI query count constant when the number of monthly buckets increases', function () {
    $owner = User::factory()->create([
        'company_timezone' => 'America/Toronto',
        'currency_code' => 'CAD',
        'company_features' => ['expenses' => true],
    ]);
    $query = app(OwnerDashboardKpiSeriesQuery::class);
    $anchor = Carbon::parse('2026-08-30 15:00:00', 'America/Toronto');

    DB::enableQueryLog();
    DB::flushQueryLog();
    $sixMonthSeries = $query->execute($owner, $anchor, 6);
    $sixMonthQueryCount = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => Str::contains($query['query'], [
            ' from "payments"',
            ' from `payments`',
            ' from "expenses"',
            ' from `expenses`',
        ]))
        ->count();

    DB::flushQueryLog();
    $twelveMonthSeries = $query->execute($owner, $anchor, 12);
    $twelveMonthQueryCount = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => Str::contains($query['query'], [
            ' from "payments"',
            ' from `payments`',
            ' from "expenses"',
            ' from `expenses`',
        ]))
        ->count();
    DB::disableQueryLog();

    $currentStateSeries = $query->currentStateSeriesForKeys(
        $anchor,
        6,
        ['tasks_todo' => 'lower_is_better'],
    );

    expect($sixMonthSeries['revenue_paid']['labels'])->toHaveCount(6)
        ->and($twelveMonthSeries['revenue_paid']['labels'])->toHaveCount(12)
        ->and($twelveMonthSeries['expenses_paid']['labels'])->toHaveCount(12)
        ->and($sixMonthQueryCount)->toBe(2)
        ->and($twelveMonthQueryCount)->toBe(2)
        ->and($currentStateSeries['tasks_todo'])->toMatchArray([
            'labels' => [],
            'values' => [],
            'measurement' => 'current_state',
            'isTemporal' => false,
            'semanticDirection' => 'lower_is_better',
            'historyStatus' => 'requires_snapshot',
            'comparison' => null,
        ]);
});

it('calculates outstanding revenue from per-invoice balances and excludes void invoices', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
        'currency_code' => 'CAD',
        'company_features' => ['invoices' => true],
    ]);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);

    $partialInvoice = Invoice::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => 'partial',
        'subtotal' => 100,
        'tax_total' => 0,
        'total' => 100,
        'currency_code' => 'CAD',
    ]);
    $overpaidInvoice = Invoice::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => 'partial',
        'subtotal' => 20,
        'tax_total' => 0,
        'total' => 20,
        'currency_code' => 'CAD',
    ]);
    Invoice::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => 'void',
        'subtotal' => 500,
        'tax_total' => 0,
        'total' => 500,
        'currency_code' => 'CAD',
    ]);

    foreach ([[$partialInvoice, 40], [$overpaidInvoice, 30]] as [$invoice, $amount]) {
        Payment::query()->create([
            'invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'user_id' => $owner->id,
            'amount' => $amount,
            'currency_code' => 'CAD',
            'method' => 'card',
            'status' => Payment::STATUS_PAID,
            'paid_at' => now(),
        ]);
    }

    $response = $this->actingAs($owner)
        ->getJson(route('dashboard', ['fresh' => 1]));

    $response
        ->assertOk()
        ->assertJsonPath('stats.revenue_outstanding', 60)
        ->assertJsonPath('financeSummary.outstanding_invoices_amount', 60);
});
