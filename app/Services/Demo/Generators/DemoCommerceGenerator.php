<?php

namespace App\Services\Demo\Generators;

use App\Actions\Invoices\CreateInvoicePaymentAction;
use App\Actions\Quotes\UpsertQuoteAction;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Reservation;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Task;
use App\Models\Tax;
use App\Models\TeamMember;
use App\Models\Transaction;
use App\Notifications\DemoActionNotification;
use App\Services\Accounting\AccountingSyncService;
use App\Services\Demo\DemoScenarioContext;
use App\Services\FinanceApprovalService;
use App\Services\SalePaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DemoCommerceGenerator
{
    public function __construct(
        private readonly CreateInvoicePaymentAction $createInvoicePayment,
        private readonly UpsertQuoteAction $upsertQuote,
        private readonly SalePaymentService $salePaymentService,
        private readonly AccountingSyncService $accountingSyncService,
        private readonly FinanceApprovalService $financeApprovalService,
    ) {}

    /**
     * @param  array<string, mixed>  $blueprint
     * @param  array<string, int>  $targets
     * @param  Collection<int, Customer>  $customers
     * @param  Collection<string, Customer>  $storyCustomers
     * @param  Collection<string, TeamMember>  $teamMembers
     * @param  Collection<string, Product>  $services
     * @param  Collection<string, Product>  $products
     * @param  Collection<int, int>  $reservationIds
     * @return array<string, int>
     */
    public function generate(
        DemoScenarioContext $context,
        array $blueprint,
        array $targets,
        Collection $customers,
        Collection $storyCustomers,
        Collection $teamMembers,
        Collection $services,
        Collection $products,
        Collection $reservationIds,
    ): array {
        $invoiceSummary = $this->createInvoicesAndPayments(
            $context,
            $targets,
            $customers,
            $storyCustomers,
            $teamMembers,
            $services,
            $reservationIds,
        );
        $quoteSummary = $this->createQuotes(
            $context,
            (int) ($targets['quotes'] ?? 0),
            $customers,
            $storyCustomers,
            $teamMembers,
            $services,
        );
        $sales = $this->createSales(
            $context,
            $blueprint,
            (int) ($targets['sales'] ?? 0),
            $customers,
            $storyCustomers,
            $products,
        );
        $expenses = $this->createExpenses(
            $context,
            $blueprint,
            (int) ($targets['expenses'] ?? 0),
            $teamMembers,
        );
        $this->recordNarrativeTimelines($context, $blueprint, $storyCustomers);
        $notifications = $this->createNotifications(
            $context,
            (int) ($targets['notifications'] ?? 0),
            $storyCustomers,
            $products,
        );

        $this->accountingSyncService->syncAccount((int) $context->owner->id);

        return [
            ...$invoiceSummary,
            ...$quoteSummary,
            'sales' => $sales,
            'expenses' => $expenses,
            'notifications' => $notifications,
        ];
    }

    /**
     * @param  array<string, int>  $targets
     * @param  Collection<int, Customer>  $customers
     * @param  Collection<string, Customer>  $storyCustomers
     * @param  Collection<string, TeamMember>  $teamMembers
     * @param  Collection<string, Product>  $services
     * @param  Collection<int, int>  $reservationIds
     * @return array{invoices:int, payments:int, refunds:int}
     */
    private function createInvoicesAndPayments(
        DemoScenarioContext $context,
        array $targets,
        Collection $customers,
        Collection $storyCustomers,
        Collection $teamMembers,
        Collection $services,
        Collection $reservationIds,
    ): array {
        $invoiceTarget = (int) ($targets['invoices'] ?? 0);
        $saleTarget = (int) ($targets['sales'] ?? 0);
        $totalPaymentTarget = (int) ($targets['payments'] ?? 0);
        $invoicePaymentTarget = max(0, $totalPaymentTarget - $saleTarget);
        $refundCount = $invoicePaymentTarget > 0 ? 1 : 0;
        $normalPaymentTarget = max(0, $invoicePaymentTarget - $refundCount);
        $allBillable = Reservation::query()
            ->forAccount((int) $context->owner->id)
            ->whereIn('id', $reservationIds->all())
            ->whereIn('status', [Reservation::STATUS_COMPLETED, Reservation::STATUS_NO_SHOW])
            ->orderBy('starts_at')
            ->get();

        if ($allBillable->count() < $invoiceTarget) {
            throw new RuntimeException(sprintf(
                'Studio Naya has %d billable reservations for %d requested invoices.',
                $allBillable->count(),
                $invoiceTarget,
            ));
        }

        $storyBillable = $allBillable
            ->filter(fn (Reservation $reservation): bool => filled(data_get($reservation->metadata, 'story_key')))
            ->take($invoiceTarget)
            ->values();
        $storyReservationIds = $storyBillable->pluck('id')->flip();
        $billable = $storyBillable
            ->concat($this->evenlyDistributedReservations(
                $allBillable->reject(
                    fn (Reservation $reservation): bool => $storyReservationIds->has($reservation->id),
                )->values(),
                max(0, $invoiceTarget - $storyBillable->count()),
            ))
            ->sortBy('starts_at')
            ->values();

        $storyByCustomer = $storyCustomers->mapWithKeys(
            fn (Customer $customer, string $key): array => [(int) $customer->id => $key],
        );
        $customersById = $customers->keyBy(fn (Customer $customer): int => (int) $customer->id);
        $storyCounters = [];
        $invoiceMeta = [];
        $invoiceIds = collect();
        $invoiceApprovalTemplate = $this->financeApprovalService->resolveInvoiceCreation(
            $context->owner,
            $context->owner,
            0.0,
        );

        foreach ($billable as $index => $reservation) {
            $storyKey = $storyByCustomer->get((int) $reservation->client_id);
            $storyCounters[$storyKey] = ($storyCounters[$storyKey] ?? 0) + 1;
            $storySequence = (int) $storyCounters[$storyKey];
            $status = $this->invoiceStatus($index, $storyKey, $storySequence);
            $service = $services->firstWhere('id', $reservation->service_id);
            $subtotal = $reservation->status === Reservation::STATUS_NO_SHOW
                ? 35.00
                : (float) ($service?->price ?? 75.00);

            if ($storyKey === 'chloe_nguyen' && $storySequence === 2) {
                $subtotal = round($subtotal * 0.50, 2);
            }
            if ($index % 19 === 0 && $reservation->status !== Reservation::STATUS_NO_SHOW) {
                $subtotal = round($subtotal * 0.95, 2);
            }

            $taxTotal = round($subtotal * 0.14975, 2);
            $total = round($subtotal + $taxTotal, 2);
            $createdAt = CarbonImmutable::instance($reservation->ends_at)
                ->setTimezone($context->timezone)
                ->addMinutes(15);
            $dueAt = $status === 'overdue'
                ? $createdAt->addDays(7)
                : $createdAt->addDays(30);
            $member = $teamMembers->firstWhere('id', $reservation->team_member_id);
            $customer = $customersById->get((int) $reservation->client_id);
            $approval = $invoiceApprovalTemplate;
            $approval['approval_policy_snapshot'] = array_replace(
                (array) ($approval['approval_policy_snapshot'] ?? []),
                [
                    'amount' => $total,
                    'evaluated_at' => $createdAt->toIso8601String(),
                ],
            );

            $invoice = Invoice::query()->create([
                'work_id' => null,
                'customer_id' => $reservation->client_id,
                'user_id' => $context->owner->id,
                'created_by_user_id' => $context->owner->id,
                'approved_by_user_id' => $approval['approved_by_user_id'] ?? null,
                'status' => in_array($status, ['paid', 'partial'], true) ? 'sent' : $status,
                'approval_status' => $approval['approval_status'],
                'current_approver_role_key' => $approval['current_approver_role_key'],
                'current_approval_level' => $approval['current_approval_level'],
                'approved_at' => ($approval['auto_approved'] ?? false) ? $createdAt->utc() : null,
                'approval_meta' => [
                    'approval_policy_snapshot' => $approval['approval_policy_snapshot'] ?? null,
                    'scenario_key' => 'studio_naya_coiffure',
                ],
                'subtotal' => $subtotal,
                'tax_total' => $taxTotal,
                'total' => $total,
                'currency_code' => 'CAD',
                'source' => 'reservation',
                'billing_snapshot' => [
                    'scenario_key' => 'studio_naya_coiffure',
                    'reservation_id' => $reservation->id,
                    'reservation_started_at' => $reservation->starts_at->toIso8601String(),
                    'employee_name' => $member?->user?->name,
                    'service_name' => $service?->name,
                    'tax_rate' => 14.975,
                    'discount_applied' => $storyKey === 'chloe_nguyen' && $storySequence === 2 ? 50 : ($index % 19 === 0 ? 5 : 0),
                    'due_at' => $dueAt->toIso8601String(),
                    'story_key' => $storyKey,
                ],
                'customer_snapshot' => [
                    'name' => trim((string) $customer?->first_name.' '.(string) $customer?->last_name),
                    'email' => $customer?->email,
                ],
            ]);
            InvoiceItem::query()->create([
                'invoice_id' => $invoice->id,
                'assigned_team_member_id' => $reservation->team_member_id,
                'title' => $reservation->status === Reservation::STATUS_NO_SHOW ? 'Frais d’absence' : ($service?->name ?? 'Service Studio Naya'),
                'description' => $reservation->status === Reservation::STATUS_NO_SHOW
                    ? 'Frais appliqués selon la politique de rendez-vous.'
                    : 'Service réalisé au Studio Naya.',
                'scheduled_date' => $reservation->starts_at->toDateString(),
                'start_time' => $reservation->starts_at->format('H:i:s'),
                'end_time' => $reservation->ends_at->format('H:i:s'),
                'assignee_name' => $member?->user?->name,
                'task_status' => 'completed',
                'quantity' => 1,
                'unit_price' => $subtotal,
                'currency_code' => 'CAD',
                'total' => $subtotal,
                'meta' => [
                    'reservation_id' => $reservation->id,
                    'service_id' => $service?->id,
                    'scenario_key' => 'studio_naya_coiffure',
                ],
            ]);
            DB::table('invoices')->where('id', $invoice->id)->update([
                'created_at' => $createdAt->utc(),
                'updated_at' => $createdAt->utc(),
            ]);
            DB::table('invoice_items')->where('invoice_id', $invoice->id)->update([
                'created_at' => $createdAt->utc(),
                'updated_at' => $createdAt->utc(),
            ]);
            $invoiceMeta[$invoice->id] = [
                'target_status' => $status,
                'created_at' => $createdAt,
                'story_key' => $storyKey,
                'story_sequence' => $storySequence,
            ];
            $invoiceIds->push((int) $invoice->id);
        }

        $payableIds = $invoiceIds->filter(
            fn (int $invoiceId): bool => in_array($invoiceMeta[$invoiceId]['target_status'], ['paid', 'partial'], true),
        )->values();
        if ($normalPaymentTarget < $payableIds->count()) {
            throw new RuntimeException('The configured payment target is smaller than the required paid and partial invoice set.');
        }

        $splitCounts = $payableIds->mapWithKeys(fn (int $invoiceId): array => [$invoiceId => 1])->all();
        $extra = $normalPaymentTarget - $payableIds->count();
        $paidInvoiceIds = $payableIds->filter(
            fn (int $invoiceId): bool => $invoiceMeta[$invoiceId]['target_status'] === 'paid',
        )->values();
        for ($index = 0; $index < $extra; $index++) {
            $invoiceId = $paidInvoiceIds[$index % $paidInvoiceIds->count()];
            $splitCounts[$invoiceId]++;
        }

        unset($reservationIds, $allBillable, $billable, $storyByCustomer, $customersById);
        gc_collect_cycles();

        $paymentSequence = 0;
        foreach ($payableIds as $invoiceId) {
            $invoice = Invoice::query()->findOrFail($invoiceId);
            $targetStatus = (string) $invoiceMeta[$invoice->id]['target_status'];
            $splitCount = $targetStatus === 'partial' ? 1 : (int) $splitCounts[$invoice->id];
            $amountToPay = $targetStatus === 'partial'
                ? round((float) $invoice->total * 0.45, 2)
                : (float) $invoice->total;
            $remaining = $amountToPay;

            for ($part = 1; $part <= $splitCount; $part++) {
                $amount = $part === $splitCount
                    ? $remaining
                    : round($amountToPay / $splitCount, 2);
                $remaining = round($remaining - $amount, 2);
                $method = $this->paymentMethod($paymentSequence);
                $paidAt = CarbonImmutable::instance($invoiceMeta[$invoice->id]['created_at'])
                    ->addHours(1 + ($part * 2));
                $storyKey = $invoiceMeta[$invoice->id]['story_key'];
                $storySequence = (int) $invoiceMeta[$invoice->id]['story_sequence'];
                $tipPercent = $part === $splitCount && (
                    ($storyKey === 'marc_andre_beaulieu' && $storySequence <= 8)
                    || ($storyKey !== 'marc_andre_beaulieu'
                        && $storyKey !== 'chloe_nguyen'
                        && $paymentSequence % 5 === 0)
                ) ? 18 : 0;
                $useDomainAction = $paymentSequence < 40
                    || $tipPercent > 0
                    || $targetStatus === 'partial';
                $payment = $this->recordHistoricalInvoicePayment(
                    $context,
                    $invoice,
                    $amount,
                    $method,
                    $paidAt,
                    $tipPercent,
                    $paymentSequence,
                    $useDomainAction,
                );
                DB::table('payments')->where('id', $payment->id)->update([
                    'created_at' => $paidAt->utc(),
                    'updated_at' => $paidAt->utc(),
                ]);
                $paymentSequence++;
            }

            $invoice->unsetRelation('payments');
            $invoice->refreshPaymentStatus();
        }

        $chloe = $storyCustomers->get('chloe_nguyen');
        $refunded = $refundCount > 0 && $chloe
            ? $this->recordChloeNarrativeRefund($context, $chloe)
            : 0;

        return [
            'invoices' => $invoiceIds->count(),
            'payments' => $paymentSequence + $refunded,
            'refunds' => $refunded,
        ];
    }

    private function recordChloeNarrativeRefund(
        DemoScenarioContext $context,
        Customer $chloe,
    ): int {
        $sourcePayment = Payment::query()
            ->where('user_id', $context->owner->id)
            ->where('customer_id', $chloe->id)
            ->whereNotNull('invoice_id')
            ->whereIn('status', Payment::settledStatuses())
            ->where('tip_amount', '<=', 0)
            ->oldest('paid_at')
            ->first();
        if (! $sourcePayment?->invoice_id) {
            return 0;
        }

        return DB::transaction(function () use ($context, $sourcePayment): int {
            $sourcePayment = Payment::query()
                ->whereKey($sourcePayment->id)
                ->lockForUpdate()
                ->firstOrFail();
            $invoice = Invoice::query()
                ->whereKey($sourcePayment->invoice_id)
                ->lockForUpdate()
                ->firstOrFail();
            $grossPaymentAmount = round((float) $sourcePayment->amount, 2);
            $refundAmount = round($grossPaymentAmount * 0.25, 2);
            $netPaymentAmount = round($grossPaymentAmount - $refundAmount, 2);
            if ($refundAmount <= 0 || $netPaymentAmount <= 0) {
                return 0;
            }

            $originalPaidAt = CarbonImmutable::instance(
                $sourcePayment->paid_at ?: $sourcePayment->created_at ?: $context->referenceDate,
            );
            $refundAt = $originalPaidAt->addDays(9);
            $originalReference = (string) ($sourcePayment->reference ?: 'NAYA-CHLOE-PAYMENT');
            $originalSubtotal = round((float) $invoice->subtotal, 2);
            $originalTaxTotal = round((float) $invoice->tax_total, 2);
            $originalTotal = round((float) $invoice->total, 2);
            $refundTax = $originalTotal > 0
                ? round($refundAmount * ($originalTaxTotal / $originalTotal), 2)
                : 0.0;
            $refundSubtotal = round($refundAmount - $refundTax, 2);

            $sourcePayment->forceFill([
                'amount' => $refundAmount,
                'tip_amount' => 0,
                'tip_type' => 'none',
                'tip_percent' => null,
                'tip_base_amount' => 0,
                'charged_total' => 0,
                'status' => Payment::STATUS_REFUNDED,
                'reference' => 'NAYA-CHLOE-REFUND',
                'notes' => sprintf(
                    'Remboursement partiel après suivi qualité coloration; paiement original %s de %.2f CAD.',
                    $originalReference,
                    $grossPaymentAmount,
                ),
                'paid_at' => $refundAt->utc(),
            ])->save();
            DB::table('payments')->where('id', $sourcePayment->id)->update([
                'created_at' => $refundAt->utc(),
                'updated_at' => $refundAt->utc(),
            ]);

            $netPaymentResult = $this->createInvoicePayment->execute(
                $invoice,
                [
                    'amount' => $netPaymentAmount,
                    'status' => Payment::STATUS_COMPLETED,
                    'paid_at' => $originalPaidAt->utc(),
                    'tip_enabled' => false,
                    'tip_mode' => 'none',
                    'reference' => 'NAYA-CHLOE-NET',
                    'notes' => sprintf(
                        'Montant net conservé après le remboursement partiel %s.',
                        $sourcePayment->reference,
                    ),
                ],
                (string) ($sourcePayment->method ?: 'card'),
                $context->owner,
                (int) $context->owner->id,
                'Paiement net Studio Naya enregistré après remboursement.',
            );
            /** @var Payment $netPayment */
            $netPayment = $netPaymentResult['payment'];
            if ($netPayment->status === Payment::STATUS_PENDING) {
                $netPayment->forceFill([
                    'status' => Payment::STATUS_COMPLETED,
                    'paid_at' => $originalPaidAt->utc(),
                    'provider' => 'manual',
                ])->save();
                $invoice->unsetRelation('payments');
                $invoice->refreshPaymentStatus();
            }
            DB::table('payments')->where('id', $netPayment->id)->update([
                'created_at' => $originalPaidAt->utc(),
                'updated_at' => $originalPaidAt->utc(),
            ]);

            $adjustment = InvoiceItem::query()->create([
                'invoice_id' => $invoice->id,
                'title' => 'Ajustement qualité — remboursement partiel',
                'description' => 'Crédit accordé après la correction de coloration de Chloé Nguyen.',
                'quantity' => 1,
                'unit_price' => -$refundSubtotal,
                'currency_code' => $invoice->currency_code ?: 'CAD',
                'total' => -$refundSubtotal,
                'meta' => [
                    'type' => 'quality_refund',
                    'scenario_key' => 'studio_naya_coiffure',
                    'refund_payment_id' => $sourcePayment->id,
                    'net_payment_id' => $netPayment->id,
                    'tax_refunded' => $refundTax,
                ],
            ]);
            DB::table('invoice_items')->where('id', $adjustment->id)->update([
                'created_at' => $refundAt->utc(),
                'updated_at' => $refundAt->utc(),
            ]);

            $billingSnapshot = is_array($invoice->billing_snapshot) ? $invoice->billing_snapshot : [];
            $billingSnapshot['refund'] = [
                'type' => 'partial_quality_refund',
                'reason' => 'color_correction_follow_up',
                'original_payment_reference' => $originalReference,
                'original_invoice_subtotal' => $originalSubtotal,
                'original_invoice_tax_total' => $originalTaxTotal,
                'original_invoice_total' => $originalTotal,
                'gross_payment_amount' => $grossPaymentAmount,
                'refund_amount' => $refundAmount,
                'net_payment_amount' => $netPaymentAmount,
                'refund_subtotal' => $refundSubtotal,
                'refund_tax' => $refundTax,
                'refund_payment_id' => $sourcePayment->id,
                'net_payment_id' => $netPayment->id,
                'recorded_at' => $refundAt->toIso8601String(),
            ];
            $invoice->forceFill([
                'subtotal' => round($originalSubtotal - $refundSubtotal, 2),
                'tax_total' => round($originalTaxTotal - $refundTax, 2),
                'total' => round($originalTotal - $refundAmount, 2),
                'billing_snapshot' => $billingSnapshot,
            ])->save();
            $invoice->unsetRelation('payments');
            $invoice->refreshPaymentStatus();
            DB::table('invoices')->where('id', $invoice->id)->update([
                'updated_at' => $refundAt->utc(),
            ]);

            return 1;
        });
    }

    private function recordHistoricalInvoicePayment(
        DemoScenarioContext $context,
        Invoice $invoice,
        float $amount,
        string $method,
        CarbonImmutable $paidAt,
        int $tipPercent,
        int $paymentSequence,
        bool $useDomainAction,
    ): Payment {
        $reference = sprintf('NAYA-PAY-%05d', $paymentSequence + 1);

        if (! $useDomainAction) {
            $payment = Payment::withoutEvents(fn (): Payment => Payment::query()->create([
                'invoice_id' => $invoice->id,
                'customer_id' => $invoice->customer_id,
                'user_id' => $context->owner->id,
                'amount' => $amount,
                'currency_code' => $invoice->currency_code,
                'tip_amount' => 0,
                'tip_type' => 'none',
                'tip_base_amount' => $amount,
                'charged_total' => $amount,
                'method' => $method,
                'provider' => 'manual',
                'status' => Payment::STATUS_COMPLETED,
                'reference' => $reference,
                'notes' => 'Paiement historique du scénario Studio Naya.',
                'paid_at' => $paidAt->utc(),
            ]));
            $invoice->unsetRelation('payments');
            $invoice->refreshPaymentStatus();

            return $payment;
        }

        $invoice->unsetRelation('payments');
        $result = $this->createInvoicePayment->execute(
            $invoice,
            [
                'amount' => $amount,
                'status' => Payment::STATUS_COMPLETED,
                'paid_at' => $paidAt->utc(),
                'tip_enabled' => $tipPercent > 0,
                'tip_mode' => $tipPercent > 0 ? 'percent' : 'none',
                'tip_percent' => $tipPercent ?: null,
                'reference' => $reference,
                'notes' => 'Paiement historique du scénario Studio Naya.',
            ],
            $method,
            $context->owner,
            (int) $context->owner->id,
            'Paiement Studio Naya enregistré.',
        );
        /** @var Payment $payment */
        $payment = $result['payment'];
        if ($payment->status === Payment::STATUS_PENDING) {
            $payment->forceFill([
                'status' => Payment::STATUS_COMPLETED,
                'paid_at' => $paidAt->utc(),
                'provider' => 'manual',
            ])->save();
            $invoice->unsetRelation('payments');
            $invoice->refreshPaymentStatus();
        }

        return $payment;
    }

    private function invoiceStatus(int $index, ?string $storyKey, int $storySequence): string
    {
        if ($storyKey === 'aicha_martin' && $storySequence <= 12) {
            return 'paid';
        }
        if ($storyKey === 'marc_andre_beaulieu' && $storySequence <= 21) {
            return 'paid';
        }
        if (in_array($storyKey, ['samantha_joseph', 'nadia_pierre'], true) && $storySequence === 1) {
            return 'partial';
        }
        if ($storyKey === 'chloe_nguyen' && $storySequence <= 2) {
            return 'paid';
        }

        return match (true) {
            $index % 100 < 76 => 'paid',
            $index % 100 < 84 => 'partial',
            $index % 100 < 90 => 'overdue',
            $index % 100 < 94 => 'sent',
            $index % 100 < 97 => 'draft',
            default => 'void',
        };
    }

    /**
     * Select records across the entire history instead of biasing invoices
     * toward the oldest reservations.
     *
     * @param  Collection<int, Reservation>  $reservations
     * @return Collection<int, Reservation>
     */
    private function evenlyDistributedReservations(Collection $reservations, int $target): Collection
    {
        $count = $reservations->count();
        if ($target <= 0 || $count === 0) {
            return collect();
        }
        if ($target >= $count) {
            return $reservations->values();
        }
        if ($target === 1) {
            return collect([$reservations[(int) floor(($count - 1) / 2)]]);
        }

        return collect(range(0, $target - 1))
            ->map(function (int $position) use ($reservations, $count, $target): Reservation {
                $index = (int) floor(($position * ($count - 1)) / ($target - 1));

                return $reservations[$index];
            })
            ->values();
    }

    private function paymentMethod(int $index): string
    {
        return match ($index % 12) {
            0, 1, 2, 3 => 'card',
            4, 5, 6 => 'debit_card',
            7, 8 => 'cash',
            9 => 'bank_transfer',
            default => 'online_card',
        };
    }

    /**
     * @param  Collection<int, Customer>  $customers
     * @param  Collection<string, Customer>  $storyCustomers
     * @param  Collection<string, TeamMember>  $teamMembers
     * @param  Collection<string, Product>  $services
     * @return array{quotes:int, deposits:int, tasks:int}
     */
    private function createQuotes(
        DemoScenarioContext $context,
        int $target,
        Collection $customers,
        Collection $storyCustomers,
        Collection $teamMembers,
        Collection $services,
    ): array {
        if ($target <= 0 || $services->isEmpty()) {
            return [
                'quotes' => 0,
                'deposits' => 0,
                'tasks' => 0,
            ];
        }

        $samantha = $storyCustomers->get('samantha_joseph');
        $serviceList = $services->where('is_active', true)->values();
        $taxIds = collect([
            ['name' => 'TPS', 'rate' => 5.000],
            ['name' => 'TVQ', 'rate' => 9.975],
        ])->map(function (array $definition): int {
            $tax = Tax::query()->firstOrCreate(
                ['name' => $definition['name']],
                ['rate' => $definition['rate']],
            );

            return (int) $tax->id;
        })->all();
        $depositCount = 0;
        $taskCount = 0;

        for ($index = 0; $index < $target; $index++) {
            $createdAt = $index === 0
                ? $context->referenceDate->subDays(84)
                : $context->referenceDate->subDays(20 + (($index * 9) % 500));
            $eligibleCustomers = $customers
                ->filter(fn (Customer $customer): bool => ! $customer->created_at || $customer->created_at->lte($createdAt))
                ->values();
            $customer = $index === 0 && $samantha && $samantha->created_at?->lte($createdAt)
                ? $samantha
                : $eligibleCustomers[$index % $eligibleCustomers->count()];
            $service = $index === 0
                ? ($services->get('event_updo') ?? $serviceList->first())
                : $serviceList[$index % $serviceList->count()];
            $status = $index === 0 ? 'accepted' : match ($index % 5) {
                0 => 'accepted',
                1 => 'sent',
                2 => 'draft',
                3 => 'rejected',
                default => 'sent',
            };
            $quantity = $index === 0 ? 3 : 1;
            $estimatedSubtotal = round((float) $service->price * $quantity, 2);
            $estimatedTotal = round($estimatedSubtotal * 1.14975, 2);
            $initialDeposit = $index === 0 ? round($estimatedTotal * 0.30, 2) : 0.0;
            $result = $this->upsertQuote->execute([
                'customer_id' => $customer->id,
                'property_id' => $customer->properties()->value('id'),
                'job_title' => $index === 0 ? 'Forfait coiffure de mariage Samantha' : 'Proposition Studio Naya · '.$service->name,
                'product' => [[
                    'id' => $service->id,
                    'name' => $service->name,
                    'item_type' => Product::ITEM_TYPE_SERVICE,
                    'quantity' => $quantity,
                    'price' => (float) $service->price,
                    'description' => $service->description,
                ]],
                'taxes' => $taxIds,
                'initial_deposit' => $initialDeposit,
                'notes' => 'Devis narratif généré à partir du catalogue Studio Naya.',
                'messages' => null,
                'status' => $status,
            ], $context->owner);
            /** @var Quote $quote */
            $quote = $result['quote'];
            $acceptedAt = $index === 0
                ? $context->referenceDate->subDays(78)
                : $createdAt->addDays(3);
            $quote->forceFill([
                'accepted_at' => $status === 'accepted' ? $acceptedAt->utc() : null,
                'signed_at' => $status === 'accepted' ? $acceptedAt->utc() : null,
                'last_sent_at' => $status !== 'draft'
                    ? ($index === 0 ? $createdAt : $createdAt->addDay())->utc()
                    : null,
                'next_follow_up_at' => $status === 'sent'
                    ? $context->referenceDate->addDays(3 + ($index % 9))->utc()
                    : null,
                'follow_up_state' => $status === 'sent' ? 'due' : null,
                'follow_up_count' => $status === 'sent' ? 1 + ($index % 2) : 0,
                'recovery_priority' => $status === 'sent' ? 40 + ($index % 50) : 0,
            ])->save();
            DB::table('quotes')->where('id', $quote->id)->update([
                'created_at' => $createdAt->utc(),
                'updated_at' => ($status === 'accepted' ? $acceptedAt : $createdAt->addDay())->utc(),
            ]);

            if ($index !== 0 || ! $samantha) {
                continue;
            }

            $depositPaidAt = $context->referenceDate->subDays(77)->setTime(11, 30);
            $deposit = Transaction::query()->create([
                'quote_id' => $quote->id,
                'work_id' => null,
                'invoice_id' => null,
                'customer_id' => $samantha->id,
                'user_id' => $context->owner->id,
                'amount' => (float) $quote->initial_deposit,
                'type' => 'deposit',
                'method' => 'card',
                'status' => 'completed',
                'reference' => 'NAYA-SAMANTHA-WEDDING-DEPOSIT',
                'notes' => 'Dépôt de 30 % reçu pour le forfait mariage Studio Naya.',
                'paid_at' => $depositPaidAt->utc(),
            ]);
            DB::table('transactions')->where('id', $deposit->id)->update([
                'created_at' => $depositPaidAt->utc(),
                'updated_at' => $depositPaidAt->utc(),
            ]);
            $depositCount++;

            $followUpCreatedAt = $acceptedAt->addHours(2);
            $task = Task::query()->create([
                'account_id' => $context->owner->id,
                'created_by_user_id' => $context->owner->id,
                'assigned_team_member_id' => $teamMembers->get('maya_kone')?->id,
                'customer_id' => $samantha->id,
                'product_id' => $service->id,
                'title' => 'Confirmer les détails du mariage de Samantha',
                'description' => 'Valider les accessoires dorés, le voile, l’horaire final et le solde avant le mariage.',
                'status' => Task::STATUS_TODO,
                'priority' => Task::PRIORITY_HIGH,
                'billable' => false,
                'due_date' => $context->referenceDate->addDays(35)->toDateString(),
            ]);
            DB::table('tasks')->where('id', $task->id)->update([
                'created_at' => $followUpCreatedAt->utc(),
                'updated_at' => $followUpCreatedAt->utc(),
            ]);
            $taskCount++;
        }

        return [
            'quotes' => $target,
            'deposits' => $depositCount,
            'tasks' => $taskCount,
        ];
    }

    /**
     * @param  array<string, mixed>  $blueprint
     * @param  Collection<int, Customer>  $customers
     * @param  Collection<string, Customer>  $storyCustomers
     * @param  Collection<string, Product>  $products
     */
    private function createSales(
        DemoScenarioContext $context,
        array $blueprint,
        int $target,
        Collection $customers,
        Collection $storyCustomers,
        Collection $products,
    ): int {
        $retailKeys = collect((array) $blueprint['products'])
            ->where('retail', true)
            ->pluck('key')
            ->all();
        $retailProducts = $products->only($retailKeys)->values();
        if ($target <= 0 || $retailProducts->isEmpty()) {
            return 0;
        }

        $narrativeAssignments = [
            0 => ['story_key' => 'aicha_martin', 'product_key' => 'hair_oil', 'offset_days' => -42],
            1 => ['story_key' => 'marc_andre_beaulieu', 'product_key' => 'beard_oil', 'offset_days' => -63],
            2 => ['story_key' => 'aicha_martin', 'product_key' => 'braid_mousse', 'offset_days' => -180],
            3 => ['story_key' => 'marc_andre_beaulieu', 'product_key' => 'beard_balm', 'offset_days' => -210],
            4 => ['story_key' => 'aicha_martin', 'product_key' => 'satin_bonnet', 'offset_days' => -300],
        ];
        $storyCustomerIds = $storyCustomers->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $genericCustomers = $customers
            ->reject(fn (Customer $customer): bool => in_array((int) $customer->id, $storyCustomerIds, true))
            ->values();

        for ($index = 0; $index < $target; $index++) {
            $assignment = $narrativeAssignments[$index] ?? null;
            $product = $assignment
                ? ($products->get($assignment['product_key']) ?? $retailProducts[$index % $retailProducts->count()])
                : $retailProducts[$index % $retailProducts->count()];
            $paidAt = $assignment
                ? $context->referenceDate->addDays($assignment['offset_days'])->setTime(16, $index % 60)
                : $context->referenceDate->subDays(2 + (($index * 11) % 520))->setTime(16, $index % 60);
            $eligibleCustomers = $genericCustomers
                ->filter(fn (Customer $customer): bool => ! $customer->created_at || $customer->created_at->lte($paidAt))
                ->values();
            $customer = $assignment
                ? $storyCustomers->get($assignment['story_key'])
                : $eligibleCustomers[($index * 7) % $eligibleCustomers->count()];
            if (! $customer || ($customer->created_at && $customer->created_at->gt($paidAt))) {
                throw new RuntimeException('A Studio Naya retail sale could not resolve an eligible customer.');
            }
            $subtotal = (float) $product->price;
            $discount = $index % 17 === 0 ? round($subtotal * 0.10, 2) : 0;
            $tax = round(($subtotal - $discount) * 0.14975, 2);
            $total = round($subtotal - $discount + $tax, 2);
            $sale = Sale::query()->create([
                'user_id' => $context->owner->id,
                'created_by_user_id' => $context->owner->id,
                'customer_id' => $customer->id,
                'status' => Sale::STATUS_PENDING,
                'subtotal' => $subtotal,
                'tax_total' => $tax,
                'currency_code' => 'CAD',
                'discount_rate' => $discount > 0 ? 10 : 0,
                'discount_total' => $discount,
                'loyalty_points_redeemed' => 0,
                'loyalty_discount_total' => 0,
                'total' => $total,
                'delivery_fee' => 0,
                'fulfillment_method' => null,
                'fulfillment_status' => null,
                'scheduled_for' => $paidAt,
                'source' => 'pos',
                'notes' => $assignment
                    ? sprintf(
                        'Vente narrative Studio Naya; story_key=%s; product_key=%s.',
                        $assignment['story_key'],
                        $assignment['product_key'],
                    )
                    : 'Vente comptoir historique Studio Naya.',
            ]);
            SaleItem::query()->create([
                'sale_id' => $sale->id,
                'product_id' => $product->id,
                'description' => $product->description,
                'quantity' => 1,
                'price' => $subtotal,
                'currency_code' => 'CAD',
                'total' => $subtotal,
            ]);
            $method = $index % 6 === 0 ? 'cash' : 'card';
            $this->salePaymentService->recordManualPayment($sale, $total, $method, $context->owner);
            $payment = $sale->payments()->latest('id')->firstOrFail();
            if ($payment->status === Payment::STATUS_PENDING) {
                $payment->forceFill([
                    'status' => Payment::STATUS_COMPLETED,
                    'provider' => 'manual',
                    'paid_at' => $paidAt->utc(),
                ])->save();
                $this->salePaymentService->refreshAfterManualPaymentSettlement($sale->fresh(), $payment, $context->owner);
            }
            $sale->refresh()->forceFill([
                'fulfillment_method' => 'pickup',
                'fulfillment_status' => Sale::FULFILLMENT_COMPLETED,
            ])->save();
            DB::table('sales')->where('id', $sale->id)->update([
                'created_at' => $paidAt->subMinutes(5)->utc(),
                'updated_at' => $paidAt->utc(),
                'paid_at' => $paidAt->utc(),
            ]);
            DB::table('sale_items')->where('sale_id', $sale->id)->update([
                'created_at' => $paidAt->subMinutes(5)->utc(),
                'updated_at' => $paidAt->utc(),
            ]);
            DB::table('payments')->where('id', $payment->id)->update([
                'created_at' => $paidAt->utc(),
                'updated_at' => $paidAt->utc(),
                'paid_at' => $paidAt->utc(),
            ]);
            DB::table('product_stock_movements')
                ->where('reference_type', $sale->getMorphClass())
                ->where('reference_id', $sale->id)
                ->update(['created_at' => $paidAt->utc(), 'updated_at' => $paidAt->utc()]);
        }

        return $target;
    }

    /**
     * @param  array<string, mixed>  $blueprint
     * @param  Collection<string, TeamMember>  $teamMembers
     */
    private function createExpenses(
        DemoScenarioContext $context,
        array $blueprint,
        int $target,
        Collection $teamMembers,
    ): int {
        $templates = collect((array) $blueprint['expense_templates'])->values();
        $suppliers = collect((array) $blueprint['suppliers'])->values();
        $members = $teamMembers->values();
        $rng = $context->randomizer('expenses');

        for ($index = 0; $index < $target; $index++) {
            $template = $templates[$index % $templates->count()];
            $range = (array) $template['amount_range'];
            $minimum = (int) round((float) $range[0] * 100);
            $maximum = (int) round((float) $range[1] * 100);
            $subtotal = $rng->getInt($minimum, max($minimum, $maximum)) / 100;
            $tax = round($subtotal * 0.14975, 2);
            $total = round($subtotal + $tax, 2);
            $historyDay = 1 + (int) floor(($index * 534) / max(1, $target - 1));
            $date = $context->referenceDate->subDays($historyDay);
            $status = match ($index % 12) {
                0 => Expense::STATUS_DUE,
                1 => Expense::STATUS_SUBMITTED,
                2 => Expense::STATUS_APPROVED,
                3 => Expense::STATUS_DRAFT,
                4 => Expense::STATUS_REIMBURSED,
                default => Expense::STATUS_PAID,
            };
            $isPaid = in_array($status, [Expense::STATUS_PAID, Expense::STATUS_REIMBURSED], true);
            $supplier = $suppliers[$index % $suppliers->count()];
            $member = $members[$index % $members->count()];
            $isRecurring = in_array($template['frequency'], ['monthly', 'biweekly'], true);

            $expense = Expense::query()->create([
                'user_id' => $context->owner->id,
                'created_by_user_id' => $index % 9 === 0 ? $member->user_id : $context->owner->id,
                'approved_by_user_id' => $status !== Expense::STATUS_DRAFT ? $context->owner->id : null,
                'paid_by_user_id' => $isPaid ? $context->owner->id : null,
                'team_member_id' => $index % 9 === 0 ? $member->id : null,
                'title' => (string) $template['name'],
                'category_key' => (string) $template['category'],
                'supplier_name' => in_array($template['category'], ['inventory', 'supplies'], true)
                    ? $supplier['name']
                    : (string) $template['name'],
                'reference_number' => sprintf('NAYA-EXP-%04d', $index + 1),
                'currency_code' => 'CAD',
                'subtotal' => $subtotal,
                'tax_amount' => $tax,
                'total' => $total,
                'expense_date' => $date->toDateString(),
                'due_date' => in_array($status, [Expense::STATUS_DUE, Expense::STATUS_SUBMITTED, Expense::STATUS_APPROVED], true)
                    ? $date->addDays(15)->toDateString()
                    : null,
                'paid_date' => $isPaid ? $date->addDays(2)->toDateString() : null,
                'approved_at' => $status !== Expense::STATUS_DRAFT ? $date->addDay()->utc() : null,
                'payment_method' => $template['payment_method'],
                'status' => $status,
                'reimbursable' => $status === Expense::STATUS_REIMBURSED,
                'reimbursement_status' => $status === Expense::STATUS_REIMBURSED
                    ? Expense::REIMBURSEMENT_STATUS_REIMBURSED
                    : Expense::REIMBURSEMENT_STATUS_NOT_APPLICABLE,
                'reimbursed_at' => $status === Expense::STATUS_REIMBURSED ? $date->addDays(5)->utc() : null,
                'is_recurring' => $isRecurring,
                'recurrence_frequency' => $isRecurring ? Expense::RECURRENCE_FREQUENCY_MONTHLY : null,
                'recurrence_interval' => 1,
                'description' => 'Dépense opérationnelle historique du scénario Studio Naya.',
                'notes' => 'Montant et date déterministes, aucun paiement externe.',
                'meta' => [
                    'scenario_key' => 'studio_naya_coiffure',
                    'template_key' => $template['key'],
                    'supplier_key' => $supplier['key'],
                ],
            ]);
            DB::table('expenses')->where('id', $expense->id)->update([
                'created_at' => $date->utc(),
                'updated_at' => $date->addDays($isPaid ? 2 : 0)->utc(),
            ]);
        }

        return $target;
    }

    /**
     * @param  array<string, mixed>  $blueprint
     * @param  Collection<string, Customer>  $storyCustomers
     */
    private function recordNarrativeTimelines(
        DemoScenarioContext $context,
        array $blueprint,
        Collection $storyCustomers,
    ): void {
        foreach ((array) $blueprint['client_stories'] as $story) {
            $customer = $storyCustomers->get($story['key']);
            if (! $customer) {
                continue;
            }

            foreach ((array) $story['timeline'] as $event) {
                if ($event['event'] === 'customer_created') {
                    continue;
                }
                $plannedFor = $context->referenceDate
                    ->addDays((int) $event['offset_days'])
                    ->setTime(12, 0);
                $occurredAt = $plannedFor->isAfter($context->referenceDate)
                    ? $context->referenceDate->subHours(2)
                    : $plannedFor;
                $entityLink = $this->narrativeEntityLink(
                    $context,
                    $customer,
                    (string) $event['event'],
                    $plannedFor,
                );
                $log = ActivityLog::record(
                    $context->owner,
                    $customer,
                    (string) $event['event'],
                    [
                        ...$event,
                        'scenario_key' => 'studio_naya_coiffure',
                        'story_key' => $story['key'],
                        'planned_for' => $plannedFor->toIso8601String(),
                        ...$entityLink,
                    ],
                    'Étape narrative Studio Naya : '.str_replace('_', ' ', (string) $event['event']).'.',
                );
                DB::table('activity_logs')->where('id', $log->id)->update([
                    'created_at' => $occurredAt->utc(),
                    'updated_at' => $occurredAt->utc(),
                ]);
            }
        }
    }

    /**
     * @return array<string, int|string>
     */
    private function narrativeEntityLink(
        DemoScenarioContext $context,
        Customer $customer,
        string $event,
        CarbonImmutable $plannedFor,
    ): array {
        $reservationEvents = [
            'first_completed_reservation',
            'reservation_rescheduled',
            'next_reservation_confirmed',
            'consultation_reservation',
            'trial_reservation',
            'wedding_reservation',
            'reservation_no_show',
            'new_reservation_pending',
            'recurring_series_started',
            'color_reservation_completed',
            'discounted_correction_completed',
        ];
        $entity = null;

        if (in_array($event, $reservationEvents, true)) {
            $entity = Reservation::query()
                ->forAccount((int) $context->owner->id)
                ->where('client_id', $customer->id)
                ->get()
                ->first(fn (Reservation $reservation): bool => data_get(
                    $reservation->metadata,
                    'narrative_event',
                ) === $event);
        } elseif (in_array($event, ['quote_sent', 'quote_accepted'], true)) {
            $entity = Quote::query()
                ->byUserWithArchived((int) $context->owner->id)
                ->where('customer_id', $customer->id)
                ->where('job_title', 'like', '%mariage%')
                ->oldest('created_at')
                ->first();
        } elseif ($event === 'deposit_paid') {
            $entity = Transaction::query()
                ->where('user_id', $context->owner->id)
                ->where('customer_id', $customer->id)
                ->where('type', 'deposit')
                ->oldest('paid_at')
                ->first();
        } elseif ($event === 'final_invoice_due') {
            $entity = Task::query()
                ->forAccount((int) $context->owner->id)
                ->where('customer_id', $customer->id)
                ->open()
                ->oldest('due_date')
                ->first();
        } elseif (in_array($event, ['invoice_paid', 'no_show_fee_invoiced'], true)) {
            $entity = Invoice::query()
                ->byUser((int) $context->owner->id)
                ->where('customer_id', $customer->id)
                ->oldest('created_at')
                ->first();
        } elseif (in_array($event, ['partial_payment_received', 'payment_with_tip'], true)) {
            $entity = Payment::query()
                ->where('user_id', $context->owner->id)
                ->where('customer_id', $customer->id)
                ->when($event === 'payment_with_tip', fn ($query) => $query->where('tip_amount', '>', 0))
                ->oldest('paid_at')
                ->first();
        } elseif ($event === 'partial_refund_recorded') {
            $entity = Payment::query()
                ->where('user_id', $context->owner->id)
                ->where('customer_id', $customer->id)
                ->where('reference', 'NAYA-CHLOE-REFUND')
                ->first();
        } elseif ($event === 'retail_purchase') {
            $entity = Sale::query()
                ->where('user_id', $context->owner->id)
                ->where('customer_id', $customer->id)
                ->get()
                ->sortBy(fn (Sale $sale): int => (int) round(abs(
                    CarbonImmutable::instance($sale->paid_at ?: $sale->created_at)
                        ->diffInSeconds($plannedFor, false),
                )))
                ->first();
        }

        if (! $entity instanceof Model) {
            return [];
        }

        $type = $entity->getMorphClass();

        return [
            'linked_entity_type' => $type,
            'linked_entity_id' => (int) $entity->getKey(),
            $this->narrativeEntityForeignKey($entity) => (int) $entity->getKey(),
        ];
    }

    private function narrativeEntityForeignKey(Model $entity): string
    {
        return match (true) {
            $entity instanceof Reservation => 'reservation_id',
            $entity instanceof Quote => 'quote_id',
            $entity instanceof Transaction => 'transaction_id',
            $entity instanceof Task => 'task_id',
            $entity instanceof Invoice => 'invoice_id',
            $entity instanceof Payment => 'payment_id',
            $entity instanceof Sale => 'sale_id',
            default => 'entity_id',
        };
    }

    /**
     * @param  Collection<string, Customer>  $storyCustomers
     * @param  Collection<string, Product>  $products
     */
    private function createNotifications(
        DemoScenarioContext $context,
        int $target,
        Collection $storyCustomers,
        Collection $products,
    ): int {
        $owner = $context->owner;
        $owner->notifications()->delete();
        $nadia = $storyCustomers->get('nadia_pierre');
        $samantha = $storyCustomers->get('samantha_joseph');
        $lowStock = $products->first(fn (Product $product): bool => (int) $product->stock <= (int) $product->minimum_stock);
        $overdueInvoiceCount = Invoice::query()
            ->byUser((int) $owner->id)
            ->where('status', 'overdue')
            ->count();
        $pendingReservationCount = Reservation::query()
            ->forAccount((int) $owner->id)
            ->where('status', Reservation::STATUS_PENDING)
            ->count();
        $templates = [
            [
                'type' => 'reservation',
                'severity' => 'warning',
                'title' => 'Dépôt requis avant confirmation',
                'message' => 'Le nouveau rendez-vous de Nadia Pierre attend son dépôt.',
                'action_url' => route('reservation.index', absolute: false),
                'reference' => ['customer_id' => $nadia?->id],
            ],
            [
                'type' => 'invoice',
                'severity' => 'danger',
                'title' => 'Factures en retard',
                'message' => sprintf(
                    '%d %s une relance.',
                    $overdueInvoiceCount,
                    $overdueInvoiceCount === 1
                        ? 'facture historique nécessite'
                        : 'factures historiques nécessitent',
                ),
                'action_url' => route('invoice.index', absolute: false),
            ],
            [
                'type' => 'inventory',
                'severity' => 'warning',
                'title' => 'Stock faible',
                'message' => ($lowStock?->name ?? 'Un produit').' a atteint son seuil de réapprovisionnement.',
                'action_url' => route('product.index', absolute: false),
                'reference' => ['product_id' => $lowStock?->id],
            ],
            [
                'type' => 'quote',
                'severity' => 'info',
                'title' => 'Suivi devis mariage',
                'message' => 'Confirmer les accessoires et l’heure finale avec Samantha Joseph.',
                'action_url' => route('quote.index', absolute: false),
                'reference' => ['customer_id' => $samantha?->id],
            ],
            [
                'type' => 'reservation',
                'severity' => 'info',
                'title' => 'Rendez-vous demain',
                'message' => 'Les rappels du lendemain sont prêts à être vérifiés.',
                'action_url' => route('reservation.index', absolute: false),
            ],
            [
                'type' => 'team',
                'severity' => 'warning',
                'title' => 'Absence planifiée',
                'message' => 'Une absence employé affecte les disponibilités de la semaine.',
                'action_url' => route('team.index', absolute: false),
            ],
            [
                'type' => 'customer',
                'severity' => 'info',
                'title' => 'Suivi qualité à compléter',
                'message' => 'Valider la satisfaction après la correction couleur de Chloé Nguyen.',
                'action_url' => route('customer.index', absolute: false),
            ],
            [
                'type' => 'reservation',
                'severity' => 'warning',
                'title' => 'Rendez-vous en attente',
                'message' => sprintf(
                    '%d %s une confirmation.',
                    $pendingReservationCount,
                    $pendingReservationCount === 1
                        ? 'rendez-vous attend'
                        : 'rendez-vous attendent',
                ),
                'action_url' => route('reservation.index', absolute: false),
            ],
        ];

        for ($index = 0; $index < $target; $index++) {
            $payload = $templates[$index % count($templates)];
            $payload['scenario_key'] = 'studio_naya_coiffure';
            $owner->notify(new DemoActionNotification($payload));
            $notification = $owner->notifications()->latest('created_at')->first();
            if ($notification) {
                $createdAt = $context->referenceDate->subHours($index + 1);
                DB::table('notifications')->where('id', $notification->id)->update([
                    'created_at' => $createdAt->utc(),
                    'updated_at' => $createdAt->utc(),
                    'read_at' => $index % 4 === 0 ? $createdAt->addMinutes(30)->utc() : null,
                ]);
            }
        }

        return $target;
    }
}
