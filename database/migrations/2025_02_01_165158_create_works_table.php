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
            $table->foreignId('quote_id')->nullable()->constrained()->onDelete('cascade'); // Quote
            $table->string('number')->nullable(); // Work number
            $table->string('job_title'); // Title of the work
            $table->text('instructions'); // Description of the work
            $table->date('start_date')->nullable(); // Start date of the work
            $table->date('end_date')->nullable(); // End date of the work
            $table->time('start_time')->nullable(); // Start time of the work
            $table->time('end_time')->nullable(); // End time of the work
            $table->boolean('is_all_day')->default(false); // Is the work all day
            $table->boolean('later')->default(false); // Is the work all day
            $table->string('ends')->default('Never'); // End date of the work
            $table->string('frequencyNumber')->default(1); // End date of the work
            $table->string('frequency')->default('Weekly'); // End date of the work
            $table->integer('totalVisits')->default(0); // End date of the work
            $table->json('repeatsOn')->nullable(); // End date of the work
            $table->text('type')->nullable(); // type of the work
            $table->text('category')->nullable();// Status of the work
            $table->boolean('is_completed')->default(false); // Status of work completion
            $table->decimal('subtotal', 10, 2)->nullable(); // Cost of the work (if applicable)
            $table->decimal('total', 10, 2)->nullable(); // Cost of the work (if applicable)
            $table->timestamps();
            $table->softDeletes();

            // Indexes for faster querying
            $table->index(['customer_id']);
        });

        // Pivot table for products used during works
        Schema::create('product_works', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->foreignId('quote_id')->nullable()->constrained()->onDelete('cascade'); // Quote
            $table->integer('quantity')->default(1);
            $table->decimal('price', 10, 2)->default(0);
            $table->string('description')->nullable();
            $table->decimal('total', 10, 2)->default(0);
            $table->timestamps();

            // Index for faster queries
            $table->index(['work_id', 'product_id', 'quote_id']);
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
