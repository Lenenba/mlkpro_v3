<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('paid_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('category_key', 100)->nullable();
            $table->string('supplier_name')->nullable();
            $table->string('reference_number')->nullable();
            $table->string('currency_code', 3)->default('CAD');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->date('expense_date');
            $table->date('due_date')->nullable();
            $table->date('paid_date')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('payment_method', 50)->nullable();
            $table->string('status', 50)->default('draft');
            $table->boolean('reimbursable')->default(false);
            $table->boolean('is_recurring')->default(false);
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'category_key']);
            $table->index(['user_id', 'expense_date']);
            $table->index(['user_id', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};

