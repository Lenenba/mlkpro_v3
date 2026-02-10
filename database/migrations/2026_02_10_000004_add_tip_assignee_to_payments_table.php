<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'tip_assignee_user_id')) {
                $table->foreignId('tip_assignee_user_id')
                    ->nullable()
                    ->after('charged_total')
                    ->constrained('users')
                    ->nullOnDelete();
                $table->index(['tip_assignee_user_id', 'paid_at'], 'payments_tip_assignee_paid_at_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'tip_assignee_user_id')) {
                $table->dropIndex('payments_tip_assignee_paid_at_idx');
                $table->dropForeign(['tip_assignee_user_id']);
                $table->dropColumn('tip_assignee_user_id');
            }
        });
    }
};

