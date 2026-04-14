<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('company_finance_settings')->nullable()->after('company_time_settings');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->string('current_approver_role_key', 50)->nullable()->after('approved_by_user_id');
            $table->unsignedSmallInteger('current_approval_level')->nullable()->after('current_approver_role_key');

            $table->index(['user_id', 'current_approver_role_key'], 'expenses_current_approver_idx');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('created_by_user_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->after('created_by_user_id')->constrained('users')->nullOnDelete();
            $table->foreignId('rejected_by_user_id')->nullable()->after('approved_by_user_id')->constrained('users')->nullOnDelete();
            $table->foreignId('processed_by_user_id')->nullable()->after('rejected_by_user_id')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('currency_code');
            $table->timestamp('rejected_at')->nullable()->after('approved_at');
            $table->timestamp('processed_at')->nullable()->after('rejected_at');
            $table->string('approval_status', 50)->default('draft')->after('status');
            $table->string('current_approver_role_key', 50)->nullable()->after('approval_status');
            $table->unsignedSmallInteger('current_approval_level')->nullable()->after('current_approver_role_key');
            $table->json('approval_meta')->nullable()->after('current_approval_level');

            $table->index(['user_id', 'approval_status'], 'invoices_approval_status_idx');
            $table->index(['user_id', 'current_approver_role_key'], 'invoices_current_approver_idx');
        });

        DB::table('invoices')->update([
            'created_by_user_id' => DB::raw('user_id'),
            'approved_by_user_id' => DB::raw('user_id'),
            'approved_at' => now(),
            'approval_status' => 'approved',
        ]);
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('invoices_approval_status_idx');
            $table->dropIndex('invoices_current_approver_idx');
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropConstrainedForeignId('approved_by_user_id');
            $table->dropConstrainedForeignId('rejected_by_user_id');
            $table->dropConstrainedForeignId('processed_by_user_id');
            $table->dropColumn([
                'approved_at',
                'rejected_at',
                'processed_at',
                'approval_status',
                'current_approver_role_key',
                'current_approval_level',
                'approval_meta',
            ]);
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex('expenses_current_approver_idx');
            $table->dropColumn([
                'current_approver_role_key',
                'current_approval_level',
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('company_finance_settings');
        });
    }
};
