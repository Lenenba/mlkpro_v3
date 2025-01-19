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
    public function edit(User $user, Quote $quote): bool
    {
        return $user->id === $quote->user_id;
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
    public function destroy(User $user, Quote $quote): bool
    {
        return $user->id === $quote->user_id;
    }
}
