<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('demo_workspaces', function (Blueprint $table) {
            $table->string('scenario_key', 120)->nullable()->index();
            $table->string('data_volume', 20)->nullable();
            $table->date('reference_date')->nullable();
            $table->unsignedBigInteger('random_seed')->nullable();
            $table->unsignedSmallInteger('scenario_version')->nullable();
        });

        Schema::table('demo_workspace_templates', function (Blueprint $table) {
            $table->string('scenario_key', 120)->nullable()->index();
            $table->string('data_volume', 20)->nullable();
            $table->date('reference_date')->nullable();
            $table->unsignedBigInteger('random_seed')->nullable();
            $table->unsignedSmallInteger('scenario_version')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('demo_workspaces', function (Blueprint $table) {
            $table->dropIndex(['scenario_key']);
            $table->dropColumn([
                'scenario_key',
                'data_volume',
                'reference_date',
                'random_seed',
                'scenario_version',
            ]);
        });

        Schema::table('demo_workspace_templates', function (Blueprint $table) {
            $table->dropIndex(['scenario_key']);
            $table->dropColumn([
                'scenario_key',
                'data_volume',
                'reference_date',
                'random_seed',
                'scenario_version',
            ]);
        });
    }
};
