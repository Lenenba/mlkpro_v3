<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    public function update(User $user, Product $product): bool
    {
        return $user->id === $product->user_id; // Autorise uniquement le propriétaire
    }

    public function delete(User $user, Product $product): bool
    {
        return $user->id === $product->user_id;
    }
}
