<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->timestamp('first_response_at')->nullable()->after('converted_at');
            $table->timestamp('last_activity_at')->nullable()->after('first_response_at');
            $table->timestamp('sla_due_at')->nullable()->after('last_activity_at');
            $table->unsignedTinyInteger('triage_priority')->nullable()->after('sla_due_at');
            $table->string('risk_level', 32)->nullable()->after('triage_priority');
            $table->timestamp('stale_since_at')->nullable()->after('risk_level');

            $table->index(['user_id', 'triage_priority'], 'requests_user_triage_priority_index');
            $table->index(['user_id', 'sla_due_at'], 'requests_user_sla_due_at_index');
            $table->index(['user_id', 'stale_since_at'], 'requests_user_stale_since_at_index');
            $table->index(['user_id', 'last_activity_at'], 'requests_user_last_activity_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->dropIndex('requests_user_triage_priority_index');
            $table->dropIndex('requests_user_sla_due_at_index');
            $table->dropIndex('requests_user_stale_since_at_index');
            $table->dropIndex('requests_user_last_activity_at_index');

            $table->dropColumn([
                'first_response_at',
                'last_activity_at',
                'sla_due_at',
                'triage_priority',
                'risk_level',
                'stale_since_at',
            ]);
        });
    }
};
