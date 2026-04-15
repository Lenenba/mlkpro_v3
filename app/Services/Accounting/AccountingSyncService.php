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
use Illuminate\Support\Collection;

class AccountingSyncService
{
    private array $accountCache = [];

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

        $result = [
            'batches_synced' => 0,
            'entries_written' => 0,
            'review_required_batches' => 0,
            'locked_period_skips' => 0,
        ];

        Invoice::query()
            ->byUser($accountId)
            ->with('items:id,invoice_id,total')
            ->get()
            ->each(function (Invoice $invoice) use (&$result, $accountId): void {
                $synced = $this->syncInvoice($accountId, $invoice);
                $result['batches_synced'] += $synced['batches_synced'];
                $result['entries_written'] += $synced['entries_written'];
                $result['review_required_batches'] += $synced['review_required_batches'];
            });

        Payment::query()
            ->where('user_id', $accountId)
            ->with('invoice:id,user_id,number', 'sale:id,user_id,number')
            ->get()
            ->each(function (Payment $payment) use (&$result, $accountId): void {
                $synced = $this->syncPayment($accountId, $payment);
                $result['batches_synced'] += $synced['batches_synced'];
                $result['entries_written'] += $synced['entries_written'];
                $result['review_required_batches'] += $synced['review_required_batches'];
            });

        Sale::query()
            ->where('user_id', $accountId)
            ->get()
            ->each(function (Sale $sale) use (&$result, $accountId): void {
                $synced = $this->syncSale($accountId, $sale);
                $result['batches_synced'] += $synced['batches_synced'];
                $result['entries_written'] += $synced['entries_written'];
                $result['review_required_batches'] += $synced['review_required_batches'];
            });

        Expense::query()
            ->byAccount($accountId)
            ->get()
            ->each(function (Expense $expense) use (&$result, $accountId): void {
                $synced = $this->syncExpense($accountId, $expense);
                $result['batches_synced'] += $synced['batches_synced'];
                $result['entries_written'] += $synced['entries_written'];
                $result['review_required_batches'] += $synced['review_required_batches'];
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
            ]
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
            totalAmount: (float) $payment->amount,
            baseAmount: (float) $payment->amount,
            taxAmount: 0.0,
            meta: [
                'payment_status' => $payment->status,
                'invoice_id' => $payment->invoice_id,
                'sale_id' => $payment->sale_id,
            ]
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
            ]
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
                    ]
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
                    ]
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
            ]
        );

        return $this->mergeResult($result, $synced);
    }

    /**
     * @param  array<string, mixed>  $meta
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
        array $meta = []
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
            existingEntryStates: $existingEntryStates
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
        array $existingEntryStates = []
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

        $revenueOrPrimaryCredit = $splitTaxEntry
            ? max(0, round($baseAmount, 2))
            : round($totalAmount, 2);

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
            && in_array($payment->status, Payment::settledStatuses(), true);
    }

    private function saleShouldGenerate(Sale $sale): bool
    {
        return $sale->status === Sale::STATUS_PAID || $sale->paid_at !== null;
    }

    private function resolveInvoiceSubtotal(Invoice $invoice): float
    {
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
        unset($this->accountCache[$accountId], $this->mappingCache[$accountId]);
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
