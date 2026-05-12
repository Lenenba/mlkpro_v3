<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_packages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offer_package_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('quote_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 24)->default('active');
            $table->date('starts_at');
            $table->date('expires_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedInteger('initial_quantity')->default(1);
            $table->unsignedInteger('consumed_quantity')->default(0);
            $table->integer('remaining_quantity')->default(1);
            $table->string('unit_type', 32)->default('credit');
            $table->decimal('price_paid', 12, 2)->default(0);
            $table->string('currency_code', 3)->default('CAD');
            $table->json('source_details')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'customer_id', 'status']);
            $table->index(['user_id', 'offer_package_id']);
            $table->index(['expires_at', 'status']);
            $table->unique('invoice_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_packages');
    }
};
