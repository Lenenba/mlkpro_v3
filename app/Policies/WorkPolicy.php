<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Work;

class WorkPolicy
{
    public function update(User $user, Work $work): bool
    {
        return $user->id === $work->user_id; // Autorise uniquement le propriétaire
    }

    public function edit(User $user, Work $work): bool
    {
        return $user->id === $work->user_id;
    }

    public function view(User $user, Work $work): bool
    {
        return $user->id === $work->user_id;
    }
}
