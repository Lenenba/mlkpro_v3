<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;
use App\Services\Rbac\AccessControl;
use App\Services\Rbac\CompanyModuleAccess;

class ProductPolicy
{
    /**
     * @var list<string>
     */
    private const SERVICE_VIEW_PERMISSIONS = [
        'services.view',
        'services.create',
        'services.edit',
        'services.delete',
    ];

    public function __construct(
        private CompanyModuleAccess $moduleAccess,
        private AccessControl $accessControl,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->canAccessProductModule($user);
    }

    public function create(User $user): bool
    {
        $accountId = $user->accountOwnerId();

        return $this->moduleAccess->allows($user, 'products', $accountId)
            && $this->accessControl->userHasPermission($user, 'products.create', $accountId);
    }

    public function view(User $user, Product $product): bool
    {
        if ((int) $user->accountOwnerId() !== (int) $product->user_id) {
            return false;
        }

        if ($product->item_type === Product::ITEM_TYPE_PRODUCT) {
            return $this->canAccessProductModule($user);
        }

        return $this->canAccessAccount(
            $user,
            self::SERVICE_VIEW_PERMISSIONS,
            $this->featureFor($product),
        );
    }

    public function update(User $user, Product $product): bool
    {
        if ((int) $user->accountOwnerId() !== (int) $product->user_id) {
            return false;
        }

        if ($product->item_type === Product::ITEM_TYPE_PRODUCT
            && ! $this->canAccessProductModule($user)) {
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

        if ($product->item_type === Product::ITEM_TYPE_PRODUCT
            && ! $this->canAccessProductModule($user)) {
            return false;
        }

        return $this->canAccessAccount(
            $user,
            [$product->item_type === Product::ITEM_TYPE_SERVICE ? 'services.delete' : 'products.delete'],
            $this->featureFor($product),
        );
    }

    public function adjustStock(User $user, Product $product): bool
    {
        if ((int) $user->accountOwnerId() !== (int) $product->user_id) {
            return false;
        }

        if (! $this->canAccessProductModule($user)) {
            return false;
        }

        return $this->canAccessAccount(
            $user,
            ['products.stock'],
            $this->featureFor($product),
        );
    }

    public function duplicate(User $user, Product $product): bool
    {
        if (! $this->view($user, $product) || ! $this->create($user)) {
            return false;
        }

        return (int) $product->stock <= 0 || $this->adjustStock($user, $product);
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

    private function canAccessProductModule(User $user): bool
    {
        $ownerId = $user->accountOwnerId();
        $owner = (int) $user->id === (int) $ownerId
            ? $user
            : User::query()->find($ownerId);

        return (bool) $owner?->hasCompanyFeature('products')
            && $this->moduleAccess->allows($user, 'products', (int) $ownerId);
    }

    private function featureFor(Product $product): string
    {
        return $product->item_type === Product::ITEM_TYPE_SERVICE ? 'services' : 'products';
    }
}
