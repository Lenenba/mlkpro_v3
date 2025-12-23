<?php

namespace App\Policies;

use App\Models\Quote;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class QuotePolicy
{
    /**
     * Determine whether the user can view the model.
     */
    public function create(User $user, Quote $quote): bool
    {
        return true;
    }

    /**
     * Determine whether the user can edit the model.
     */
    public function edit(User $user, Quote $quote): bool|Response
    {
        if ($user->id !== $quote->user_id) {
            return false;
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
        return $user->id === $quote->user_id;
    }

    /**
     * Determine whether the user can destroy the model.
     */
    public function destroy(User $user, Quote $quote): bool|Response
    {
        if ($user->id !== $quote->user_id) {
            return false;
        }

        if ($quote->isArchived()) {
            return Response::deny('This quote is already archived.');
        }

        return true;
    }

    public function restore(User $user, Quote $quote): bool|Response
    {
        if ($user->id !== $quote->user_id) {
            return false;
        }

        if (!$quote->isArchived()) {
            return Response::deny('This quote is not archived.');
        }

        return true;
    }
}
