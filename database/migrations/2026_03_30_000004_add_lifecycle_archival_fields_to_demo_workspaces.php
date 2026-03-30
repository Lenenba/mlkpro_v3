<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('demo_workspaces', function (Blueprint $table) {
            $table->timestamp('purged_at')
                ->nullable()
                ->after('provisioning_failed_at');
            $table->softDeletes()->after('last_reset_at');

            $table->index(['purged_at', 'deleted_at'], 'demo_workspaces_purged_deleted_idx');
        });
    }

    public function down(): void
    {
        Schema::table('demo_workspaces', function (Blueprint $table) {
            $table->dropIndex('demo_workspaces_purged_deleted_idx');
            $table->dropSoftDeletes();
            $table->dropColumn('purged_at');
        });
    }
};
