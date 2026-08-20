<?php

use App\Enums\DemoDataVolume;
use App\Models\AccountingEntry;
use App\Models\AccountingEntryBatch;
use App\Models\Customer;
use App\Models\DemoWorkspace;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Reservation;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Demo\DemoScenarioContext;
use App\Services\Demo\Generators\DemoCommerceGenerator;
use App\Services\FinanceApprovalService;
use Illuminate\Support\Facades\DB;

function studioNayaCommerceContext(): DemoScenarioContext
{
    $owner = User::factory()->create([
        'name' => 'Studio Naya Owner',
        'email' => 'studio-naya-commerce@example.test',
        'company_name' => 'Studio Naya Coiffure',
        'company_type' => 'services',
        'company_sector' => 'salon',
        'company_timezone' => 'America/Toronto',
        'currency_code' => 'CAD',
    ]);
    $workspace = DemoWorkspace::query()->create([
        'owner_user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'prospect_name' => 'Studio Naya',
        'company_name' => 'Studio Naya Coiffure',
        'company_type' => 'services',
        'company_sector' => 'salon',
        'seed_profile' => 'immersive',
        'scenario_key' => 'studio_naya_coiffure',
        'data_volume' => DemoDataVolume::Small,
        'reference_date' => '2026-08-20',
        'random_seed' => 12345,
        'scenario_version' => 1,
        'team_size' => 5,
        'locale' => 'fr',
        'timezone' => 'America/Toronto',
        'selected_modules' => [],
    ]);

    return new DemoScenarioContext(
        workspace: $workspace,
        owner: $owner,
        dataVolume: DemoDataVolume::Small,
        referenceDate: '2026-08-20',
        randomSeed: 12345,
        timezone: 'America/Toronto',
    );
}

test('studio naya commerce posts approved documents and a coherent net refund to accounting', function () {
    $context = studioNayaCommerceContext();
    $ownerId = (int) $context->owner->id;
    $createdAt = $context->referenceDate->subMonths(8);
    $chloe = Customer::query()->create([
        'user_id' => $ownerId,
        'first_name' => 'Chloé',
        'last_name' => 'Nguyen',
        'email' => 'chloe-commerce@example.test',
    ]);
    DB::table('customers')->where('id', $chloe->id)->update([
        'created_at' => $createdAt->utc(),
        'updated_at' => $createdAt->utc(),
    ]);
    $member = TeamMember::query()->create([
        'account_id' => $ownerId,
        'user_id' => $ownerId,
        'role' => 'owner_senior_stylist',
        'title' => 'Propriétaire et coiffeuse senior',
        'permissions' => ['reservations.manage'],
        'is_active' => true,
    ]);
    $category = ProductCategory::query()->create([
        'user_id' => $ownerId,
        'created_by_user_id' => $ownerId,
        'name' => 'Coloration',
    ]);
    $service = Product::query()->create([
        'user_id' => $ownerId,
        'category_id' => $category->id,
        'name' => 'Balayage',
        'description' => 'Service de coloration Studio Naya.',
        'price' => 245,
        'currency_code' => 'CAD',
        'tax_rate' => 14.975,
        'item_type' => Product::ITEM_TYPE_SERVICE,
        'tracking_type' => 'none',
        'is_active' => true,
    ]);
    $reservations = collect([-35, -27])->map(function (int $offset) use ($context, $ownerId, $chloe, $member, $service): Reservation {
        $startsAt = $context->referenceDate->addDays($offset)->setTime(10, 0);
        $reservation = Reservation::query()->create([
            'account_id' => $ownerId,
            'team_member_id' => $member->id,
            'client_id' => $chloe->id,
            'service_id' => $service->id,
            'status' => Reservation::STATUS_COMPLETED,
            'source' => Reservation::SOURCE_STAFF,
            'timezone' => $context->timezone,
            'starts_at' => $startsAt->utc(),
            'ends_at' => $startsAt->addMinutes(240)->utc(),
            'duration_minutes' => 240,
            'buffer_minutes' => 20,
            'created_by_user_id' => $ownerId,
            'metadata' => [
                'scenario_key' => 'studio_naya_coiffure',
                'story_key' => 'chloe_nguyen',
            ],
        ]);
        DB::table('reservations')->where('id', $reservation->id)->update([
            'created_at' => $startsAt->subDays(14)->utc(),
            'updated_at' => $startsAt->utc(),
        ]);

        return $reservation;
    });
    $summary = app(DemoCommerceGenerator::class)->generate(
        $context,
        [
            'key' => 'studio_naya_coiffure',
            'products' => [],
            'suppliers' => [],
            'expense_templates' => [],
            'client_stories' => [],
        ],
        [
            'invoices' => 2,
            'payments' => 3,
            'quotes' => 0,
            'sales' => 0,
            'expenses' => 0,
            'notifications' => 0,
        ],
        collect([$chloe]),
        collect(['chloe_nguyen' => $chloe]),
        collect(['maya_kone' => $member]),
        collect(['balayage' => $service]),
        collect(),
        $reservations->pluck('id'),
    );
    $approvedStatuses = [
        FinanceApprovalService::APPROVAL_STATUS_APPROVED,
        FinanceApprovalService::APPROVAL_STATUS_PROCESSED,
    ];
    $invoices = Invoice::query()->byUser($ownerId)->get();

    expect($summary['invoices'])->toBe(2)
        ->and($summary['payments'])->toBe(3)
        ->and($summary['refunds'])->toBe(1)
        ->and($invoices)->toHaveCount(2)
        ->and($invoices->pluck('approval_status')->unique()->values()->all())
        ->toBe([FinanceApprovalService::APPROVAL_STATUS_APPROVED])
        ->and($invoices->every(
            fn (Invoice $invoice): bool => (int) $invoice->approved_by_user_id === $ownerId
                && $invoice->approved_at !== null
                && data_get($invoice->approval_meta, 'scenario_key') === 'studio_naya_coiffure',
        ))->toBeTrue();

    $eligibleInvoiceCount = $invoices
        ->whereIn('approval_status', $approvedStatuses)
        ->whereNotIn('status', ['draft', 'void'])
        ->count();
    $eligiblePaymentCount = Payment::query()
        ->where('user_id', $ownerId)
        ->whereNotNull('invoice_id')
        ->whereIn('status', Payment::settledStatuses())
        ->whereHas('invoice', fn ($query) => $query->whereIn('approval_status', $approvedStatuses))
        ->count();

    expect(AccountingEntryBatch::query()
        ->forUser($ownerId)
        ->where('source_type', 'invoice')
        ->where('source_event_key', 'invoice_issued')
        ->count())->toBe($eligibleInvoiceCount)
        ->and(AccountingEntryBatch::query()
            ->forUser($ownerId)
            ->where('source_type', 'payment')
            ->where('source_event_key', 'payment_collected')
            ->count())->toBe($eligiblePaymentCount);

    $refund = Payment::query()
        ->where('user_id', $ownerId)
        ->where('customer_id', $chloe->id)
        ->where('reference', 'NAYA-CHLOE-REFUND')
        ->firstOrFail();
    $netPayment = Payment::query()
        ->where('reference', 'NAYA-CHLOE-NET')
        ->firstOrFail();
    $invoice = Invoice::query()
        ->with(['items', 'payments'])
        ->findOrFail($refund->invoice_id);
    $refundSnapshot = data_get($invoice->billing_snapshot, 'refund');
    $refundAdjustment = $invoice->items->first(
        fn ($item): bool => data_get($item->meta, 'type') === 'quality_refund',
    );
    $settledInvoiceAmount = round((float) $invoice->payments
        ->whereIn('status', Payment::settledStatuses())
        ->sum('amount'), 2);
    $tenantNetRevenue = round((float) Payment::query()
        ->where('user_id', $ownerId)
        ->whereIn('status', Payment::settledStatuses())
        ->sum('amount'), 2);
    $tenantGrossBeforeRefund = round($tenantNetRevenue + (float) $refund->amount, 2);

    expect($refund->status)->toBe(Payment::STATUS_REFUNDED)
        ->and($netPayment->status)->toBe(Payment::STATUS_COMPLETED)
        ->and($refundSnapshot)->toBeArray()
        ->and(round((float) $refundSnapshot['gross_payment_amount'], 2))
        ->toBe(round((float) $refund->amount + (float) $netPayment->amount, 2))
        ->and(round((float) $refundSnapshot['refund_amount'], 2))->toBe(round((float) $refund->amount, 2))
        ->and(round((float) $refundSnapshot['net_payment_amount'], 2))->toBe(round((float) $netPayment->amount, 2))
        ->and(round((float) $refundSnapshot['original_invoice_total'] - (float) $refund->amount, 2))
        ->toBe(round((float) $invoice->total, 2))
        ->and($refundAdjustment)->not->toBeNull()
        ->and(round((float) $invoice->items->sum('total'), 2))->toBe(round((float) $invoice->subtotal, 2))
        ->and($settledInvoiceAmount)->toBe(round((float) $invoice->total, 2))
        ->and($invoice->status)->toBe('paid')
        ->and(round($tenantGrossBeforeRefund - $tenantNetRevenue, 2))->toBe(round((float) $refund->amount, 2));

    expect(AccountingEntryBatch::query()
        ->forUser($ownerId)
        ->where('source_type', 'payment')
        ->where('source_id', $refund->id)
        ->exists())->toBeFalse();

    $batches = AccountingEntryBatch::query()
        ->forUser($ownerId)
        ->where(function ($query) use ($invoice, $netPayment): void {
            $query->where(function ($invoiceQuery) use ($invoice): void {
                $invoiceQuery->where('source_type', 'invoice')
                    ->where('source_id', $invoice->id);
            })->orWhere(function ($paymentQuery) use ($netPayment): void {
                $paymentQuery->where('source_type', 'payment')
                    ->where('source_id', $netPayment->id);
            });
        })
        ->with('entries')
        ->get();

    expect($batches)->toHaveCount(2);
    $invoiceBatch = $batches->firstWhere('source_type', 'invoice');
    $paymentBatch = $batches->firstWhere('source_type', 'payment');

    expect($invoiceBatch)->not->toBeNull()
        ->and(data_get($invoiceBatch?->meta, 'approval_status'))
        ->toBe(FinanceApprovalService::APPROVAL_STATUS_APPROVED)
        ->and(round((float) $invoiceBatch?->entries
            ->where('direction', AccountingEntry::DIRECTION_DEBIT)
            ->sum('amount'), 2))->toBe(round((float) $invoice->total, 2))
        ->and($paymentBatch)->not->toBeNull()
        ->and(round((float) $paymentBatch?->entries
            ->where('direction', AccountingEntry::DIRECTION_DEBIT)
            ->sum('amount'), 2))->toBe(round((float) $netPayment->amount, 2));

    foreach ($batches as $batch) {
        $debits = round((float) $batch->entries
            ->where('direction', AccountingEntry::DIRECTION_DEBIT)
            ->sum('amount'), 2);
        $credits = round((float) $batch->entries
            ->where('direction', AccountingEntry::DIRECTION_CREDIT)
            ->sum('amount'), 2);

        expect($debits)->toBe($credits);
    }
});
