<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->foreignId('request_id')->nullable()->constrained('requests')->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('quotes')->nullOnDelete();
            $table->foreignId('work_id')->nullable()->constrained('works')->nullOnDelete();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('accepted_at')->nullable();

            $table->index(['parent_id', 'work_id']);
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropForeign(['request_id']);
            $table->dropForeign(['parent_id']);
            $table->dropForeign(['work_id']);
            $table->dropIndex(['parent_id', 'work_id']);
            $table->dropColumn(['request_id', 'parent_id', 'work_id', 'signed_at', 'accepted_at']);
        });
    }
};

