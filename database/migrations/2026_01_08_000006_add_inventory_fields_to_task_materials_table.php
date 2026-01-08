<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_materials', function (Blueprint $table) {
            $table->foreignId('warehouse_id')->nullable()->after('product_id')
                ->constrained('warehouses')->nullOnDelete();
            $table->foreignId('lot_id')->nullable()->after('warehouse_id')
                ->constrained('product_lots')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('task_materials', function (Blueprint $table) {
            $table->dropConstrainedForeignId('warehouse_id');
            $table->dropConstrainedForeignId('lot_id');
        });
    }
};
