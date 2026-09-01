<?php

namespace App\Services\Rbac;

use App\Models\CompanyRole;
use App\Models\Permission;

final readonly class RbacCatalogSynchronizer
{
    public function __construct(private PermissionCatalog $catalog) {}

    public function synchronize(): void
    {
        foreach ($this->catalog->permissions() as $permission) {
            Permission::query()->updateOrCreate(
                ['slug' => $permission['slug']],
                [
                    'group' => $permission['group'],
                    'name' => $permission['name'],
                    'description' => $permission['description'],
                ]
            );
        }

        foreach ($this->catalog->defaultRoles() as $roleData) {
            $permissionSlugs = $roleData['permissions'];
            unset($roleData['permissions']);

            $role = CompanyRole::query()->updateOrCreate(
                [
                    'company_id' => null,
                    'slug' => $roleData['slug'],
                ],
                $roleData
            );

            $permissionIds = Permission::query()
                ->whereIn('slug', $permissionSlugs)
                ->pluck('id')
                ->all();

            $role->permissions()->sync($permissionIds);
        }
    }
}
