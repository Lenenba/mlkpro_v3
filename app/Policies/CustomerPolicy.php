<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Customer $customer): bool
    {
        return $user->id === $customer->user_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Customer $customer): bool
    {
        return $user->id === $customer->user_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function view(User $user, Customer $customer): bool
    {
        if ((int) $user->accountOwnerId() !== (int) $customer->user_id) {
            return false;
        }

        if ((int) $user->id === (int) $customer->user_id) {
            return true;
        }

        $owner = User::query()->find($customer->user_id);
        if (! $owner) {
            return false;
        }

        $membership = $user->relationLoaded('teamMembership')
            ? $user->teamMembership
            : $user->teamMembership()->first();
        if (! $membership
            || ! $membership->is_active
            || (int) $membership->account_id !== (int) $owner->id) {
            return false;
        }

        if ($membership->hasPermission('customers.view')
            || $membership->hasPermission('customers.create')) {
            return true;
        }

        $capabilityPermissions = [
            'sales' => ['sales.manage', 'sales.pos'],
            'reservations' => ['reservations.view', 'reservations.queue', 'reservations.manage'],
            'jobs' => ['jobs.view', 'jobs.edit'],
            'tasks' => ['tasks.view', 'tasks.create', 'tasks.edit', 'tasks.delete'],
        ];

        foreach ($capabilityPermissions as $feature => $permissions) {
            if (! $owner->hasCompanyFeature($feature)) {
                continue;
            }

            foreach ($permissions as $permission) {
                if ($membership->hasPermission($permission)) {
                    return true;
                }
            }
        }

        return false;
    }
}
