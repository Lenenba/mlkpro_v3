<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('team_members', 'company_role_id')) {
            Schema::table('team_members', function (Blueprint $table) {
                $table->foreignId('company_role_id')
                    ->nullable()
                    ->after('role')
                    ->constrained('company_roles')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('team_members', 'company_role_id')) {
            Schema::table('team_members', function (Blueprint $table) {
                $table->dropConstrainedForeignId('company_role_id');
            });
        }
    }
};
