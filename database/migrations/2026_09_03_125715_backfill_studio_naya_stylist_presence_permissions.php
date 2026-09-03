<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('slug', ['view_presence', 'manage_own_presence'])
            ->pluck('id');

        if ($permissionIds->isEmpty()) {
            return;
        }

        $studioNayaAccountIds = DB::table('users')
            ->select('id')
            ->where('demo_type', 'scenario:studio_naya_coiffure');

        $roleIds = DB::table('company_roles')
            ->whereIn('company_id', $studioNayaAccountIds)
            ->get(['id', 'slug'])
            ->filter(fn (object $role): bool => $role->slug === 'demo_stylist'
                || str_starts_with((string) $role->slug, 'demo_stylist_'))
            ->pluck('id');

        if ($roleIds->isEmpty()) {
            return;
        }

        $timestamp = now();
        $rows = $roleIds->flatMap(
            fn (mixed $roleId) => $permissionIds->map(fn (mixed $permissionId): array => [
                'company_role_id' => (int) $roleId,
                'permission_id' => (int) $permissionId,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ])
        )->all();

        DB::table('company_role_permission')->insertOrIgnore($rows);
    }

    public function down(): void {}
};
