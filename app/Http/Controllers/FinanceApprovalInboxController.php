<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Invoice;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\FinanceApprovalService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class FinanceApprovalInboxController extends Controller
{
    private const INBOX_PER_PAGE = 12;

    public function index(Request $request)
    {
        $actor = $request->user();
        if (! $actor || ! $this->canAccessInbox($actor)) {
            abort(403);
        }

        $accountId = (int) $actor->accountOwnerId();
        $showsExpenses = $actor->hasCompanyFeature('expenses');
        $showsInvoices = $actor->hasCompanyFeature('invoices');
        $search = trim((string) $request->query('search', ''));
        $expensePage = max(1, (int) $request->query('expense_page', 1));
        $invoicePage = max(1, (int) $request->query('invoice_page', 1));

        $expenses = $this->emptyPaginator($request, 'expense_page');
        if ($showsExpenses) {
            $expenses = $this->paginateInboxItems(
                $this->expenseInboxItems($accountId, $actor, $search),
                $expensePage,
                $request,
                'expense_page'
            );
        }

        $invoices = $this->emptyPaginator($request, 'invoice_page');
        if ($showsInvoices) {
            $invoices = $this->paginateInboxItems(
                $this->invoiceInboxItems($accountId, $actor, $search),
                $invoicePage,
                $request,
                'invoice_page'
            );
        }

        return $this->inertiaOrJson('FinanceApprovals/Index', [
            'filters' => [
                'search' => $search,
            ],
            'stats' => [
                'expenses_pending' => $expenses->total(),
                'invoices_pending' => $invoices->total(),
                'total_pending' => $expenses->total() + $invoices->total(),
            ],
            'expenses' => $expenses,
            'invoices' => $invoices,
        ]);
    }

    private function expenseInboxItems(int $accountId, User $actor, string $search = ''): Collection
    {
        $query = Expense::query()
            ->byAccount($accountId)
            ->whereIn('status', [
                Expense::STATUS_SUBMITTED,
                Expense::STATUS_PENDING_APPROVAL,
            ])
            ->with([
                'creator:id,name',
                'customer:id,first_name,last_name,company_name',
                'work:id,job_title,number',
            ])
            ->orderByDesc('expense_date')
            ->orderByDesc('created_at');

        $this->applyExpenseSearch($query, $search);

        return $query
            ->get()
            ->map(function (Expense $expense) use ($actor) {
                $expense->setAttribute('inbox_actions', $this->expenseApprovalActions($expense, $actor));
                $expense->setAttribute('document_url', route('expense.show', $expense));

                return $expense;
            })
            ->filter(fn (Expense $expense) => $expense->inbox_actions !== [])
            ->values();
    }

    private function invoiceInboxItems(int $accountId, User $actor, string $search = ''): Collection
    {
        $query = Invoice::query()
            ->byUser($accountId)
            ->whereIn('approval_status', [
                FinanceApprovalService::APPROVAL_STATUS_SUBMITTED,
                FinanceApprovalService::APPROVAL_STATUS_PENDING_APPROVAL,
                FinanceApprovalService::APPROVAL_STATUS_APPROVED,
            ])
            ->with([
                'customer:id,first_name,last_name,company_name,email',
                'work:id,job_title,number',
                'creator:id,name',
                'approver:id,name',
            ])
            ->orderByDesc('created_at');

        $this->applyInvoiceSearch($query, $search);

        return $query
            ->get()
            ->map(function (Invoice $invoice) use ($actor) {
                $invoice->setAttribute('inbox_actions', $this->invoiceApprovalActions($invoice, $actor));
                $invoice->setAttribute('document_url', route('invoice.show', $invoice));

                return $invoice;
            })
            ->filter(fn (Invoice $invoice) => $invoice->inbox_actions !== [])
            ->values();
    }

    private function applyExpenseSearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $query->where(function (Builder $builder) use ($search): void {
            $builder
                ->where('title', 'like', '%'.$search.'%')
                ->orWhere('supplier_name', 'like', '%'.$search.'%')
                ->orWhere('reference_number', 'like', '%'.$search.'%')
                ->orWhereHas('creator', fn (Builder $creator) => $creator->where('name', 'like', '%'.$search.'%'))
                ->orWhereHas('customer', function (Builder $customer) use ($search): void {
                    $customer
                        ->where('company_name', 'like', '%'.$search.'%')
                        ->orWhere('first_name', 'like', '%'.$search.'%')
                        ->orWhere('last_name', 'like', '%'.$search.'%');
                })
                ->orWhereHas('work', function (Builder $work) use ($search): void {
                    $work
                        ->where('number', 'like', '%'.$search.'%')
                        ->orWhere('job_title', 'like', '%'.$search.'%');
                });
        });
    }

    private function applyInvoiceSearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $query->where(function (Builder $builder) use ($search): void {
            $builder
                ->where('number', 'like', '%'.$search.'%')
                ->orWhereHas('creator', fn (Builder $creator) => $creator->where('name', 'like', '%'.$search.'%'))
                ->orWhereHas('customer', function (Builder $customer) use ($search): void {
                    $customer
                        ->where('company_name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%')
                        ->orWhere('first_name', 'like', '%'.$search.'%')
                        ->orWhere('last_name', 'like', '%'.$search.'%');
                })
                ->orWhereHas('work', function (Builder $work) use ($search): void {
                    $work
                        ->where('number', 'like', '%'.$search.'%')
                        ->orWhere('job_title', 'like', '%'.$search.'%');
                });
        });
    }

    private function paginateInboxItems(
        Collection $items,
        int $page,
        Request $request,
        string $pageName
    ): LengthAwarePaginator {
        return new LengthAwarePaginator(
            $items->forPage($page, self::INBOX_PER_PAGE)->values(),
            $items->count(),
            self::INBOX_PER_PAGE,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
                'pageName' => $pageName,
            ]
        );
    }

    private function emptyPaginator(Request $request, string $pageName): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            collect(),
            0,
            self::INBOX_PER_PAGE,
            1,
            [
                'path' => $request->url(),
                'query' => $request->query(),
                'pageName' => $pageName,
            ]
        );
    }

    private function canAccessInbox(User $user): bool
    {
        if ($this->isOwnerActor($user)) {
            return $user->hasCompanyFeature('expenses') || $user->hasCompanyFeature('invoices');
        }

        $membership = $this->membership($user);
        if (! $membership) {
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

    private function expenseApprovalActions(Expense $expense, User $actor): array
    {
        return collect(['approve', 'reject'])
            ->filter(function (string $action) use ($actor, $expense): bool {
                $authorization = app(FinanceApprovalService::class)->authorizeExpenseAction($actor, $expense, $action);

                return (bool) ($authorization['allowed'] ?? false);
            })
            ->values()
            ->all();
    }

    private function invoiceApprovalActions(Invoice $invoice, User $actor): array
    {
        $actions = [];
        if (in_array((string) $invoice->approval_status, [
            FinanceApprovalService::APPROVAL_STATUS_SUBMITTED,
            FinanceApprovalService::APPROVAL_STATUS_PENDING_APPROVAL,
        ], true)) {
            $actions = ['approve', 'reject'];
        } elseif ((string) $invoice->approval_status === FinanceApprovalService::APPROVAL_STATUS_APPROVED) {
            $actions = ['process'];
        }

        return collect($actions)
            ->filter(function (string $action) use ($actor, $invoice): bool {
                $authorization = app(FinanceApprovalService::class)->authorizeInvoiceAction($actor, $invoice, $action);

                return (bool) ($authorization['allowed'] ?? false);
            })
            ->values()
            ->all();
    }

    private function isOwnerActor(User $user): bool
    {
        return $user->isAccountOwner()
            && (int) $user->id === (int) $user->accountOwnerId();
    }

    private function membership(User $user): ?TeamMember
    {
        return $user->relationLoaded('teamMembership')
            ? $user->teamMembership
            : $user->teamMembership()->first();
    }
}
