<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->foreignId('duplicate_of_prospect_id')
                ->nullable()
                ->after('archive_reason')
                ->constrained('requests')
                ->nullOnDelete();
            $table->foreignId('merged_into_prospect_id')
                ->nullable()
                ->after('duplicate_of_prospect_id')
                ->constrained('requests')
                ->nullOnDelete();

            $table->index(['user_id', 'duplicate_of_prospect_id'], 'requests_user_duplicate_of_prospect_index');
            $table->index(['user_id', 'merged_into_prospect_id'], 'requests_user_merged_into_prospect_index');
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->dropIndex('requests_user_duplicate_of_prospect_index');
            $table->dropIndex('requests_user_merged_into_prospect_index');
            $table->dropConstrainedForeignId('merged_into_prospect_id');
            $table->dropConstrainedForeignId('duplicate_of_prospect_id');
        });
    }
};
