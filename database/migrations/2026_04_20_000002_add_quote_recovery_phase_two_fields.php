<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->timestamp('last_sent_at')->nullable()->after('archived_at');
            $table->timestamp('last_viewed_at')->nullable()->after('last_sent_at');
            $table->timestamp('last_followed_up_at')->nullable()->after('last_viewed_at');
            $table->timestamp('next_follow_up_at')->nullable()->after('last_followed_up_at');
            $table->string('follow_up_state')->nullable()->after('next_follow_up_at');
            $table->unsignedSmallInteger('follow_up_count')->nullable()->after('follow_up_state');
            $table->unsignedInteger('recovery_priority')->nullable()->after('follow_up_count');

            $table->index(['user_id', 'next_follow_up_at'], 'quotes_user_followup_idx');
            $table->index(['user_id', 'last_viewed_at'], 'quotes_user_viewed_idx');
            $table->index(['user_id', 'recovery_priority'], 'quotes_user_recovery_priority_idx');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropIndex('quotes_user_followup_idx');
            $table->dropIndex('quotes_user_viewed_idx');
            $table->dropIndex('quotes_user_recovery_priority_idx');

            $table->dropColumn([
                'last_sent_at',
                'last_viewed_at',
                'last_followed_up_at',
                'next_follow_up_at',
                'follow_up_state',
                'follow_up_count',
                'recovery_priority',
            ]);
        });
    }
};
