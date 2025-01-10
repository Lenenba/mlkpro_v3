<?php

use App\Models\ProductCategory;
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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('number')->nullable();
            $table->text('description')->nullable();
            $table->foreignIdFor(ProductCategory::class, 'category_id')->constrained('product_categories');
            $table->string('image')->nullable(); // Ajout de la colonne image
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade'); // Ajout de la colonne user_id
            $table->integer('stock')->default(0);
            $table->integer('price')->default(0);
            $table->integer('minimum_stock')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
