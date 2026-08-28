<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            ! Schema::hasTable('reservation_queue_items')
            || Schema::hasIndex('reservation_queue_items', 'rqi_walk_in_dispatch_idx')
        ) {
            return;
        }

        Schema::table('reservation_queue_items', function (Blueprint $table): void {
            $table->index(
                ['item_type', 'status', 'account_id'],
                'rqi_walk_in_dispatch_idx'
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasIndex('reservation_queue_items', 'rqi_walk_in_dispatch_idx')) {
            return;
        }

        Schema::table('reservation_queue_items', function (Blueprint $table): void {
            $table->dropIndex('rqi_walk_in_dispatch_idx');
        });
    }
};
