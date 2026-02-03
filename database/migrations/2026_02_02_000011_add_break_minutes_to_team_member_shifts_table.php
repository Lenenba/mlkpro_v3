<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_member_shifts', function (Blueprint $table) {
            $table->unsignedInteger('break_minutes')->nullable()->after('end_time');
        });
    }

    public function down(): void
    {
        Schema::table('team_member_shifts', function (Blueprint $table) {
            $table->dropColumn('break_minutes');
        });
    }
};
