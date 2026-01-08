<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    public function view(User $user, Product $product): bool
    {
        if ($user->accountOwnerId() !== $product->user_id) {
            return false;
        }

        if ($user->id === $product->user_id) {
            return true;
        }

        $membership = $user->relationLoaded('teamMembership')
            ? $user->teamMembership
            : $user->teamMembership()->first();
        if (!$membership || !$membership->hasPermission('sales.manage')) {
            return false;
        }

        $owner = User::query()->select(['id', 'company_type'])->find($product->user_id);

        return $owner?->company_type === 'products';
    }
    public function update(User $user, Product $product): bool
    {
        return $user->id === $product->user_id; // Autorise uniquement le propriétaire
    }

    public function delete(User $user, Product $product): bool
    {
        return $user->id === $product->user_id;
    }
}
