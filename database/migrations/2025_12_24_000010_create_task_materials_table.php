<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('source_service_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('label');
            $table->text('description')->nullable();
            $table->string('unit', 50)->nullable();
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->boolean('billable')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('stock_moved_at')->nullable();
            $table->timestamps();

            $table->index(['task_id', 'billable']);
            $table->index(['product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_materials');
    }
};
