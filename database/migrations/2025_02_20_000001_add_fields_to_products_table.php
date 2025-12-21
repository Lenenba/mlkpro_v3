<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('sku')->nullable()->index();
            $table->string('barcode')->nullable()->index();
            $table->string('unit')->nullable();
            $table->string('supplier_name')->nullable();
            $table->decimal('cost_price', 10, 2)->default(0);
            $table->decimal('margin_percent', 5, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->nullable();
            $table->boolean('is_active')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'sku',
                'barcode',
                'unit',
                'supplier_name',
                'cost_price',
                'margin_percent',
                'tax_rate',
                'is_active',
            ]);
        });
    }
};
