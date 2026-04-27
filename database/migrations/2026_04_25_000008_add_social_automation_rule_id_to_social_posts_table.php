<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('social_posts') || Schema::hasColumn('social_posts', 'social_automation_rule_id')) {
            return;
        }

        Schema::table('social_posts', function (Blueprint $table): void {
            $table->foreignId('social_automation_rule_id')
                ->nullable()
                ->after('source_id')
                ->constrained('social_automation_rules')
                ->nullOnDelete();

            $table->index(
                ['social_automation_rule_id', 'status'],
                'social_posts_automation_rule_status_idx'
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('social_posts') || ! Schema::hasColumn('social_posts', 'social_automation_rule_id')) {
            return;
        }

        Schema::table('social_posts', function (Blueprint $table): void {
            $table->dropIndex('social_posts_automation_rule_status_idx');
            $table->dropConstrainedForeignId('social_automation_rule_id');
        });
    }
};
