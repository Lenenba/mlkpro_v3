<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('team_members')) {
            return;
        }

        Schema::table('team_members', function (Blueprint $table) {
            if (Schema::hasColumn('team_members', 'role')) {
                return;
            }

            $table->string('role')->default('member')->after('user_id');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('team_members')) {
            return;
        }

        Schema::table('team_members', function (Blueprint $table) {
            if (!Schema::hasColumn('team_members', 'role')) {
                return;
            }

            $table->dropColumn('role');
        });
    }
};

