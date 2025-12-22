<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('team_members')) {
            return;
        }

        // Fresh installs already have the new schema.
        if (Schema::hasColumn('team_members', 'account_id')) {
            return;
        }

        Schema::table('team_members', function (Blueprint $table) {
            $table->unsignedBigInteger('account_id')->nullable()->after('id');
            $table->json('permissions')->nullable()->after('phone');
        });

        // Preserve the previous owner relationship: old `user_id` was the account owner.
        DB::table('team_members')->update(['account_id' => DB::raw('user_id')]);

        // Ensure we have an "employee" role for team member logins.
        $employeeRoleId = DB::table('roles')->where('name', 'employee')->value('id');
        if (!$employeeRoleId) {
            $employeeRoleId = DB::table('roles')->insertGetId([
                'name' => 'employee',
                'description' => 'Employee role',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Convert existing rows (name/email) into real login users, and repoint `user_id` to the member user.
        $members = DB::table('team_members')
            ->select(['id', 'name', 'email'])
            ->orderBy('id')
            ->get();

        foreach ($members as $member) {
            $email = $member->email ?: 'team.member.' . $member->id . '@example.com';
            $name = $member->name ?: ('Team member #' . $member->id);

            $userId = DB::table('users')->where('email', $email)->value('id');
            if (!$userId) {
                $userId = DB::table('users')->insertGetId([
                    'name' => $name,
                    'email' => $email,
                    'role_id' => $employeeRoleId,
                    'password' => Hash::make('password'),
                    'remember_token' => Str::random(10),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('team_members')
                ->where('id', $member->id)
                ->update([
                    'user_id' => $userId,
                    'permissions' => json_encode(['jobs.view']),
                ]);
        }

        // Enforce constraints and clean the legacy columns.
        Schema::table('team_members', function (Blueprint $table) {
            $table->foreign('account_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['account_id', 'user_id']);
            $table->dropColumn(['name', 'email']);
        });

        DB::statement('ALTER TABLE `team_members` MODIFY `account_id` BIGINT UNSIGNED NOT NULL');
    }

    public function down(): void
    {
        // This migration is intended as a one-way upgrade from a legacy schema.
        // If you need to revert, use a database backup or migrate:fresh in dev.
    }
};

