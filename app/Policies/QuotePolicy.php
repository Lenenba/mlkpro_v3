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
}
