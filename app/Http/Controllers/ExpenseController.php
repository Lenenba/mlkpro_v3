<?php

namespace App\Http\Controllers;

use App\Enums\CurrencyCode;
use App\Http\Requests\Expenses\ExpenseWriteRequest;
use App\Models\Expense;
use App\Models\ExpenseAttachment;
use App\Models\TeamMember;
use App\Services\ExpenseAiDraftService;
use App\Services\ExpenseRecurringService;
use App\Models\User;
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
            ->with(['creator:id,name'])
            ->withCount('attachments')
            ->orderBy($sort, $direction)
            ->paginate((int) $filters['per_page'])
            ->withQueryString();
        $expenses->setCollection(
            $expenses->getCollection()->map(function (Expense $expense) {
                return $this->presentExpenseSummary($expense);
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
            'recurrenceSource:id,title',
            'attachments.user:id,name',
        ])->loadCount('generatedRecurrences');
        $expense = $this->presentExpenseDetail($expense);

        return $this->inertiaOrJson('Expense/Show', [
            'expense' => $expense,
            'categories' => config('expenses.categories', []),
            'paymentMethods' => config('expenses.payment_methods', []),
            'statuses' => Expense::STATUSES,
            'recurrenceFrequencies' => Expense::RECURRENCE_FREQUENCIES,
            'teamMembers' => $this->teamMemberOptions($request->user(), (int) $expense->user_id),
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
            $recurringService
        ));

        $this->storeAttachments($request, $expense, (int) $user->id);

        $payload = [
            'message' => 'Expense created successfully.',
            'expense' => $expense->fresh(['creator:id,name', 'teamMember.user:id,name', 'attachments']),
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
            $recurringService
        ))->save();

        $this->storeAttachments($request, $expense, (int) $user->id);

        $payload = [
            'message' => 'Expense updated successfully.',
            'expense' => $expense->fresh(['creator:id,name', 'approver:id,name', 'payer:id,name', 'reimburser:id,name', 'teamMember.user:id,name', 'attachments']),
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
        $expense = $this->presentExpenseDetail($draft['expense']);
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

    public function submit(Request $request, Expense $expense)
    {
        return $this->transitionExpense(
            $request,
            $expense,
            Expense::STATUS_SUBMITTED,
            [Expense::STATUS_DRAFT, Expense::STATUS_REVIEW_REQUIRED],
            'submit',
            'Expense submitted successfully.'
        );
    }

    public function approve(Request $request, Expense $expense)
    {
        return $this->transitionExpense(
            $request,
            $expense,
            Expense::STATUS_APPROVED,
            [Expense::STATUS_SUBMITTED],
            'approve',
            'Expense approved successfully.'
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
            'Expense marked as due successfully.'
        );
    }

    public function markPaid(Request $request, Expense $expense)
    {
        return $this->transitionExpense(
            $request,
            $expense,
            Expense::STATUS_PAID,
            [Expense::STATUS_APPROVED, Expense::STATUS_DUE, Expense::STATUS_SUBMITTED],
            'mark_paid',
            'Expense marked as paid successfully.'
        );
    }

    public function markReimbursed(Request $request, Expense $expense)
    {
        $this->authorize('update', $expense);

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
            'meta' => $this->appendWorkflowHistory(
                is_array($expense->meta) ? $expense->meta : [],
                'mark_reimbursed',
                $expense->status,
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
            'recurrenceSource:id,title',
            'attachments.user:id,name',
        ])->loadCount('generatedRecurrences');
        $expense = $this->presentExpenseDetail($expense);

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
            [Expense::STATUS_DRAFT, Expense::STATUS_SUBMITTED, Expense::STATUS_APPROVED, Expense::STATUS_DUE],
            'cancel',
            'Expense cancelled successfully.'
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
                        ->orWhere('reference_number', 'like', '%'.$search.'%');
                });
            })
            ->when($filters['status'] ?? null, fn ($builder, $status) => $builder->where('status', $status))
            ->when($filters['category_key'] ?? null, fn ($builder, $category) => $builder->where('category_key', $category))
            ->when($filters['quick_filter'] ?? null, function ($builder, $quickFilter) {
                switch ($quickFilter) {
                    case 'submitted':
                        $builder->where('status', Expense::STATUS_SUBMITTED);
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
        ?ExpenseRecurringService $recurringService = null
    ): array
    {
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

        if (in_array($status, [Expense::STATUS_PAID, Expense::STATUS_REIMBURSED], true) && ! $paidDate) {
            $paidDate = now()->toDateString();
            $paidByUserId = $actorId;
        }

        if ($status === Expense::STATUS_APPROVED && ! $approvedAt) {
            $approvedAt = now();
            $approvedByUserId = $actorId;
        }

        $existingMeta = is_array($existing?->meta) ? $existing->meta : [];
        $meta = $this->appendWorkflowHistory(
            $existingMeta,
            $existing ? 'manual_update' : 'created',
            $existing?->status,
            $status,
            $actorId
        );

        return [
            'user_id' => $accountId,
            'created_by_user_id' => $existing?->created_by_user_id ?: $actorId,
            'approved_by_user_id' => $approvedByUserId,
            'paid_by_user_id' => $paidByUserId,
            'reimbursed_by_user_id' => $reimbursedByUserId,
            'team_member_id' => $teamMemberId,
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
        string $message
    ) {
        $this->authorize('update', $expense);

        $validated = $request->validate([
            'comment' => 'nullable|string|max:1000',
            'paid_date' => 'nullable|date',
        ]);

        if (! in_array($expense->status, $allowedFrom, true)) {
            throw ValidationException::withMessages([
                'status' => 'This expense cannot move to the requested workflow state.',
            ]);
        }

        if (in_array($targetStatus, [Expense::STATUS_APPROVED, Expense::STATUS_DUE, Expense::STATUS_PAID], true)
            && blank($expense->category_key)) {
            throw ValidationException::withMessages([
                'category_key' => 'A category is required before approval or payment.',
            ]);
        }

        $actorId = (int) $request->user()->id;
        $updates = [
            'status' => $targetStatus,
            'meta' => $this->appendWorkflowHistory(
                is_array($expense->meta) ? $expense->meta : [],
                $action,
                $expense->status,
                $targetStatus,
                $actorId,
                $validated['comment'] ?? null
            ),
        ];

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
            'recurrenceSource:id,title',
            'attachments.user:id,name',
        ])->loadCount('generatedRecurrences');
        $expense = $this->presentExpenseDetail($expense);

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => $message,
                'expense' => $expense,
            ]);
        }

        return redirect()->back()->with('success', $message);
    }

    private function presentExpenseSummary(Expense $expense): Expense
    {
        $expense->setAttribute('available_actions', $this->availableWorkflowActions($expense));
        $expense->setAttribute('ai_review_required', (bool) data_get($expense->meta, 'ai_intake.review_required', false));

        return $expense;
    }

    private function presentExpenseDetail(Expense $expense): Expense
    {
        $expense->setAttribute('available_actions', $this->availableWorkflowActions($expense));
        $expense->setAttribute('workflow_history', $this->workflowHistory($expense));
        $expense->setAttribute('ai_intake', $this->aiIntake($expense));

        return $expense;
    }

    /**
     * @return array<int, string>
     */
    private function availableWorkflowActions(Expense $expense): array
    {
        $hasCategory = filled($expense->category_key);

        return match ($expense->status) {
            Expense::STATUS_DRAFT, Expense::STATUS_REVIEW_REQUIRED => ['submit', 'cancel'],
            Expense::STATUS_SUBMITTED => $hasCategory ? ['approve', 'mark_paid', 'cancel'] : ['cancel'],
            Expense::STATUS_APPROVED => $hasCategory ? $this->appendReimbursementAction($expense, ['mark_due', 'mark_paid', 'cancel']) : ['cancel'],
            Expense::STATUS_DUE => $hasCategory ? $this->appendReimbursementAction($expense, ['mark_paid', 'cancel']) : ['cancel'],
            Expense::STATUS_PAID => $this->appendReimbursementAction($expense, []),
            default => [],
        };
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
}
