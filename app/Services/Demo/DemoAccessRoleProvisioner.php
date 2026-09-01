<?php

namespace App\Services\Demo;

use App\Models\CompanyRole;
use App\Models\Permission;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Rbac\PermissionCatalog;
use App\Services\Rbac\RbacCatalogSynchronizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class DemoAccessRoleProvisioner
{
    public function __construct(
        private PermissionCatalog $permissionCatalog,
        private RbacCatalogSynchronizer $catalogSynchronizer,
    ) {}

    /**
     * @return array{company_roles: int, role_assignments: int}
     */
    public function provision(User $accountOwner, bool $synchronizeCatalog = true): array
    {
        return DB::transaction(
            fn (): array => $this->provisionAccount($accountOwner, $synchronizeCatalog),
            3,
        );
    }

    /**
     * @return array{company_roles: int, role_assignments: int}
     */
    private function provisionAccount(User $accountOwner, bool $synchronizeCatalog): array
    {
        $accountId = (int) $accountOwner->id;
        $members = TeamMember::query()
            ->forAccount($accountId)
            ->with('companyRole.permissions')
            ->oldest('id')
            ->lockForUpdate()
            ->get();
        $assignments = $members
            ->reject(fn (TeamMember $member): bool => $this->hasValidAccessRole($member, $accountId))
            ->map(fn (TeamMember $member): array => $this->assignmentProfile($member, $accountId));

        if ($assignments->isEmpty()) {
            return $this->roleSummary($members, $accountId);
        }

        if ($synchronizeCatalog) {
            $this->catalogSynchronizer->synchronize();
        }

        foreach ($assignments->groupBy('role_key') as $roleAssignments) {
            $variants = $roleAssignments->groupBy('signature')->sortKeys();
            $hasMultipleVariants = $variants->count() > 1;

            foreach ($variants->values() as $variantIndex => $variantAssignments) {
                $profile = $variantAssignments->first();

                if (! is_array($profile)) {
                    continue;
                }

                $role = $this->resolveCompanyRole(
                    $accountId,
                    $profile,
                    $hasMultipleVariants ? (int) $variantIndex + 1 : null,
                );

                $role->permissions()->sync(
                    Permission::query()
                        ->whereIn('slug', $profile['canonical_permissions'])
                        ->pluck('id')
                        ->all()
                );

                $variantAssignments->each(function (array $assignment) use ($role): void {
                    $assignment['member']->forceFill([
                        'role' => $assignment['operational_role'],
                        'company_role_id' => $role->id,
                        'permissions' => $assignment['unmapped_permissions'],
                    ])->save();
                });
            }
        }

        $assignedMembers = TeamMember::query()
            ->forAccount($accountId)
            ->with('companyRole')
            ->get()
            ->filter(fn (TeamMember $member): bool => $this->hasValidAccessRole($member, $accountId));

        return $this->roleSummary($assignedMembers, $accountId);
    }

    /**
     * @return array{
     *     member: TeamMember,
     *     role_key: string,
     *     signature: string,
     *     operational_role: string,
     *     canonical_permissions: array<int, string>,
     *     unmapped_permissions: array<int, string>
     * }
     */
    private function assignmentProfile(TeamMember $member, int $accountId): array
    {
        $roleKey = Str::snake(trim((string) $member->role)) ?: 'member';
        $permissions = collect($member->permissions ?? [])
            ->filter(fn (mixed $permission): bool => is_string($permission) && trim($permission) !== '')
            ->map(fn (string $permission): string => trim($permission))
            ->unique()
            ->sort()
            ->values()
            ->all();
        $permissionProfile = $this->permissionProfile($permissions);

        return [
            'member' => $member,
            'role_key' => $roleKey,
            'signature' => hash('sha256', json_encode([
                $permissionProfile['canonical'],
                $permissionProfile['unmapped'],
            ], JSON_THROW_ON_ERROR)),
            'operational_role' => $this->operationalRole($member, $accountId),
            'canonical_permissions' => $permissionProfile['canonical'],
            'unmapped_permissions' => $permissionProfile['unmapped'],
        ];
    }

    /**
     * @param  array<int, string>  $permissions
     * @return array{canonical: array<int, string>, unmapped: array<int, string>}
     */
    private function permissionProfile(array $permissions): array
    {
        $catalogSlugs = array_fill_keys($this->permissionCatalog->permissionSlugs(), true);
        $canonicalPermissions = [];
        $unmappedPermissions = [];

        foreach ($permissions as $permission) {
            $matches = collect($this->permissionCatalog->expand(
                $this->legacyDemoPermissionAliases($permission)
            ))
                ->filter(fn (string $candidate): bool => isset($catalogSlugs[$candidate]))
                ->values()
                ->all();

            if ($matches === []) {
                $unmappedPermissions[] = $permission;

                continue;
            }

            $canonicalPermissions = [...$canonicalPermissions, ...$matches];
        }

        $canonicalPermissions = array_values(array_unique($canonicalPermissions));
        $unmappedPermissions = array_values(array_unique($unmappedPermissions));
        sort($canonicalPermissions);
        sort($unmappedPermissions);

        return [
            'canonical' => $canonicalPermissions,
            'unmapped' => $unmappedPermissions,
        ];
    }

    /**
     * @param  array{
     *     member: TeamMember,
     *     role_key: string,
     *     signature: string,
     *     operational_role: string,
     *     canonical_permissions: array<int, string>,
     *     unmapped_permissions: array<int, string>
     * }  $profile
     */
    private function resolveCompanyRole(int $accountId, array $profile, ?int $variant): CompanyRole
    {
        $roleKey = $profile['role_key'];
        $baseSlug = 'demo_'.Str::snake($roleKey);
        $name = Str::headline($roleKey);
        $signature = Str::substr($profile['signature'], 0, 12);
        $usesSignature = $variant !== null;
        $slug = $usesSignature ? $baseSlug.'_'.$signature : $baseSlug;
        $collisionSuffix = 1;

        if ($variant !== null) {
            $name .= ' '.$variant;
        }

        while ($existingRole = CompanyRole::query()
            ->with('permissions:id,slug')
            ->where('company_id', $accountId)
            ->where('slug', $slug)
            ->first()) {
            if ($existingRole->is_active && $this->roleMatchesProfile($existingRole, $profile)) {
                return $existingRole;
            }

            if (! $usesSignature) {
                $usesSignature = true;
                $slug = $baseSlug.'_'.$signature;
                $name = Str::headline($roleKey).' Variant';

                continue;
            }

            $collisionSuffix++;
            $slug = $baseSlug.'_'.$signature.'_'.$collisionSuffix;
            $name = Str::headline($roleKey).' Variant '.$collisionSuffix;
        }

        return CompanyRole::query()->create([
            'company_id' => $accountId,
            'slug' => $slug,
            'name' => $name,
            'description' => 'Access role generated for this demo workspace.',
            'is_system' => false,
            'is_default' => false,
            'is_editable' => true,
            'is_deletable' => true,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array{
     *     member: TeamMember,
     *     role_key: string,
     *     signature: string,
     *     operational_role: string,
     *     canonical_permissions: array<int, string>,
     *     unmapped_permissions: array<int, string>
     * }  $profile
     */
    private function roleMatchesProfile(CompanyRole $role, array $profile): bool
    {
        return $role->permissions
            ->pluck('slug')
            ->sort()
            ->values()
            ->all() === $profile['canonical_permissions'];
    }

    private function operationalRole(TeamMember $member, int $accountId): string
    {
        $currentRole = trim((string) $member->role);

        if (in_array($currentRole, ['admin', 'member', 'seller', 'sales_manager'], true)) {
            return $currentRole;
        }

        if ((int) $member->user_id === $accountId) {
            return 'admin';
        }

        return match (data_get($member->planning_rules, 'demo_access_role')) {
            'manager' => 'admin',
            'front_desk' => 'sales_manager',
            default => 'member',
        };
    }

    private function hasValidAccessRole(TeamMember $member, int $accountId): bool
    {
        return $member->companyRole !== null
            && $member->companyRole->isAvailableForCompany($accountId);
    }

    /**
     * @return array<int, string>
     */
    private function legacyDemoPermissionAliases(string $permission): array
    {
        return match ($permission) {
            'jobs' => ['jobs.view', 'jobs.edit'],
            'tasks' => ['tasks.view', 'tasks.edit'],
            default => [$permission],
        };
    }

    /**
     * @param  Collection<int, TeamMember>  $members
     * @return array{company_roles: int, role_assignments: int}
     */
    private function roleSummary(Collection $members, int $accountId): array
    {
        $assignedMembers = $members->filter(
            fn (TeamMember $member): bool => $this->hasValidAccessRole($member, $accountId)
        );

        return [
            'company_roles' => $assignedMembers->pluck('company_role_id')->unique()->count(),
            'role_assignments' => $assignedMembers->count(),
        ];
    }
}
