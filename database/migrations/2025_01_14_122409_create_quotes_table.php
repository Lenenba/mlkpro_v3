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
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            $table->foreignId('property_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('job_title')->require()->default('New Quote');
            $table->string('status')->default('draft');
            $table->string('number')->nullable();
            $table->decimal('total', 10, 2)->default(0);
            $table->decimal('initial_deposit', 10, 2)->default(0);
            $table->boolean('is_fixed')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('quote_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->nullable()->constrained()->onDelete('cascade');
            $table->integer('quantity')->default(1);
            $table->decimal('price', 10, 2);
            $table->string('description')->nullable();
            $table->decimal('total', 10, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('quote_taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->decimal('rate', 5, 2); // Ex. : 5.00 pour 5%
            $table->decimal('amount', 10, 2)->default(0);
            $table->timestamps();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quotes');
        Schema::dropIfExists('quote_products');
        Schema::dropIfExists('quote_taxes');
    }
};
