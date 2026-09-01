<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;
use App\Services\Rbac\AccessControl;
use App\Services\Rbac\CompanyModuleAccess;

class CustomerPolicy
{
    public function __construct(
        private CompanyModuleAccess $moduleAccess,
        private AccessControl $accessControl,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->moduleAccess->allows($user, 'customers');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Customer $customer): bool
    {
        return (int) $user->accountOwnerId() === (int) $customer->user_id
            && $this->moduleAccess->allows($user, 'customers', (int) $customer->user_id)
            && $this->accessControl->userHasPermission($user, 'customers.edit', (int) $customer->user_id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Customer $customer): bool
    {
        return (int) $user->accountOwnerId() === (int) $customer->user_id
            && $this->moduleAccess->allows($user, 'customers', (int) $customer->user_id)
            && $this->accessControl->userHasPermission($user, 'customers.delete', (int) $customer->user_id);
    }

    public function create(User $user): bool
    {
        $accountId = $user->accountOwnerId();

        return $this->moduleAccess->allows($user, 'customers', $accountId)
            && $this->accessControl->userHasPermission($user, 'customers.create', $accountId);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function view(User $user, Customer $customer): bool
    {
        if ((int) $user->accountOwnerId() !== (int) $customer->user_id) {
            return false;
        }

        return $this->moduleAccess->allows($user, 'customers', (int) $customer->user_id);
    }

    public function viewNotes(User $user, Customer $customer): bool
    {
        if (! $this->view($user, $customer)) {
            return false;
        }

        return $this->accessControl->userHasPermission($user, 'view_client_notes', (int) $customer->user_id)
            || $this->accessControl->userHasPermission($user, 'manage_client_notes', (int) $customer->user_id);
    }

    public function manageNotes(User $user, Customer $customer): bool
    {
        return $this->view($user, $customer)
            && $this->accessControl->userHasPermission($user, 'manage_client_notes', (int) $customer->user_id);
    }

    public function logActivity(User $user, Customer $customer): bool
    {
        if (! $this->view($user, $customer)) {
            return false;
        }

        if ((int) $user->id === (int) $customer->user_id) {
            return true;
        }

        return $this->accessControl->userHasPermission($user, 'sales.manage', (int) $customer->user_id);
    }
}
