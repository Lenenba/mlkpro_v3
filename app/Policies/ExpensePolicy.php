<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\TeamMember;
use App\Models\User;

class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        if (! $this->hasExpenseModule($user)) {
            return false;
        }

        if ($this->isOwnerActor($user)) {
            return true;
        }

        $membership = $this->membership($user);

        return (bool) $membership
            && (
                $membership->hasPermission('expenses.view')
                || $membership->hasPermission('expenses.create')
                || $membership->hasPermission('expenses.edit')
                || $membership->hasPermission('expenses.approve')
                || $membership->hasPermission('expenses.approve_high')
                || $membership->hasPermission('expenses.pay')
            );
    }

    public function view(User $user, Expense $expense): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        return (int) $user->accountOwnerId() === (int) $expense->user_id;
    }

    public function create(User $user): bool
    {
        if (! $this->hasExpenseModule($user)) {
            return false;
        }

        if ($this->isOwnerActor($user)) {
            return true;
        }

        $membership = $this->membership($user);

        return (bool) $membership && $membership->hasPermission('expenses.create');
    }

    public function update(User $user, Expense $expense): bool
    {
        if (! $this->view($user, $expense)) {
            return false;
        }

        if ($this->isOwnerActor($user)) {
            return true;
        }

        $membership = $this->membership($user);
        if (! $membership || ! $membership->hasPermission('expenses.edit')) {
            return false;
        }

        if ((int) $expense->created_by_user_id !== (int) $user->id) {
            return false;
        }

        return ! in_array($expense->status, [Expense::STATUS_PAID, Expense::STATUS_REIMBURSED, Expense::STATUS_CANCELLED], true);
    }

    public function transition(User $user, Expense $expense): bool
    {
        if (! $this->view($user, $expense)) {
            return false;
        }

        if ($this->isOwnerActor($user)) {
            return true;
        }

        $membership = $this->membership($user);
        if (! $membership) {
            return false;
        }

        if ((int) $expense->created_by_user_id === (int) $user->id) {
            return $membership->hasPermission('expenses.edit')
                || $membership->hasPermission('expenses.create');
        }

        return $membership->hasPermission('expenses.approve')
            || $membership->hasPermission('expenses.approve_high')
            || $membership->hasPermission('expenses.pay');
    }

    public function delete(User $user, Expense $expense): bool
    {
        return $this->isOwnerActor($user)
            && $this->hasExpenseModule($user)
            && (int) $user->accountOwnerId() === (int) $expense->user_id;
    }

    private function hasExpenseModule(User $user): bool
    {
        return ! $user->isClient() && $user->hasCompanyFeature('expenses');
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
