<?php

use App\Models\User;
use App\Services\Demo\DemoAccessRoleProvisioner;
use App\Services\Rbac\RbacCatalogSynchronizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        app(RbacCatalogSynchronizer::class)->synchronize();

        $demoAccountIds = DB::table('team_members')
            ->select('account_id')
            ->whereNull('company_role_id');

        User::query()
            ->where(function (Builder $query): void {
                $query->where('is_demo', true)
                    ->orWhere('is_demo_user', true);
            })
            ->whereIn('id', $demoAccountIds)
            ->eachById(function (User $account): void {
                app(DemoAccessRoleProvisioner::class)->provision($account, synchronizeCatalog: false);
            });
    }

    public function down(): void {}
};
