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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('number')->nullable();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('company_name')->nullable();
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('description')->nullable();
            $table->string('logo')->nullable();
            $table->boolean('billing_same_as_physical')->default(false); // Indicates if billing matches physical address
            $table->string('refer_by')->nullable();
            $table->enum('salutation', ['Mr', 'Mrs','Miss'])->default('Mr');
            $table->timestamps();
        });

        // properties table
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->onDelete('cascade'); // Link to customers
            $table->enum('type', ['physical', 'billing','other'])->default('physical');
            $table->string('country')->nullable();
            $table->string('street1')->nullable();
            $table->string('street2')->nullable();
            $table->string('city');
            $table->string('state')->nullable();
            $table->string('zip')->nullable();
            $table->timestamps();
        });
    }
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
        Schema::dropIfExists('properties');
    }
};
