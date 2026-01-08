<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->string('lot_number')->nullable()->index();
            $table->string('serial_number')->nullable()->index();
            $table->date('expires_at')->nullable();
            $table->date('received_at')->nullable();
            $table->integer('quantity')->default(0);
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'warehouse_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_lots');
    }
};
