<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('demo_workspace_templates', function (Blueprint $table) {
            $table->json('scenario_packs')->nullable()->after('selected_modules');
            $table->json('branding_profile')->nullable()->after('scenario_packs');
        });

        Schema::table('demo_workspaces', function (Blueprint $table) {
            $table->foreignId('cloned_from_demo_workspace_id')
                ->nullable()
                ->after('demo_workspace_template_id')
                ->constrained('demo_workspaces')
                ->nullOnDelete();
            $table->foreignId('last_reset_by_user_id')
                ->nullable()
                ->after('sent_by_user_id')
                ->constrained('users')
                ->nullOnDelete();
            $table->json('scenario_packs')->nullable()->after('selected_modules');
            $table->json('branding_profile')->nullable()->after('scenario_packs');
            $table->json('baseline_snapshot')->nullable()->after('branding_profile');
            $table->timestamp('baseline_created_at')->nullable()->after('sent_at');
            $table->timestamp('last_reset_at')->nullable()->after('baseline_created_at');

            $table->index('cloned_from_demo_workspace_id', 'demo_workspaces_clone_source_idx');
            $table->index(['baseline_created_at', 'last_reset_at'], 'demo_workspaces_baseline_reset_idx');
        });
    }

    public function down(): void
    {
        Schema::table('demo_workspaces', function (Blueprint $table) {
            $table->dropIndex('demo_workspaces_clone_source_idx');
            $table->dropIndex('demo_workspaces_baseline_reset_idx');
            $table->dropConstrainedForeignId('cloned_from_demo_workspace_id');
            $table->dropConstrainedForeignId('last_reset_by_user_id');
            $table->dropColumn([
                'scenario_packs',
                'branding_profile',
                'baseline_snapshot',
                'baseline_created_at',
                'last_reset_at',
            ]);
        });

        Schema::table('demo_workspace_templates', function (Blueprint $table) {
            $table->dropColumn([
                'scenario_packs',
                'branding_profile',
            ]);
        });
    }
};
