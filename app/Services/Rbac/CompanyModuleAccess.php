<?php

namespace App\Services\Rbac;

use App\Models\TeamMember;
use App\Models\User;

class CompanyModuleAccess
{
    /**
     * Permissions that open each complete module.
     *
     * Contextual workflows use their own permissions and do not grant access to
     * these complete directories.
     *
     * @var array<string, string>
     */
    private const PERMISSIONS = [
        'customers' => 'customers.view',
        'products' => 'products.view',
    ];

    public function allows(User $user, string $module, ?int $accountId = null): bool
    {
        $permission = self::PERMISSIONS[$module] ?? null;
        if (! $permission || $user->isClient() || $user->isPlatformAdmin()) {
            return false;
        }

        $accountId ??= $user->accountOwnerId();
        $owner = (int) $user->id === (int) $accountId
            ? $user
            : User::query()->find($accountId);

        if (! $owner || ! $this->isAvailableFor($owner, $module)) {
            return false;
        }

        if ($user->isSuperadmin()) {
            return true;
        }

        if ((int) $user->id === $accountId && $user->isAccountOwner()) {
            return true;
        }

        $membership = $this->membership($user, $accountId);
        if (! $membership) {
            return false;
        }

        return $membership->hasPermission($permission);
    }

    /**
     * Build the client-side module gates from the already-expanded permission list.
     *
     * @param  list<string>  $permissions
     * @return array<string, bool>
     */
    public function payload(?User $accountOwner, array $permissions, bool $hasFullAccess = false): array
    {
        return collect(self::PERMISSIONS)
            ->mapWithKeys(fn (string $permission, string $module): array => [
                $module => $accountOwner !== null
                    && $this->isAvailableFor($accountOwner, $module)
                    && ($hasFullAccess || in_array($permission, $permissions, true)),
            ])
            ->all();
    }

    private function isAvailableFor(User $accountOwner, string $module): bool
    {
        return match ($module) {
            'customers' => $accountOwner->company_type !== 'products'
                || $accountOwner->hasCompanyFeature('sales'),
            'products' => $accountOwner->hasCompanyFeature('products'),
            default => false,
        };
    }

    private function membership(User $user, int $accountId): ?TeamMember
    {
        if (
            $user->relationLoaded('teamMembership')
            && $user->teamMembership
            && (int) $user->teamMembership->account_id === $accountId
            && $user->teamMembership->is_active
        ) {
            $user->teamMembership->loadMissing('companyRole.permissions');

            return $user->teamMembership;
        }

        return TeamMember::query()
            ->forAccount($accountId)
            ->active()
            ->where('user_id', $user->id)
            ->with('companyRole.permissions')
            ->first();
    }
}
