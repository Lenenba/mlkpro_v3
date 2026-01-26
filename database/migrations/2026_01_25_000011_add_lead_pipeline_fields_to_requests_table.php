<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->foreignId('assigned_team_member_id')
                ->nullable()
                ->after('customer_id')
                ->constrained('team_members')
                ->nullOnDelete();
            $table->timestamp('status_updated_at')->nullable()->after('status');
            $table->timestamp('next_follow_up_at')->nullable()->after('converted_at');
            $table->string('lost_reason')->nullable()->after('next_follow_up_at');

            $table->index(['status', 'next_follow_up_at']);
        });

        DB::table('requests')
            ->where('status', 'REQ_CONVERTED')
            ->update([
                'status' => 'REQ_QUOTE_SENT',
                'status_updated_at' => DB::raw('updated_at'),
            ]);
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->dropIndex(['status', 'next_follow_up_at']);
            $table->dropConstrainedForeignId('assigned_team_member_id');
            $table->dropColumn(['status_updated_at', 'next_follow_up_at', 'lost_reason']);
        });

        DB::table('requests')
            ->where('status', 'REQ_QUOTE_SENT')
            ->update(['status' => 'REQ_CONVERTED']);
    }
};
