<?php

namespace App\Services\Accounting;

use App\Models\AccountingAccount;
use App\Models\AccountingEntry;
use App\Models\AccountingEntryBatch;
use App\Models\AccountingMapping;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Sale;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AccountingSyncService
{
    private array $accountCache = [];

    private array $batchRevisionCache = [];

    private array $configurationRevisionCache = [];

    private array $mappingCache = [];

    public function __construct(
        private readonly AccountingBootstrapService $bootstrapService,
        private readonly AccountingPeriodService $periodService,
        private readonly AccountingReviewService $reviewService
    ) {}

    /**
     * @return array<string, int>
     */
    public function syncAccount(int $accountId): array
    {
        $this->bootstrapService->ensureForAccount($accountId);
        $this->clearCache($accountId);
        $this->baselineLegacyConfigurationFingerprints($accountId);

        $result = [
            'batches_synced' => 0,
            'entries_written' => 0,
            'review_required_batches' => 0,
            'locked_period_skips' => 0,
            'sources_scanned' => 0,
            'sources_skipped' => 0,
        ];

        Invoice::query()
            ->byUser($accountId)
            ->withMax('items', 'updated_at')
            ->withSum('items', 'total')
            ->get()
            ->each(function (Invoice $invoice) use (&$result, $accountId): void {
                $sourceFingerprint = $this->invoiceFingerprint($invoice);
                $result['sources_scanned']++;
                if (! $this->sourceNeedsSync(
                    $accountId,
                    'invoice',
                    (int) $invoice->id,
                    $this->invoiceShouldGenerate($invoice) ? ['invoice_issued'] : [],
                    $sourceFingerprint,
                    [$invoice->updated_at, $invoice->getAttribute('items_max_updated_at')]
                )) {
                    $result['sources_skipped']++;

                    return;
                }

                $invoice->loadMissing('items:id,invoice_id,total');
                $synced = $this->syncInvoice($accountId, $invoice);
                $result = $this->mergeResult($result, $synced);
            });

        Payment::query()
            ->where('user_id', $accountId)
            ->with('invoice:id,user_id,number,approval_status,status,updated_at')
            ->get()
            ->each(function (Payment $payment) use (&$result, $accountId): void {
                $sourceFingerprint = $this->paymentFingerprint($payment);
                $result['sources_scanned']++;
                if (! $this->sourceNeedsSync(
                    $accountId,
                    'payment',
                    (int) $payment->id,
                    $this->paymentShouldGenerate($payment) ? ['payment_collected'] : [],
                    $sourceFingerprint,
                    [$payment->updated_at, $payment->invoice?->updated_at]
                )) {
                    $result['sources_skipped']++;

                    return;
                }

                $synced = $this->syncPayment($accountId, $payment);
                $result = $this->mergeResult($result, $synced);
            });

        Sale::query()
            ->where('user_id', $accountId)
            ->get()
            ->each(function (Sale $sale) use (&$result, $accountId): void {
                $sourceFingerprint = $this->saleFingerprint($sale);
                $result['sources_scanned']++;
                if (! $this->sourceNeedsSync(
                    $accountId,
                    'sale',
                    (int) $sale->id,
                    $this->saleShouldGenerate($sale) ? ['sale_completed'] : [],
                    $sourceFingerprint,
                    [$sale->updated_at]
                )) {
                    $result['sources_skipped']++;

                    return;
                }

                $synced = $this->syncSale($accountId, $sale);
                $result = $this->mergeResult($result, $synced);
            });

        Expense::query()
            ->byAccount($accountId)
            ->get()
            ->each(function (Expense $expense) use (&$result, $accountId): void {
                $sourceFingerprint = $this->expenseFingerprint($expense);
                $result['sources_scanned']++;
                if (! $this->sourceNeedsSync(
                    $accountId,
                    'expense',
                    (int) $expense->id,
                    $this->expenseEventKeys($expense),
                    $sourceFingerprint,
                    [$expense->updated_at]
                )) {
                    $result['sources_skipped']++;

                    return;
                }

                $synced = $this->syncExpense($accountId, $expense);
                $result = $this->mergeResult($result, $synced);
            });

        return $result;
    }

    /**
     * @return array<string, int>
     */
    private function syncInvoice(int $accountId, Invoice $invoice): array
    {
        $eventKey = 'invoice_issued';
        if (! $this->invoiceShouldGenerate($invoice)) {
            $this->forgetBatch($accountId, 'invoice', (int) $invoice->id, $eventKey);

            return $this->emptyResult();
        }

        $subtotal = $this->resolveInvoiceSubtotal($invoice);
        $taxAmount = max(0, round((float) $invoice->total - $subtotal, 2));

        return $this->persistBatch(
            accountId: $accountId,
            sourceType: 'invoice',
            sourceId: (int) $invoice->id,
            sourceEventKey: $eventKey,
            sourceReference: $invoice->number ?: 'Invoice #'.$invoice->id,
            entryDate: optional($invoice->created_at)->toDateString() ?: now()->toDateString(),
            currencyCode: $invoice->currency_code,
            domain: 'invoices',
            mappingKey: $eventKey,
            description: 'Invoice '.($invoice->number ?: '#'.$invoice->id).' issued',
            totalAmount: (float) $invoice->total,
            baseAmount: $subtotal,
            taxAmount: $taxAmount,
            meta: [
                'source_status' => $invoice->status,
                'approval_status' => $invoice->approval_status,
            ],
            sourceFingerprint: $this->invoiceFingerprint($invoice)
        );
    }

    /**
     * @return array<string, int>
     */
    private function syncPayment(int $accountId, Payment $payment): array
    {
        $eventKey = 'payment_collected';
        if (! $this->paymentShouldGenerate($payment)) {
            $this->forgetBatch($accountId, 'payment', (int) $payment->id, $eventKey);

            return $this->emptyResult();
        }

        $sourceReference = $payment->reference
            ?: ($payment->invoice?->number
                ? 'Payment for '.$payment->invoice->number
                : 'Payment #'.$payment->id);
        $baseAmount = max(0, round((float) $payment->amount, 2));
        $originalTipAmount = max(0, round((float) ($payment->tip_amount ?? 0), 2));
        $tipReversedAmount = min(
            $originalTipAmount,
            max(0, round((float) ($payment->tip_reversed_amount ?? 0), 2))
        );
        $tipAmount = max(0, round($originalTipAmount - $tipReversedAmount, 2));
        $chargedTotal = $this->resolvePaymentChargedTotal($payment, $baseAmount, $tipAmount);
        $tipAccount = $tipAmount > 0 ? $this->accountByKey($accountId, 'tips_payable') : null;
        $suspenseAccount = $this->accountByKey($accountId, 'suspense');
        $chargeVariance = round($chargedTotal - $baseAmount - $tipAmount, 2);
        $creditSplits = $tipAmount > 0 ? [[
            'account_id' => $tipAccount?->id,
            'amount' => $tipAmount,
            'missing_mapping_key' => 'tips_payable_account_id',
            'description' => 'Tips collected',
            'meta' => [
                'account_key' => 'tips_payable',
                'payment_id' => (int) $payment->id,
            ],
        ]] : [];
        $debitSplits = [];

        if ($chargeVariance > 0) {
            $creditSplits[] = $this->paymentVarianceSplit(
                $suspenseAccount?->id,
                $chargeVariance,
                'credit'
            );
        } elseif ($chargeVariance < 0) {
            $debitSplits[] = $this->paymentVarianceSplit(
                $suspenseAccount?->id,
                abs($chargeVariance),
                'debit'
            );
        }

        return $this->persistBatch(
            accountId: $accountId,
            sourceType: 'payment',
            sourceId: (int) $payment->id,
            sourceEventKey: $eventKey,
            sourceReference: $sourceReference,
            entryDate: optional($payment->paid_at)->toDateString()
                ?: optional($payment->created_at)->toDateString()
                ?: now()->toDateString(),
            currencyCode: $payment->currency_code,
            domain: 'payments',
            mappingKey: $eventKey,
            description: 'Payment collected',
            totalAmount: $chargedTotal,
            baseAmount: $baseAmount,
            taxAmount: 0.0,
            meta: [
                'payment_status' => $payment->status,
                'invoice_id' => $payment->invoice_id,
                'sale_id' => $payment->sale_id,
                'invoice_amount' => $baseAmount,
                'tip_amount' => $tipAmount,
                'tip_original_amount' => $originalTipAmount,
                'tip_reversed_amount' => $tipReversedAmount,
                'charged_total' => $chargedTotal,
                'charged_total_variance' => $chargeVariance,
            ],
            creditSplits: $creditSplits,
            debitSplits: $debitSplits,
            sourceFingerprint: $this->paymentFingerprint($payment)
        );
    }

    /**
     * @return array<string, int>
     */
    private function syncSale(int $accountId, Sale $sale): array
    {
        $eventKey = 'sale_completed';
        if (! $this->saleShouldGenerate($sale)) {
            $this->forgetBatch($accountId, 'sale', (int) $sale->id, $eventKey);

            return $this->emptyResult();
        }

        $taxAmount = round((float) ($sale->tax_total ?? 0), 2);
        $baseAmount = max(0, round((float) ($sale->subtotal ?? 0), 2));
        if ($baseAmount <= 0 && (float) $sale->total > 0) {
            $baseAmount = max(0, round((float) $sale->total - $taxAmount, 2));
        }

        return $this->persistBatch(
            accountId: $accountId,
            sourceType: 'sale',
            sourceId: (int) $sale->id,
            sourceEventKey: $eventKey,
            sourceReference: $sale->number ?: 'Sale #'.$sale->id,
            entryDate: optional($sale->paid_at)->toDateString()
                ?: optional($sale->created_at)->toDateString()
                ?: now()->toDateString(),
            currencyCode: $sale->currency_code,
            domain: 'sales',
            mappingKey: $eventKey,
            description: 'Sale '.($sale->number ?: '#'.$sale->id).' completed',
            totalAmount: (float) $sale->total,
            baseAmount: $baseAmount,
            taxAmount: $taxAmount,
            meta: [
                'sale_status' => $sale->status,
                'payment_status' => $sale->payment_status,
            ],
            sourceFingerprint: $this->saleFingerprint($sale)
        );
    }

    /**
     * @return array<string, int>
     */
    private function syncExpense(int $accountId, Expense $expense): array
    {
        $result = $this->emptyResult();
        $isPaidLike = in_array($expense->status, [Expense::STATUS_PAID, Expense::STATUS_REIMBURSED], true);
        $isReimbursed = $expense->reimbursable
            && (
                $expense->reimbursement_status === Expense::REIMBURSEMENT_STATUS_REIMBURSED
                || $expense->status === Expense::STATUS_REIMBURSED
            );

        if ($expense->reimbursable) {
            if ($isPaidLike) {
                $eventKey = 'reimbursable_expense_paid';
                $synced = $this->persistBatch(
                    accountId: $accountId,
                    sourceType: 'expense',
                    sourceId: (int) $expense->id,
                    sourceEventKey: $eventKey,
                    sourceReference: $expense->reference_number ?: $expense->title,
                    entryDate: optional($expense->paid_date)->toDateString()
                        ?: optional($expense->expense_date)->toDateString()
                        ?: now()->toDateString(),
                    currencyCode: $expense->currency_code,
                    domain: 'expenses',
                    mappingKey: $eventKey,
                    description: 'Reimbursable expense '.($expense->reference_number ?: '#'.$expense->id),
                    totalAmount: (float) $expense->total,
                    baseAmount: (float) $expense->total,
                    taxAmount: (float) ($expense->tax_amount ?? 0),
                    meta: [
                        'expense_status' => $expense->status,
                        'reimbursement_status' => $expense->reimbursement_status,
                        'category_key' => $expense->category_key,
                    ],
                    sourceFingerprint: $this->expenseFingerprint($expense)
                );
                $result = $this->mergeResult($result, $synced);
            } else {
                $this->forgetBatch($accountId, 'expense', (int) $expense->id, 'reimbursable_expense_paid');
            }

            if ($isReimbursed) {
                $synced = $this->persistBatch(
                    accountId: $accountId,
                    sourceType: 'expense',
                    sourceId: (int) $expense->id,
                    sourceEventKey: 'reimbursable_expense_reimbursed',
                    sourceReference: $expense->reference_number ?: $expense->title,
                    entryDate: optional($expense->reimbursed_at)->toDateString()
                        ?: optional($expense->paid_date)->toDateString()
                        ?: now()->toDateString(),
                    currencyCode: $expense->currency_code,
                    domain: 'expenses',
                    mappingKey: 'reimbursable_expense_reimbursed',
                    description: 'Expense reimbursement settled',
                    totalAmount: (float) $expense->total,
                    baseAmount: (float) $expense->total,
                    taxAmount: 0.0,
                    meta: [
                        'expense_status' => $expense->status,
                        'reimbursement_status' => $expense->reimbursement_status,
                        'category_key' => $expense->category_key,
                    ],
                    sourceFingerprint: $this->expenseFingerprint($expense)
                );
                $result = $this->mergeResult($result, $synced);
            } else {
                $this->forgetBatch($accountId, 'expense', (int) $expense->id, 'reimbursable_expense_reimbursed');
            }

            $this->forgetBatch($accountId, 'expense', (int) $expense->id, 'expense_paid');

            return $result;
        }

        if (! $isPaidLike) {
            $this->forgetBatch($accountId, 'expense', (int) $expense->id, 'expense_paid');

            return $result;
        }

        $synced = $this->persistBatch(
            accountId: $accountId,
            sourceType: 'expense',
            sourceId: (int) $expense->id,
            sourceEventKey: 'expense_paid',
            sourceReference: $expense->reference_number ?: $expense->title,
            entryDate: optional($expense->paid_date)->toDateString()
                ?: optional($expense->expense_date)->toDateString()
                ?: now()->toDateString(),
            currencyCode: $expense->currency_code,
            domain: 'expenses',
            mappingKey: 'expense_paid',
            description: 'Expense '.($expense->reference_number ?: '#'.$expense->id).' paid',
            totalAmount: (float) $expense->total,
            baseAmount: (float) $expense->total,
            taxAmount: (float) ($expense->tax_amount ?? 0),
            meta: [
                'expense_status' => $expense->status,
                'reimbursement_status' => $expense->reimbursement_status,
                'category_key' => $expense->category_key,
            ],
            sourceFingerprint: $this->expenseFingerprint($expense)
        );

        return $this->mergeResult($result, $synced);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<int, array{account_id?: int|null, amount?: float|int|string, missing_mapping_key?: string, review_key?: string, description?: string, meta?: array<string, mixed>}>  $creditSplits
     * @param  array<int, array{account_id?: int|null, amount?: float|int|string, missing_mapping_key?: string, review_key?: string, description?: string, meta?: array<string, mixed>}>  $debitSplits
     * @return array<string, int>
     */
    private function persistBatch(
        int $accountId,
        string $sourceType,
        int $sourceId,
        string $sourceEventKey,
        string $sourceReference,
        string $entryDate,
        ?string $currencyCode,
        string $domain,
        string $mappingKey,
        string $description,
        float $totalAmount,
        float $baseAmount,
        float $taxAmount,
        array $meta = [],
        array $creditSplits = [],
        array $debitSplits = [],
        ?string $sourceFingerprint = null
    ): array {
        $existingBatch = AccountingEntryBatch::query()
            ->forUser($accountId)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('source_event_key', $sourceEventKey)
            ->first();
        $existingEntryStates = $this->existingEntryStates($existingBatch);
        $existingBatchMeta = $existingBatch?->meta ?? [];

        if ($this->periodLocked($accountId, $entryDate, $existingBatch)) {
            return [
                'batches_synced' => 0,
                'entries_written' => 0,
                'review_required_batches' => 0,
                'locked_period_skips' => 1,
            ];
        }

        $mapping = $this->mapping($accountId, $domain, $mappingKey);
        $linePayload = $this->buildLinePayload(
            accountId: $accountId,
            mapping: $mapping,
            domain: $domain,
            mappingKey: $mappingKey,
            currencyCode: $currencyCode ?: 'CAD',
            entryDate: $entryDate,
            description: $description,
            totalAmount: round($totalAmount, 2),
            baseAmount: round($baseAmount, 2),
            taxAmount: round($taxAmount, 2),
            existingEntryStates: $existingEntryStates,
            creditSplits: $creditSplits,
            debitSplits: $debitSplits
        );

        $batch = AccountingEntryBatch::query()->updateOrCreate(
            [
                'user_id' => $accountId,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'source_event_key' => $sourceEventKey,
            ],
            [
                'source_reference' => $sourceReference,
                'entry_date' => $entryDate,
                'generated_at' => now(),
                'status' => $linePayload['status'],
                'meta' => array_merge($existingBatchMeta, $meta, [
                    'domain' => $domain,
                    'mapping_key' => $mappingKey,
                    'missing_mapping_keys' => $linePayload['missing_mapping_keys'],
                    'source_url' => $this->sourceUrl($sourceType, $sourceId),
                    'source_fingerprint' => $sourceFingerprint,
                    'configuration_fingerprint' => $this->configurationRevision(
                        $accountId,
                        $domain,
                        $mappingKey
                    )['fingerprint'],
                ]),
            ]
        );
        $batch->update([
            'meta' => $this->reviewService->batchMetaForStatus(
                null,
                $batch->meta ?? [],
                data_get($existingBatchMeta, 'review_status', AccountingEntry::REVIEW_STATUS_UNREVIEWED)
            ),
        ]);

        $batch->entries()->delete();

        foreach ($linePayload['entries'] as $entry) {
            $batch->entries()->create(array_merge($entry, [
                'user_id' => $accountId,
            ]));
        }

        return [
            'batches_synced' => 1,
            'entries_written' => count($linePayload['entries']),
            'review_required_batches' => $linePayload['status'] === AccountingEntryBatch::STATUS_REVIEW_REQUIRED ? 1 : 0,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $mapping
     * @param  array<string, array<string, mixed>>  $existingEntryStates
     * @param  array<int, array{account_id?: int|null, amount?: float|int|string, missing_mapping_key?: string, review_key?: string, description?: string, meta?: array<string, mixed>}>  $creditSplits
     * @param  array<int, array{account_id?: int|null, amount?: float|int|string, missing_mapping_key?: string, review_key?: string, description?: string, meta?: array<string, mixed>}>  $debitSplits
     * @return array{entries: array<int, array<string, mixed>>, status: string, missing_mapping_keys: array<int, string>}
     */
    private function buildLinePayload(
        int $accountId,
        ?array $mapping,
        string $domain,
        string $mappingKey,
        string $currencyCode,
        string $entryDate,
        string $description,
        float $totalAmount,
        float $baseAmount,
        float $taxAmount,
        array $existingEntryStates = [],
        array $creditSplits = [],
        array $debitSplits = []
    ): array {
        $accountsById = $this->accountsById($accountId);
        $suspenseAccount = $this->accountByKey($accountId, 'suspense');
        $missingMappingKeys = [];

        $debitAccountId = $mapping['debit_account_id'] ?? null;
        $creditAccountId = $mapping['credit_account_id'] ?? null;
        $taxAccountId = $mapping['tax_account_id'] ?? null;

        if (! $debitAccountId) {
            $missingMappingKeys[] = 'debit_account_id';
            $debitAccountId = $suspenseAccount?->id;
        }

        if (! $creditAccountId) {
            $missingMappingKeys[] = 'credit_account_id';
            $creditAccountId = $suspenseAccount?->id;
        }

        $entries = [];
        $normalizedDebitSplits = [];
        $normalizedCreditSplits = [];

        foreach ([
            AccountingEntry::DIRECTION_DEBIT => $debitSplits,
            AccountingEntry::DIRECTION_CREDIT => $creditSplits,
        ] as $direction => $splits) {
            foreach ($splits as $split) {
                $amount = max(0, round((float) ($split['amount'] ?? 0), 2));
                if ($amount <= 0) {
                    continue;
                }

                $accountId = $split['account_id'] ?? null;
                if (! $accountId) {
                    $missingMappingKeys[] = (string) ($split['missing_mapping_key'] ?? 'split_account_id');
                    $accountId = $suspenseAccount?->id;
                }
                if (! empty($split['review_key'])) {
                    $missingMappingKeys[] = (string) $split['review_key'];
                }

                $normalized = [
                    'account_id' => $accountId,
                    'amount' => $amount,
                    'description' => (string) ($split['description'] ?? $description),
                    'meta' => is_array($split['meta'] ?? null) ? $split['meta'] : [],
                ];
                if ($direction === AccountingEntry::DIRECTION_DEBIT) {
                    $normalizedDebitSplits[] = $normalized;
                } else {
                    $normalizedCreditSplits[] = $normalized;
                }
            }
        }

        $splitTaxEntry = in_array($domain, ['invoices', 'sales'], true) && $taxAmount > 0;
        $entries[] = [
            'account_id' => $debitAccountId,
            'direction' => AccountingEntry::DIRECTION_DEBIT,
            'amount' => round($totalAmount, 2),
            'tax_amount' => $domain === 'expenses' ? round($taxAmount, 2) : 0,
            'currency_code' => $currencyCode,
            'entry_date' => $entryDate,
            'description' => $description,
            'review_status' => AccountingEntry::REVIEW_STATUS_UNREVIEWED,
            'reconciliation_status' => AccountingEntry::REVIEW_STATUS_UNREVIEWED,
            'meta' => [
                'domain' => $domain,
                'mapping_key' => $mappingKey,
                'account_key' => $accountsById[$debitAccountId]?->key,
            ],
        ];

        foreach ($normalizedDebitSplits as $debitSplit) {
            $accountId = $debitSplit['account_id'];
            $entries[] = [
                'account_id' => $accountId,
                'direction' => AccountingEntry::DIRECTION_DEBIT,
                'amount' => $debitSplit['amount'],
                'tax_amount' => 0,
                'currency_code' => $currencyCode,
                'entry_date' => $entryDate,
                'description' => $debitSplit['description'],
                'review_status' => AccountingEntry::REVIEW_STATUS_UNREVIEWED,
                'reconciliation_status' => AccountingEntry::REVIEW_STATUS_UNREVIEWED,
                'meta' => array_merge([
                    'domain' => $domain,
                    'mapping_key' => $mappingKey,
                    'account_key' => $accountsById[$accountId]?->key,
                ], $debitSplit['meta']),
            ];
        }

        $revenueOrPrimaryCredit = $normalizedCreditSplits !== [] || $normalizedDebitSplits !== []
            ? max(0, round($baseAmount, 2))
            : ($splitTaxEntry
                ? max(0, round($baseAmount, 2))
                : round($totalAmount, 2));

        if ($revenueOrPrimaryCredit > 0) {
            $entries[] = [
                'account_id' => $creditAccountId,
                'direction' => AccountingEntry::DIRECTION_CREDIT,
                'amount' => $revenueOrPrimaryCredit,
                'tax_amount' => 0,
                'currency_code' => $currencyCode,
                'entry_date' => $entryDate,
                'description' => $description,
                'review_status' => AccountingEntry::REVIEW_STATUS_UNREVIEWED,
                'reconciliation_status' => AccountingEntry::REVIEW_STATUS_UNREVIEWED,
                'meta' => [
                    'domain' => $domain,
                    'mapping_key' => $mappingKey,
                    'account_key' => $accountsById[$creditAccountId]?->key,
                ],
            ];
        }

        if ($splitTaxEntry) {
            if (! $taxAccountId) {
                $missingMappingKeys[] = 'tax_account_id';
                $taxAccountId = $suspenseAccount?->id;
            }

            $entries[] = [
                'account_id' => $taxAccountId,
                'direction' => AccountingEntry::DIRECTION_CREDIT,
                'amount' => round($taxAmount, 2),
                'tax_amount' => round($taxAmount, 2),
                'currency_code' => $currencyCode,
                'entry_date' => $entryDate,
                'description' => $description,
                'review_status' => AccountingEntry::REVIEW_STATUS_UNREVIEWED,
                'reconciliation_status' => AccountingEntry::REVIEW_STATUS_UNREVIEWED,
                'meta' => [
                    'domain' => $domain,
                    'mapping_key' => $mappingKey,
                    'account_key' => $accountsById[$taxAccountId]?->key,
                ],
            ];
        }

        foreach ($normalizedCreditSplits as $creditSplit) {
            $accountId = $creditSplit['account_id'];
            $entries[] = [
                'account_id' => $accountId,
                'direction' => AccountingEntry::DIRECTION_CREDIT,
                'amount' => $creditSplit['amount'],
                'tax_amount' => 0,
                'currency_code' => $currencyCode,
                'entry_date' => $entryDate,
                'description' => $creditSplit['description'],
                'review_status' => AccountingEntry::REVIEW_STATUS_UNREVIEWED,
                'reconciliation_status' => AccountingEntry::REVIEW_STATUS_UNREVIEWED,
                'meta' => array_merge([
                    'domain' => $domain,
                    'mapping_key' => $mappingKey,
                    'account_key' => $accountsById[$accountId]?->key,
                ], $creditSplit['meta']),
            ];
        }

        $entries = array_map(function (array $entry) use ($existingEntryStates): array {
            $signature = $this->entrySignature($entry);
            $existingState = $existingEntryStates[$signature] ?? null;
            if (! is_array($existingState)) {
                return $entry;
            }

            $entry['review_status'] = $existingState['review_status'] ?? $entry['review_status'];
            $entry['reconciliation_status'] = $existingState['reconciliation_status'] ?? $entry['reconciliation_status'];
            $entry['meta'] = array_merge($entry['meta'] ?? [], $existingState['meta'] ?? []);

            return $entry;
        }, $entries);

        $status = empty($missingMappingKeys)
            ? AccountingEntryBatch::STATUS_GENERATED
            : AccountingEntryBatch::STATUS_REVIEW_REQUIRED;

        return [
            'entries' => $entries,
            'status' => $status,
            'missing_mapping_keys' => array_values(array_unique($missingMappingKeys)),
        ];
    }

    private function invoiceShouldGenerate(Invoice $invoice): bool
    {
        return in_array(
            $invoice->approval_status,
            ['approved', 'processed'],
            true
        ) && ! in_array($invoice->status, ['draft', 'void'], true);
    }

    private function paymentShouldGenerate(Payment $payment): bool
    {
        return $payment->invoice_id !== null
            && $payment->invoice !== null
            && in_array(
                (string) $payment->invoice->approval_status,
                ['approved', 'processed'],
                true
            )
            && in_array($payment->status, Payment::settledStatuses(), true);
    }

    private function resolvePaymentChargedTotal(Payment $payment, float $baseAmount, float $tipAmount): float
    {
        $expectedTotal = round($baseAmount + $tipAmount, 2);
        $recordedTotal = $payment->charged_total;

        if ($recordedTotal === null) {
            return $expectedTotal;
        }

        $reversedTipAmount = min(
            max(0, round((float) ($payment->tip_amount ?? 0), 2)),
            max(0, round((float) ($payment->tip_reversed_amount ?? 0), 2))
        );

        return max(0, round((float) $recordedTotal - $reversedTipAmount, 2));
    }

    /**
     * @return array{account_id: int|null, amount: float, missing_mapping_key: string, review_key: string, description: string, meta: array<string, mixed>}
     */
    private function paymentVarianceSplit(?int $suspenseAccountId, float $amount, string $direction): array
    {
        return [
            'account_id' => $suspenseAccountId,
            'amount' => $amount,
            'missing_mapping_key' => 'suspense_account_id',
            'review_key' => 'charged_total_variance',
            'description' => 'Payment charged-total variance',
            'meta' => [
                'account_key' => 'suspense',
                'reason' => 'charged_total_variance',
                'direction' => $direction,
            ],
        ];
    }

    private function saleShouldGenerate(Sale $sale): bool
    {
        return $sale->status === Sale::STATUS_PAID || $sale->paid_at !== null;
    }

    /**
     * @param  array<int, string>  $expectedEventKeys
     * @param  array<int, mixed>  $sourceTimestamps
     */
    private function sourceNeedsSync(
        int $accountId,
        string $sourceType,
        int $sourceId,
        array $expectedEventKeys,
        string $sourceFingerprint,
        array $sourceTimestamps
    ): bool {
        $sourceKey = $this->batchRevisionSourceKey($sourceType, $sourceId);
        $existingRevisions = $this->batchRevisions($accountId)[$sourceKey] ?? [];
        $existingEventKeys = array_keys($existingRevisions);
        sort($existingEventKeys);
        sort($expectedEventKeys);

        if ($existingEventKeys !== $expectedEventKeys) {
            return true;
        }

        if ($expectedEventKeys === []) {
            return false;
        }

        foreach ($expectedEventKeys as $eventKey) {
            $existingRevision = $existingRevisions[$eventKey];
            $configurationRevision = $this->configurationRevisionForEvent($accountId, $eventKey);
            $storedSourceFingerprint = $existingRevision['source_fingerprint'];
            $storedConfigurationFingerprint = $existingRevision['configuration_fingerprint'];

            if (is_string($storedSourceFingerprint) && $storedSourceFingerprint !== $sourceFingerprint) {
                return true;
            }

            if (is_string($storedConfigurationFingerprint)
                && $storedConfigurationFingerprint !== $configurationRevision['fingerprint']) {
                return true;
            }

            if (! is_string($storedSourceFingerprint)) {
                $generatedAt = $existingRevision['generated_at'];
                $changedAt = $this->latestTimestamp($sourceTimestamps);

                if (! $generatedAt || ($changedAt && $changedAt->gt($generatedAt))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function expenseEventKeys(Expense $expense): array
    {
        $isPaidLike = in_array($expense->status, [Expense::STATUS_PAID, Expense::STATUS_REIMBURSED], true);
        $isReimbursed = $expense->reimbursable
            && (
                $expense->reimbursement_status === Expense::REIMBURSEMENT_STATUS_REIMBURSED
                || $expense->status === Expense::STATUS_REIMBURSED
            );

        if ($expense->reimbursable) {
            return array_values(array_filter([
                $isPaidLike ? 'reimbursable_expense_paid' : null,
                $isReimbursed ? 'reimbursable_expense_reimbursed' : null,
            ]));
        }

        return $isPaidLike ? ['expense_paid'] : [];
    }

    private function invoiceFingerprint(Invoice $invoice): string
    {
        $itemsTotal = $invoice->getAttribute('items_sum_total');
        if ($itemsTotal === null && $invoice->relationLoaded('items')) {
            $itemsTotal = $invoice->items->sum('total');
        }

        return $this->fingerprint([
            'approval_status' => $invoice->approval_status,
            'created_at' => optional($invoice->created_at)->toIso8601String(),
            'currency_code' => $invoice->currency_code,
            'items_total' => $itemsTotal === null ? null : round((float) $itemsTotal, 2),
            'number' => $invoice->number,
            'status' => $invoice->status,
            'subtotal' => $invoice->subtotal === null ? null : round((float) $invoice->subtotal, 2),
            'total' => round((float) $invoice->total, 2),
        ]);
    }

    private function paymentFingerprint(Payment $payment): string
    {
        return $this->fingerprint([
            'amount' => round((float) $payment->amount, 2),
            'charged_total' => $payment->charged_total === null ? null : round((float) $payment->charged_total, 2),
            'created_at' => optional($payment->created_at)->toIso8601String(),
            'currency_code' => $payment->currency_code,
            'invoice_approval_status' => $payment->invoice?->approval_status,
            'invoice_id' => $payment->invoice_id,
            'invoice_number' => $payment->invoice?->number,
            'invoice_status' => $payment->invoice?->status,
            'paid_at' => optional($payment->paid_at)->toIso8601String(),
            'reference' => $payment->reference,
            'sale_id' => $payment->sale_id,
            'status' => $payment->status,
            'tip_amount' => round((float) ($payment->tip_amount ?? 0), 2),
            'tip_reversed_amount' => round((float) ($payment->tip_reversed_amount ?? 0), 2),
        ]);
    }

    private function saleFingerprint(Sale $sale): string
    {
        return $this->fingerprint([
            'created_at' => optional($sale->created_at)->toIso8601String(),
            'currency_code' => $sale->currency_code,
            'number' => $sale->number,
            'paid_at' => optional($sale->paid_at)->toIso8601String(),
            'payment_status' => $sale->payment_status,
            'status' => $sale->status,
            'subtotal' => round((float) ($sale->subtotal ?? 0), 2),
            'tax_total' => round((float) ($sale->tax_total ?? 0), 2),
            'total' => round((float) $sale->total, 2),
        ]);
    }

    private function expenseFingerprint(Expense $expense): string
    {
        return $this->fingerprint([
            'category_key' => $expense->category_key,
            'currency_code' => $expense->currency_code,
            'expense_date' => optional($expense->expense_date)->toDateString(),
            'paid_date' => optional($expense->paid_date)->toDateString(),
            'reimbursable' => (bool) $expense->reimbursable,
            'reimbursed_at' => optional($expense->reimbursed_at)->toIso8601String(),
            'reimbursement_status' => $expense->reimbursement_status,
            'reference_number' => $expense->reference_number,
            'status' => $expense->status,
            'tax_amount' => round((float) ($expense->tax_amount ?? 0), 2),
            'title' => $expense->title,
            'total' => round((float) $expense->total, 2),
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function fingerprint(array $values): string
    {
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * @return array<string, array<string, array{generated_at: Carbon|null, source_fingerprint: mixed, configuration_fingerprint: mixed}>>
     */
    private function batchRevisions(int $accountId): array
    {
        if (! array_key_exists($accountId, $this->batchRevisionCache)) {
            $this->batchRevisionCache[$accountId] = AccountingEntryBatch::query()
                ->forUser($accountId)
                ->get([
                    'source_type',
                    'source_id',
                    'source_event_key',
                    'generated_at',
                    'meta',
                ])
                ->groupBy(fn (AccountingEntryBatch $batch) => $this->batchRevisionSourceKey(
                    $batch->source_type,
                    (int) $batch->source_id
                ))
                ->map(fn (Collection $batches) => $batches->mapWithKeys(fn (AccountingEntryBatch $batch) => [
                    $batch->source_event_key => [
                        'generated_at' => $batch->generated_at,
                        'source_fingerprint' => data_get($batch->meta, 'source_fingerprint'),
                        'configuration_fingerprint' => data_get($batch->meta, 'configuration_fingerprint'),
                    ],
                ])->all())
                ->all();
        }

        return $this->batchRevisionCache[$accountId];
    }

    private function batchRevisionSourceKey(string $sourceType, int $sourceId): string
    {
        return $sourceType.':'.$sourceId;
    }

    private function baselineLegacyConfigurationFingerprints(int $accountId): void
    {
        $hasLegacyBatches = AccountingEntryBatch::query()
            ->forUser($accountId)
            ->whereNull('meta->configuration_fingerprint')
            ->exists();

        if (! $hasLegacyBatches) {
            return;
        }

        foreach ([
            'invoice_issued',
            'payment_collected',
            'sale_completed',
            'expense_paid',
            'reimbursable_expense_paid',
            'reimbursable_expense_reimbursed',
        ] as $eventKey) {
            $configurationFingerprint = $this->configurationRevisionForEvent($accountId, $eventKey)['fingerprint'];

            AccountingEntryBatch::query()
                ->forUser($accountId)
                ->where('source_event_key', $eventKey)
                ->whereNull('meta->configuration_fingerprint')
                ->update([
                    'meta->configuration_fingerprint' => $configurationFingerprint,
                ]);
        }

        unset($this->batchRevisionCache[$accountId]);
    }

    /**
     * @return array{fingerprint: string, updated_at: Carbon|null}
     */
    private function configurationRevisionForEvent(int $accountId, string $eventKey): array
    {
        [$domain, $mappingKey] = match ($eventKey) {
            'invoice_issued' => ['invoices', 'invoice_issued'],
            'payment_collected' => ['payments', 'payment_collected'],
            'sale_completed' => ['sales', 'sale_completed'],
            'expense_paid' => ['expenses', 'expense_paid'],
            'reimbursable_expense_paid' => ['expenses', 'reimbursable_expense_paid'],
            'reimbursable_expense_reimbursed' => ['expenses', 'reimbursable_expense_reimbursed'],
        };

        return $this->configurationRevision($accountId, $domain, $mappingKey);
    }

    /**
     * @return array{fingerprint: string, updated_at: Carbon|null}
     */
    private function configurationRevision(int $accountId, string $domain, string $mappingKey): array
    {
        $cacheKey = $domain.'.'.$mappingKey;
        if (! array_key_exists($accountId, $this->configurationRevisionCache)) {
            $accounts = AccountingAccount::query()
                ->forUser($accountId)
                ->orderBy('id')
                ->get(['id', 'key', 'is_active', 'updated_at']);
            $mappings = AccountingMapping::query()
                ->forUser($accountId)
                ->orderBy('id')
                ->get([
                    'source_domain',
                    'source_key',
                    'debit_account_id',
                    'credit_account_id',
                    'tax_account_id',
                    'is_active',
                    'updated_at',
                ]);

            $this->configurationRevisionCache[$accountId] = [
                'accounts' => $accounts,
                'mappings' => $mappings->keyBy(fn (AccountingMapping $mapping) => $mapping->source_domain.'.'.$mapping->source_key),
                'revisions' => [],
            ];
        }

        if (! array_key_exists($cacheKey, $this->configurationRevisionCache[$accountId]['revisions'])) {
            /** @var Collection<int, AccountingAccount> $accounts */
            $accounts = $this->configurationRevisionCache[$accountId]['accounts'];
            /** @var AccountingMapping|null $mapping */
            $mapping = $this->configurationRevisionCache[$accountId]['mappings']->get($cacheKey);
            $accountIds = collect([
                $mapping?->debit_account_id,
                $mapping?->credit_account_id,
                $mapping?->tax_account_id,
            ])->filter()->map(fn ($id) => (int) $id);
            $relevantAccounts = $accounts
                ->filter(fn (AccountingAccount $account) => $accountIds->contains((int) $account->id)
                    || in_array($account->key, ['suspense', 'tips_payable'], true))
                ->values();

            $this->configurationRevisionCache[$accountId]['revisions'][$cacheKey] = [
                'fingerprint' => $this->fingerprint([
                    'accounts' => $relevantAccounts->map(fn (AccountingAccount $account) => [
                        'id' => (int) $account->id,
                        'is_active' => (bool) $account->is_active,
                        'key' => $account->key,
                    ])->all(),
                    'mapping' => $mapping ? [
                        'credit_account_id' => $mapping->credit_account_id,
                        'debit_account_id' => $mapping->debit_account_id,
                        'is_active' => (bool) $mapping->is_active,
                        'tax_account_id' => $mapping->tax_account_id,
                    ] : null,
                ]),
                'updated_at' => $this->latestTimestamp([
                    $mapping?->updated_at,
                    ...$relevantAccounts->pluck('updated_at')->all(),
                ]),
            ];
        }

        return $this->configurationRevisionCache[$accountId]['revisions'][$cacheKey];
    }

    /**
     * @param  array<int, mixed>  $timestamps
     */
    private function latestTimestamp(array $timestamps): ?Carbon
    {
        return collect($timestamps)
            ->filter()
            ->map(fn ($timestamp) => $timestamp instanceof Carbon ? $timestamp : Carbon::parse($timestamp))
            ->sortDesc()
            ->first();
    }

    private function resolveInvoiceSubtotal(Invoice $invoice): float
    {
        if ($invoice->subtotal !== null) {
            return min(
                max(0, round((float) $invoice->subtotal, 2)),
                max(0, round((float) $invoice->total, 2))
            );
        }

        $subtotal = round((float) $invoice->items->sum('total'), 2);

        if ($subtotal <= 0) {
            return round((float) $invoice->total, 2);
        }

        return min($subtotal, round((float) $invoice->total, 2));
    }

    private function forgetBatch(int $accountId, string $sourceType, int $sourceId, string $eventKey): void
    {
        AccountingEntryBatch::query()
            ->forUser($accountId)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('source_event_key', $eventKey)
            ->get()
            ->each(function (AccountingEntryBatch $batch) use ($accountId): void {
                $entryDate = optional($batch->entry_date)->toDateString();
                if ($entryDate && $this->periodService->isClosedForDate($accountId, $entryDate)) {
                    return;
                }

                $batch->delete();
            });
    }

    private function clearCache(int $accountId): void
    {
        unset(
            $this->accountCache[$accountId],
            $this->batchRevisionCache[$accountId],
            $this->configurationRevisionCache[$accountId],
            $this->mappingCache[$accountId]
        );
    }

    private function accountByKey(int $accountId, string $key): ?AccountingAccount
    {
        return $this->accountsByKey($accountId)->get($key);
    }

    /**
     * @return \Illuminate\Support\Collection<string, \App\Models\AccountingAccount>
     */
    private function accountsByKey(int $accountId): Collection
    {
        if (! array_key_exists($accountId, $this->accountCache)) {
            $accounts = AccountingAccount::query()
                ->forUser($accountId)
                ->get();

            $this->accountCache[$accountId] = [
                'by_key' => $accounts->keyBy('key'),
                'by_id' => $accounts->keyBy('id')->all(),
            ];
        }

        return $this->accountCache[$accountId]['by_key'];
    }

    /**
     * @return array<int, \App\Models\AccountingAccount>
     */
    private function accountsById(int $accountId): array
    {
        $this->accountsByKey($accountId);

        return $this->accountCache[$accountId]['by_id'];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mapping(int $accountId, string $domain, string $key): ?array
    {
        if (! array_key_exists($accountId, $this->mappingCache)) {
            $this->mappingCache[$accountId] = AccountingMapping::query()
                ->forUser($accountId)
                ->active()
                ->get()
                ->mapWithKeys(fn (AccountingMapping $mapping) => [
                    $mapping->source_domain.'.'.$mapping->source_key => [
                        'debit_account_id' => $mapping->debit_account_id,
                        'credit_account_id' => $mapping->credit_account_id,
                        'tax_account_id' => $mapping->tax_account_id,
                        'meta' => $mapping->meta,
                    ],
                ])
                ->all();
        }

        return $this->mappingCache[$accountId][$domain.'.'.$key] ?? null;
    }

    /**
     * @return array<string, int>
     */
    private function emptyResult(): array
    {
        return [
            'batches_synced' => 0,
            'entries_written' => 0,
            'review_required_batches' => 0,
            'locked_period_skips' => 0,
            'sources_scanned' => 0,
            'sources_skipped' => 0,
        ];
    }

    /**
     * @param  array<string, int>  $base
     * @param  array<string, int>  $delta
     * @return array<string, int>
     */
    private function mergeResult(array $base, array $delta): array
    {
        foreach ($delta as $key => $value) {
            $base[$key] = ($base[$key] ?? 0) + $value;
        }

        return $base;
    }

    private function sourceUrl(string $sourceType, int $sourceId): ?string
    {
        return match ($sourceType) {
            'expense' => route('expense.show', $sourceId),
            'invoice' => route('invoice.show', $sourceId),
            'sale' => route('sales.show', $sourceId),
            'payment' => null,
            default => null,
        };
    }

    private function periodLocked(int $accountId, string $entryDate, ?AccountingEntryBatch $existingBatch = null): bool
    {
        if ($existingBatch?->entry_date) {
            return $this->periodService->isClosedForDate(
                $accountId,
                $existingBatch->entry_date->toDateString()
            );
        }

        return $this->periodService->isClosedForDate($accountId, $entryDate);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function existingEntryStates(?AccountingEntryBatch $batch): array
    {
        if (! $batch) {
            return [];
        }

        return $batch->entries()
            ->get()
            ->mapWithKeys(fn (AccountingEntry $entry) => [
                $this->entrySignature([
                    'account_id' => $entry->account_id,
                    'direction' => $entry->direction,
                    'amount' => round((float) $entry->amount, 2),
                    'tax_amount' => round((float) $entry->tax_amount, 2),
                    'description' => $entry->description,
                ]) => [
                    'review_status' => $entry->review_status,
                    'reconciliation_status' => $entry->reconciliation_status,
                    'meta' => collect($entry->meta ?? [])
                        ->only(['reviewed_at', 'reviewed_by', 'reconciled_at', 'reconciled_by'])
                        ->all(),
                ],
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function entrySignature(array $entry): string
    {
        return implode('|', [
            (string) ($entry['account_id'] ?? 0),
            (string) ($entry['direction'] ?? ''),
            number_format((float) ($entry['amount'] ?? 0), 2, '.', ''),
            number_format((float) ($entry['tax_amount'] ?? 0), 2, '.', ''),
            (string) ($entry['description'] ?? ''),
        ]);
    }
}
