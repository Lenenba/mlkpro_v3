<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->softDeletes()->after('archive_reason');
            $table->foreignId('deleted_by_user_id')
                ->nullable()
                ->after('deleted_at')
                ->constrained('users')
                ->nullOnDelete();

            $table->index(['user_id', 'deleted_at'], 'requests_user_deleted_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->dropIndex('requests_user_deleted_at_index');
            $table->dropConstrainedForeignId('deleted_by_user_id');
            $table->dropSoftDeletes();
        });
    }
};
