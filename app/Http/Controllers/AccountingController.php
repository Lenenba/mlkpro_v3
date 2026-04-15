<?php

namespace App\Http\Controllers;

use App\Models\AccountingAccount;
use App\Models\AccountingEntry;
use App\Models\AccountingEntryBatch;
use App\Models\AccountingExport;
use App\Models\AccountingMapping;
use App\Models\AccountingPeriod;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Accounting\AccountingExportService;
use App\Services\Accounting\AccountingPeriodService;
use App\Services\Accounting\AccountingReadService;
use App\Services\Accounting\AccountingReviewService;
use App\Services\Accounting\AccountingSyncService;
use App\Services\Accounting\AccountingTaxService;
use App\Services\FinanceApprovalService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class AccountingController extends Controller
{
    public function index(
        Request $request,
        AccountingSyncService $syncService,
        AccountingReadService $readService,
        AccountingTaxService $taxService,
        AccountingPeriodService $periodService,
        AccountingReviewService $reviewService
    ) {
        $actor = $request->user();
        if (! $actor || ! $this->canViewAccounting($actor)) {
            abort(403);
        }

        $accountId = (int) $actor->accountOwnerId();
        $owner = $actor->isAccountOwner() && (int) $actor->id === $accountId
            ? $actor
            : User::query()->find($accountId);
        $financeSettings = is_array($owner?->company_finance_settings) ? $owner->company_finance_settings : [];
        $financeApproval = app(FinanceApprovalService::class);
        $resolvedFinanceSettings = $financeApproval->settingsFor($owner ?? $actor);
        $filters = $request->only([
            'period',
            'source_type',
            'account_id',
            'review_status',
            'search',
        ]);
        $filters['per_page'] = $this->resolveDataTablePerPage($request);

        $syncSummary = $syncService->syncAccount($accountId);
        $journal = $readService->journal($accountId, $filters);
        $journalSummary = $readService->summary($accountId, $filters);
        $taxSummary = $taxService->summary($accountId, $filters);
        $periodTimeline = $periodService->timeline($accountId, $filters['period'] ?? null);
        $reviewWorkspace = $reviewService->workspace($accountId, $filters);
        $exportHistory = AccountingExport::query()
            ->forUser($accountId)
            ->with('generatedBy:id,name')
            ->orderByDesc('generated_at')
            ->orderByDesc('id')
            ->limit(6)
            ->get()
            ->map(fn (AccountingExport $export) => [
                'id' => $export->id,
                'period_key' => $export->period_key,
                'start_date' => optional($export->start_date)->toDateString(),
                'end_date' => optional($export->end_date)->toDateString(),
                'format' => $export->format,
                'status' => $export->status,
                'generated_at' => optional($export->generated_at)->toIso8601String(),
                'generated_by_name' => $export->generatedBy?->name,
                'row_count' => (int) data_get($export->meta, 'row_count', 0),
                'batch_count' => (int) data_get($export->meta, 'batch_count', 0),
                'download_url' => route('accounting.exports.download', $export),
            ])
            ->values()
            ->all();
        $latestExport = $exportHistory[0] ?? null;
        $mobileSummary = $this->mobileSummary($accountId, $filters, $periodTimeline['summary'], $reviewWorkspace, $taxSummary, $latestExport);
        $mobileAlerts = $this->mobileAlerts($mobileSummary, $latestExport);
        $nextSteps = $this->nextSteps($mobileSummary, $latestExport);

        return $this->inertiaOrJson('Accounting/Index', [
            'status' => [
                'phase' => 'phase_5',
                'state' => 'mobile_supervision_ready',
                'last_synced_at' => now()->toIso8601String(),
            ],
            'snapshot' => [
                'company_name' => $owner?->company_name ?: config('app.name'),
                'currency_code' => $owner?->businessCurrencyCode() ?? 'CAD',
                'finance_approvals_enabled' => $this->financeApprovalsEnabled($financeSettings),
                'invoice_auto_approve_under_amount' => data_get($financeSettings, 'invoice.auto_approve_under_amount'),
            ],
            'abilities' => [
                'can_manage' => $this->canManageAccounting($actor),
                'can_configure' => $actor->isAccountOwner(),
                'can_access_finance_approvals' => $this->canAccessFinanceApprovals($actor),
            ],
            'finance_configuration' => [
                'approval_mode' => $financeApproval->modeFor($owner ?? $actor),
                'expense_roles' => collect(data_get($resolvedFinanceSettings, 'expense.roles', []))
                    ->map(fn (array $role) => [
                        'role_key' => (string) data_get($role, 'role_key'),
                        'max_amount' => data_get($role, 'max_amount'),
                        'approval_order' => (int) data_get($role, 'approval_order', 1),
                    ])
                    ->values()
                    ->all(),
                'invoice_roles' => collect(data_get($resolvedFinanceSettings, 'invoice.roles', []))
                    ->map(fn (array $role) => [
                        'role_key' => (string) data_get($role, 'role_key'),
                        'max_amount' => data_get($role, 'max_amount'),
                        'approval_order' => (int) data_get($role, 'approval_order', 1),
                    ])
                    ->values()
                    ->all(),
            ],
            'source_counts' => [
                'expenses' => Expense::query()->where('user_id', $accountId)->count(),
                'invoices' => Invoice::query()->where('user_id', $accountId)->count(),
                'payments' => Payment::query()->where('user_id', $accountId)->count(),
                'sales' => Sale::query()->where('user_id', $accountId)->count(),
            ],
            'system_accounts' => AccountingAccount::query()
                ->forUser($accountId)
                ->orderBy('sort_order')
                ->orderBy('code')
                ->get()
                ->map(fn (AccountingAccount $account) => [
                    'id' => $account->id,
                    'key' => $account->key,
                    'code' => $account->code,
                    'name' => $account->name,
                    'type' => $account->type,
                    'description' => $account->description,
                    'is_system' => $account->is_system,
                    'is_active' => $account->is_active,
                ])
                ->values()
                ->all(),
            'mapping_conventions' => AccountingMapping::query()
                ->forUser($accountId)
                ->with(['debitAccount:id,key,code,name', 'creditAccount:id,key,code,name', 'taxAccount:id,key,code,name'])
                ->orderBy('source_domain')
                ->orderBy('source_key')
                ->get()
                ->map(fn (AccountingMapping $mapping) => [
                    'id' => $mapping->id,
                    'source_domain' => $mapping->source_domain,
                    'source_key' => $mapping->source_key,
                    'description' => data_get($mapping->meta, 'description'),
                    'debit_account_key' => $mapping->debitAccount?->key,
                    'debit_account_label' => $mapping->debitAccount
                        ? trim($mapping->debitAccount->code.' '.$mapping->debitAccount->name)
                        : null,
                    'credit_account_key' => $mapping->creditAccount?->key,
                    'credit_account_label' => $mapping->creditAccount
                        ? trim($mapping->creditAccount->code.' '.$mapping->creditAccount->name)
                        : null,
                    'tax_account_key' => $mapping->taxAccount?->key,
                    'tax_account_label' => $mapping->taxAccount
                        ? trim($mapping->taxAccount->code.' '.$mapping->taxAccount->name)
                        : null,
                ])
                ->values()
                ->all(),
            'journal' => [
                'data' => collect($journal->items())
                    ->map(fn ($entry) => [
                        'id' => $entry->id,
                        'direction' => $entry->direction,
                        'amount' => (float) $entry->amount,
                        'tax_amount' => (float) $entry->tax_amount,
                        'currency_code' => $entry->currency_code,
                        'entry_date' => optional($entry->entry_date)->toDateString(),
                        'description' => $entry->description,
                        'review_status' => $entry->review_status,
                        'reconciliation_status' => $entry->reconciliation_status,
                        'signed_amount' => $entry->signed_amount,
                        'account' => [
                            'id' => $entry->account?->id,
                            'code' => $entry->account?->code,
                            'name' => $entry->account?->name,
                            'type' => $entry->account?->type,
                        ],
                        'batch' => [
                            'id' => $entry->batch?->id,
                            'status' => $entry->batch?->status,
                            'source_type' => $entry->batch?->source_type,
                            'source_id' => $entry->batch?->source_id,
                            'source_event_key' => $entry->batch?->source_event_key,
                            'source_reference' => $entry->batch?->source_reference,
                            'source_url' => data_get($entry->batch?->meta, 'source_url'),
                            'review_status' => $reviewService->batchReviewStatus($entry->batch),
                        ],
                    ])
                    ->values()
                    ->all(),
                'links' => $journal->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $journal->currentPage(),
                    'last_page' => $journal->lastPage(),
                    'per_page' => $journal->perPage(),
                    'total' => $journal->total(),
                ],
            ],
            'journal_summary' => $journalSummary,
            'tax_summary' => $taxSummary,
            'periods' => $periodTimeline['periods'],
            'period_summary' => $periodTimeline['summary'],
            'review_workspace' => $reviewWorkspace,
            'mobile_summary' => $mobileSummary,
            'mobile_alerts' => $mobileAlerts,
            'next_steps' => $nextSteps,
            'handoff_summary' => [
                'selected_period_label' => $taxSummary['period_label'] ?? 'All periods',
                'selected_period_key' => $taxSummary['period_key'],
                'entry_count' => $journalSummary['entry_count'] ?? 0,
                'review_required_count' => $journalSummary['review_required_count'] ?? 0,
                'last_export_at' => data_get($latestExport, 'generated_at'),
                'last_export_row_count' => data_get($latestExport, 'row_count', 0),
                'available_formats' => ['csv'],
            ],
            'export_history' => $exportHistory,
            'filters' => $filters,
            'filter_options' => [
                'accounts' => $readService->accountOptions($accountId),
                'source_types' => [
                    ['value' => 'expense', 'label' => 'Expenses'],
                    ['value' => 'invoice', 'label' => 'Invoices'],
                    ['value' => 'payment', 'label' => 'Payments'],
                    ['value' => 'sale', 'label' => 'Sales'],
                ],
                'review_statuses' => [
                    ['value' => 'unreviewed', 'label' => 'Unreviewed'],
                    ['value' => 'reviewed', 'label' => 'Reviewed'],
                    ['value' => 'reconciled', 'label' => 'Reconciled'],
                ],
            ],
            'sync_summary' => $syncSummary,
        ]);
    }

    public function export(
        Request $request,
        AccountingSyncService $syncService,
        AccountingExportService $exportService
    ) {
        $actor = $request->user();
        if (! $actor || ! $this->canViewAccounting($actor)) {
            abort(403);
        }

        $accountId = (int) $actor->accountOwnerId();
        $filters = $request->only([
            'period',
            'source_type',
            'account_id',
            'review_status',
            'search',
        ]);

        $syncService->syncAccount($accountId);
        $export = $exportService->generateCsv($actor, $accountId, $filters);

        return Storage::disk('local')->download(
            $export->path,
            (string) data_get($export->meta, 'filename', 'accounting-export.csv'),
            ['Content-Type' => 'text/csv']
        );
    }

    public function downloadExport(Request $request, AccountingExport $accountingExport)
    {
        $actor = $request->user();
        if (! $actor || ! $this->canViewAccounting($actor)) {
            abort(403);
        }

        if ((int) $accountingExport->user_id !== (int) $actor->accountOwnerId()) {
            abort(404);
        }

        if (! $accountingExport->path || ! Storage::disk('local')->exists($accountingExport->path)) {
            abort(404);
        }

        return Storage::disk('local')->download(
            $accountingExport->path,
            (string) data_get($accountingExport->meta, 'filename', 'accounting-export.csv'),
            ['Content-Type' => 'text/csv']
        );
    }

    public function openPeriod(Request $request, string $periodKey, AccountingPeriodService $periodService)
    {
        return $this->transitionPeriod($request, $periodKey, AccountingPeriod::STATUS_OPEN, $periodService);
    }

    public function markPeriodInReview(Request $request, string $periodKey, AccountingPeriodService $periodService)
    {
        return $this->transitionPeriod($request, $periodKey, AccountingPeriod::STATUS_IN_REVIEW, $periodService);
    }

    public function closePeriod(Request $request, string $periodKey, AccountingPeriodService $periodService)
    {
        return $this->transitionPeriod($request, $periodKey, AccountingPeriod::STATUS_CLOSED, $periodService);
    }

    public function reopenPeriod(Request $request, string $periodKey, AccountingPeriodService $periodService)
    {
        return $this->transitionPeriod($request, $periodKey, AccountingPeriod::STATUS_REOPENED, $periodService);
    }

    public function markEntryUnreviewed(Request $request, AccountingEntry $accountingEntry, AccountingReviewService $reviewService)
    {
        return $this->transitionEntryReview($request, $accountingEntry, AccountingEntry::REVIEW_STATUS_UNREVIEWED, $reviewService);
    }

    public function markEntryReviewed(Request $request, AccountingEntry $accountingEntry, AccountingReviewService $reviewService)
    {
        return $this->transitionEntryReview($request, $accountingEntry, AccountingEntry::REVIEW_STATUS_REVIEWED, $reviewService);
    }

    public function markEntryReconciled(Request $request, AccountingEntry $accountingEntry, AccountingReviewService $reviewService)
    {
        return $this->transitionEntryReview($request, $accountingEntry, AccountingEntry::REVIEW_STATUS_RECONCILED, $reviewService);
    }

    public function markBatchUnreviewed(Request $request, AccountingEntryBatch $accountingEntryBatch, AccountingReviewService $reviewService)
    {
        return $this->transitionBatchReview($request, $accountingEntryBatch, AccountingEntry::REVIEW_STATUS_UNREVIEWED, $reviewService);
    }

    public function markBatchReviewed(Request $request, AccountingEntryBatch $accountingEntryBatch, AccountingReviewService $reviewService)
    {
        return $this->transitionBatchReview($request, $accountingEntryBatch, AccountingEntry::REVIEW_STATUS_REVIEWED, $reviewService);
    }

    public function markBatchReconciled(Request $request, AccountingEntryBatch $accountingEntryBatch, AccountingReviewService $reviewService)
    {
        return $this->transitionBatchReview($request, $accountingEntryBatch, AccountingEntry::REVIEW_STATUS_RECONCILED, $reviewService);
    }

    private function canViewAccounting(User $user): bool
    {
        if ($user->isAccountOwner()) {
            return true;
        }

        $membership = $user->relationLoaded('teamMembership')
            ? $user->teamMembership
            : $user->teamMembership()->first();

        return $membership instanceof TeamMember
            && (
                $membership->hasPermission('accounting.view')
                || $membership->hasPermission('accounting.manage')
            );
    }

    private function canManageAccounting(User $user): bool
    {
        if ($user->isAccountOwner()) {
            return true;
        }

        $membership = $user->relationLoaded('teamMembership')
            ? $user->teamMembership
            : $user->teamMembership()->first();

        return $membership instanceof TeamMember
            && $membership->hasPermission('accounting.manage');
    }

    private function canAccessFinanceApprovals(User $user): bool
    {
        if ($user->isAccountOwner()) {
            return $user->hasCompanyFeature('expenses') || $user->hasCompanyFeature('invoices');
        }

        $membership = $user->relationLoaded('teamMembership')
            ? $user->teamMembership
            : $user->teamMembership()->first();

        if (! ($membership instanceof TeamMember)) {
            return false;
        }

        return (
            $user->hasCompanyFeature('expenses')
            && (
                $membership->hasPermission('expenses.approve')
                || $membership->hasPermission('expenses.approve_high')
            )
        ) || (
            $user->hasCompanyFeature('invoices')
            && (
                $membership->hasPermission('invoices.approve')
                || $membership->hasPermission('invoices.approve_high')
            )
        );
    }

    private function financeApprovalsEnabled(array $settings): bool
    {
        $configuredRoles = collect([
            ...((array) data_get($settings, 'expense.roles', [])),
            ...((array) data_get($settings, 'invoice.roles', [])),
        ])->contains(fn ($role): bool => filled(data_get($role, 'role_key')));

        return $configuredRoles
            || filled(data_get($settings, 'invoice.auto_approve_under_amount'))
            || data_get($settings, 'approval_mode') === FinanceApprovalService::MODE_TEAM;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $periodSummary
     * @param  array<string, mixed>  $reviewWorkspace
     * @param  array<string, mixed>  $taxSummary
     * @param  array<string, mixed>|null  $latestExport
     * @return array<string, mixed>
     */
    private function mobileSummary(
        int $accountId,
        array $filters,
        array $periodSummary,
        array $reviewWorkspace,
        array $taxSummary,
        ?array $latestExport
    ): array {
        $periodKey = data_get($filters, 'period');
        $paymentQuery = Payment::query()
            ->where('user_id', $accountId)
            ->settled();
        $expenseQuery = Expense::query()
            ->byAccount($accountId)
            ->whereIn('status', [Expense::STATUS_PAID, Expense::STATUS_REIMBURSED]);

        $this->applyPeriodToQuery($paymentQuery, is_string($periodKey) ? $periodKey : null, 'paid_at');
        $this->applyPeriodToQuery($expenseQuery, is_string($periodKey) ? $periodKey : null, 'paid_date');

        $openPeriodCount = (int) data_get($periodSummary, 'open_count', 0)
            + (int) data_get($periodSummary, 'in_review_count', 0)
            + (int) data_get($periodSummary, 'reopened_count', 0);
        $unreconciledEntryCount = (int) data_get($reviewWorkspace, 'entry_status_counts.unreviewed', 0)
            + (int) data_get($reviewWorkspace, 'entry_status_counts.reviewed', 0);

        return [
            'period_key' => $periodKey,
            'period_label' => data_get($taxSummary, 'period_label', 'All periods'),
            'cash_in' => round((float) $paymentQuery->sum('amount'), 2),
            'cash_out' => round((float) $expenseQuery->sum('total'), 2),
            'net_tax_due' => round((float) data_get($taxSummary, 'net_tax_due', 0), 2),
            'open_period_count' => $openPeriodCount,
            'unreconciled_entry_count' => $unreconciledEntryCount,
            'pending_batch_count' => (int) data_get($reviewWorkspace, 'pending_batch_count', 0),
            'last_export_at' => data_get($latestExport, 'generated_at'),
        ];
    }

    /**
     * @param  array<string, mixed>  $mobileSummary
     * @param  array<string, mixed>|null  $latestExport
     * @return array<int, array<string, mixed>>
     */
    private function mobileAlerts(array $mobileSummary, ?array $latestExport): array
    {
        $alerts = [];

        if ((int) data_get($mobileSummary, 'pending_batch_count', 0) > 0) {
            $alerts[] = [
                'key' => 'pending_review',
                'tone' => 'warning',
                'value' => (int) data_get($mobileSummary, 'pending_batch_count', 0),
                'value_type' => 'count',
                'target' => 'review',
            ];
        }

        if ((int) data_get($mobileSummary, 'open_period_count', 0) > 0) {
            $alerts[] = [
                'key' => 'open_periods',
                'tone' => 'neutral',
                'value' => (int) data_get($mobileSummary, 'open_period_count', 0),
                'value_type' => 'count',
                'target' => 'periods',
            ];
        }

        $netTaxDue = round((float) data_get($mobileSummary, 'net_tax_due', 0), 2);
        if ($netTaxDue !== 0.0) {
            $alerts[] = [
                'key' => $netTaxDue > 0 ? 'tax_due' : 'tax_credit',
                'tone' => $netTaxDue > 0 ? 'warning' : 'success',
                'value' => abs($netTaxDue),
                'value_type' => 'money',
                'target' => 'taxes',
            ];
        }

        if (! $latestExport) {
            $alerts[] = [
                'key' => 'export_missing',
                'tone' => 'info',
                'value' => 0,
                'value_type' => 'count',
                'target' => 'exports',
            ];
        }

        return $alerts;
    }

    /**
     * @param  array<string, mixed>  $mobileSummary
     * @param  array<string, mixed>|null  $latestExport
     * @return array<int, array<string, mixed>>
     */
    private function nextSteps(array $mobileSummary, ?array $latestExport): array
    {
        $steps = [];

        if ((int) data_get($mobileSummary, 'pending_batch_count', 0) > 0) {
            $steps[] = [
                'key' => 'review_batches',
                'target' => 'review',
            ];
        }

        if ((int) data_get($mobileSummary, 'open_period_count', 0) > 0) {
            $steps[] = [
                'key' => 'close_periods',
                'target' => 'periods',
            ];
        }

        if (! $latestExport) {
            $steps[] = [
                'key' => 'generate_export',
                'target' => 'periods',
            ];
        }

        return $steps;
    }

    private function applyPeriodToQuery(Builder $query, ?string $periodKey, string $column): void
    {
        if (! $periodKey) {
            return;
        }

        try {
            $start = Carbon::createFromFormat('Y-m', $periodKey)->startOfMonth();
        } catch (\Throwable) {
            return;
        }

        $query->whereBetween($column, [$start, $start->copy()->endOfMonth()]);
    }

    private function transitionPeriod(
        Request $request,
        string $periodKey,
        string $targetStatus,
        AccountingPeriodService $periodService
    ) {
        $actor = $request->user();
        if (! $actor || ! $this->canManageAccounting($actor)) {
            abort(403);
        }

        $period = $periodService->transition($actor, (int) $actor->accountOwnerId(), $periodKey, $targetStatus);
        $message = 'Accounting period updated.';

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => $message,
                'period' => [
                    'period_key' => $period->period_key,
                    'status' => $period->status,
                    'closed_at' => optional($period->closed_at)->toIso8601String(),
                    'reopened_at' => optional($period->reopened_at)->toIso8601String(),
                ],
            ]);
        }

        return redirect()->back()->with('success', $message);
    }

    private function transitionEntryReview(
        Request $request,
        AccountingEntry $entry,
        string $targetStatus,
        AccountingReviewService $reviewService
    ) {
        $actor = $request->user();
        if (! $actor || ! $this->canManageAccounting($actor)) {
            abort(403);
        }

        $entry = $reviewService->transitionEntry($actor, (int) $actor->accountOwnerId(), $entry, $targetStatus);
        $message = 'Accounting entry updated.';

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => $message,
                'entry' => [
                    'id' => $entry->id,
                    'review_status' => $entry->review_status,
                    'reconciliation_status' => $entry->reconciliation_status,
                    'batch_id' => $entry->batch_id,
                ],
            ]);
        }

        return redirect()->back()->with('success', $message);
    }

    private function transitionBatchReview(
        Request $request,
        AccountingEntryBatch $batch,
        string $targetStatus,
        AccountingReviewService $reviewService
    ) {
        $actor = $request->user();
        if (! $actor || ! $this->canManageAccounting($actor)) {
            abort(403);
        }

        $batch = $reviewService->transitionBatch($actor, (int) $actor->accountOwnerId(), $batch, $targetStatus);
        $message = 'Accounting batch updated.';

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => $message,
                'batch' => [
                    'id' => $batch->id,
                    'review_status' => data_get($batch->meta, 'review_status', AccountingEntry::REVIEW_STATUS_UNREVIEWED),
                    'entry_count' => $batch->entries->count(),
                ],
            ]);
        }

        return redirect()->back()->with('success', $message);
    }
}
