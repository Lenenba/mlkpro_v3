<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;

class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManageExpenses($user);
    }

    public function view(User $user, Expense $expense): bool
    {
        return $this->canManageExpenses($user)
            && (int) $user->accountOwnerId() === (int) $expense->user_id;
    }

    public function create(User $user): bool
    {
        return $this->canManageExpenses($user);
    }

    public function update(User $user, Expense $expense): bool
    {
        return $this->canManageExpenses($user)
            && (int) $user->accountOwnerId() === (int) $expense->user_id;
    }

    public function delete(User $user, Expense $expense): bool
    {
        return $this->canManageExpenses($user)
            && (int) $user->accountOwnerId() === (int) $expense->user_id;
    }

    private function canManageExpenses(User $user): bool
    {
        return ! $user->isClient()
            && $user->isOwner()
            && (int) $user->id === (int) $user->accountOwnerId();
    }
}

