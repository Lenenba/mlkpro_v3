<?php

namespace App\Http\Controllers;

use App\Enums\CurrencyCode;
use App\Http\Requests\Expenses\ExpenseWriteRequest;
use App\Models\ActivityLog;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseAttachment;
use App\Models\Invoice;
use App\Models\PettyCashAccount;
use App\Models\PettyCashAttachment;
use App\Models\PettyCashClosure;
use App\Models\PettyCashMovement;
use App\Models\Sale;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Work;
use App\Services\ExpenseAiDraftService;
use App\Services\ExpenseRecurringService;
use App\Services\FinanceApprovalService;
use App\Utils\FileHandler;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Expense::class);

        $user = $request->user();
        $accountId = (int) ($user?->accountOwnerId() ?? 0);

        $filters = $request->only([
            'search',
            'status',
            'category_key',
            'quick_filter',
            'supplier_name',
            'customer_id',
            'work_id',
            'sale_id',
            'invoice_id',
            'campaign_id',
            'expense_date_from',
            'expense_date_to',
            'created_from',
            'created_to',
            'recap_period',
            'recap_from',
            'recap_to',
            'petty_type',
            'petty_status',
            'petty_responsible_user_id',
            'petty_from',
            'petty_to',
            'petty_page',
            'sort',
            'direction',
        ]);
        $filters['per_page'] = $this->resolveDataTablePerPage($request);

        $baseQuery = Expense::query()->byAccount($accountId);
        $filteredQuery = $this->applyFilters(clone $baseQuery, $filters);

        $sort = in_array($filters['sort'] ?? null, ['title', 'total', 'expense_date', 'due_date', 'created_at'], true)
            ? $filters['sort']
            : 'expense_date';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $expenses = (clone $filteredQuery)
            ->with([
                'creator:id,name',
                'customer:id,first_name,last_name,company_name',
                'work:id,job_title,number',
                'sale:id,number',
                'invoice:id,number',
                'campaign:id,name',
            ])
            ->withCount('attachments')
            ->orderBy($sort, $direction)
            ->paginate((int) $filters['per_page'])
            ->withQueryString();
        $expenses->setCollection(
            $expenses->getCollection()->map(function (Expense $expense) use ($user) {
                return $this->presentExpenseSummary($expense, $user);
            })
        );

        $stats = [
            'total' => (clone $baseQuery)->count(),
            'draft' => (clone $baseQuery)->where('status', Expense::STATUS_DRAFT)->count(),
            'overdue' => (clone $baseQuery)
                ->whereNotIn('status', [Expense::STATUS_PAID, Expense::STATUS_REIMBURSED, Expense::STATUS_CANCELLED])
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<', now()->toDateString())
                ->count(),
            'due_total' => round((float) ((clone $baseQuery)
                ->whereNotIn('status', [Expense::STATUS_PAID, Expense::STATUS_REIMBURSED, Expense::STATUS_CANCELLED])
                ->sum('total') ?? 0), 2),
            'paid_this_month' => round((float) ((clone $baseQuery)
                ->whereIn('status', [Expense::STATUS_PAID, Expense::STATUS_REIMBURSED])
                ->whereBetween('paid_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
                ->sum('total') ?? 0), 2),
            'linked_total' => round((float) ((clone $baseQuery)
                ->where(function ($query) {
                    $query->whereNotNull('customer_id')
                        ->orWhereNotNull('work_id')
                        ->orWhereNotNull('sale_id')
                        ->orWhereNotNull('invoice_id')
                        ->orWhereNotNull('campaign_id');
                })
                ->sum('total') ?? 0), 2),
            'top_categories' => $this->topCategoryStats(clone $baseQuery),
            'top_suppliers' => $this->topSupplierStats(clone $baseQuery),
        ];
        $periodRecap = $this->buildPeriodRecap(clone $baseQuery, $filters);

        $owner = $user && (int) $user->id === $accountId
            ? $user
            : User::query()->find($accountId);
        $tenantCurrencyCode = $owner?->businessCurrencyCode() ?? CurrencyCode::default()->value;
        $teamMembers = $this->teamMemberOptions($user, $accountId);
        $pettyCash = $this->buildPettyCashPanel($user, $accountId, $filters, $tenantCurrencyCode, $teamMembers);

        return $this->inertiaOrJson('Expense/Index', [
            'filters' => $filters,
            'expenses' => $expenses,
            'count' => (clone $filteredQuery)->count(),
            'stats' => $stats,
            'periodRecap' => $periodRecap,
            'pettyCash' => $pettyCash,
            'categories' => config('expenses.categories', []),
            'paymentMethods' => config('expenses.payment_methods', []),
            'statuses' => Expense::STATUSES,
            'recurrenceFrequencies' => Expense::RECURRENCE_FREQUENCIES,
            'teamMembers' => $teamMembers,
            'linkOptions' => $this->expenseLinkOptions($user, $accountId),
            'tenantCurrencyCode' => $tenantCurrencyCode,
            'canUseAiIntake' => ($user?->can('create', Expense::class) ?? false)
                && ($user?->hasCompanyFeature('assistant') ?? false),
        ]);
    }

    public function show(Request $request, Expense $expense)
    {
        $this->authorize('view', $expense);

        $expense->load([
            'creator:id,name',
            'approver:id,name',
            'payer:id,name',
            'reimburser:id,name',
            'teamMember.user:id,name',
            'customer:id,first_name,last_name,company_name',
            'work:id,job_title,number',
            'sale:id,number',
            'invoice:id,number',
            'campaign:id,name',
            'recurrenceSource:id,title',
            'attachments.user:id,name',
        ])->loadCount('generatedRecurrences');
        $expense = $this->presentExpenseDetail($expense, $request->user());

        return $this->inertiaOrJson('Expense/Show', [
            'expense' => $expense,
            'categories' => config('expenses.categories', []),
            'paymentMethods' => config('expenses.payment_methods', []),
            'statuses' => Expense::STATUSES,
            'recurrenceFrequencies' => Expense::RECURRENCE_FREQUENCIES,
            'teamMembers' => $this->teamMemberOptions($request->user(), (int) $expense->user_id),
            'linkOptions' => $this->expenseLinkOptions($request->user(), (int) $expense->user_id),
            'tenantCurrencyCode' => strtoupper((string) ($expense->currency_code ?: CurrencyCode::default()->value)),
            'canEdit' => $request->user()?->can('update', $expense) ?? false,
        ]);
    }

    public function store(ExpenseWriteRequest $request, ExpenseRecurringService $recurringService)
    {
        $this->authorize('create', Expense::class);

        $user = $request->user();
        $accountId = (int) ($user?->accountOwnerId() ?? 0);
        $validated = $request->validated();
        $pettyCashMovement = null;

        $expense = DB::transaction(function () use ($request, $validated, $accountId, $user, $recurringService, &$pettyCashMovement) {
            $expense = Expense::query()->create($this->buildExpensePayload(
                $validated,
                $accountId,
                (int) $user->id,
                null,
                $recurringService,
                $user
            ));

            $this->storeAttachments($request, $expense, (int) $user->id);

            if ($request->boolean('petty_cash_create')) {
                $pettyCashMovement = $this->createPettyCashMovementFromManualExpense($request, $expense, $validated);
            }

            $this->recordExpenseAuditEvent($user, $expense, 'created', [
                'status' => $expense->status,
                'approval_policy_snapshot' => data_get($expense->meta, 'approval.policy_snapshot'),
            ], 'Expense created');
            if (data_get($expense->meta, 'approval.policy_snapshot.auto_approved')) {
                $this->recordExpenseAuditEvent($user, $expense, 'auto_approved', [
                    'status' => $expense->status,
                    'approval_mode' => data_get($expense->meta, 'approval.policy_snapshot.approval_mode'),
                ], 'Expense auto-approved from plan-based workflow');
            }

            return $expense;
        });

        $message = 'Expense created successfully.';
        if ($pettyCashMovement) {
            $message .= $pettyCashMovement->status === PettyCashMovement::STATUS_POSTED
                ? ' Petty cash movement posted.'
                : ' Petty cash movement drafted.';
        }

        $payload = [
            'message' => $message,
            'expense' => $this->presentExpenseDetail($expense->fresh([
                'creator:id,name',
                'teamMember.user:id,name',
                'customer:id,first_name,last_name,company_name',
                'work:id,job_title,number',
                'sale:id,number',
                'invoice:id,number',
                'campaign:id,name',
                'attachments',
            ]), $user),
            'pettyCashMovement' => $pettyCashMovement
                ? $this->presentPettyCashMovement($pettyCashMovement->fresh($this->pettyCashMovementRelations()), $user)
                : null,
        ];

        if ($this->shouldReturnJson($request)) {
            return response()->json($payload, 201);
        }

        return redirect()->back()->with('success', $payload['message']);
    }

    public function update(ExpenseWriteRequest $request, Expense $expense, ExpenseRecurringService $recurringService)
    {
        $this->authorize('update', $expense);

        $user = $request->user();
        $validated = $request->validated();

        $expense->fill($this->buildExpensePayload(
            $validated,
            (int) $expense->user_id,
            (int) $user->id,
            $expense,
            $recurringService,
            $user
        ))->save();

        $this->storeAttachments($request, $expense, (int) $user->id);
        $this->recordExpenseAuditEvent($user, $expense, 'updated', [
            'status' => $expense->status,
        ], 'Expense updated');

        $payload = [
            'message' => 'Expense updated successfully.',
            'expense' => $this->presentExpenseDetail($expense->fresh([
                'creator:id,name',
                'approver:id,name',
                'payer:id,name',
                'reimburser:id,name',
                'teamMember.user:id,name',
                'customer:id,first_name,last_name,company_name',
                'work:id,job_title,number',
                'sale:id,number',
                'invoice:id,number',
                'campaign:id,name',
                'attachments',
            ]), $user),
        ];

        if ($this->shouldReturnJson($request)) {
            return response()->json($payload);
        }

        return redirect()->back()->with('success', $payload['message']);
    }

    public function scanWithAi(Request $request, ExpenseAiDraftService $draftService)
    {
        $this->authorize('create', Expense::class);

        $validated = $request->validate([
            'document' => 'required|file|mimes:pdf,png,jpg,jpeg,webp|max:10240',
            'note' => 'nullable|string|max:1000',
            'petty_cash_create' => ['nullable', 'boolean'],
            'petty_cash_status' => ['nullable', 'string', Rule::in([PettyCashMovement::STATUS_DRAFT, PettyCashMovement::STATUS_POSTED])],
            'petty_cash_responsible_user_id' => ['nullable', 'integer'],
            'petty_cash_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $user = $request->user();
        $document = $request->file('document');

        if (! $document instanceof UploadedFile) {
            throw ValidationException::withMessages([
                'document' => 'A valid invoice or receipt file is required.',
            ]);
        }

        $draft = $draftService->createFromDocument($user, $document, [
            'note' => $validated['note'] ?? null,
        ]);
        $pettyCashMovement = $request->boolean('petty_cash_create')
            ? $this->createPettyCashMovementFromScannedExpense($request, $draft['expense'], $validated)
            : null;
        $expense = $this->presentExpenseDetail($draft['expense']->fresh([
            'creator:id,name',
            'approver:id,name',
            'payer:id,name',
            'attachments.user:id,name',
        ]), $user);
        $message = (string) ($draft['message'] ?? 'AI draft created successfully.');
        if ($pettyCashMovement) {
            $message .= $pettyCashMovement->status === PettyCashMovement::STATUS_POSTED
                ? ' Petty cash movement posted.'
                : ' Petty cash movement drafted.';
        }

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => $message,
                'expense' => $expense,
                'pettyCashMovement' => $pettyCashMovement
                    ? $this->presentPettyCashMovement($pettyCashMovement->fresh($this->pettyCashMovementRelations()), $user)
                    : null,
            ], 201);
        }

        return redirect()
            ->route('expense.show', $expense)
            ->with('success', $message);
    }

    public function export(Request $request)
    {
        $this->authorize('viewAny', Expense::class);

        $user = $request->user();
        $accountId = (int) ($user?->accountOwnerId() ?? 0);
        $filters = $request->only([
            'search',
            'status',
            'category_key',
            'quick_filter',
            'supplier_name',
            'customer_id',
            'work_id',
            'sale_id',
            'invoice_id',
            'campaign_id',
            'expense_date_from',
            'expense_date_to',
            'created_from',
            'created_to',
            'sort',
            'direction',
        ]);

        $query = $this->applyFilters(Expense::query()->byAccount($accountId), $filters)
            ->with([
                'creator:id,name',
                'approver:id,name',
                'payer:id,name',
                'reimburser:id,name',
                'customer:id,first_name,last_name,company_name',
                'work:id,job_title,number',
                'sale:id,number',
                'invoice:id,number',
                'campaign:id,name',
                'attachments:id,expense_id',
            ])
            ->withCount('attachments');

        $filename = 'expenses-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'title',
                'status',
                'reimbursement_status',
                'category_key',
                'supplier_name',
                'reference_number',
                'currency_code',
                'subtotal',
                'tax_amount',
                'total',
                'expense_date',
                'due_date',
                'paid_date',
                'payment_method',
                'team_member',
                'customer',
                'work',
                'sale',
                'invoice',
                'campaign',
                'is_recurring',
                'recurrence_frequency',
                'recurrence_next_date',
                'created_by',
                'approved_by',
                'paid_by',
                'reimbursed_by',
                'attachments_count',
                'attachment_ids',
                'created_at',
            ]);

            $query->orderByDesc('expense_date')
                ->chunk(200, function ($expenses) use ($handle): void {
                    foreach ($expenses as $expense) {
                        fputcsv($handle, [
                            $expense->title,
                            $expense->status,
                            $expense->reimbursement_status,
                            $expense->category_key,
                            $expense->supplier_name,
                            $expense->reference_number,
                            strtoupper((string) $expense->currency_code),
                            $expense->subtotal,
                            $expense->tax_amount,
                            $expense->total,
                            optional($expense->expense_date)->toDateString(),
                            optional($expense->due_date)->toDateString(),
                            optional($expense->paid_date)->toDateString(),
                            $expense->payment_method,
                            $expense->teamMember?->user?->name,
                            $this->customerDisplayName($expense->customer),
                            $expense->work?->number ?: $expense->work?->job_title,
                            $expense->sale?->number,
                            $expense->invoice?->number,
                            $expense->campaign?->name,
                            $expense->is_recurring ? '1' : '0',
                            $expense->recurrence_frequency,
                            optional($expense->recurrence_next_date)->toDateString(),
                            $expense->creator?->name,
                            $expense->approver?->name,
                            $expense->payer?->name,
                            $expense->reimburser?->name,
                            $expense->attachments_count,
                            $expense->attachments->pluck('id')->implode('|'),
                            optional($expense->created_at)->toDateTimeString(),
                        ]);
                    }
                });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function exportPettyCash(Request $request)
    {
        $this->authorize('viewAny', Expense::class);

        $user = $request->user();
        $accountId = (int) ($user?->accountOwnerId() ?? 0);
        $owner = $accountId > 0 ? User::query()->find($accountId) : null;
        $pettyCashAccount = $this->resolvePettyCashAccount(
            $accountId,
            $owner?->businessCurrencyCode() ?? CurrencyCode::default()->value,
            $user
        );
        $filters = $request->only([
            'petty_type',
            'petty_status',
            'petty_responsible_user_id',
            'petty_from',
            'petty_to',
        ]);

        $query = $this->applyPettyCashFilters(
            PettyCashMovement::query()
                ->where('petty_cash_account_id', $pettyCashAccount->id)
                ->with([
                    'creator:id,name',
                    'responsible:id,name',
                    'teamMember.user:id,name',
                    'expense:id,title,total,expense_date',
                    'attachments:id,petty_cash_movement_id,path,original_name',
                ])
                ->withCount('attachments'),
            $filters
        );

        $filename = 'petty-cash-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'type',
                'status',
                'amount',
                'balance_delta',
                'currency_code',
                'movement_date',
                'responsible',
                'team_member',
                'linked_expense_id',
                'linked_expense',
                'note',
                'requires_receipt',
                'receipt_attached',
                'attachments_count',
                'attachment_ids',
                'attachment_paths',
                'posted_at',
                'voided_at',
                'void_reason',
                'created_by',
                'accounting_event',
                'created_at',
            ]);

            $query->orderByDesc('movement_date')
                ->orderByDesc('id')
                ->chunk(200, function ($movements) use ($handle): void {
                    foreach ($movements as $movement) {
                        fputcsv($handle, [
                            $movement->type,
                            $movement->status,
                            $movement->amount,
                            $this->pettyCashMovementDelta($movement),
                            strtoupper((string) $movement->currency_code),
                            optional($movement->movement_date)->toDateString(),
                            $movement->responsible?->name,
                            $movement->teamMember?->user?->name ?: $movement->teamMember?->title,
                            $movement->expense?->id,
                            $movement->expense?->title,
                            $movement->note,
                            $movement->requires_receipt ? '1' : '0',
                            $movement->receipt_attached ? '1' : '0',
                            $movement->attachments_count,
                            $movement->attachments->pluck('id')->implode('|'),
                            $movement->attachments->pluck('path')->implode('|'),
                            optional($movement->posted_at)->toDateTimeString(),
                            optional($movement->voided_at)->toDateTimeString(),
                            $movement->void_reason,
                            $movement->creator?->name,
                            $this->pettyCashAccountingEvent($movement),
                            optional($movement->created_at)->toDateTimeString(),
                        ]);
                    }
                });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function submit(Request $request, Expense $expense)
    {
        $this->authorize('transition', $expense);

        $validated = $request->validate([
            'comment' => 'nullable|string|max:1000',
        ]);

        if (! in_array($expense->status, [Expense::STATUS_DRAFT, Expense::STATUS_REVIEW_REQUIRED, Expense::STATUS_REJECTED], true)) {
            throw ValidationException::withMessages([
                'status' => 'This expense cannot move to the requested workflow state.',
            ]);
        }

        $authorization = app(FinanceApprovalService::class)->authorizeExpenseAction($request->user(), $expense, 'submit');
        if (! ($authorization['allowed'] ?? false)) {
            throw ValidationException::withMessages([
                'status' => [$authorization['message'] ?? 'You cannot submit this expense.'],
            ]);
        }

        return $this->transitionExpense(
            $request,
            $expense,
            (string) $authorization['status'],
            [Expense::STATUS_DRAFT, Expense::STATUS_REVIEW_REQUIRED, Expense::STATUS_REJECTED],
            'submit',
            'Expense submitted successfully.',
            [
                'current_approver_role_key' => $authorization['current_approver_role_key'] ?? null,
                'current_approval_level' => $authorization['current_approval_level'] ?? null,
                'approval_policy_snapshot' => $authorization['approval_policy_snapshot'] ?? null,
                'owner_override' => $authorization['owner_override'] ?? false,
                'comment' => $validated['comment'] ?? null,
            ]
        );
    }

    public function approve(Request $request, Expense $expense)
    {
        return $this->transitionExpense(
            $request,
            $expense,
            Expense::STATUS_APPROVED,
            [Expense::STATUS_SUBMITTED, Expense::STATUS_PENDING_APPROVAL],
            'approve',
            'Expense approved successfully.',
            [
                'finance_action' => 'approve',
                'clear_approver' => true,
            ]
        );
    }

    public function reject(Request $request, Expense $expense)
    {
        return $this->transitionExpense(
            $request,
            $expense,
            Expense::STATUS_REJECTED,
            [Expense::STATUS_SUBMITTED, Expense::STATUS_PENDING_APPROVAL],
            'reject',
            'Expense rejected successfully.',
            [
                'finance_action' => 'reject',
                'clear_approver' => true,
            ]
        );
    }

    public function markDue(Request $request, Expense $expense)
    {
        return $this->transitionExpense(
            $request,
            $expense,
            Expense::STATUS_DUE,
            [Expense::STATUS_APPROVED],
            'mark_due',
            'Expense marked as due successfully.',
            [
                'finance_action' => 'mark_due',
            ]
        );
    }

    public function markPaid(Request $request, Expense $expense)
    {
        return $this->transitionExpense(
            $request,
            $expense,
            Expense::STATUS_PAID,
            [Expense::STATUS_APPROVED, Expense::STATUS_DUE],
            'mark_paid',
            'Expense marked as paid successfully.',
            [
                'finance_action' => 'mark_paid',
            ]
        );
    }

    public function markReimbursed(Request $request, Expense $expense)
    {
        $this->authorize('transition', $expense);

        $authorization = app(FinanceApprovalService::class)->authorizeExpenseAction($request->user(), $expense, 'mark_reimbursed');
        if (! ($authorization['allowed'] ?? false)) {
            throw ValidationException::withMessages([
                'status' => [$authorization['message'] ?? 'You cannot reimburse this expense.'],
            ]);
        }

        $validated = $request->validate([
            'comment' => 'nullable|string|max:1000',
            'paid_date' => 'nullable|date',
            'reimbursement_reference' => 'nullable|string|max:255',
        ]);

        if (! $expense->reimbursable) {
            throw ValidationException::withMessages([
                'reimbursable' => 'Only reimbursable expenses can be marked as reimbursed.',
            ]);
        }

        if ($expense->reimbursement_status === Expense::REIMBURSEMENT_STATUS_REIMBURSED) {
            throw ValidationException::withMessages([
                'reimbursement_status' => 'This expense is already marked as reimbursed.',
            ]);
        }

        if ($expense->status === Expense::STATUS_CANCELLED) {
            throw ValidationException::withMessages([
                'status' => 'Cancelled expenses cannot be reimbursed.',
            ]);
        }

        $actorId = (int) $request->user()->id;
        $fromStatus = (string) $expense->status;
        $effectivePaidDate = $validated['paid_date'] ?? $expense->paid_date?->toDateString() ?? now()->toDateString();
        $nextStatus = in_array($expense->status, [Expense::STATUS_PAID, Expense::STATUS_REIMBURSED], true)
            ? $expense->status
            : Expense::STATUS_PAID;

        $expense->fill([
            'status' => $nextStatus,
            'paid_date' => $effectivePaidDate,
            'paid_by_user_id' => $expense->paid_by_user_id ?: $actorId,
            'reimbursement_status' => Expense::REIMBURSEMENT_STATUS_REIMBURSED,
            'reimbursed_at' => Carbon::parse($effectivePaidDate)->endOfDay(),
            'reimbursed_by_user_id' => $actorId,
            'reimbursement_reference' => $validated['reimbursement_reference'] ?? $expense->reimbursement_reference,
            'current_approver_role_key' => null,
            'current_approval_level' => null,
            'meta' => $this->appendWorkflowHistory(
                is_array($expense->meta) ? $expense->meta : [],
                'mark_reimbursed',
                $fromStatus,
                $nextStatus,
                $actorId,
                $validated['comment'] ?? null,
                array_filter([
                    'reimbursement_status_from' => $expense->reimbursement_status,
                    'reimbursement_status_to' => Expense::REIMBURSEMENT_STATUS_REIMBURSED,
                    'reimbursement_reference' => $validated['reimbursement_reference'] ?? null,
                ], fn ($value) => $value !== null && $value !== '')
            ),
        ])->save();

        $expense->load([
            'creator:id,name',
            'approver:id,name',
            'payer:id,name',
            'reimburser:id,name',
            'teamMember.user:id,name',
            'customer:id,first_name,last_name,company_name',
            'work:id,job_title,number',
            'sale:id,number',
            'invoice:id,number',
            'campaign:id,name',
            'recurrenceSource:id,title',
            'attachments.user:id,name',
        ])->loadCount('generatedRecurrences');
        $expense = $this->presentExpenseDetail($expense, $request->user());
        $this->recordExpenseAuditEvent($request->user(), $expense, 'mark_reimbursed', [
            'from' => $fromStatus,
            'to' => $expense->status,
            'reimbursement_status' => $expense->reimbursement_status,
            'comment' => $validated['comment'] ?? null,
        ], 'Expense reimbursed');

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Expense reimbursed successfully.',
                'expense' => $expense,
            ]);
        }

        return redirect()->back()->with('success', 'Expense reimbursed successfully.');
    }

    public function cancel(Request $request, Expense $expense)
    {
        return $this->transitionExpense(
            $request,
            $expense,
            Expense::STATUS_CANCELLED,
            [Expense::STATUS_DRAFT, Expense::STATUS_REVIEW_REQUIRED, Expense::STATUS_SUBMITTED, Expense::STATUS_PENDING_APPROVAL, Expense::STATUS_APPROVED, Expense::STATUS_DUE],
            'cancel',
            'Expense cancelled successfully.',
            [
                'finance_action' => 'cancel',
                'clear_approver' => true,
            ]
        );
    }

    public function storePettyCashMovement(Request $request)
    {
        $this->authorize('viewAny', Expense::class);

        $user = $request->user();
        $accountId = (int) ($user?->accountOwnerId() ?? 0);
        $owner = $user && (int) $user->id === $accountId
            ? $user
            : User::query()->find($accountId);
        $pettyCashAccount = $this->resolvePettyCashAccount(
            $accountId,
            $owner?->businessCurrencyCode() ?? CurrencyCode::default()->value,
            $user
        );

        if (! $this->canCreatePettyCashMovement($user)) {
            abort(403);
        }

        $validated = $this->validatePettyCashMovement($request, $accountId, $pettyCashAccount);
        $status = (string) ($validated['status'] ?? PettyCashMovement::STATUS_DRAFT);
        if ($status === PettyCashMovement::STATUS_POSTED && ! $this->canPostPettyCashMovement($user)) {
            abort(403);
        }
        if ($validated['type'] === PettyCashMovement::TYPE_ADJUSTMENT && ! $this->canAdjustPettyCash($user)) {
            abort(403);
        }

        $movementDate = Carbon::parse((string) $validated['movement_date'])->startOfDay();
        $this->ensurePettyCashDateIsOpen($pettyCashAccount, $movementDate);
        $requiresReceipt = $this->pettyCashMovementRequiresReceipt(
            $pettyCashAccount,
            (string) $validated['type'],
            (float) $validated['amount'],
            (bool) ($validated['requires_receipt'] ?? false)
        );
        $receiptMandatory = $this->pettyCashReceiptIsMandatory($pettyCashAccount, (float) $validated['amount']);
        $this->ensurePettyCashReceiptRequirementIsMet($request, $receiptMandatory, $status, (bool) ($validated['receipt_attached'] ?? false));

        $movement = DB::transaction(function () use ($request, $validated, $pettyCashAccount, $accountId, $user, $status, $requiresReceipt) {
            $movement = PettyCashMovement::query()->create([
                'user_id' => $accountId,
                'petty_cash_account_id' => $pettyCashAccount->id,
                'expense_id' => $validated['expense_id'] ?? null,
                'team_member_id' => $validated['team_member_id'] ?? null,
                'created_by_user_id' => $user?->id,
                'responsible_user_id' => $validated['responsible_user_id'] ?? $user?->id,
                'type' => $validated['type'],
                'status' => $status,
                'amount' => round((float) $validated['amount'], 2),
                'currency_code' => $pettyCashAccount->currency_code,
                'movement_date' => $validated['movement_date'],
                'note' => $validated['note'] ?? null,
                'requires_receipt' => $requiresReceipt,
                'receipt_attached' => (bool) ($validated['receipt_attached'] ?? false),
                'posted_at' => $status === PettyCashMovement::STATUS_POSTED ? now() : null,
                'meta' => [
                    'source' => 'expense_petty_cash_controls',
                    'accounting_event' => $status === PettyCashMovement::STATUS_POSTED
                        ? $this->pettyCashAccountingEventForType((string) $validated['type'])
                        : null,
                ],
            ]);

            if ($request->hasFile('receipt')) {
                $this->storePettyCashAttachment($request->file('receipt'), $movement, (int) $user->id);
                $movement->forceFill(['receipt_attached' => true])->save();
            }

            if ($movement->status === PettyCashMovement::STATUS_POSTED) {
                $this->recalculatePettyCashBalance($pettyCashAccount);
            }

            ActivityLog::record($user, $movement, 'petty_cash_movement_created', [
                'type' => $movement->type,
                'status' => $movement->status,
                'amount' => (float) $movement->amount,
                'currency_code' => $movement->currency_code,
            ], 'Petty cash movement created');

            return $movement;
        });

        return $this->pettyCashMutationResponse($request, $movement->fresh($this->pettyCashMovementRelations()), 'Petty cash movement created.', 201);
    }

    public function postPettyCashMovement(Request $request, PettyCashMovement $movement)
    {
        $this->authorize('viewAny', Expense::class);

        $user = $request->user();
        $this->ensurePettyCashMovementBelongsToActor($movement, $user);
        if (! $this->canPostPettyCashMovement($user)) {
            abort(403);
        }
        if ($movement->type === PettyCashMovement::TYPE_ADJUSTMENT && ! $this->canAdjustPettyCash($user)) {
            abort(403);
        }

        if ($movement->status !== PettyCashMovement::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => 'Only draft petty cash movements can be posted.',
            ]);
        }
        $this->ensurePettyCashDateIsOpen($movement->account, $movement->movement_date);
        $this->ensurePettyCashReceiptRequirementIsMet(
            $request,
            $this->pettyCashReceiptIsMandatory($movement->account, (float) $movement->amount),
            PettyCashMovement::STATUS_POSTED,
            (bool) $movement->receipt_attached
        );

        $movement = DB::transaction(function () use ($movement, $user) {
            $movement->forceFill([
                'status' => PettyCashMovement::STATUS_POSTED,
                'posted_at' => now(),
                'meta' => array_replace((array) ($movement->meta ?? []), [
                    'accounting_event' => $this->pettyCashAccountingEvent($movement),
                ]),
            ])->save();

            $this->recalculatePettyCashBalance($movement->account);

            ActivityLog::record($user, $movement, 'petty_cash_movement_posted', [
                'type' => $movement->type,
                'amount' => (float) $movement->amount,
                'balance_delta' => $this->pettyCashMovementDelta($movement),
            ], 'Petty cash movement posted');

            return $movement;
        });

        return $this->pettyCashMutationResponse($request, $movement->fresh($this->pettyCashMovementRelations()), 'Petty cash movement posted.');
    }

    public function voidPettyCashMovement(Request $request, PettyCashMovement $movement)
    {
        $this->authorize('viewAny', Expense::class);

        $user = $request->user();
        $this->ensurePettyCashMovementBelongsToActor($movement, $user);
        if (! $this->canPostPettyCashMovement($user)) {
            abort(403);
        }

        $validated = $request->validate([
            'void_reason' => 'required|string|max:1000',
        ]);

        if ($movement->status === PettyCashMovement::STATUS_VOIDED) {
            throw ValidationException::withMessages([
                'status' => 'This petty cash movement is already voided.',
            ]);
        }
        $this->ensurePettyCashDateIsOpen($movement->account, $movement->movement_date);

        $movement = DB::transaction(function () use ($movement, $user, $validated) {
            $wasPosted = $movement->status === PettyCashMovement::STATUS_POSTED;
            $movement->forceFill([
                'status' => PettyCashMovement::STATUS_VOIDED,
                'voided_at' => now(),
                'voided_by_user_id' => $user?->id,
                'void_reason' => $validated['void_reason'],
            ])->save();

            if ($wasPosted) {
                $this->recalculatePettyCashBalance($movement->account);
            }

            ActivityLog::record($user, $movement, 'petty_cash_movement_voided', [
                'type' => $movement->type,
                'amount' => (float) $movement->amount,
                'void_reason' => $validated['void_reason'],
            ], 'Petty cash movement voided');

            return $movement;
        });

        return $this->pettyCashMutationResponse($request, $movement->fresh($this->pettyCashMovementRelations()), 'Petty cash movement voided.');
    }

    public function updatePettyCashAccount(Request $request)
    {
        $this->authorize('viewAny', Expense::class);

        $user = $request->user();
        $accountId = (int) ($user?->accountOwnerId() ?? 0);
        if (! $this->canManagePettyCash($user)) {
            abort(403);
        }

        $owner = $accountId > 0 ? User::query()->find($accountId) : null;
        $pettyCashAccount = $this->resolvePettyCashAccount(
            $accountId,
            $owner?->businessCurrencyCode() ?? CurrencyCode::default()->value,
            $user
        );

        $validated = $request->validate([
            'responsible_user_id' => ['required', 'integer'],
            'low_balance_threshold' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'receipt_required_above' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
        ]);

        $this->ensureResponsibleBelongsToAccount((int) $validated['responsible_user_id'], $accountId);

        $pettyCashAccount->forceFill([
            'responsible_user_id' => (int) $validated['responsible_user_id'],
            'low_balance_threshold' => round((float) ($validated['low_balance_threshold'] ?? 0), 2),
            'receipt_required_above' => round((float) ($validated['receipt_required_above'] ?? 0), 2),
        ])->save();

        ActivityLog::record($user, $pettyCashAccount, 'petty_cash_account_updated', [
            'responsible_user_id' => (int) $pettyCashAccount->responsible_user_id,
            'low_balance_threshold' => (float) $pettyCashAccount->low_balance_threshold,
            'receipt_required_above' => (float) $pettyCashAccount->receipt_required_above,
        ], 'Petty cash account controls updated');

        return $this->pettyCashPanelResponse($request, 'Petty cash settings updated.');
    }

    public function storePettyCashClosure(Request $request)
    {
        $this->authorize('viewAny', Expense::class);

        $user = $request->user();
        $accountId = (int) ($user?->accountOwnerId() ?? 0);
        if (! $this->canClosePettyCash($user)) {
            abort(403);
        }

        $owner = $accountId > 0 ? User::query()->find($accountId) : null;
        $pettyCashAccount = $this->resolvePettyCashAccount(
            $accountId,
            $owner?->businessCurrencyCode() ?? CurrencyCode::default()->value,
            $user
        );
        $validated = $this->validatePettyCashClosure($request);
        [$periodStart, $periodEnd] = $this->normalizePettyCashClosurePeriod(
            $validated['period_start'],
            $validated['period_end']
        );
        $status = (string) ($validated['status'] ?? PettyCashClosure::STATUS_IN_REVIEW);
        $expectedBalance = $this->pettyCashExpectedBalanceAt($pettyCashAccount, $periodEnd);
        $countedBalance = round((float) $validated['counted_balance'], 2);
        $difference = round($countedBalance - $expectedBalance, 2);

        if (abs($difference) >= 0.01 && trim((string) ($validated['comment'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'comment' => 'A comment is required when the counted balance differs from the expected balance.',
            ]);
        }

        $this->ensurePettyCashClosurePeriodIsAvailable($pettyCashAccount, $periodStart, $periodEnd);

        $closure = DB::transaction(function () use ($pettyCashAccount, $accountId, $user, $validated, $periodStart, $periodEnd, $status, $expectedBalance, $countedBalance, $difference) {
            $closure = PettyCashClosure::query()->create([
                'user_id' => $accountId,
                'petty_cash_account_id' => $pettyCashAccount->id,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'expected_balance' => $expectedBalance,
                'counted_balance' => $countedBalance,
                'difference' => $difference,
                'status' => $status,
                'reviewed_by_user_id' => $status === PettyCashClosure::STATUS_IN_REVIEW ? $user?->id : null,
                'closed_by_user_id' => $status === PettyCashClosure::STATUS_CLOSED ? $user?->id : null,
                'closed_at' => $status === PettyCashClosure::STATUS_CLOSED ? now() : null,
                'comment' => $validated['comment'] ?? null,
                'meta' => [
                    'accounting_event' => $status === PettyCashClosure::STATUS_CLOSED
                        ? 'petty_cash_period_closed'
                        : 'petty_cash_closure_submitted',
                ],
            ]);

            ActivityLog::record($user, $closure, $status === PettyCashClosure::STATUS_CLOSED ? 'petty_cash_period_closed' : 'petty_cash_closure_submitted', [
                'period_start' => $closure->period_start?->toDateString(),
                'period_end' => $closure->period_end?->toDateString(),
                'expected_balance' => (float) $closure->expected_balance,
                'counted_balance' => (float) $closure->counted_balance,
                'difference' => (float) $closure->difference,
                'status' => $closure->status,
            ], $status === PettyCashClosure::STATUS_CLOSED ? 'Petty cash period closed' : 'Petty cash closure submitted');

            return $closure;
        });

        return $this->pettyCashPanelResponse($request, 'Petty cash closure saved.', [
            'closure' => $this->presentPettyCashClosure($closure->fresh($this->pettyCashClosureRelations())),
        ], 201);
    }

    public function closePettyCashClosure(Request $request, PettyCashClosure $closure)
    {
        $this->authorize('viewAny', Expense::class);

        $user = $request->user();
        $this->ensurePettyCashClosureBelongsToActor($closure, $user);
        if (! $this->canClosePettyCash($user)) {
            abort(403);
        }

        if ($closure->status === PettyCashClosure::STATUS_CLOSED) {
            throw ValidationException::withMessages([
                'status' => 'This petty cash period is already closed.',
            ]);
        }

        $validated = $request->validate([
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $closure->forceFill([
            'status' => PettyCashClosure::STATUS_CLOSED,
            'closed_by_user_id' => $user?->id,
            'closed_at' => now(),
            'comment' => $validated['comment'] ?? $closure->comment,
            'meta' => array_replace((array) ($closure->meta ?? []), [
                'accounting_event' => 'petty_cash_period_closed',
            ]),
        ])->save();

        ActivityLog::record($user, $closure, 'petty_cash_period_closed', [
            'period_start' => $closure->period_start?->toDateString(),
            'period_end' => $closure->period_end?->toDateString(),
            'difference' => (float) $closure->difference,
        ], 'Petty cash period closed');

        return $this->pettyCashPanelResponse($request, 'Petty cash period closed.', [
            'closure' => $this->presentPettyCashClosure($closure->fresh($this->pettyCashClosureRelations())),
        ]);
    }

    public function reopenPettyCashClosure(Request $request, PettyCashClosure $closure)
    {
        $this->authorize('viewAny', Expense::class);

        $user = $request->user();
        $this->ensurePettyCashClosureBelongsToActor($closure, $user);
        if (! $this->canClosePettyCash($user)) {
            abort(403);
        }

        if ($closure->status !== PettyCashClosure::STATUS_CLOSED) {
            throw ValidationException::withMessages([
                'status' => 'Only closed petty cash periods can be reopened.',
            ]);
        }

        $validated = $request->validate([
            'comment' => ['required', 'string', 'max:2000'],
        ]);

        $closure->forceFill([
            'status' => PettyCashClosure::STATUS_REOPENED,
            'reopened_by_user_id' => $user?->id,
            'reopened_at' => now(),
            'comment' => trim((string) $closure->comment) !== ''
                ? trim((string) $closure->comment)."\n\nReopened: ".$validated['comment']
                : $validated['comment'],
            'meta' => array_replace((array) ($closure->meta ?? []), [
                'reopened_reason' => $validated['comment'],
            ]),
        ])->save();

        ActivityLog::record($user, $closure, 'petty_cash_period_reopened', [
            'period_start' => $closure->period_start?->toDateString(),
            'period_end' => $closure->period_end?->toDateString(),
            'reason' => $validated['comment'],
        ], 'Petty cash period reopened');

        return $this->pettyCashPanelResponse($request, 'Petty cash period reopened.', [
            'closure' => $this->presentPettyCashClosure($closure->fresh($this->pettyCashClosureRelations())),
        ]);
    }

    public function destroy(Request $request, Expense $expense)
    {
        $this->authorize('delete', $expense);

        foreach ($expense->attachments as $attachment) {
            if ($attachment->path && Storage::disk('public')->exists($attachment->path)) {
                Storage::disk('public')->delete($attachment->path);
            }
        }

        $expense->delete();

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Expense deleted successfully.',
            ]);
        }

        return redirect()->back()->with('success', 'Expense deleted successfully.');
    }

    private function applyFilters($query, array $filters)
    {
        return $query
            ->when($filters['search'] ?? null, function ($builder, $search) {
                $builder->where(function ($inner) use ($search) {
                    $inner->where('title', 'like', '%'.$search.'%')
                        ->orWhere('supplier_name', 'like', '%'.$search.'%')
                        ->orWhere('reference_number', 'like', '%'.$search.'%')
                        ->orWhereHas('customer', function ($customerQuery) use ($search) {
                            $customerQuery->where('company_name', 'like', '%'.$search.'%')
                                ->orWhere('first_name', 'like', '%'.$search.'%')
                                ->orWhere('last_name', 'like', '%'.$search.'%');
                        })
                        ->orWhereHas('campaign', fn ($campaignQuery) => $campaignQuery->where('name', 'like', '%'.$search.'%'));
                });
            })
            ->when($filters['status'] ?? null, fn ($builder, $status) => $builder->where('status', $status))
            ->when($filters['category_key'] ?? null, fn ($builder, $category) => $builder->where('category_key', $category))
            ->when($filters['customer_id'] ?? null, fn ($builder, $customerId) => $builder->where('customer_id', $customerId))
            ->when($filters['work_id'] ?? null, fn ($builder, $workId) => $builder->where('work_id', $workId))
            ->when($filters['sale_id'] ?? null, fn ($builder, $saleId) => $builder->where('sale_id', $saleId))
            ->when($filters['invoice_id'] ?? null, fn ($builder, $invoiceId) => $builder->where('invoice_id', $invoiceId))
            ->when($filters['campaign_id'] ?? null, fn ($builder, $campaignId) => $builder->where('campaign_id', $campaignId))
            ->when($filters['quick_filter'] ?? null, function ($builder, $quickFilter) {
                switch ($quickFilter) {
                    case 'submitted':
                        $builder->whereIn('status', [Expense::STATUS_SUBMITTED, Expense::STATUS_PENDING_APPROVAL]);
                        break;
                    case 'due':
                        $builder->where('status', Expense::STATUS_DUE);
                        break;
                    case 'paid':
                        $builder->where('status', Expense::STATUS_PAID);
                        break;
                    case 'overdue':
                        $builder
                            ->whereNotIn('status', [Expense::STATUS_PAID, Expense::STATUS_REIMBURSED, Expense::STATUS_CANCELLED])
                            ->whereNotNull('due_date')
                            ->whereDate('due_date', '<', now()->toDateString());
                        break;
                    case 'reimbursable':
                        $builder->where('reimbursable', true);
                        break;
                    case 'reimbursement_pending':
                        $builder->where('reimbursement_status', Expense::REIMBURSEMENT_STATUS_PENDING);
                        break;
                    case 'recurring':
                        $builder->where('is_recurring', true);
                        break;
                }
            })
            ->when($filters['supplier_name'] ?? null, fn ($builder, $supplier) => $builder->where('supplier_name', 'like', '%'.$supplier.'%'))
            ->when($filters['expense_date_from'] ?? null, fn ($builder, $date) => $builder->whereDate('expense_date', '>=', $date))
            ->when($filters['expense_date_to'] ?? null, fn ($builder, $date) => $builder->whereDate('expense_date', '<=', $date))
            ->when($filters['created_from'] ?? null, fn ($builder, $date) => $builder->whereDate('created_at', '>=', $date))
            ->when($filters['created_to'] ?? null, fn ($builder, $date) => $builder->whereDate('created_at', '<=', $date));
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function buildExpensePayload(
        array $validated,
        int $accountId,
        int $actorId,
        ?Expense $existing = null,
        ?ExpenseRecurringService $recurringService = null,
        ?User $actor = null
    ): array {
        $total = round((float) ($validated['total'] ?? 0), 2);
        $taxAmount = round((float) ($validated['tax_amount'] ?? 0), 2);
        $subtotal = array_key_exists('subtotal', $validated) && $validated['subtotal'] !== null && $validated['subtotal'] !== ''
            ? round((float) $validated['subtotal'], 2)
            : round(max(0, $total - $taxAmount), 2);

        $status = (string) ($validated['status'] ?? ($existing?->status ?: Expense::STATUS_DRAFT));
        $paidDate = $validated['paid_date'] ?? $existing?->paid_date?->toDateString();
        $approvedAt = $existing?->approved_at;
        $approvedByUserId = $existing?->approved_by_user_id;
        $paidByUserId = $existing?->paid_by_user_id;
        $currentApproverRoleKey = $existing?->current_approver_role_key;
        $currentApprovalLevel = $existing?->current_approval_level;
        $reimbursable = (bool) ($validated['reimbursable'] ?? false);
        $teamMemberId = $reimbursable ? ($validated['team_member_id'] ?? null) : null;
        $isRecurring = (bool) ($validated['is_recurring'] ?? false);
        $recurrenceFrequency = $isRecurring ? ($validated['recurrence_frequency'] ?? null) : null;
        $recurrenceInterval = $isRecurring ? max(1, (int) ($validated['recurrence_interval'] ?? ($existing?->recurrence_interval ?? 1))) : 1;
        $lastGeneratedExpenseDate = $existing
            ? $existing->generatedRecurrences()->max('expense_date')
            : null;
        $recurrenceNextDate = $isRecurring && $recurringService
            ? $recurringService->computeNextDate(
                (string) $validated['expense_date'],
                (string) $recurrenceFrequency,
                $recurrenceInterval,
                $lastGeneratedExpenseDate ? (string) $lastGeneratedExpenseDate : null
            )
            : null;
        $reimbursementStatus = $reimbursable
            ? ($existing?->reimbursement_status === Expense::REIMBURSEMENT_STATUS_REIMBURSED
                ? Expense::REIMBURSEMENT_STATUS_REIMBURSED
                : Expense::REIMBURSEMENT_STATUS_PENDING)
            : Expense::REIMBURSEMENT_STATUS_NOT_APPLICABLE;
        $reimbursedAt = $reimbursable ? $existing?->reimbursed_at : null;
        $reimbursedByUserId = $reimbursable ? $existing?->reimbursed_by_user_id : null;
        $reimbursementReference = $reimbursable ? $existing?->reimbursement_reference : null;
        $existingMeta = is_array($existing?->meta) ? $existing->meta : [];
        $approvalPolicySnapshot = data_get($existingMeta, 'approval.policy_snapshot');

        if (! $existing && $actor) {
            $creation = app(FinanceApprovalService::class)->resolveExpenseCreation(
                $actor,
                $total,
                $status,
                $status === Expense::STATUS_REVIEW_REQUIRED
            );
            $status = (string) ($creation['status'] ?? $status);
            $currentApproverRoleKey = $creation['current_approver_role_key'] ?? null;
            $currentApprovalLevel = $creation['current_approval_level'] ?? null;
            $approvalPolicySnapshot = $creation['approval_policy_snapshot'] ?? $approvalPolicySnapshot;
        }

        if ($existing && $actor && ! $actor->isAccountOwner()) {
            $status = $existing->status;
        }

        if (in_array($status, [Expense::STATUS_PAID, Expense::STATUS_REIMBURSED], true) && ! $paidDate) {
            $paidDate = now()->toDateString();
            $paidByUserId = $actorId;
        }

        if (in_array($status, [Expense::STATUS_APPROVED, Expense::STATUS_DUE, Expense::STATUS_PAID, Expense::STATUS_REIMBURSED], true) && ! $approvedAt) {
            $approvedAt = now();
            $approvedByUserId = $actorId;
        }

        $meta = $this->appendWorkflowHistory(
            $existingMeta,
            $existing ? 'manual_update' : 'created',
            $existing?->status,
            $status,
            $actorId
        );
        if ($approvalPolicySnapshot) {
            data_set($meta, 'approval.policy_snapshot', $approvalPolicySnapshot);
        }

        return [
            'user_id' => $accountId,
            'created_by_user_id' => $existing?->created_by_user_id ?: $actorId,
            'approved_by_user_id' => $approvedByUserId,
            'current_approver_role_key' => $currentApproverRoleKey,
            'current_approval_level' => $currentApprovalLevel,
            'paid_by_user_id' => $paidByUserId,
            'reimbursed_by_user_id' => $reimbursedByUserId,
            'team_member_id' => $teamMemberId,
            'customer_id' => $validated['customer_id'] ?? null,
            'work_id' => $validated['work_id'] ?? null,
            'sale_id' => $validated['sale_id'] ?? null,
            'invoice_id' => $validated['invoice_id'] ?? null,
            'campaign_id' => $validated['campaign_id'] ?? null,
            'recurrence_source_expense_id' => $existing?->recurrence_source_expense_id,
            'title' => trim((string) ($validated['title'] ?? '')),
            'category_key' => $validated['category_key'] ?? null,
            'supplier_name' => $validated['supplier_name'] ?? null,
            'reference_number' => $validated['reference_number'] ?? null,
            'currency_code' => CurrencyCode::tryFromMixed($validated['currency_code'] ?? null)?->value
                ?? ($existing?->currency_code ?: (User::query()->whereKey($accountId)->value('currency_code') ?: CurrencyCode::default()->value)),
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'expense_date' => $validated['expense_date'],
            'due_date' => $validated['due_date'] ?? null,
            'paid_date' => $paidDate,
            'approved_at' => $approvedAt,
            'reimbursed_at' => $reimbursedAt,
            'payment_method' => $validated['payment_method'] ?? null,
            'status' => $status,
            'reimbursable' => $reimbursable,
            'reimbursement_status' => $reimbursementStatus,
            'reimbursement_reference' => $reimbursementReference,
            'is_recurring' => $isRecurring && ! $existing?->recurrence_source_expense_id,
            'recurrence_frequency' => $existing?->recurrence_source_expense_id ? null : $recurrenceFrequency,
            'recurrence_interval' => $existing?->recurrence_source_expense_id ? 1 : $recurrenceInterval,
            'recurrence_next_date' => $existing?->recurrence_source_expense_id ? null : $recurrenceNextDate,
            'recurrence_ends_at' => $existing?->recurrence_source_expense_id ? null : ($isRecurring ? ($validated['recurrence_ends_at'] ?? null) : null),
            'recurrence_last_generated_at' => $existing?->recurrence_source_expense_id ? null : $existing?->recurrence_last_generated_at,
            'description' => $validated['description'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'meta' => $meta,
        ];
    }

    private function transitionExpense(
        Request $request,
        Expense $expense,
        string $targetStatus,
        array $allowedFrom,
        string $action,
        string $message,
        array $options = []
    ) {
        $this->authorize('transition', $expense);

        $validated = $request->validate([
            'comment' => 'nullable|string|max:1000',
            'paid_date' => 'nullable|date',
        ]);

        if (! in_array($expense->status, $allowedFrom, true)) {
            throw ValidationException::withMessages([
                'status' => 'This expense cannot move to the requested workflow state.',
            ]);
        }

        if (! empty($options['finance_action'])) {
            $authorization = app(FinanceApprovalService::class)->authorizeExpenseAction(
                $request->user(),
                $expense,
                (string) $options['finance_action']
            );

            if (! ($authorization['allowed'] ?? false)) {
                throw ValidationException::withMessages([
                    'status' => [$authorization['message'] ?? 'You cannot move this expense to the requested workflow state.'],
                ]);
            }
        } else {
            $authorization = [];
        }

        if (in_array($targetStatus, [Expense::STATUS_APPROVED, Expense::STATUS_DUE, Expense::STATUS_PAID], true)
            && blank($expense->category_key)) {
            throw ValidationException::withMessages([
                'category_key' => 'A category is required before approval or payment.',
            ]);
        }

        $actorId = (int) $request->user()->id;
        $fromStatus = (string) $expense->status;
        $updates = [
            'status' => $targetStatus,
            'meta' => $this->appendWorkflowHistory(
                is_array($expense->meta) ? $expense->meta : [],
                $action,
                $fromStatus,
                $targetStatus,
                $actorId,
                $options['comment'] ?? ($validated['comment'] ?? null),
                array_filter([
                    'current_approver_role_key' => $options['current_approver_role_key'] ?? null,
                    'current_approval_level' => $options['current_approval_level'] ?? null,
                    'owner_override' => $options['owner_override'] ?? ($authorization['owner_override'] ?? null),
                ], fn ($value) => $value !== null && $value !== '')
            ),
        ];

        if (array_key_exists('current_approver_role_key', $options)) {
            $updates['current_approver_role_key'] = $options['current_approver_role_key'];
        }

        if (array_key_exists('current_approval_level', $options)) {
            $updates['current_approval_level'] = $options['current_approval_level'];
        }

        if (! empty($options['clear_approver'])) {
            $updates['current_approver_role_key'] = null;
            $updates['current_approval_level'] = null;
        }

        if (! empty($options['approval_policy_snapshot'])) {
            $meta = $updates['meta'];
            data_set($meta, 'approval.policy_snapshot', $options['approval_policy_snapshot']);
            $updates['meta'] = $meta;
        }

        if (in_array($targetStatus, [Expense::STATUS_APPROVED, Expense::STATUS_DUE, Expense::STATUS_PAID], true)
            && ! $expense->approved_at) {
            $updates['approved_at'] = now();
            $updates['approved_by_user_id'] = $actorId;
        }

        if ($targetStatus === Expense::STATUS_PAID) {
            $updates['paid_date'] = $validated['paid_date'] ?? now()->toDateString();
            $updates['paid_by_user_id'] = $actorId;
        }

        $expense->fill($updates)->save();
        $expense->load([
            'creator:id,name',
            'approver:id,name',
            'payer:id,name',
            'reimburser:id,name',
            'teamMember.user:id,name',
            'customer:id,first_name,last_name,company_name',
            'work:id,job_title,number',
            'sale:id,number',
            'invoice:id,number',
            'campaign:id,name',
            'recurrenceSource:id,title',
            'attachments.user:id,name',
        ])->loadCount('generatedRecurrences');
        $expense = $this->presentExpenseDetail($expense, $request->user());
        $this->recordExpenseAuditEvent($request->user(), $expense, $action, [
            'from' => $fromStatus,
            'to' => $expense->status,
            'comment' => $validated['comment'] ?? null,
            'owner_override' => $authorization['owner_override'] ?? false,
        ], ucfirst(str_replace('_', ' ', $action)).' expense');

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => $message,
                'expense' => $expense,
            ]);
        }

        return redirect()->back()->with('success', $message);
    }

    private function presentExpenseSummary(Expense $expense, ?User $actor = null): Expense
    {
        $expense->setAttribute('available_actions', $this->availableWorkflowActions($expense, $actor));
        $expense->setAttribute('ai_review_required', (bool) data_get($expense->meta, 'ai_intake.review_required', false));

        return $expense;
    }

    private function presentExpenseDetail(Expense $expense, ?User $actor = null): Expense
    {
        $expense->setAttribute('available_actions', $this->availableWorkflowActions($expense, $actor));
        $expense->setAttribute('workflow_history', $this->workflowHistory($expense));
        $expense->setAttribute('ai_intake', $this->aiIntake($expense));

        return $expense;
    }

    /**
     * @return array<int, string>
     */
    private function availableWorkflowActions(Expense $expense, ?User $actor = null): array
    {
        $hasCategory = filled($expense->category_key);

        $actions = match ($expense->status) {
            Expense::STATUS_DRAFT, Expense::STATUS_REVIEW_REQUIRED, Expense::STATUS_REJECTED => ['submit', 'cancel'],
            Expense::STATUS_SUBMITTED, Expense::STATUS_PENDING_APPROVAL => $hasCategory ? ['approve', 'reject', 'cancel'] : ['cancel'],
            Expense::STATUS_APPROVED => $hasCategory ? $this->appendReimbursementAction($expense, ['mark_due', 'mark_paid', 'cancel']) : ['cancel'],
            Expense::STATUS_DUE => $hasCategory ? $this->appendReimbursementAction($expense, ['mark_paid', 'cancel']) : ['cancel'],
            Expense::STATUS_PAID => $this->appendReimbursementAction($expense, []),
            default => [],
        };

        if (! $actor) {
            return $actions;
        }

        return array_values(array_filter($actions, function (string $action) use ($actor, $expense): bool {
            $authorization = app(FinanceApprovalService::class)->authorizeExpenseAction($actor, $expense, $action);

            return (bool) ($authorization['allowed'] ?? false);
        }));
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function appendWorkflowHistory(
        array $meta,
        string $action,
        ?string $fromStatus,
        ?string $toStatus,
        int $actorId,
        ?string $comment = null,
        ?array $extra = null
    ): array {
        $history = collect($meta['workflow_history'] ?? [])
            ->filter(fn ($entry) => is_array($entry))
            ->values()
            ->all();

        if ($action === 'manual_update' && $fromStatus === $toStatus) {
            $meta['workflow_history'] = $history;

            return $meta;
        }

        $history[] = array_filter(array_merge([
            'action' => $action,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_id' => $actorId,
            'actor_name' => User::query()->whereKey($actorId)->value('name'),
            'comment' => $comment ? trim($comment) : null,
            'at' => now()->toIso8601String(),
        ], $extra ?? []), fn ($value) => $value !== null && $value !== '');

        $meta['workflow_history'] = array_values($history);

        return $meta;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function workflowHistory(Expense $expense): array
    {
        return collect($expense->meta['workflow_history'] ?? [])
            ->filter(fn ($entry) => is_array($entry))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function aiIntake(Expense $expense): ?array
    {
        $aiIntake = data_get($expense->meta, 'ai_intake');

        return is_array($aiIntake) ? $aiIntake : null;
    }

    /**
     * @return array<int, string>
     */
    private function appendReimbursementAction(Expense $expense, array $actions): array
    {
        if (! $expense->reimbursable || $expense->reimbursement_status === Expense::REIMBURSEMENT_STATUS_REIMBURSED) {
            return $actions;
        }

        array_splice($actions, max(count($actions) - 1, 0), 0, 'mark_reimbursed');

        return array_values(array_unique($actions));
    }

    /**
     * @return array<int, array{id:int,name:string,role:?string,title:?string}>
     */
    private function teamMemberOptions(?User $user, int $accountId): array
    {
        if (! $user || ! $user->hasCompanyFeature('team_members') || $accountId <= 0) {
            return [];
        }

        return TeamMember::query()
            ->forAccount($accountId)
            ->active()
            ->with('user:id,name')
            ->orderBy('created_at')
            ->get()
            ->map(fn (TeamMember $member) => [
                'id' => (int) $member->id,
                'name' => (string) ($member->user?->name ?: 'Member'),
                'role' => $member->role,
                'title' => $member->title,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{
     *   customers: array<int, array{id:int,name:string}>,
     *   works: array<int, array{id:int,name:string}>,
     *   sales: array<int, array{id:int,name:string}>,
     *   invoices: array<int, array{id:int,name:string}>,
     *   campaigns: array<int, array{id:int,name:string}>
     * }
     */
    private function expenseLinkOptions(?User $user, int $accountId): array
    {
        if (! $user || $accountId <= 0) {
            return [
                'customers' => [],
                'works' => [],
                'sales' => [],
                'invoices' => [],
                'campaigns' => [],
            ];
        }

        $customers = Customer::query()
            ->where('user_id', $accountId)
            ->orderByDesc('created_at')
            ->limit(200)
            ->get(['id', 'company_name', 'first_name', 'last_name'])
            ->map(fn (Customer $customer) => [
                'id' => (int) $customer->id,
                'name' => $this->customerDisplayName($customer),
            ])
            ->values()
            ->all();

        $works = $user->hasCompanyFeature('jobs')
            ? Work::query()
                ->where('user_id', $accountId)
                ->orderByDesc('created_at')
                ->limit(200)
                ->get(['id', 'number', 'job_title'])
                ->map(fn (Work $work) => [
                    'id' => (int) $work->id,
                    'name' => trim(($work->number ? $work->number.' - ' : '').($work->job_title ?: 'Work')),
                ])
                ->values()
                ->all()
            : [];

        $sales = $user->hasCompanyFeature('sales')
            ? Sale::query()
                ->where('user_id', $accountId)
                ->orderByDesc('created_at')
                ->limit(200)
                ->get(['id', 'number'])
                ->map(fn (Sale $sale) => [
                    'id' => (int) $sale->id,
                    'name' => (string) ($sale->number ?: 'Sale #'.$sale->id),
                ])
                ->values()
                ->all()
            : [];

        $invoices = Invoice::query()
            ->when($user->hasCompanyFeature('invoices'), function ($query) use ($accountId) {
                $query->where('user_id', $accountId);
            }, fn ($query) => $query->whereRaw('1 = 0'))
            ->orderByDesc('created_at')
            ->limit(200)
            ->get(['id', 'number'])
            ->map(fn (Invoice $invoice) => [
                'id' => (int) $invoice->id,
                'name' => (string) ($invoice->number ?: 'Invoice #'.$invoice->id),
            ])
            ->values()
            ->all();

        $campaigns = $user->hasCompanyFeature('campaigns')
            ? Campaign::query()
                ->where('user_id', $accountId)
                ->orderByDesc('created_at')
                ->limit(200)
                ->get(['id', 'name'])
                ->map(fn (Campaign $campaign) => [
                    'id' => (int) $campaign->id,
                    'name' => (string) $campaign->name,
                ])
                ->values()
                ->all()
            : [];

        return [
            'customers' => $customers,
            'works' => $works,
            'sales' => $sales,
            'invoices' => $invoices,
            'campaigns' => $campaigns,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<int, array<string, mixed>>  $teamMembers
     * @return array<string, mixed>
     */
    private function buildPettyCashPanel(?User $user, int $accountId, array $filters, string $currencyCode, array $teamMembers): array
    {
        if (! $user || $accountId <= 0) {
            return [
                'account' => null,
                'stats' => [],
                'filters' => [],
                'movements' => [],
                'movementLinks' => [],
                'movementCount' => 0,
                'closures' => [],
                'reconciliation' => [],
                'types' => PettyCashMovement::TYPES,
                'statuses' => PettyCashMovement::STATUSES,
                'closureStatuses' => PettyCashClosure::STATUSES,
                'responsibleOptions' => [],
                'expenseOptions' => [],
                'canCreate' => false,
                'canPost' => false,
                'canManage' => false,
                'canClose' => false,
                'canAdjust' => false,
            ];
        }

        $account = $this->resolvePettyCashAccount($accountId, $currencyCode, $user);
        $movementQuery = $this->applyPettyCashFilters(
            PettyCashMovement::query()
                ->where('petty_cash_account_id', $account->id)
                ->with($this->pettyCashMovementRelations())
                ->withCount('attachments'),
            $filters
        );
        $periodStart = $this->parseDateOrNull($filters['petty_from'] ?? null)?->startOfDay()
            ?: now()->startOfMonth();
        $periodEnd = $this->parseDateOrNull($filters['petty_to'] ?? null)?->endOfDay()
            ?: now()->endOfMonth();
        if ($periodEnd->lt($periodStart)) {
            [$periodStart, $periodEnd] = [$periodEnd->copy()->startOfDay(), $periodStart->copy()->endOfDay()];
        }

        $periodPosted = PettyCashMovement::query()
            ->where('petty_cash_account_id', $account->id)
            ->where('status', PettyCashMovement::STATUS_POSTED)
            ->whereBetween('movement_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->get();
        $periodInflows = $periodPosted
            ->sum(fn (PettyCashMovement $movement) => max(0, $this->pettyCashMovementDelta($movement)));
        $periodOutflows = abs($periodPosted
            ->sum(fn (PettyCashMovement $movement) => min(0, $this->pettyCashMovementDelta($movement))));
        $reconciliation = $this->buildPettyCashReconciliation($account, $periodStart, $periodEnd);
        $movementPaginator = (clone $movementQuery)
            ->orderByDesc('movement_date')
            ->orderByDesc('id')
            ->paginate(20, ['*'], 'petty_page')
            ->withQueryString();
        $movementRows = $movementPaginator->getCollection()
            ->map(fn (PettyCashMovement $movement) => $this->presentPettyCashMovement($movement, $user))
            ->values()
            ->all();

        return [
            'account' => $this->presentPettyCashAccount($account->fresh('responsible')),
            'stats' => [
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'period_inflows' => round((float) $periodInflows, 2),
                'period_outflows' => round((float) $periodOutflows, 2),
                'draft_count' => (int) PettyCashMovement::query()
                    ->where('petty_cash_account_id', $account->id)
                    ->where('status', PettyCashMovement::STATUS_DRAFT)
                    ->count(),
                'posted_count' => (int) PettyCashMovement::query()
                    ->where('petty_cash_account_id', $account->id)
                    ->where('status', PettyCashMovement::STATUS_POSTED)
                    ->count(),
                'voided_count' => (int) PettyCashMovement::query()
                    ->where('petty_cash_account_id', $account->id)
                    ->where('status', PettyCashMovement::STATUS_VOIDED)
                    ->count(),
                'missing_receipt_count' => (int) PettyCashMovement::query()
                    ->where('petty_cash_account_id', $account->id)
                    ->where('requires_receipt', true)
                    ->where('receipt_attached', false)
                    ->where('status', '!=', PettyCashMovement::STATUS_VOIDED)
                    ->count(),
                'unlinked_expense_count' => (int) PettyCashMovement::query()
                    ->where('petty_cash_account_id', $account->id)
                    ->whereIn('type', [PettyCashMovement::TYPE_EXPENSE, PettyCashMovement::TYPE_ADVANCE])
                    ->whereNull('expense_id')
                    ->where('status', '!=', PettyCashMovement::STATUS_VOIDED)
                    ->count(),
                'low_balance' => (float) $account->low_balance_threshold > 0
                    && (float) $account->current_balance <= (float) $account->low_balance_threshold,
            ],
            'reconciliation' => $reconciliation,
            'filters' => [
                'petty_type' => $filters['petty_type'] ?? '',
                'petty_status' => $filters['petty_status'] ?? '',
                'petty_responsible_user_id' => $filters['petty_responsible_user_id'] ?? '',
                'petty_from' => $filters['petty_from'] ?? '',
                'petty_to' => $filters['petty_to'] ?? '',
            ],
            'movements' => $movementRows,
            'movementLinks' => $movementPaginator->linkCollection()->toArray(),
            'movementCount' => (int) $movementPaginator->total(),
            'closures' => PettyCashClosure::query()
                ->where('petty_cash_account_id', $account->id)
                ->with($this->pettyCashClosureRelations())
                ->orderByDesc('period_end')
                ->orderByDesc('id')
                ->limit(8)
                ->get()
                ->map(fn (PettyCashClosure $closure) => $this->presentPettyCashClosure($closure))
                ->values()
                ->all(),
            'types' => PettyCashMovement::TYPES,
            'statuses' => PettyCashMovement::STATUSES,
            'closureStatuses' => PettyCashClosure::STATUSES,
            'responsibleOptions' => $this->pettyCashResponsibleOptions($user, $accountId),
            'expenseOptions' => $this->pettyCashExpenseOptions($accountId),
            'teamMemberOptions' => $teamMembers,
            'canCreate' => $this->canCreatePettyCashMovement($user),
            'canPost' => $this->canPostPettyCashMovement($user),
            'canManage' => $this->canManagePettyCash($user),
            'canClose' => $this->canClosePettyCash($user),
            'canAdjust' => $this->canAdjustPettyCash($user),
        ];
    }

    private function applyPettyCashFilters($query, array $filters)
    {
        return $query
            ->when($filters['petty_type'] ?? null, fn ($builder, $type) => $builder->where('type', $type))
            ->when($filters['petty_status'] ?? null, fn ($builder, $status) => $builder->where('status', $status))
            ->when($filters['petty_responsible_user_id'] ?? null, fn ($builder, $responsibleId) => $builder->where('responsible_user_id', $responsibleId))
            ->when($filters['petty_from'] ?? null, fn ($builder, $date) => $builder->whereDate('movement_date', '>=', $date))
            ->when($filters['petty_to'] ?? null, fn ($builder, $date) => $builder->whereDate('movement_date', '<=', $date));
    }

    private function resolvePettyCashAccount(int $accountId, string $currencyCode, ?User $actor = null): PettyCashAccount
    {
        $currencyCode = strtoupper((string) ($currencyCode ?: CurrencyCode::default()->value));

        return PettyCashAccount::query()->firstOrCreate(
            [
                'user_id' => $accountId,
                'currency_code' => $currencyCode,
            ],
            [
                'responsible_user_id' => $actor?->id,
                'name' => 'Petite caisse',
                'opening_balance' => 0,
                'current_balance' => 0,
                'low_balance_threshold' => 0,
                'receipt_required_above' => 0,
                'is_active' => true,
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function presentPettyCashAccount(PettyCashAccount $account): array
    {
        return [
            'id' => (int) $account->id,
            'name' => (string) $account->name,
            'currency_code' => strtoupper((string) $account->currency_code),
            'opening_balance' => round((float) $account->opening_balance, 2),
            'current_balance' => round((float) $account->current_balance, 2),
            'low_balance_threshold' => round((float) $account->low_balance_threshold, 2),
            'receipt_required_above' => round((float) $account->receipt_required_above, 2),
            'responsible_user_id' => $account->responsible_user_id ? (int) $account->responsible_user_id : null,
            'responsible_name' => $account->responsible?->name,
            'is_active' => (bool) $account->is_active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentPettyCashMovement(PettyCashMovement $movement, ?User $actor = null): array
    {
        $movement->loadMissing($this->pettyCashMovementRelations());
        if (! array_key_exists('attachments_count', $movement->getAttributes())) {
            $movement->loadCount('attachments');
        }

        return [
            'id' => (int) $movement->id,
            'type' => (string) $movement->type,
            'status' => (string) $movement->status,
            'amount' => round((float) $movement->amount, 2),
            'balance_delta' => $this->pettyCashMovementDelta($movement),
            'currency_code' => strtoupper((string) $movement->currency_code),
            'movement_date' => optional($movement->movement_date)->toDateString(),
            'note' => $movement->note,
            'requires_receipt' => (bool) $movement->requires_receipt,
            'receipt_attached' => (bool) $movement->receipt_attached,
            'attachments_count' => (int) ($movement->attachments_count ?? 0),
            'posted_at' => optional($movement->posted_at)->toDateTimeString(),
            'voided_at' => optional($movement->voided_at)->toDateTimeString(),
            'void_reason' => $movement->void_reason,
            'locked_by_closure' => $this->pettyCashMovementIsLocked($movement),
            'accounting_event' => $this->pettyCashAccountingEvent($movement),
            'responsible' => $movement->responsible ? [
                'id' => (int) $movement->responsible->id,
                'name' => (string) $movement->responsible->name,
            ] : null,
            'creator' => $movement->creator ? [
                'id' => (int) $movement->creator->id,
                'name' => (string) $movement->creator->name,
            ] : null,
            'team_member' => $movement->teamMember ? [
                'id' => (int) $movement->teamMember->id,
                'name' => (string) ($movement->teamMember->user?->name ?: $movement->teamMember->title ?: 'Member'),
            ] : null,
            'expense' => $movement->expense ? [
                'id' => (int) $movement->expense->id,
                'title' => (string) $movement->expense->title,
                'total' => round((float) $movement->expense->total, 2),
                'expense_date' => optional($movement->expense->expense_date)->toDateString(),
            ] : null,
            'available_actions' => $this->pettyCashMovementActions($movement, $actor),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function pettyCashMovementActions(PettyCashMovement $movement, ?User $actor): array
    {
        if (! $actor || ! $this->canPostPettyCashMovement($actor)) {
            return [];
        }

        if ($this->pettyCashMovementIsLocked($movement)) {
            return [];
        }

        if ($movement->type === PettyCashMovement::TYPE_ADJUSTMENT && ! $this->canAdjustPettyCash($actor)) {
            return [];
        }

        return match ($movement->status) {
            PettyCashMovement::STATUS_DRAFT => ['post', 'void'],
            PettyCashMovement::STATUS_POSTED => ['void'],
            default => [],
        };
    }

    /**
     * @return array<int, string>
     */
    private function pettyCashMovementRelations(): array
    {
        return [
            'creator:id,name',
            'responsible:id,name',
            'teamMember.user:id,name',
            'expense:id,title,total,expense_date',
            'account:id,user_id,currency_code,opening_balance,current_balance',
        ];
    }

    /**
     * @return array<int, array{id:int,name:string}>
     */
    private function pettyCashResponsibleOptions(?User $user, int $accountId): array
    {
        $owner = User::query()->find($accountId);
        $options = collect();

        if ($owner) {
            $options->push([
                'id' => (int) $owner->id,
                'name' => (string) $owner->name,
            ]);
        }

        if ($user?->hasCompanyFeature('team_members')) {
            TeamMember::query()
                ->forAccount($accountId)
                ->active()
                ->with('user:id,name')
                ->get()
                ->each(function (TeamMember $member) use ($options) {
                    if ($member->user) {
                        $options->push([
                            'id' => (int) $member->user->id,
                            'name' => (string) $member->user->name,
                        ]);
                    }
                });
        }

        return $options
            ->unique('id')
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id:int,name:string}>
     */
    private function pettyCashExpenseOptions(int $accountId): array
    {
        return Expense::query()
            ->byAccount($accountId)
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->limit(100)
            ->get(['id', 'title', 'total', 'expense_date'])
            ->map(function (Expense $expense) {
                $expenseDate = $expense->expense_date?->toDateString();

                return [
                    'id' => (int) $expense->id,
                    'name' => trim($expense->title.' - '.$expenseDate.' - '.number_format((float) $expense->total, 2)),
                ];
            })
            ->values()
            ->all();
    }

    private function canCreatePettyCashMovement(?User $user): bool
    {
        if (! $user || ! $user->hasCompanyFeature('expenses')) {
            return false;
        }

        if ($user->isAccountOwner() && (int) $user->id === (int) $user->accountOwnerId()) {
            return true;
        }

        $membership = $this->expenseActorMembership($user);

        return (bool) $membership && (
            $membership->hasPermission('expenses.create')
            || $membership->hasPermission('expenses.edit')
            || $membership->hasPermission('expenses.pay')
        );
    }

    private function canPostPettyCashMovement(?User $user): bool
    {
        if (! $user || ! $user->hasCompanyFeature('expenses')) {
            return false;
        }

        if ($user->isAccountOwner() && (int) $user->id === (int) $user->accountOwnerId()) {
            return true;
        }

        $membership = $this->expenseActorMembership($user);

        return (bool) $membership && (
            $membership->hasPermission('expenses.pay')
            || $membership->hasPermission('expenses.approve_high')
        );
    }

    private function canManagePettyCash(?User $user): bool
    {
        if (! $user || ! $user->hasCompanyFeature('expenses')) {
            return false;
        }

        if ($user->isAccountOwner() && (int) $user->id === (int) $user->accountOwnerId()) {
            return true;
        }

        $membership = $this->expenseActorMembership($user);

        return (bool) $membership && (
            $membership->hasPermission('expenses.pay')
            || $membership->hasPermission('expenses.approve_high')
        );
    }

    private function canClosePettyCash(?User $user): bool
    {
        if (! $user || ! $user->hasCompanyFeature('expenses')) {
            return false;
        }

        if ($user->isAccountOwner() && (int) $user->id === (int) $user->accountOwnerId()) {
            return true;
        }

        $membership = $this->expenseActorMembership($user);

        return (bool) $membership && (
            $membership->hasPermission('expenses.approve')
            || $membership->hasPermission('expenses.approve_high')
            || $membership->hasPermission('expenses.pay')
        );
    }

    private function canAdjustPettyCash(?User $user): bool
    {
        if (! $user || ! $user->hasCompanyFeature('expenses')) {
            return false;
        }

        if ($user->isAccountOwner() && (int) $user->id === (int) $user->accountOwnerId()) {
            return true;
        }

        $membership = $this->expenseActorMembership($user);

        return (bool) $membership && $membership->hasPermission('expenses.approve_high');
    }

    private function expenseActorMembership(User $user): ?TeamMember
    {
        return $user->relationLoaded('teamMembership')
            ? $user->teamMembership
            : $user->teamMembership()->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePettyCashMovement(Request $request, int $accountId, PettyCashAccount $account): array
    {
        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(PettyCashMovement::TYPES)],
            'status' => ['nullable', 'string', Rule::in([PettyCashMovement::STATUS_DRAFT, PettyCashMovement::STATUS_POSTED])],
            'amount' => [
                'required',
                'numeric',
                function (string $attribute, mixed $value, \Closure $fail) use ($request): void {
                    $numeric = (float) $value;
                    $type = (string) $request->input('type');

                    if ($type === PettyCashMovement::TYPE_ADJUSTMENT) {
                        if (abs($numeric) < 0.01) {
                            $fail('Adjustment amount must be different from zero.');
                        }

                        return;
                    }

                    if ($numeric <= 0) {
                        $fail('Amount must be greater than zero.');
                    }
                },
            ],
            'movement_date' => ['required', 'date'],
            'responsible_user_id' => ['required', 'integer'],
            'team_member_id' => ['nullable', 'integer'],
            'expense_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:2000'],
            'requires_receipt' => ['nullable', 'boolean'],
            'receipt_attached' => ['nullable', 'boolean'],
            'receipt' => ['nullable', 'file', 'mimes:pdf,png,jpg,jpeg,webp', 'max:10240'],
        ]);

        $this->ensureResponsibleBelongsToAccount((int) $validated['responsible_user_id'], $accountId);

        if (($validated['type'] ?? null) === PettyCashMovement::TYPE_ADJUSTMENT && trim((string) ($validated['note'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'note' => 'A comment is required for petty cash adjustments.',
            ]);
        }

        if (! empty($validated['team_member_id'])) {
            $this->ensureTeamMemberBelongsToAccount((int) $validated['team_member_id'], $accountId);
        }

        if (! empty($validated['expense_id'])) {
            $this->ensureExpenseBelongsToAccount((int) $validated['expense_id'], $accountId);
        }

        $requiresReceipt = $this->pettyCashMovementRequiresReceipt(
            $account,
            (string) $validated['type'],
            (float) $validated['amount'],
            (bool) ($validated['requires_receipt'] ?? false)
        );
        if ($requiresReceipt) {
            $validated['requires_receipt'] = true;
        }

        return $validated;
    }

    private function ensureResponsibleBelongsToAccount(int $responsibleUserId, int $accountId): void
    {
        if ($responsibleUserId === $accountId) {
            return;
        }

        $exists = TeamMember::query()
            ->where('account_id', $accountId)
            ->where('user_id', $responsibleUserId)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'responsible_user_id' => 'The selected responsible user is not part of this account.',
            ]);
        }
    }

    private function ensureTeamMemberBelongsToAccount(int $teamMemberId, int $accountId): void
    {
        $exists = TeamMember::query()
            ->where('account_id', $accountId)
            ->whereKey($teamMemberId)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'team_member_id' => 'The selected team member is not part of this account.',
            ]);
        }
    }

    private function ensureExpenseBelongsToAccount(int $expenseId, int $accountId): void
    {
        $exists = Expense::query()
            ->byAccount($accountId)
            ->whereKey($expenseId)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'expense_id' => 'The selected expense is not part of this account.',
            ]);
        }
    }

    private function ensurePettyCashMovementBelongsToActor(PettyCashMovement $movement, ?User $user): void
    {
        if (! $user || (int) $movement->user_id !== (int) $user->accountOwnerId()) {
            abort(404);
        }
    }

    private function ensurePettyCashClosureBelongsToActor(PettyCashClosure $closure, ?User $user): void
    {
        if (! $user || (int) $closure->user_id !== (int) $user->accountOwnerId()) {
            abort(404);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePettyCashClosure(Request $request): array
    {
        return $request->validate([
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date'],
            'counted_balance' => ['required', 'numeric', 'min:-999999999.99', 'max:999999999.99'],
            'status' => ['nullable', 'string', Rule::in([PettyCashClosure::STATUS_IN_REVIEW, PettyCashClosure::STATUS_CLOSED])],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function normalizePettyCashClosurePeriod(mixed $periodStart, mixed $periodEnd): array
    {
        $start = Carbon::parse((string) $periodStart)->startOfDay();
        $end = Carbon::parse((string) $periodEnd)->startOfDay();

        if ($end->lt($start)) {
            [$start, $end] = [$end, $start];
        }

        return [$start, $end];
    }

    private function ensurePettyCashClosurePeriodIsAvailable(PettyCashAccount $account, Carbon $periodStart, Carbon $periodEnd): void
    {
        $overlap = PettyCashClosure::query()
            ->where('petty_cash_account_id', $account->id)
            ->whereIn('status', [PettyCashClosure::STATUS_IN_REVIEW, PettyCashClosure::STATUS_CLOSED])
            ->whereDate('period_start', '<=', $periodEnd->toDateString())
            ->whereDate('period_end', '>=', $periodStart->toDateString())
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages([
                'period_start' => 'This petty cash period already has an active closure.',
            ]);
        }
    }

    private function ensurePettyCashDateIsOpen(PettyCashAccount $account, mixed $date): void
    {
        if (! $date) {
            return;
        }

        $movementDate = $date instanceof Carbon
            ? $date->copy()->startOfDay()
            : Carbon::parse((string) $date)->startOfDay();

        if ($this->pettyCashClosedClosureForDate($account, $movementDate)) {
            throw ValidationException::withMessages([
                'movement_date' => 'This petty cash period is closed. Reopen the closure before changing movements in this period.',
            ]);
        }
    }

    private function pettyCashMovementIsLocked(PettyCashMovement $movement): bool
    {
        if (! $movement->movement_date) {
            return false;
        }

        $account = $movement->relationLoaded('account')
            ? $movement->account
            : $movement->account()->first();

        return $account
            ? (bool) $this->pettyCashClosedClosureForDate($account, $movement->movement_date)
            : false;
    }

    private function pettyCashClosedClosureForDate(PettyCashAccount $account, mixed $date): ?PettyCashClosure
    {
        $cashDate = $date instanceof Carbon
            ? $date->copy()->toDateString()
            : Carbon::parse((string) $date)->toDateString();

        return PettyCashClosure::query()
            ->where('petty_cash_account_id', $account->id)
            ->where('status', PettyCashClosure::STATUS_CLOSED)
            ->whereDate('period_start', '<=', $cashDate)
            ->whereDate('period_end', '>=', $cashDate)
            ->latest('id')
            ->first();
    }

    private function pettyCashExpectedBalanceAt(PettyCashAccount $account, Carbon $periodEnd): float
    {
        $postedMovements = PettyCashMovement::query()
            ->where('petty_cash_account_id', $account->id)
            ->where('status', PettyCashMovement::STATUS_POSTED)
            ->whereDate('movement_date', '<=', $periodEnd->toDateString())
            ->get();
        $delta = $postedMovements->sum(fn (PettyCashMovement $movement) => $this->pettyCashMovementDelta($movement));

        return round((float) $account->opening_balance + (float) $delta, 2);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPettyCashReconciliation(PettyCashAccount $account, Carbon $periodStart, Carbon $periodEnd): array
    {
        $movements = PettyCashMovement::query()
            ->where('petty_cash_account_id', $account->id)
            ->where('status', '!=', PettyCashMovement::STATUS_VOIDED)
            ->whereBetween('movement_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->withCount('attachments')
            ->get();
        $latestClosure = PettyCashClosure::query()
            ->where('petty_cash_account_id', $account->id)
            ->whereDate('period_start', '<=', $periodEnd->toDateString())
            ->whereDate('period_end', '>=', $periodStart->toDateString())
            ->whereIn('status', [PettyCashClosure::STATUS_IN_REVIEW, PettyCashClosure::STATUS_CLOSED])
            ->with($this->pettyCashClosureRelations())
            ->orderByRaw("CASE WHEN status = ? THEN 0 ELSE 1 END", [PettyCashClosure::STATUS_CLOSED])
            ->orderByDesc('period_end')
            ->orderByDesc('id')
            ->first();
        $missingReceiptCount = $movements
            ->filter(fn (PettyCashMovement $movement) => (bool) $movement->requires_receipt && ! (bool) $movement->receipt_attached)
            ->count();
        $unlinkedCount = $movements
            ->filter(fn (PettyCashMovement $movement) => in_array($movement->type, [PettyCashMovement::TYPE_EXPENSE, PettyCashMovement::TYPE_ADVANCE], true) && ! $movement->expense_id)
            ->count();

        return [
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'movement_count' => $movements->count(),
            'justified_count' => $movements->count() - $missingReceiptCount,
            'missing_receipt_count' => $missingReceiptCount,
            'unlinked_expense_count' => $unlinkedCount,
            'expected_balance' => $this->pettyCashExpectedBalanceAt($account, $periodEnd),
            'counted_balance' => $latestClosure ? round((float) $latestClosure->counted_balance, 2) : null,
            'difference' => $latestClosure ? round((float) $latestClosure->difference, 2) : null,
            'closure' => $latestClosure ? $this->presentPettyCashClosure($latestClosure) : null,
        ];
    }

    private function pettyCashTypeRequiresReceipt(string $type): bool
    {
        return in_array($type, [PettyCashMovement::TYPE_EXPENSE, PettyCashMovement::TYPE_ADVANCE], true);
    }

    private function pettyCashMovementRequiresReceipt(PettyCashAccount $account, string $type, float $amount, bool $requested): bool
    {
        $threshold = round((float) $account->receipt_required_above, 2);

        return $requested
            || $this->pettyCashTypeRequiresReceipt($type)
            || ($threshold > 0 && abs($amount) >= $threshold);
    }

    private function pettyCashReceiptIsMandatory(PettyCashAccount $account, float $amount): bool
    {
        $threshold = round((float) $account->receipt_required_above, 2);

        return $threshold > 0 && abs($amount) >= $threshold;
    }

    private function ensurePettyCashReceiptRequirementIsMet(Request $request, bool $receiptMandatory, string $status, bool $receiptAttached): void
    {
        if ($status !== PettyCashMovement::STATUS_POSTED || ! $receiptMandatory) {
            return;
        }

        if ($receiptAttached || $request->hasFile('receipt')) {
            return;
        }

        throw ValidationException::withMessages([
            'receipt' => 'A receipt is required before posting this petty cash movement.',
        ]);
    }

    private function pettyCashMovementDelta(PettyCashMovement $movement): float
    {
        $amount = round((float) $movement->amount, 2);

        return match ($movement->type) {
            PettyCashMovement::TYPE_FUNDING,
            PettyCashMovement::TYPE_REIMBURSEMENT => abs($amount),
            PettyCashMovement::TYPE_EXPENSE,
            PettyCashMovement::TYPE_ADVANCE => -abs($amount),
            PettyCashMovement::TYPE_ADJUSTMENT => $amount,
            default => 0.0,
        };
    }

    private function pettyCashAccountingEvent(PettyCashMovement $movement): ?string
    {
        if ($movement->status !== PettyCashMovement::STATUS_POSTED) {
            return null;
        }

        return $this->pettyCashAccountingEventForType((string) $movement->type);
    }

    private function pettyCashAccountingEventForType(string $type): ?string
    {
        return match ($type) {
            PettyCashMovement::TYPE_FUNDING => 'petty_cash_funded',
            PettyCashMovement::TYPE_EXPENSE => 'petty_cash_expense_posted',
            PettyCashMovement::TYPE_ADVANCE => 'petty_cash_advance_posted',
            PettyCashMovement::TYPE_REIMBURSEMENT => 'petty_cash_reimbursement_posted',
            PettyCashMovement::TYPE_ADJUSTMENT => 'petty_cash_adjustment_posted',
            default => null,
        };
    }

    private function recalculatePettyCashBalance(PettyCashAccount $account): PettyCashAccount
    {
        $postedMovements = PettyCashMovement::query()
            ->where('petty_cash_account_id', $account->id)
            ->where('status', PettyCashMovement::STATUS_POSTED)
            ->get();
        $delta = $postedMovements->sum(fn (PettyCashMovement $movement) => $this->pettyCashMovementDelta($movement));
        $account->forceFill([
            'current_balance' => round((float) $account->opening_balance + (float) $delta, 2),
        ])->save();

        return $account->refresh();
    }

    private function storePettyCashAttachment(mixed $file, PettyCashMovement $movement, int $userId): void
    {
        if (! $file instanceof UploadedFile) {
            return;
        }

        $mime = $file->getClientMimeType() ?? $file->getMimeType();
        $path = str_starts_with((string) $mime, 'image/')
            ? FileHandler::storeFile('petty-cash-attachments', $file)
            : $file->store('petty-cash-attachments', 'public');

        PettyCashAttachment::query()->create([
            'user_id' => (int) $movement->user_id,
            'petty_cash_movement_id' => $movement->id,
            'uploaded_by_user_id' => $userId,
            'disk' => 'public',
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $mime,
            'size' => $file->getSize(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function createPettyCashMovementFromManualExpense(Request $request, Expense $expense, array $validated): PettyCashMovement
    {
        $user = $request->user();
        $accountId = (int) ($user?->accountOwnerId() ?? 0);
        if (! $this->canCreatePettyCashMovement($user)) {
            abort(403);
        }

        $owner = $accountId > 0 ? User::query()->find($accountId) : null;
        $pettyCashAccount = $this->resolvePettyCashAccount(
            $accountId,
            $owner?->businessCurrencyCode() ?? CurrencyCode::default()->value,
            $user
        );
        $status = (string) ($validated['petty_cash_status'] ?? PettyCashMovement::STATUS_DRAFT);
        if ($status === PettyCashMovement::STATUS_POSTED && ! $this->canPostPettyCashMovement($user)) {
            abort(403);
        }

        $amount = round((float) $expense->total, 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'petty_cash_create' => 'The expense amount must be greater than zero to create a petty cash movement.',
            ]);
        }

        $movementDate = $expense->expense_date
            ? $expense->expense_date->copy()->startOfDay()
            : now()->startOfDay();
        $this->ensurePettyCashDateIsOpen($pettyCashAccount, $movementDate);

        $responsibleUserId = (int) ($validated['petty_cash_responsible_user_id']
            ?? $pettyCashAccount->responsible_user_id
            ?? $user?->id);
        $this->ensureResponsibleBelongsToAccount($responsibleUserId, $accountId);

        $expense->loadMissing('attachments');
        $receiptAttached = $expense->attachments->isNotEmpty();
        $requiresReceipt = $this->pettyCashMovementRequiresReceipt(
            $pettyCashAccount,
            PettyCashMovement::TYPE_EXPENSE,
            $amount,
            true
        );
        $receiptMandatory = $this->pettyCashReceiptIsMandatory($pettyCashAccount, $amount);
        $this->ensurePettyCashReceiptRequirementIsMet($request, $receiptMandatory, $status, $receiptAttached);

        $note = trim((string) ($validated['petty_cash_note'] ?? ''));
        if ($note === '') {
            $note = trim('Expense: '.(string) $expense->title);
        }

        $movement = PettyCashMovement::query()->create([
            'user_id' => $accountId,
            'petty_cash_account_id' => $pettyCashAccount->id,
            'expense_id' => $expense->id,
            'team_member_id' => $expense->team_member_id,
            'created_by_user_id' => $user?->id,
            'responsible_user_id' => $responsibleUserId,
            'type' => PettyCashMovement::TYPE_EXPENSE,
            'status' => $status,
            'amount' => $amount,
            'currency_code' => $pettyCashAccount->currency_code,
            'movement_date' => $movementDate->toDateString(),
            'note' => $note,
            'requires_receipt' => $requiresReceipt,
            'receipt_attached' => $receiptAttached,
            'posted_at' => $status === PettyCashMovement::STATUS_POSTED ? now() : null,
            'meta' => [
                'source' => 'expense_manual_create',
                'source_expense_attachment_ids' => $expense->attachments->pluck('id')->values()->all(),
                'accounting_event' => $status === PettyCashMovement::STATUS_POSTED
                    ? $this->pettyCashAccountingEventForType(PettyCashMovement::TYPE_EXPENSE)
                    : null,
            ],
        ]);

        if ($movement->status === PettyCashMovement::STATUS_POSTED) {
            $this->recalculatePettyCashBalance($pettyCashAccount);
        }

        ActivityLog::record($user, $movement, 'petty_cash_movement_created_from_expense', [
            'expense_id' => (int) $expense->id,
            'status' => $movement->status,
            'amount' => (float) $movement->amount,
            'currency_code' => $movement->currency_code,
        ], 'Petty cash movement created from expense form');
        ActivityLog::record($user, $expense, 'petty_cash_movement_linked_from_expense', [
            'petty_cash_movement_id' => (int) $movement->id,
            'status' => $movement->status,
            'amount' => (float) $movement->amount,
        ], 'Expense linked to petty cash');

        return $movement;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function createPettyCashMovementFromScannedExpense(Request $request, Expense $expense, array $validated): PettyCashMovement
    {
        $user = $request->user();
        $accountId = (int) ($user?->accountOwnerId() ?? 0);
        if (! $this->canCreatePettyCashMovement($user)) {
            abort(403);
        }

        $owner = $accountId > 0 ? User::query()->find($accountId) : null;
        $pettyCashAccount = $this->resolvePettyCashAccount(
            $accountId,
            $owner?->businessCurrencyCode() ?? CurrencyCode::default()->value,
            $user
        );
        $status = (string) ($validated['petty_cash_status'] ?? PettyCashMovement::STATUS_DRAFT);
        if ($status === PettyCashMovement::STATUS_POSTED && ! $this->canPostPettyCashMovement($user)) {
            abort(403);
        }

        $amount = round((float) $expense->total, 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'petty_cash_create' => 'The scanned expense amount must be greater than zero to create a petty cash movement.',
            ]);
        }

        $movementDate = $expense->expense_date
            ? $expense->expense_date->copy()->startOfDay()
            : now()->startOfDay();
        $this->ensurePettyCashDateIsOpen($pettyCashAccount, $movementDate);

        $responsibleUserId = (int) ($validated['petty_cash_responsible_user_id']
            ?? $pettyCashAccount->responsible_user_id
            ?? $user?->id);
        $this->ensureResponsibleBelongsToAccount($responsibleUserId, $accountId);

        $requiresReceipt = $this->pettyCashMovementRequiresReceipt(
            $pettyCashAccount,
            PettyCashMovement::TYPE_EXPENSE,
            $amount,
            true
        );
        $expense->loadMissing('attachments');

        $movement = PettyCashMovement::query()->create([
            'user_id' => $accountId,
            'petty_cash_account_id' => $pettyCashAccount->id,
            'expense_id' => $expense->id,
            'team_member_id' => $expense->team_member_id,
            'created_by_user_id' => $user?->id,
            'responsible_user_id' => $responsibleUserId,
            'type' => PettyCashMovement::TYPE_EXPENSE,
            'status' => $status,
            'amount' => $amount,
            'currency_code' => $pettyCashAccount->currency_code,
            'movement_date' => $movementDate->toDateString(),
            'note' => $validated['petty_cash_note']
                ?? trim('AI scan: '.(string) $expense->title),
            'requires_receipt' => $requiresReceipt,
            'receipt_attached' => true,
            'posted_at' => $status === PettyCashMovement::STATUS_POSTED ? now() : null,
            'meta' => [
                'source' => 'expense_ai_scan',
                'source_expense_attachment_ids' => $expense->attachments->pluck('id')->values()->all(),
                'accounting_event' => $status === PettyCashMovement::STATUS_POSTED
                    ? $this->pettyCashAccountingEventForType(PettyCashMovement::TYPE_EXPENSE)
                    : null,
            ],
        ]);

        if ($movement->status === PettyCashMovement::STATUS_POSTED) {
            $this->recalculatePettyCashBalance($pettyCashAccount);
        }

        ActivityLog::record($user, $movement, 'petty_cash_movement_created_from_scan', [
            'expense_id' => (int) $expense->id,
            'status' => $movement->status,
            'amount' => (float) $movement->amount,
            'currency_code' => $movement->currency_code,
        ], 'Petty cash movement created from AI expense scan');
        ActivityLog::record($user, $expense, 'petty_cash_movement_linked_from_scan', [
            'petty_cash_movement_id' => (int) $movement->id,
            'status' => $movement->status,
            'amount' => (float) $movement->amount,
        ], 'AI scanned expense linked to petty cash');

        return $movement;
    }

    /**
     * @return array<int, string>
     */
    private function pettyCashClosureRelations(): array
    {
        return [
            'reviewer:id,name',
            'closer:id,name',
            'reopener:id,name',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentPettyCashClosure(PettyCashClosure $closure): array
    {
        $closure->loadMissing($this->pettyCashClosureRelations());

        return [
            'id' => (int) $closure->id,
            'period_start' => optional($closure->period_start)->toDateString(),
            'period_end' => optional($closure->period_end)->toDateString(),
            'expected_balance' => round((float) $closure->expected_balance, 2),
            'counted_balance' => round((float) $closure->counted_balance, 2),
            'difference' => round((float) $closure->difference, 2),
            'status' => (string) $closure->status,
            'comment' => $closure->comment,
            'closed_at' => optional($closure->closed_at)->toDateTimeString(),
            'reopened_at' => optional($closure->reopened_at)->toDateTimeString(),
            'reviewer' => $closure->reviewer ? [
                'id' => (int) $closure->reviewer->id,
                'name' => (string) $closure->reviewer->name,
            ] : null,
            'closer' => $closure->closer ? [
                'id' => (int) $closure->closer->id,
                'name' => (string) $closure->closer->name,
            ] : null,
            'reopener' => $closure->reopener ? [
                'id' => (int) $closure->reopener->id,
                'name' => (string) $closure->reopener->name,
            ] : null,
            'accounting_event' => data_get($closure->meta, 'accounting_event'),
        ];
    }

    private function pettyCashPanelResponse(Request $request, string $message, array $extra = [], int $status = 200)
    {
        if ($this->shouldReturnJson($request)) {
            $user = $request->user();
            $accountId = (int) ($user?->accountOwnerId() ?? 0);
            $owner = $accountId > 0 ? User::query()->find($accountId) : null;
            $currencyCode = $owner?->businessCurrencyCode() ?? CurrencyCode::default()->value;
            $teamMembers = $this->teamMemberOptions($user, $accountId);

            return response()->json(array_merge([
                'message' => $message,
                'pettyCash' => $this->buildPettyCashPanel($user, $accountId, $request->only([
                    'petty_type',
                    'petty_status',
                    'petty_responsible_user_id',
                    'petty_from',
                    'petty_to',
                ]), $currencyCode, $teamMembers),
            ], $extra), $status);
        }

        return redirect()->back()->with('success', $message);
    }

    private function pettyCashMutationResponse(Request $request, PettyCashMovement $movement, string $message, int $status = 200)
    {
        if ($this->shouldReturnJson($request)) {
            $user = $request->user();
            $accountId = (int) ($user?->accountOwnerId() ?? 0);
            $owner = $accountId > 0 ? User::query()->find($accountId) : null;
            $currencyCode = $owner?->businessCurrencyCode() ?? CurrencyCode::default()->value;
            $teamMembers = $this->teamMemberOptions($user, $accountId);

            return response()->json([
                'message' => $message,
                'movement' => $this->presentPettyCashMovement($movement, $user),
                'pettyCash' => $this->buildPettyCashPanel($user, $accountId, $request->only([
                    'petty_type',
                    'petty_status',
                    'petty_responsible_user_id',
                    'petty_from',
                    'petty_to',
                ]), $currencyCode, $teamMembers),
            ], $status);
        }

        return redirect()->back()->with('success', $message);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPeriodRecap($baseQuery, array $filters): array
    {
        $period = $this->resolveRecapPeriod($filters);
        $periodQuery = $this->periodExpenseQuery(clone $baseQuery, $period['start'], $period['end']);
        $activeQuery = $this->activeExpenseQuery(clone $periodQuery);
        $totalSpent = $this->sumExpenseTotal(clone $activeQuery);
        $previousTotal = $this->sumExpenseTotal($this->activeExpenseQuery(
            $this->periodExpenseQuery(clone $baseQuery, $period['previous_start'], $period['previous_end'])
        ));

        $approvedStatuses = [
            Expense::STATUS_APPROVED,
            Expense::STATUS_DUE,
            Expense::STATUS_PAID,
            Expense::STATUS_REIMBURSED,
        ];
        $pendingStatuses = [
            Expense::STATUS_SUBMITTED,
            Expense::STATUS_PENDING_APPROVAL,
        ];

        $paidTotal = $this->sumExpenseTotal(
            (clone $baseQuery)
                ->whereIn('status', [Expense::STATUS_PAID, Expense::STATUS_REIMBURSED])
                ->whereBetween('paid_date', [$period['start']->toDateString(), $period['end']->toDateString()])
        );
        $toPayTotal = $this->sumExpenseTotal(
            (clone $activeQuery)->whereIn('status', [Expense::STATUS_APPROVED, Expense::STATUS_DUE])
        );
        $reimbursementTotal = $this->sumExpenseTotal(
            (clone $activeQuery)->where('reimbursement_status', Expense::REIMBURSEMENT_STATUS_PENDING)
        );
        $pendingApprovalCount = (int) (clone $periodQuery)
            ->whereIn('status', $pendingStatuses)
            ->count();
        $rejectedCount = (int) (clone $periodQuery)
            ->where('status', Expense::STATUS_REJECTED)
            ->count();
        $missingReceiptCount = (int) (clone $activeQuery)
            ->whereDoesntHave('attachments')
            ->count();
        $recurringCount = (int) (clone $activeQuery)
            ->where('is_recurring', true)
            ->count();

        $kpis = [
            'total_spent' => $totalSpent,
            'previous_total_spent' => $previousTotal,
            'total_delta_percent' => $this->percentageChange($totalSpent, $previousTotal),
            'approved_total' => $this->sumExpenseTotal((clone $activeQuery)->whereIn('status', $approvedStatuses)),
            'paid_total' => $paidTotal,
            'to_pay_total' => $toPayTotal,
            'reimbursement_total' => $reimbursementTotal,
            'pending_approval_count' => $pendingApprovalCount,
            'rejected_count' => $rejectedCount,
            'missing_receipt_count' => $missingReceiptCount,
            'recurring_count' => $recurringCount,
            'expense_count' => (int) (clone $activeQuery)->count(),
        ];

        return [
            'period' => [
                'key' => $period['key'],
                'start' => $period['start']->toDateString(),
                'end' => $period['end']->toDateString(),
                'previous_start' => $period['previous_start']->toDateString(),
                'previous_end' => $period['previous_end']->toDateString(),
            ],
            'kpis' => $kpis,
            'breakdowns' => [
                'categories' => $this->periodCategoryBreakdown(clone $activeQuery, $totalSpent),
                'suppliers' => $this->periodSupplierBreakdown(clone $activeQuery, $totalSpent),
                'team_members' => $this->periodTeamMemberBreakdown(clone $activeQuery, $totalSpent),
                'payment_methods' => $this->periodPaymentMethodBreakdown(clone $activeQuery, $totalSpent),
                'linked_contexts' => $this->periodLinkedContextBreakdown(clone $activeQuery),
            ],
            'alerts' => $this->periodRecapAlerts($kpis),
        ];
    }

    /**
     * @return array{key:string,start:Carbon,end:Carbon,previous_start:Carbon,previous_end:Carbon}
     */
    private function resolveRecapPeriod(array $filters): array
    {
        $allowedPeriods = ['week', 'month', 'quarter', 'year', 'custom'];
        $periodKey = in_array($filters['recap_period'] ?? null, $allowedPeriods, true)
            ? (string) $filters['recap_period']
            : 'month';
        $today = now();

        if ($periodKey === 'custom') {
            $start = $this->parseDateOrNull($filters['recap_from'] ?? null)?->startOfDay()
                ?: $today->copy()->startOfMonth();
            $end = $this->parseDateOrNull($filters['recap_to'] ?? null)?->endOfDay()
                ?: $today->copy()->endOfDay();

            if ($end->lt($start)) {
                [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
            }
        } else {
            $start = match ($periodKey) {
                'week' => $today->copy()->startOfWeek(),
                'quarter' => $today->copy()->startOfQuarter(),
                'year' => $today->copy()->startOfYear(),
                default => $today->copy()->startOfMonth(),
            };
            $end = match ($periodKey) {
                'week' => $today->copy()->endOfWeek(),
                'quarter' => $today->copy()->endOfQuarter(),
                'year' => $today->copy()->endOfYear(),
                default => $today->copy()->endOfMonth(),
            };
        }

        if ($periodKey === 'custom') {
            $days = max(1, $start->diffInDays($end) + 1);
            $previousEnd = $start->copy()->subDay()->endOfDay();
            $previousStart = $previousEnd->copy()->subDays($days - 1)->startOfDay();
        } else {
            $previousStart = match ($periodKey) {
                'week' => $start->copy()->subWeek()->startOfWeek(),
                'quarter' => $start->copy()->subQuarter()->startOfQuarter(),
                'year' => $start->copy()->subYear()->startOfYear(),
                default => $start->copy()->subMonthNoOverflow()->startOfMonth(),
            };
            $previousEnd = match ($periodKey) {
                'week' => $previousStart->copy()->endOfWeek(),
                'quarter' => $previousStart->copy()->endOfQuarter(),
                'year' => $previousStart->copy()->endOfYear(),
                default => $previousStart->copy()->endOfMonth(),
            };
        }

        return [
            'key' => $periodKey,
            'start' => $start->copy()->startOfDay(),
            'end' => $end->copy()->endOfDay(),
            'previous_start' => $previousStart->copy()->startOfDay(),
            'previous_end' => $previousEnd->copy()->endOfDay(),
        ];
    }

    private function parseDateOrNull(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function periodExpenseQuery($query, Carbon $start, Carbon $end)
    {
        return $query->whereBetween('expense_date', [$start->toDateString(), $end->toDateString()]);
    }

    private function activeExpenseQuery($query)
    {
        return $query->whereNotIn('status', [Expense::STATUS_CANCELLED, Expense::STATUS_REJECTED]);
    }

    private function sumExpenseTotal($query): float
    {
        return round((float) ($query->sum('total') ?? 0), 2);
    }

    private function percentageChange(float $current, float $previous): ?float
    {
        if (abs($previous) < 0.01) {
            return abs($current) < 0.01 ? 0.0 : null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * @return array<int, array{key:string,label:string,total:float,count:int,share:float}>
     */
    private function periodCategoryBreakdown($query, float $periodTotal): array
    {
        return $query
            ->selectRaw('COALESCE(category_key, ?) as category_key, COUNT(*) as total_count, COALESCE(SUM(total), 0) as total_amount', ['other'])
            ->groupBy('category_key')
            ->orderByDesc('total_amount')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [
                'key' => (string) ($row->category_key ?: 'other'),
                'label' => (string) ($row->category_key ?: 'other'),
                'count' => (int) ($row->total_count ?? 0),
                'total' => round((float) ($row->total_amount ?? 0), 2),
                'share' => $this->breakdownShare((float) ($row->total_amount ?? 0), $periodTotal),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{name:string,total:float,count:int,share:float}>
     */
    private function periodSupplierBreakdown($query, float $periodTotal): array
    {
        return $query
            ->selectRaw("COALESCE(NULLIF(supplier_name, ''), 'No supplier') as supplier_label, COUNT(*) as total_count, COALESCE(SUM(total), 0) as total_amount")
            ->groupBy('supplier_name')
            ->orderByDesc('total_amount')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [
                'name' => (string) ($row->supplier_label ?: 'No supplier'),
                'count' => (int) ($row->total_count ?? 0),
                'total' => round((float) ($row->total_amount ?? 0), 2),
                'share' => $this->breakdownShare((float) ($row->total_amount ?? 0), $periodTotal),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id:?int,label:string,total:float,count:int,share:float}>
     */
    private function periodTeamMemberBreakdown($query, float $periodTotal): array
    {
        $rows = $query
            ->selectRaw('team_member_id, COUNT(*) as total_count, COALESCE(SUM(total), 0) as total_amount')
            ->groupBy('team_member_id')
            ->orderByDesc('total_amount')
            ->limit(5)
            ->get();
        $memberNames = TeamMember::query()
            ->whereIn('id', $rows->pluck('team_member_id')->filter()->values())
            ->with('user:id,name')
            ->get()
            ->mapWithKeys(fn (TeamMember $member) => [
                (int) $member->id => (string) ($member->user?->name ?: $member->title ?: 'Member'),
            ]);

        return $rows
            ->map(fn ($row) => [
                'id' => $row->team_member_id ? (int) $row->team_member_id : null,
                'label' => $row->team_member_id ? (string) ($memberNames[(int) $row->team_member_id] ?? 'Member') : 'No team member',
                'count' => (int) ($row->total_count ?? 0),
                'total' => round((float) ($row->total_amount ?? 0), 2),
                'share' => $this->breakdownShare((float) ($row->total_amount ?? 0), $periodTotal),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{key:string,label:string,total:float,count:int,share:float}>
     */
    private function periodPaymentMethodBreakdown($query, float $periodTotal): array
    {
        return $query
            ->selectRaw("COALESCE(NULLIF(payment_method, ''), 'other') as payment_method_key, COUNT(*) as total_count, COALESCE(SUM(total), 0) as total_amount")
            ->groupBy('payment_method')
            ->orderByDesc('total_amount')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [
                'key' => (string) ($row->payment_method_key ?: 'other'),
                'label' => (string) ($row->payment_method_key ?: 'other'),
                'count' => (int) ($row->total_count ?? 0),
                'total' => round((float) ($row->total_amount ?? 0), 2),
                'share' => $this->breakdownShare((float) ($row->total_amount ?? 0), $periodTotal),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{key:string,total:float,count:int}>
     */
    private function periodLinkedContextBreakdown($query): array
    {
        $contexts = [
            'customer' => 'customer_id',
            'work' => 'work_id',
            'sale' => 'sale_id',
            'invoice' => 'invoice_id',
            'campaign' => 'campaign_id',
        ];

        return collect($contexts)
            ->map(function (string $column, string $key) use ($query) {
                $contextQuery = (clone $query)->whereNotNull($column);

                return [
                    'key' => $key,
                    'count' => (int) $contextQuery->count(),
                    'total' => $this->sumExpenseTotal(clone $contextQuery),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $kpis
     * @return array<int, array<string, mixed>>
     */
    private function periodRecapAlerts(array $kpis): array
    {
        return collect([
            ['key' => 'missing_receipts', 'count' => (int) ($kpis['missing_receipt_count'] ?? 0), 'total' => null],
            ['key' => 'pending_approval', 'count' => (int) ($kpis['pending_approval_count'] ?? 0), 'total' => null],
            ['key' => 'to_pay', 'count' => null, 'total' => (float) ($kpis['to_pay_total'] ?? 0)],
            ['key' => 'reimbursements', 'count' => null, 'total' => (float) ($kpis['reimbursement_total'] ?? 0)],
            ['key' => 'recurring', 'count' => (int) ($kpis['recurring_count'] ?? 0), 'total' => null],
        ])
            ->filter(fn (array $alert) => ($alert['count'] ?? 0) > 0 || ($alert['total'] ?? 0) > 0)
            ->values()
            ->all();
    }

    private function breakdownShare(float $value, float $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        return round(($value / $total) * 100, 1);
    }

    /**
     * @return array<int, array{key:string,label:string,total:float,count:int}>
     */
    private function topCategoryStats($query): array
    {
        return $query
            ->selectRaw('COALESCE(category_key, ?) as category_key, COUNT(*) as total_count, COALESCE(SUM(total), 0) as total_amount', ['other'])
            ->groupBy('category_key')
            ->orderByDesc('total_amount')
            ->limit(3)
            ->get()
            ->map(fn ($row) => [
                'key' => (string) ($row->category_key ?: 'other'),
                'label' => (string) ($row->category_key ?: 'other'),
                'count' => (int) ($row->total_count ?? 0),
                'total' => round((float) ($row->total_amount ?? 0), 2),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{name:string,total:float,count:int}>
     */
    private function topSupplierStats($query): array
    {
        return $query
            ->selectRaw("COALESCE(NULLIF(supplier_name, ''), 'No supplier') as supplier_label, COUNT(*) as total_count, COALESCE(SUM(total), 0) as total_amount")
            ->groupBy('supplier_name')
            ->orderByDesc('total_amount')
            ->limit(3)
            ->get()
            ->map(fn ($row) => [
                'name' => (string) ($row->supplier_label ?: 'No supplier'),
                'count' => (int) ($row->total_count ?? 0),
                'total' => round((float) ($row->total_amount ?? 0), 2),
            ])
            ->values()
            ->all();
    }

    private function customerDisplayName(?Customer $customer): string
    {
        if (! $customer) {
            return '';
        }

        $company = trim((string) ($customer->company_name ?? ''));
        if ($company !== '') {
            return $company;
        }

        return trim((string) collect([
            $customer->first_name,
            $customer->last_name,
        ])->filter()->implode(' '));
    }

    private function storeAttachments(Request $request, Expense $expense, int $userId): void
    {
        if (! $request->hasFile('attachments')) {
            return;
        }

        foreach ($request->file('attachments', []) as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $this->storeAttachmentFile($file, $expense, $userId);
        }
    }

    private function storeAttachmentFile(UploadedFile $file, Expense $expense, int $userId): void
    {
        $mime = $file->getClientMimeType() ?? $file->getMimeType();
        $hash = $this->hashUploadedFile($file);
        $path = str_starts_with((string) $mime, 'image/')
            ? FileHandler::storeFile('expense-attachments', $file)
            : $file->store('expense-attachments', 'public');

        ExpenseAttachment::query()->create([
            'expense_id' => $expense->id,
            'user_id' => $userId,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $mime,
            'size' => $file->getSize(),
            'meta' => array_filter([
                'hash_sha256' => $hash,
            ]),
        ]);
    }

    private function hashUploadedFile(UploadedFile $file): ?string
    {
        $realPath = $file->getRealPath();
        if (! $realPath || ! is_file($realPath)) {
            return null;
        }

        return hash_file('sha256', $realPath) ?: null;
    }

    private function recordExpenseAuditEvent(?User $actor, Expense $expense, string $action, array $properties = [], ?string $description = null): void
    {
        ActivityLog::record($actor, $expense, $action, $properties, $description);
    }
}
