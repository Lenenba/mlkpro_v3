<?php

namespace App\Services\Rbac;

use App\Models\CompanyRole;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class CompanyRoleTemplateProvisioner
{
    public function __construct(
        private CompanyRoleTemplateCatalog $templateCatalog,
        private PermissionCatalog $permissionCatalog,
        private RbacCatalogSynchronizer $catalogSynchronizer,
        private CompanyPermissionAvailability $permissionAvailability,
    ) {}

    /**
     * Create the sector-specific starter roles once without changing an existing role.
     *
     * @return Collection<int, CompanyRole>
     */
    public function provision(User $accountOwner): Collection
    {
        $accountId = (int) $accountOwner->accountOwnerId();
        if ($accountId !== (int) $accountOwner->id) {
            throw new InvalidArgumentException('Standard roles can only be provisioned for an account owner.');
        }

        $this->ensurePermissionCatalog();

        return DB::transaction(function () use ($accountId): Collection {
            $lockedOwner = User::query()
                ->whereKey($accountId)
                ->lockForUpdate()
                ->firstOrFail();
            $existingStandardRoles = CompanyRole::query()
                ->where('company_id', $accountId)
                ->where('is_system', false)
                ->where('is_default', true)
                ->with('permissions')
                ->get();

            if ($existingStandardRoles->isNotEmpty()) {
                return $existingStandardRoles->values();
            }

            $templates = collect($this->templateCatalog->templatesFor(
                $lockedOwner->company_type,
                $lockedOwner->company_sector,
            ));
            $permissions = Permission::query()
                ->whereIn('slug', $templates->pluck('permissions')->flatten()->unique()->values())
                ->get(['id', 'group', 'slug'])
                ->keyBy('slug');
            $availablePermissionLookup = array_fill_keys(
                $this->permissionAvailability->availableSlugs($lockedOwner),
                true,
            );

            return $templates->map(function (array $template) use (
                $accountId,
                $availablePermissionLookup,
                $permissions,
            ): CompanyRole {
                $role = CompanyRole::query()->firstOrCreate(
                    [
                        'company_id' => $accountId,
                        'slug' => $template['slug'],
                    ],
                    [
                        'name' => $template['name'],
                        'description' => $template['description'],
                        'is_system' => false,
                        'is_default' => true,
                        'is_editable' => true,
                        'is_deletable' => false,
                        'is_active' => true,
                    ],
                );

                if ($role->wasRecentlyCreated) {
                    $permissionIds = collect($template['permissions'])
                        ->map(fn (string $slug): ?Permission => $permissions->get($slug))
                        ->filter(fn (?Permission $permission): bool => $permission !== null)
                        ->filter(fn (Permission $permission): bool => isset($availablePermissionLookup[$permission->slug]))
                        ->pluck('id')
                        ->all();

                    $role->permissions()->sync($permissionIds);
                }

                return $role->loadMissing('permissions');
            })->values();
        }, 3);
    }

    /**
     * @return Collection<string, CompanyRole>
     */
    public function invitationRoles(User $accountOwner): Collection
    {
        $accountId = (int) $accountOwner->accountOwnerId();
        if ($accountId !== (int) $accountOwner->id) {
            return collect();
        }

        $slugsByInvitationRole = $this->templateCatalog->invitationRoleSlugs(
            $accountOwner->company_type,
            $accountOwner->company_sector,
        );
        $rolesBySlug = CompanyRole::query()
            ->where('company_id', $accountId)
            ->where('is_active', true)
            ->whereIn('slug', array_values($slugsByInvitationRole))
            ->get()
            ->keyBy('slug');

        return collect($slugsByInvitationRole)
            ->map(fn (string $slug): ?CompanyRole => $rolesBySlug->get($slug))
            ->filter(fn (?CompanyRole $role): bool => $role !== null);
    }

    private function ensurePermissionCatalog(): void
    {
        $permissionSlugs = $this->permissionCatalog->permissionSlugs();
        $catalogPermissionCount = Permission::query()
            ->whereIn('slug', $permissionSlugs)
            ->count();

        if ($catalogPermissionCount === count($permissionSlugs)) {
            return;
        }

        $this->catalogSynchronizer->synchronize();
    }
}
