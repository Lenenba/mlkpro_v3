<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_member_shifts', function (Blueprint $table) {
            $table->string('status', 20)->default('approved')->after('kind');
            $table->foreignId('approved_by_user_id')->nullable()->after('created_by_user_id')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by_user_id');

            $table->index(['account_id', 'status']);
            $table->index(['team_member_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('team_member_shifts', function (Blueprint $table) {
            $table->dropIndex(['account_id', 'status']);
            $table->dropIndex(['team_member_id', 'status']);
            $table->dropConstrainedForeignId('approved_by_user_id');
            $table->dropColumn(['status', 'approved_at']);
        });
    }
};
