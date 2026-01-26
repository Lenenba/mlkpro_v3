<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_support_tickets', function (Blueprint $table) {
            $table->index(['account_id', 'status'], 'support_tickets_account_status_idx');
            $table->index(['account_id', 'priority'], 'support_tickets_account_priority_idx');
            $table->index(['account_id', 'created_at'], 'support_tickets_account_created_idx');
            $table->index('status', 'support_tickets_status_idx');
            $table->index('priority', 'support_tickets_priority_idx');
            $table->index('sla_due_at', 'support_tickets_sla_due_idx');
            $table->index('assigned_to_user_id', 'support_tickets_assigned_to_idx');
        });

        Schema::table('platform_audit_logs', function (Blueprint $table) {
            $table->index('user_id', 'audit_logs_user_idx');
            $table->index('action', 'audit_logs_action_idx');
            $table->index('created_at', 'audit_logs_created_idx');
            $table->index(['subject_type', 'subject_id'], 'audit_logs_subject_idx');
            $table->index(['subject_type', 'subject_id', 'created_at'], 'audit_logs_subject_created_idx');
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->index(['subject_type', 'subject_id', 'created_at'], 'activity_logs_subject_created_idx');
            $table->index('created_at', 'activity_logs_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('platform_support_tickets', function (Blueprint $table) {
            $table->dropIndex('support_tickets_account_status_idx');
            $table->dropIndex('support_tickets_account_priority_idx');
            $table->dropIndex('support_tickets_account_created_idx');
            $table->dropIndex('support_tickets_status_idx');
            $table->dropIndex('support_tickets_priority_idx');
            $table->dropIndex('support_tickets_sla_due_idx');
            $table->dropIndex('support_tickets_assigned_to_idx');
        });

        Schema::table('platform_audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_logs_user_idx');
            $table->dropIndex('audit_logs_action_idx');
            $table->dropIndex('audit_logs_created_idx');
            $table->dropIndex('audit_logs_subject_idx');
            $table->dropIndex('audit_logs_subject_created_idx');
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex('activity_logs_subject_created_idx');
            $table->dropIndex('activity_logs_created_idx');
        });
    }
};
