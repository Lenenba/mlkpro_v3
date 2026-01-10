<?php

namespace App\Policies;

use App\Models\Quote;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class QuotePolicy
{
    /**
     * Determine whether the user can view the model.
     */
    public function viewAny(User $user): bool
    {
        $accountId = $user->accountOwnerId();
        if ($user->id === $accountId) {
            return true;
        }

        $membership = $this->membership($user, $accountId);
        if (!$membership) {
            return false;
        }

        return $membership->hasPermission('quotes.view') || $membership->hasPermission('quotes.edit') || $membership->hasPermission('quotes.create');
    }

    public function create(User $user): bool
    {
        $accountId = $user->accountOwnerId();
        if ($user->id === $accountId) {
            return true;
        }

        $membership = $this->membership($user, $accountId);
        if (!$membership) {
            return false;
        }

        return $membership->hasPermission('quotes.create') || $membership->hasPermission('quotes.edit');
    }

    /**
     * Determine whether the user can edit the model.
     */
    public function edit(User $user, Quote $quote): bool|Response
    {
        $accountId = $user->accountOwnerId();
        if ($quote->user_id !== $accountId) {
            return false;
        }

        if ($user->id !== $accountId) {
            $membership = $this->membership($user, $accountId);
            if (!$membership || !$membership->hasPermission('quotes.edit')) {
                return false;
            }
        }

        if ($quote->isLocked()) {
            return Response::deny('This quote is locked.');
        }

        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function show(User $user, Quote $quote): bool
    {
        $accountId = $user->accountOwnerId();
        if ($quote->user_id !== $accountId) {
            return false;
        }

        if ($user->id === $accountId) {
            return true;
        }

        $membership = $this->membership($user, $accountId);
        if (!$membership) {
            return false;
        }

        return $membership->hasPermission('quotes.view') || $membership->hasPermission('quotes.edit');
    }

    /**
     * Determine whether the user can destroy the model.
     */
    public function destroy(User $user, Quote $quote): bool|Response
    {
        $accountId = $user->accountOwnerId();
        if ($quote->user_id !== $accountId) {
            return false;
        }

        if ($user->id !== $accountId) {
            $membership = $this->membership($user, $accountId);
            if (!$membership || !$membership->hasPermission('quotes.edit')) {
                return false;
            }
        }

        if ($quote->isArchived()) {
            return Response::deny('This quote is already archived.');
        }

        return true;
    }

    public function restore(User $user, Quote $quote): bool|Response
    {
        $accountId = $user->accountOwnerId();
        if ($quote->user_id !== $accountId) {
            return false;
        }

        if ($user->id !== $accountId) {
            $membership = $this->membership($user, $accountId);
            if (!$membership || !$membership->hasPermission('quotes.edit')) {
                return false;
            }
        }

        if (!$quote->isArchived()) {
            return Response::deny('This quote is not archived.');
        }

        return true;
    }

    private function membership(User $user, int $accountId): ?TeamMember
    {
        return TeamMember::query()
            ->forAccount($accountId)
            ->active()
            ->where('user_id', $user->id)
            ->first();
    }
}
