<?php

use App\Models\Customer;
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
        // Main table for works
        Schema::create('works', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Worker
            $table->foreignIdFor(Customer::class, 'customer_id')->constrained('customers')->onDelete('cascade'); // Client customer
            $table->string('number')->nullable(); // Work number
            $table->text('description'); // Description of the work
            $table->text('type'); // type of the work
            $table->text('category'); // Status of the work
            $table->datetime('work_date'); // Date and time of the work
            $table->integer('time_spent')->default(0); // Time spent in minutes
            $table->boolean('is_completed')->default(false); // Status of work completion
            $table->decimal('cost', 10, 2)->nullable(); // Cost of the work (if applicable)
            $table->decimal('base_cost', 10, 2)->nullable(); // Cost of the work (if applicable)
            $table->timestamps();

            // Indexes for faster querying
            $table->index(['work_date', 'user_id']);
            $table->index(['customer_id']);
        });

        // Pivot table for products used during works
        Schema::create('product_works', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->integer('quantity_used'); // Quantity of the product used
            $table->string('unit')->default('pcs'); // Unit of measurement, default is pieces
            $table->timestamps();

            // Index for faster queries
            $table->index(['work_id', 'product_id']);
        });

        // Additional table to track worker ratings
        Schema::create('work_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Worker being rated
            $table->tinyInteger('rating')->unsigned()->default(0); // Rating out of 5
            $table->text('feedback')->nullable(); // Optional feedback
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('worker_ratings');
        Schema::dropIfExists('product_works');
        Schema::dropIfExists('works');
    }
};
