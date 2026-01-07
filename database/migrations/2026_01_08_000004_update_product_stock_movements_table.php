<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_stock_movements', function (Blueprint $table) {
            $table->foreignId('warehouse_id')->nullable()->after('product_id')
                ->constrained('warehouses')->nullOnDelete();
            $table->foreignId('lot_id')->nullable()->after('warehouse_id')
                ->constrained('product_lots')->nullOnDelete();
            $table->string('reason')->nullable()->after('type');
            $table->string('reference_type')->nullable()->after('reason');
            $table->unsignedBigInteger('reference_id')->nullable()->after('reference_type');
            $table->integer('before_quantity')->nullable()->after('quantity');
            $table->integer('after_quantity')->nullable()->after('before_quantity');
            $table->decimal('unit_cost', 10, 2)->nullable()->after('after_quantity');
            $table->json('meta')->nullable()->after('unit_cost');

            $table->index(['reference_type', 'reference_id']);
            $table->index(['warehouse_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('product_stock_movements', function (Blueprint $table) {
            $table->dropIndex(['reference_type', 'reference_id']);
            $table->dropIndex(['warehouse_id', 'created_at']);
            $table->dropConstrainedForeignId('warehouse_id');
            $table->dropConstrainedForeignId('lot_id');
            $table->dropColumn([
                'reason',
                'reference_type',
                'reference_id',
                'before_quantity',
                'after_quantity',
                'unit_cost',
                'meta',
            ]);
        });
    }
};
