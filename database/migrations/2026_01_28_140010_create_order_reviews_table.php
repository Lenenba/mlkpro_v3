<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->boolean('is_approved')->default(true);
            $table->string('blocked_reason', 120)->nullable();
            $table->timestamps();

            $table->unique(['sale_id']);
            $table->index(['customer_id', 'is_approved']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_reviews');
    }
};
