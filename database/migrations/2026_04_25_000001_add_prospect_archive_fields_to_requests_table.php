<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('stale_since_at');
            $table->foreignId('archived_by_user_id')
                ->nullable()
                ->after('archived_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->string('archive_reason')->nullable()->after('archived_by_user_id');

            $table->index(['user_id', 'archived_at'], 'requests_user_archived_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->dropIndex('requests_user_archived_at_index');
            $table->dropConstrainedForeignId('archived_by_user_id');
            $table->dropColumn(['archived_at', 'archive_reason']);
        });
    }
};
