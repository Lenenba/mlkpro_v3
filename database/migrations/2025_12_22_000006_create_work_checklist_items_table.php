<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_id')->constrained()->onDelete('cascade');
            $table->foreignId('quote_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('quote_product_id')->nullable()->constrained('quote_products')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('pending');
            $table->integer('sort_order')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['work_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_checklist_items');
    }
};

