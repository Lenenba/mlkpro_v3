<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Invoice;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\FinanceApprovalService;
use Illuminate\Http\Request;

class FinanceApprovalInboxController extends Controller
{
    public function index(Request $request)
    {
        $actor = $request->user();
        if (! $actor || ! $this->canAccessInbox($actor)) {
            abort(403);
        }

        $accountId = (int) $actor->accountOwnerId();
        $showsExpenses = $actor->hasCompanyFeature('expenses');
        $showsInvoices = $actor->hasCompanyFeature('invoices');

        $expenses = collect();
        if ($showsExpenses) {
            $expenses = Expense::query()
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
                ->orderByDesc('created_at')
                ->limit(30)
                ->get()
                ->map(function (Expense $expense) use ($actor) {
                    $expense->setAttribute('inbox_actions', $this->expenseApprovalActions($expense, $actor));
                    $expense->setAttribute('document_url', route('expense.show', $expense));

                    return $expense;
                })
                ->filter(fn (Expense $expense) => $expense->inbox_actions !== [])
                ->values();
        }

        $invoices = collect();
        if ($showsInvoices) {
            $invoices = Invoice::query()
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
                ->orderByDesc('created_at')
                ->limit(30)
                ->get()
                ->map(function (Invoice $invoice) use ($actor) {
                    $invoice->setAttribute('inbox_actions', $this->invoiceApprovalActions($invoice, $actor));
                    $invoice->setAttribute('document_url', route('invoice.show', $invoice));

                    return $invoice;
                })
                ->filter(fn (Invoice $invoice) => $invoice->inbox_actions !== [])
                ->values();
        }

        return $this->inertiaOrJson('FinanceApprovals/Index', [
            'stats' => [
                'expenses_pending' => $expenses->count(),
                'invoices_pending' => $invoices->count(),
                'total_pending' => $expenses->count() + $invoices->count(),
            ],
            'expenses' => $expenses,
            'invoices' => $invoices,
        ]);
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
