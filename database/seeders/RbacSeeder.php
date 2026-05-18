<?php

namespace Database\Seeders;

use App\Models\CompanyRole;
use App\Models\Permission;
use App\Services\Rbac\PermissionCatalog;
use Illuminate\Database\Seeder;

class RbacSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = app(PermissionCatalog::class);

        foreach ($catalog->permissions() as $permission) {
            Permission::query()->updateOrCreate(
                ['slug' => $permission['slug']],
                [
                    'group' => $permission['group'],
                    'name' => $permission['name'],
                    'description' => $permission['description'],
                ]
            );
        }

        foreach ($catalog->defaultRoles() as $roleData) {
            $permissions = $roleData['permissions'];
            unset($roleData['permissions']);

            $role = CompanyRole::query()->updateOrCreate(
                [
                    'company_id' => null,
                    'slug' => $roleData['slug'],
                ],
                $roleData
            );

            $permissionIds = Permission::query()
                ->whereIn('slug', $permissions)
                ->pluck('id')
                ->all();

            $role->permissions()->sync($permissionIds);
        }
    }
}
