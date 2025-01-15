<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class QuotePolicy
{
    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Quote $quote): bool
    {
        return $user->id === $quote->user_id;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function create(User $user, Customer $customer): bool
    {
        return $user->id === $customer->user_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Quote $quote): bool
    {
        return $user->id === $quote->user_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function view(User $user, Quote $quote): bool
    {
        return $user->id === $quote->user_id;
    }
}
