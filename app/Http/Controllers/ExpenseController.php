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
use Illuminate\Support\Facades\Storage;
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

        $owner = $user && (int) $user->id === $accountId
            ? $user
            : User::query()->find($accountId);

        return $this->inertiaOrJson('Expense/Index', [
            'filters' => $filters,
            'expenses' => $expenses,
            'count' => (clone $filteredQuery)->count(),
            'stats' => $stats,
            'categories' => config('expenses.categories', []),
            'paymentMethods' => config('expenses.payment_methods', []),
            'statuses' => Expense::STATUSES,
            'recurrenceFrequencies' => Expense::RECURRENCE_FREQUENCIES,
            'teamMembers' => $this->teamMemberOptions($user, $accountId),
            'linkOptions' => $this->expenseLinkOptions($user, $accountId),
            'tenantCurrencyCode' => $owner?->businessCurrencyCode() ?? CurrencyCode::default()->value,
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

        $expense = Expense::query()->create($this->buildExpensePayload(
            $validated,
            $accountId,
            (int) $user->id,
            null,
            $recurringService,
            $user
        ));

        $this->storeAttachments($request, $expense, (int) $user->id);
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

        $payload = [
            'message' => 'Expense created successfully.',
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
        $expense = $this->presentExpenseDetail($draft['expense'], $user);
        $message = (string) ($draft['message'] ?? 'AI draft created successfully.');

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => $message,
                'expense' => $expense,
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
