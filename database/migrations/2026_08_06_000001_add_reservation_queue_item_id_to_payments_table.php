<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('payments', 'reservation_queue_item_id')) {
            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('reservation_queue_item_id')
                ->nullable()
                ->after('sale_id')
                ->constrained('reservation_queue_items')
                ->nullOnDelete()
                ->unique();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('payments', 'reservation_queue_item_id')) {
            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['reservation_queue_item_id']);
            $table->dropConstrainedForeignId('reservation_queue_item_id');
        });
    }
};
