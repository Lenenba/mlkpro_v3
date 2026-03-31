<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_workspace_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('company_type', 40);
            $table->string('company_sector', 80)->nullable();
            $table->string('seed_profile', 40);
            $table->unsignedSmallInteger('team_size')->default(1);
            $table->string('locale', 10)->default('fr');
            $table->string('timezone', 120)->default('America/Toronto');
            $table->unsignedSmallInteger('expiration_days')->default(14);
            $table->json('selected_modules');
            $table->text('suggested_flow')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['company_type', 'company_sector', 'is_active'], 'demo_workspace_templates_type_sector_active_idx');
            $table->index(['is_default', 'is_active'], 'demo_workspace_templates_default_active_idx');
        });

        Schema::table('demo_workspaces', function (Blueprint $table) {
            $table->foreignId('demo_workspace_template_id')
                ->nullable()
                ->after('created_by_user_id')
                ->constrained('demo_workspace_templates')
                ->nullOnDelete();
            $table->foreignId('sent_by_user_id')
                ->nullable()
                ->after('owner_user_id')
                ->constrained('users')
                ->nullOnDelete();
            $table->text('suggested_flow')->nullable()->after('internal_notes');
            $table->timestamp('sent_at')->nullable()->after('last_seeded_at');

            $table->index(['sent_at', 'expires_at'], 'demo_workspaces_sent_expiration_idx');
        });
    }

    public function down(): void
    {
        Schema::table('demo_workspaces', function (Blueprint $table) {
            $table->dropIndex('demo_workspaces_sent_expiration_idx');
            $table->dropConstrainedForeignId('demo_workspace_template_id');
            $table->dropConstrainedForeignId('sent_by_user_id');
            $table->dropColumn([
                'suggested_flow',
                'sent_at',
            ]);
        });

        Schema::dropIfExists('demo_workspace_templates');
    }
};
