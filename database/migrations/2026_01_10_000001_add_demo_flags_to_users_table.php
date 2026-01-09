<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false)->after('is_suspended');
            $table->string('demo_type', 20)->nullable()->after('is_demo');
            $table->boolean('is_demo_user')->default(false)->after('demo_type');
            $table->string('demo_role', 30)->nullable()->after('is_demo_user');

            $table->index(['is_demo', 'demo_type']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_demo', 'demo_type']);
            $table->dropColumn(['is_demo', 'demo_type', 'is_demo_user', 'demo_role']);
        });
    }
};
