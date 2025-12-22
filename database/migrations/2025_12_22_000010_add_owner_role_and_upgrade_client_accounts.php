<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $ownerRoleId = DB::table('roles')->where('name', 'owner')->value('id');
        if (!$ownerRoleId) {
            $ownerRoleId = DB::table('roles')->insertGetId([
                'name' => 'owner',
                'description' => 'Account owner role',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $clientRoleId = DB::table('roles')->where('name', 'client')->value('id');
        if ($clientRoleId) {
            DB::table('users')
                ->where('role_id', $clientRoleId)
                ->where(function ($query) {
                    $query->whereNotNull('company_name')
                        ->orWhereNotNull('onboarding_completed_at');
                })
                ->update([
                    'role_id' => $ownerRoleId,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        $ownerRoleId = DB::table('roles')->where('name', 'owner')->value('id');
        $clientRoleId = DB::table('roles')->where('name', 'client')->value('id');

        if ($ownerRoleId && $clientRoleId) {
            DB::table('users')
                ->where('role_id', $ownerRoleId)
                ->update([
                    'role_id' => $clientRoleId,
                    'updated_at' => now(),
                ]);
        }

        if ($ownerRoleId) {
            DB::table('roles')->where('id', $ownerRoleId)->delete();
        }
    }
};
