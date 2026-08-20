<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    /**
     * @var list<string>
     */
    private const VIEW_PERMISSIONS = [
        'products.view',
        'products.create',
        'products.edit',
        'products.delete',
        'products.inventory',
        'products.stock',
        'sales.manage',
        'sales.pos',
    ];

    /**
     * @var list<string>
     */
    private const SERVICE_VIEW_PERMISSIONS = [
        'services.view',
        'services.create',
        'services.edit',
        'services.delete',
    ];

    public function viewAny(User $user): bool
    {
        return $this->canAccessAccount($user, self::VIEW_PERMISSIONS);
    }

    public function view(User $user, Product $product): bool
    {
        if ((int) $user->accountOwnerId() !== (int) $product->user_id) {
            return false;
        }

        return $this->canAccessAccount(
            $user,
            $product->item_type === Product::ITEM_TYPE_SERVICE
                ? self::SERVICE_VIEW_PERMISSIONS
                : self::VIEW_PERMISSIONS,
            $this->featureFor($product),
        );
    }

    public function update(User $user, Product $product): bool
    {
        if ((int) $user->accountOwnerId() !== (int) $product->user_id) {
            return false;
        }

        return $this->canAccessAccount(
            $user,
            [$product->item_type === Product::ITEM_TYPE_SERVICE ? 'services.edit' : 'products.edit'],
            $this->featureFor($product),
        );
    }

    public function delete(User $user, Product $product): bool
    {
        if ((int) $user->accountOwnerId() !== (int) $product->user_id) {
            return false;
        }

        return $this->canAccessAccount(
            $user,
            [$product->item_type === Product::ITEM_TYPE_SERVICE ? 'services.delete' : 'products.delete'],
            $this->featureFor($product),
        );
    }

    /**
     * @param  list<string>  $permissions
     */
    private function canAccessAccount(User $user, array $permissions, string $feature = 'products'): bool
    {
        $ownerId = $user->accountOwnerId();
        $owner = (int) $user->id === (int) $ownerId
            ? $user
            : User::query()->find($ownerId);

        if (! $owner || ! $owner->hasCompanyFeature($feature)) {
            return false;
        }

        if ((int) $user->id === (int) $owner->id) {
            return true;
        }

        $membership = $user->relationLoaded('teamMembership')
            ? $user->teamMembership
            : $user->teamMembership()->first();
        if (! $membership
            || ! $membership->is_active
            || (int) $membership->account_id !== (int) $owner->id) {
            return false;
        }

        foreach ($permissions as $permission) {
            if ($membership->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    private function featureFor(Product $product): string
    {
        return $product->item_type === Product::ITEM_TYPE_SERVICE ? 'services' : 'products';
    }
}
